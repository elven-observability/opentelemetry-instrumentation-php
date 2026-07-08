<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Instrumentation;

use Elven\Observability\Php\Observability;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class JobInstrumentation
{
    /**
     * @template T
     * @param callable(): T $callback
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $carrier
     * @return T
     */
    public static function trace(string $name, callable $callback, array $attributes = [], array $carrier = []): mixed
    {
        $scope = self::activateCarrier($carrier);
        $span = Observability::tracer()->span($name, SpanKind::KIND_CONSUMER, $attributes);

        try {
            return $callback();
        } catch (Throwable $throwable) {
            $span->recordException($throwable);
            $span->setStatus(StatusCode::STATUS_ERROR, $throwable::class);
            throw $throwable;
        } finally {
            $span->end();
            self::detachScope($scope);
            Observability::boot()->forceFlush();
        }
    }

    /**
     * @param array<string, mixed> $carrier
     * @return array<string, mixed>
     */
    public static function inject(array $carrier = []): array
    {
        Observability::propagator()->inject($carrier);

        return $carrier;
    }

    /** @param array<string, mixed> $carrier */
    private static function activateCarrier(array $carrier): ?ScopeInterface
    {
        if ($carrier === []) {
            return null;
        }

        try {
            return Observability::propagator()->extract($carrier)->activate();
        } catch (Throwable) {
            return null;
        }
    }

    private static function detachScope(?ScopeInterface $scope): void
    {
        try {
            $scope?->detach();
        } catch (Throwable) {
        }
    }
}
