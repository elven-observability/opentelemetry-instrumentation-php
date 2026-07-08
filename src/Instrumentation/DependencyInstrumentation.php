<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Instrumentation;

use Elven\Observability\Php\Observability;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

final class DependencyInstrumentation
{
    /**
     * @template T
     * @param array<string, mixed> $attributes
     * @param callable(): T $callback
     * @return T
     */
    public static function trace(string $type, string $name, string $operation, callable $callback, array $attributes = []): mixed
    {
        $start = hrtime(true);
        $spanName = strtoupper($type) . ' ' . $operation;
        $base = [
            'dependency.type' => $type,
            'dependency.name' => $name,
            'operation' => $operation,
        ];

        return Observability::tracer()->withSpan(
            $spanName,
            static function ($span) use ($callback, $base, $attributes, $start) {
                $span->setAttributes($base + $attributes);
                try {
                    $result = $callback();
                    Observability::metrics()->histogram('elven.php.dependency.duration', 's')->record(
                        (hrtime(true) - $start) / 1_000_000_000,
                        $base,
                    );

                    return $result;
                } catch (Throwable $throwable) {
                    $span->recordException($throwable);
                    $span->setStatus(StatusCode::STATUS_ERROR, $throwable::class);
                    Observability::metrics()->histogram('elven.php.dependency.duration', 's')->record(
                        (hrtime(true) - $start) / 1_000_000_000,
                        $base + ['error.type' => self::errorType($throwable)],
                    );
                    throw $throwable;
                }
            },
            SpanKind::KIND_CLIENT,
            $base + $attributes,
        );
    }

    private static function errorType(Throwable $throwable): string
    {
        $parts = explode('\\', $throwable::class);

        return end($parts) ?: 'Throwable';
    }
}
