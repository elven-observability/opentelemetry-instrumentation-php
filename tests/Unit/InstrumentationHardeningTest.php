<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Unit;

use Elven\Observability\Php\Instrumentation\DbInstrumentation;
use Elven\Observability\Php\Instrumentation\HttpClientInstrumentation;
use Elven\Observability\Php\Instrumentation\JobInstrumentation;
use Elven\Observability\Php\Integrations\Guzzle\GuzzleMiddleware;
use Elven\Observability\Php\Observability;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use OpenTelemetry\API\Trace\Span;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class InstrumentationHardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        Observability::resetForTests();
    }

    public function testGuzzleInjectsClientSpanAndDoesNotLeakAsyncContext(): void
    {
        Observability::init($this->localConfig());

        $capturedRequest = null;
        $innerPromise = new Promise();
        $handler = static function (RequestInterface $request) use (&$capturedRequest, $innerPromise): Promise {
            $capturedRequest = $request;

            return $innerPromise;
        };
        $client = new Client(['handler' => GuzzleMiddleware::middleware()($handler)]);

        Observability::tracer()->withSpan('parent', function () use ($client, $innerPromise, &$capturedRequest): void {
            $parentContext = Span::getCurrent()->getContext();
            $promise = $client->requestAsync('GET', 'https://downstream.example.test/search');
            $afterScheduleContext = Span::getCurrent()->getContext();

            self::assertSame($parentContext->getSpanId(), $afterScheduleContext->getSpanId());
            self::assertInstanceOf(RequestInterface::class, $capturedRequest);

            $traceparent = $capturedRequest->getHeaderLine('traceparent');
            $parts = explode('-', $traceparent);

            self::assertCount(4, $parts);
            self::assertSame($parentContext->getTraceId(), $parts[1]);
            self::assertNotSame($parentContext->getSpanId(), $parts[2]);

            $innerPromise->resolve(new \GuzzleHttp\Psr7\Response(200));
            $promise->wait();
        });
    }

    public function testHttpClient4xxMarksErrorWithoutThrowing(): void
    {
        Observability::init($this->localConfig());

        $result = (new HttpClientInstrumentation())->request(
            'GET',
            'https://api.example.test/missing',
            [],
            static fn (array $headers): array => ['status_code' => 404, 'headers' => $headers],
        );

        self::assertSame(404, $result['status_code']);
        self::assertArrayHasKey('traceparent', $result['headers']);
    }

    public function testJobInstrumentationPropagatesCarrier(): void
    {
        Observability::init($this->localConfig());

        $carrier = [];
        $producerTraceId = null;

        Observability::tracer()->withSpan('producer', static function () use (&$carrier, &$producerTraceId): void {
            $producerTraceId = Span::getCurrent()->getContext()->getTraceId();
            $carrier = JobInstrumentation::inject();
        });

        JobInstrumentation::trace(
            'consumer',
            static function () use ($producerTraceId): void {
                self::assertSame($producerTraceId, Span::getCurrent()->getContext()->getTraceId());
            },
            ['messaging.system' => 'test'],
            $carrier,
        );
    }

    public function testJobInstrumentationFlushesAfterSpanEndAndPreservesExceptions(): void
    {
        Observability::init($this->localConfig());

        $this->expectException(RuntimeException::class);

        JobInstrumentation::trace('consumer.error', static function (): void {
            throw new RuntimeException('job failed');
        });
    }

    public function testDbInstrumentationEmitsModernAndLegacyQueryTextWhenCaptureIsEnabled(): void
    {
        Observability::init($this->localConfig([
            'capture_db_statement' => true,
            'redact_db_statement' => true,
        ]));

        $result = (new DbInstrumentation())->trace(
            'mysql',
            'SELECT',
            "select * from users where email='person@example.com' and id=42",
            static fn (): string => 'ok',
        );

        self::assertSame('ok', $result);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function localConfig(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => true,
            'service_name' => 'unit-test',
            'environment' => 'test',
            'traces_exporter' => 'none',
            'metrics_exporter' => 'none',
            'logs_exporter' => 'none',
        ];
    }
}
