<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Integration;

use Elven\Observability\Php\Observability;
use PHPUnit\Framework\TestCase;

final class RedirectSafetyTest extends TestCase
{
    public function testExportDoesNotFollowCollectorRedirect(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);
        $port = (int) substr(strrchr($name, ':') ?: ':0', 1);
        $store = tempnam(sys_get_temp_dir(), 'elven-redirect-');
        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' '
            . escapeshellarg(dirname(__DIR__) . '/Support/redirect-collector.php');
        $process = proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['ELVEN_TEST_REDIRECT_STORE' => $store]),
        );
        self::assertIsResource($process);
        try {
            $ready = false;
            for ($i = 0; $i < 100; $i++) {
                $probe = @fsockopen('127.0.0.1', $port);
                if ($probe) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready);
            Observability::init([
                'enabled' => true,
                'otlp_traces_endpoint' => 'http://127.0.0.1:' . $port . '/redirect',
                'otlp_protocol' => 'http/json',
                'metrics_exporter' => 'none',
                'logs_exporter' => 'none',
                'timeout_millis' => 500,
            ]);
            Observability::tracer()->withSpan('audit', static function (): void {
            });
            Observability::boot()->shutdown();
            $requests = file_get_contents($store);
            self::assertIsString($requests);
            self::assertStringContainsString('/redirect', $requests);
            self::assertStringNotContainsString('/sink', $requests);
        } finally {
            Observability::resetForTests();
            proc_terminate($process);
            proc_close($process);
            @unlink($store);
        }
    }
}
