<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Integration;

use Elven\Observability\Php\Observability;
use PHPUnit\Framework\TestCase;

final class FakeCollectorTest extends TestCase
{
    private string $store;
    private int $port;
    /** @var resource|null */
    private $process = null;

    protected function setUp(): void
    {
        $this->store = sys_get_temp_dir() . '/elven-fake-collector-' . bin2hex(random_bytes(4)) . '.jsonl';
        $this->port = $this->freePort();
        $cmd = sprintf(
            'ELVEN_FAKE_COLLECTOR_STORE=%s exec php -S 127.0.0.1:%d %s >/dev/null 2>&1',
            escapeshellarg($this->store),
            $this->port,
            escapeshellarg(dirname(__DIR__, 2) . '/examples/fake-collector/server.php'),
        );
        $this->process = proc_open($cmd, [], $pipes);
        $this->waitUntilReady();
    }

    protected function tearDown(): void
    {
        Observability::resetForTests();
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if (is_file($this->store)) {
            unlink($this->store);
        }
    }

    public function testExportsTraceMetricAndLogPayloadsToFakeCollector(): void
    {
        Observability::init([
            'enabled' => true,
            'service_name' => 'php-modern-test',
            'environment' => 'test',
            'otlp_endpoint' => 'http://127.0.0.1:' . $this->port,
            'otlp_protocol' => 'http/protobuf',
            'timeout_millis' => 1000,
            'traces_exporter' => 'otlp',
            'metrics_exporter' => 'otlp',
            'logs_exporter' => 'otlp',
        ]);

        Observability::tracer()->withSpan('test.operation', static function (): void {
            Observability::metrics()->counter('test.operation.count')->add(1, ['operation' => 'test']);
            Observability::logs()->emit('hello from integration', 'info', ['operation' => 'test']);
        });
        Observability::boot()->forceFlush();
        Observability::boot()->shutdown();

        $lines = $this->readLines();
        $paths = array_map(static fn (array $line): string => $line['path'], $lines);

        self::assertContains('/v1/traces', $paths);
        self::assertContains('/v1/metrics', $paths);
        self::assertContains('/v1/logs', $paths);
        foreach ($lines as $line) {
            self::assertGreaterThan(0, $line['length']);
        }
    }

    public function testHttpJsonPayloadContainsCorrelatableTraceMetricAndLogData(): void
    {
        Observability::init([
            'enabled' => true,
            'service_name' => 'php-json-test',
            'environment' => 'test',
            'otlp_endpoint' => 'http://127.0.0.1:' . $this->port,
            'otlp_protocol' => 'http/json',
            'timeout_millis' => 1000,
            'traces_exporter' => 'otlp',
            'metrics_exporter' => 'otlp',
            'logs_exporter' => 'otlp',
        ]);

        Observability::tracer()->withSpan('GET /orders/{id}', static function (): void {
            Observability::metrics()->histogram('http.server.request.duration', 's')->record(0.012, [
                'http.route' => '/orders/{id}',
                'http.request.method' => 'GET',
                'http.response.status_code' => 201,
                'user_id' => 'must-not-be-exported',
            ]);
            Observability::logs()->emit('hello person@example.com', 'info', ['operation' => 'orders']);
        });
        Observability::boot()->forceFlush();
        Observability::boot()->shutdown();

        $payloads = $this->jsonPayloadsByPath();

        self::assertArrayHasKey('/v1/traces', $payloads);
        self::assertArrayHasKey('/v1/metrics', $payloads);
        self::assertArrayHasKey('/v1/logs', $payloads);
        self::assertSame('php-json-test', $this->firstResourceAttribute($payloads['/v1/traces'], 'service.name'));
        self::assertSame('GET /orders/{id}', $this->firstSpan($payloads['/v1/traces'])['name'] ?? null);
        self::assertSame('/orders/{id}', $this->firstMetricAttribute($payloads['/v1/metrics'], 'http.route'));
        self::assertNull($this->firstMetricAttribute($payloads['/v1/metrics'], 'user_id'));
        self::assertSame('orders', $this->firstLogAttribute($payloads['/v1/logs'], 'operation'));
    }

