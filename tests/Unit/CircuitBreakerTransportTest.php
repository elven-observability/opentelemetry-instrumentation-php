<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Unit;

use Elven\Observability\Php\Export\CircuitBreakerTransport;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CircuitBreakerTransportTest extends TestCase
{
    public function testCircuitOpensAfterConsecutiveFailures(): void
    {
        $inner = new class implements TransportInterface {
            public int $calls = 0;

            public function contentType(): string
            {
                return 'application/x-protobuf';
            }

            /** @return FutureInterface<mixed> */
            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                $this->calls++;

                return new ErrorFuture(new RuntimeException('down'));
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
        $transport = new CircuitBreakerTransport($inner, 2, 30000);

        $this->awaitIgnoringFailure($transport->send('a'));
        $this->awaitIgnoringFailure($transport->send('b'));
        $this->awaitIgnoringFailure($transport->send('c'));

        self::assertSame(2, $inner->calls);
    }

    public function testSuccessfulExportResetsFailureCount(): void
    {
        $inner = new class implements TransportInterface {
            public int $calls = 0;
            public bool $fail = true;

            public function contentType(): string
            {
                return 'application/x-protobuf';
            }

            /** @return FutureInterface<mixed> */
            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                $this->calls++;

                return $this->fail
                    ? new ErrorFuture(new RuntimeException('down'))
                    : new CompletedFuture('');
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
        $transport = new CircuitBreakerTransport($inner, 2, 30000);

        $this->awaitIgnoringFailure($transport->send('a'));
        $inner->fail = false;
        $transport->send('b')->await();
        $inner->fail = true;
        $this->awaitIgnoringFailure($transport->send('c'));
        $this->awaitIgnoringFailure($transport->send('d'));

        self::assertSame(4, $inner->calls);
    }

    /** @param FutureInterface<mixed> $future */
    private function awaitIgnoringFailure(FutureInterface $future): void
    {
        try {
            $future->await();
        } catch (RuntimeException) {
        }
    }
}
