<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Config;

final class ObservabilityConfig
{
    /**
     * @param array<string, scalar|null> $resourceAttributes
     * @param array<string, string> $headers
     * @param list<string> $propagators
     * @param list<string> $allowedRawAttributes
     * @param list<string> $allowedMetricLabels
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $debug,
        public readonly string $serviceName,
        public readonly ?string $serviceNamespace,
        public readonly ?string $serviceVersion,
        public readonly string $environment,
        public readonly array $resourceAttributes,
        public readonly string $otlpEndpoint,
        public readonly ?string $tracesEndpoint,
        public readonly ?string $metricsEndpoint,
        public readonly ?string $logsEndpoint,
        public readonly string $otlpProtocol,
        public readonly ?string $tracesProtocol,
        public readonly ?string $metricsProtocol,
        public readonly ?string $logsProtocol,
        public readonly array $headers,
        public readonly int $timeoutMillis,
        public readonly int $exportRetryDelayMillis,
        public readonly int $exportMaxRetries,
        public readonly bool $exportCircuitBreakerEnabled,
        public readonly int $exportCircuitBreakerFailureThreshold,
        public readonly int $exportCircuitBreakerCooldownMillis,
        public readonly string $tracesExporter,
        public readonly string $metricsExporter,
        public readonly string $logsExporter,
        public readonly array $propagators,
        public readonly string $sampler,
        public readonly float $samplerArg,
        public readonly int $spanProcessorMaxQueueSize,
        public readonly int $spanProcessorScheduleDelayMillis,
        public readonly int $spanProcessorExportTimeoutMillis,
        public readonly int $spanProcessorMaxExportBatchSize,
        public readonly int $logProcessorMaxQueueSize,
        public readonly int $logProcessorScheduleDelayMillis,
        public readonly int $logProcessorExportTimeoutMillis,
        public readonly int $logProcessorMaxExportBatchSize,
        public readonly bool $redactionEnabled,
        public readonly bool $captureDbStatement,
        public readonly bool $redactDbStatement,
        public readonly array $allowedRawAttributes,
        public readonly array $allowedMetricLabels,
        public readonly int $maxAttributeLength,
        public readonly bool $workerResetStrict,
        public readonly bool $logCorrelationEnabled,
        public readonly int $metricCardinalityLimit = 512,
        public readonly int $maxMetricInstrumentsPerKind = 256,
    ) {
    }

    public function shouldExportTraces(): bool
    {
        return $this->enabled && $this->tracesExporter === 'otlp';
    }

    public function shouldExportMetrics(): bool
    {
        return $this->enabled && $this->metricsExporter === 'otlp';
    }

    public function shouldExportLogs(): bool
    {
        return $this->enabled && $this->logsExporter === 'otlp';
    }

    public function isRawAttributeAllowed(string $key): bool
    {
        return in_array($key, $this->allowedRawAttributes, true);
    }

    public function signalEndpoint(string $signal): string
    {
        $endpoint = match ($signal) {
            'traces' => $this->tracesEndpoint,
            'metrics' => $this->metricsEndpoint,
            'logs' => $this->logsEndpoint,
            default => null,
        };

        if ($endpoint !== null && $endpoint !== '') {
            return $endpoint;
        }

        return rtrim($this->otlpEndpoint, '/') . '/v1/' . $signal;
    }

    public function signalProtocol(string $signal): string
    {
        $protocol = match ($signal) {
            'traces' => $this->tracesProtocol,
            'metrics' => $this->metricsProtocol,
            'logs' => $this->logsProtocol,
            default => null,
        };

        return $protocol ?: $this->otlpProtocol;
    }
}