    public function testDeadCollectorDoesNotBlockNormalInstrumentationPath(): void
    {
        $deadPort = $this->freePort();
        Observability::init([
            'enabled' => true,
            'service_name' => 'dead-collector-test',
            'environment' => 'test',
            'otlp_endpoint' => 'http://127.0.0.1:' . $deadPort,
            'otlp_protocol' => 'http/protobuf',
            'timeout_millis' => 20,
            'export_max_retries' => 0,
            'traces_exporter' => 'otlp',
            'metrics_exporter' => 'otlp',
            'logs_exporter' => 'otlp',
        ]);

        $start = hrtime(true);
        Observability::tracer()->withSpan('fast.path', static function (): void {
            Observability::metrics()->counter('fast.path.count')->add(1, ['operation' => 'fast']);
            Observability::logs()->emit('fast path', 'info', ['operation' => 'fast']);
        });
        $elapsedMillis = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(150.0, $elapsedMillis);
        Observability::boot()->forceFlush();
    }

    /** @return list<array<string, mixed>> */
    private function readLines(): array
    {
        $deadline = microtime(true) + 3;
        do {
            if (is_file($this->store) && filesize($this->store) > 0) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        if (!is_file($this->store)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $line): ?array => json_decode($line, true) ?: null,
            file($this->store, FILE_IGNORE_NEW_LINES) ?: [],
        )));
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);
        $port = (int) substr(strrchr($name, ':') ?: ':0', 1);
        self::assertGreaterThan(0, $port);

        return $port;
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 3;
        do {
            $socket = @fsockopen('127.0.0.1', $this->port);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('Fake collector did not start on 127.0.0.1:' . $this->port);
    }

    /** @return array<string, array<string, mixed>> */
    private function jsonPayloadsByPath(): array
    {
        $payloads = [];
        foreach ($this->readLines() as $line) {
            if (!isset($line['path'], $line['body_base64'])) {
                continue;
            }
            $body = base64_decode((string) $line['body_base64'], true);
            if ($body === false || $body === '') {
                continue;
            }
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $path = (string) $line['path'];
                if (!isset($payloads[$path])) {
                    $payloads[$path] = $decoded;
                }
            }
        }

        return $payloads;
    }

    /** @param array<string, mixed> $payload */
    private function firstResourceAttribute(array $payload, string $key): mixed
    {
        foreach (($payload['resourceSpans'] ?? $payload['resourceMetrics'] ?? $payload['resourceLogs'] ?? []) as $resource) {
            $value = $this->attributeValue($resource['resource']['attributes'] ?? [], $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function firstSpan(array $payload): array
    {
        foreach (($payload['resourceSpans'] ?? []) as $resource) {
            foreach (($resource['scopeSpans'] ?? []) as $scope) {
                foreach (($scope['spans'] ?? []) as $span) {
                    return is_array($span) ? $span : [];
                }
            }
        }

        return [];
    }

    /** @param array<string, mixed> $payload */
    private function firstMetricAttribute(array $payload, string $key): mixed
    {
        foreach (($payload['resourceMetrics'] ?? []) as $resource) {
            foreach (($resource['scopeMetrics'] ?? []) as $scope) {
                foreach (($scope['metrics'] ?? []) as $metric) {
                    foreach (['sum', 'gauge', 'histogram'] as $kind) {
                        foreach (($metric[$kind]['dataPoints'] ?? []) as $point) {
                            $value = $this->attributeValue($point['attributes'] ?? [], $key);
                            if ($value !== null) {
                                return $value;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function firstLogAttribute(array $payload, string $key): mixed
    {
        foreach (($payload['resourceLogs'] ?? []) as $resource) {
            foreach (($resource['scopeLogs'] ?? []) as $scope) {
                foreach (($scope['logRecords'] ?? []) as $record) {
                    $value = $this->attributeValue($record['attributes'] ?? [], $key);
                    if ($value !== null) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $attributes */
    private function attributeValue(array $attributes, string $key): mixed
    {
        foreach ($attributes as $attribute) {
            if (($attribute['key'] ?? null) !== $key || !isset($attribute['value']) || !is_array($attribute['value'])) {
                continue;
            }
            foreach (['stringValue', 'intValue', 'doubleValue', 'boolValue'] as $field) {
                if (array_key_exists($field, $attribute['value'])) {
                    return $attribute['value'][$field];
                }
            }
        }

        return null;
    }
}
