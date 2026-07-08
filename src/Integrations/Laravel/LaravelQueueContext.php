<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Integrations\Laravel;

use Elven\Observability\Php\Observability;
use Elven\Observability\Php\Tracing\SafeSpan;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class LaravelQueueContext
{
    private static ?SafeSpan $span = null;
    private static ?ScopeInterface $scope = null;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function injectPayload(array $payload): array
    {
        $carrier = [];
        Observability::propagator()->inject($carrier);
        if ($carrier !== []) {
            $payload['elven_otel_context'] = $carrier;
        }

        return $payload;
    }

    public static function start(object $event): void
    {
        self::finish();
        self::$scope = self::activatePayloadCarrier($event);
        $jobName = 'laravel.queue.job';
        if (isset($event->job) && is_object($event->job) && method_exists($event->job, 'resolveName')) {
            $jobName = (string) $event->job->resolveName();
        }

        self::$span = Observability::tracer()->span('QUEUE ' . $jobName, SpanKind::KIND_CONSUMER, [
            'messaging.system' => 'laravel_queue',
            'messaging.operation.name' => 'process',
            'messaging.destination.name' => isset($event->connectionName) ? (string) $event->connectionName : null,
        ]);
    }

    public static function fail(?Throwable $throwable): void
    {
        if ($throwable !== null) {
            self::$span?->recordException($throwable);
            self::$span?->setStatus(StatusCode::STATUS_ERROR, $throwable::class);
        }
        self::finish();
    }

    public static function finish(): void
    {
        self::$span?->end();
        self::$span = null;
        try {
            self::$scope?->detach();
        } catch (Throwable) {
        }
        self::$scope = null;
        Observability::boot()->forceFlush();
    }

    private static function activatePayloadCarrier(object $event): ?ScopeInterface
    {
        $carrier = self::payloadCarrier($event);
        if ($carrier === []) {
            return null;
        }

        try {
            return Observability::propagator()->extract($carrier)->activate();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function payloadCarrier(object $event): array
    {
        $payload = null;
        if (isset($event->job) && is_object($event->job) && method_exists($event->job, 'payload')) {
            $payload = $event->job->payload();
        } elseif (isset($event->payload) && is_array($event->payload)) {
            $payload = $event->payload;
        }

        if (!is_array($payload)) {
            return [];
        }

        $candidates = [
            $payload['elven_otel_context'] ?? null,
            $payload['data']['elven_otel_context'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }
}
