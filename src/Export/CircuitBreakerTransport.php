<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Export;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use RuntimeException;
use Throwable;

/** @implements TransportInterface<string> */
final class CircuitBreakerTransport implements TransportInterface
{
    private int $consecutiveFailures = 0;
    private int $openUntilNanos = 0;

    public function __construct(
        /** @var TransportInterface<string> */
        private readonly TransportInterface $inner,
        private readonly int $failureThreshold,
        private readonly int $cooldownMillis,
    ) {
    }

    public function contentType(): string
    {
        return $this->inner->contentType();
    }

    /** @return FutureInterface<mixed> */
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->isOpen()) {
            return new ErrorFuture(new RuntimeException('OTLP exporter circuit breaker is open'));
        }

        try {
            return new CircuitBreakerFuture(
                $this->inner->send($payload, $cancellation),
                function (): void {
                    $this->recordSuccess();
                },
                function (): void {
                    $this->recordFailure();
                },
            );
        } catch (Throwable $throwable) {
            $this->recordFailure();

            return new ErrorFuture($throwable);
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }

    private function isOpen(): bool
    {
        return $this->openUntilNanos > hrtime(true);
    }

    private function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->openUntilNanos = 0;
    }

    private function recordFailure(): void
    {
        $this->consecutiveFailures++;
        if ($this->consecutiveFailures >= $this->failureThreshold) {
            $this->openUntilNanos = hrtime(true) + ($this->cooldownMillis * 1_000_000);
        }
    }
}
