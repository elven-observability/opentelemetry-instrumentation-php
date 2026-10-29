<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Config;

final class EnvConfigResolver
{
    /** @param array<string, mixed> $config */
    public function resolve(array $config = []): ObservabilityConfig
    {
        $enabled = $this->bool($config, 'enabled', 'ELVEN_OTEL_ENABLED', false);
        if ($this->env('ELVEN_OTEL_ENABLED') !== null && !$this->envBool('ELVEN_OTEL_ENABLED', false)) {
            $enabled = false;
        }
        if ($this->envBool('OTEL_SDK_DISABLED', false)) {
            $enabled = false;
        }

        $environment = $this->string(
            $config,
            'environment',
            'ELVEN_ENVIRONMENT',
            $this->env('OTEL_DEPLOYMENT_ENVIRONMENT') ?: 'production'
        );

        $headers = $this->headers($this->string($config, 'otlp_headers', 'OTEL_EXPORTER_OTLP_HEADERS', ''));
        $allowRaw = $this->csv($this->string($config, 'allow_raw_attributes', 'ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES', ''));
        $resourceAttributes = $this->resourceAttributes($config);
        $serviceNameDefault = isset($resourceAttributes['service.name']) && is_scalar($resourceAttributes['service.name'])
            ? (string) $resourceAttributes['service.name']
            : 'unknown_service:php';
        $spanMaxQueue = max(1, $this->int($config, 'span_processor_max_queue_size', 'OTEL_BSP_MAX_QUEUE_SIZE', 2048));
        $spanMaxBatch = min(
            $spanMaxQueue,
            max(1, $this->int($config, 'span_processor_max_export_batch_size', 'OTEL_BSP_MAX_EXPORT_BATCH_SIZE', 512))
        );
        $logMaxQueue = max(1, $this->int($config, 'log_processor_max_queue_size', 'OTEL_BLRP_MAX_QUEUE_SIZE', 2048));
        $logMaxBatch = min(
            $logMaxQueue,
            max(1, $this->int($config, 'log_processor_max_export_batch_size', 'OTEL_BLRP_MAX_EXPORT_BATCH_SIZE', 512))
        );

        return new ObservabilityConfig(
            enabled: $enabled,
            debug: $this->bool($config, 'debug', 'ELVEN_OTEL_DEBUG', false),
            serviceName: $this->string($config, 'service_name', 'OTEL_SERVICE_NAME', $serviceNameDefault),
            serviceNamespace: $this->nullableString($config, 'service_namespace', 'OTEL_SERVICE_NAMESPACE'),
            serviceVersion: $this->nullableString($config, 'service_version', 'OTEL_SERVICE_VERSION'),
            environment: $environment,
            resourceAttributes: $resourceAttributes,
            otlpEndpoint: rtrim($this->string($config, 'otlp_endpoint', 'OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'), '/'),
            tracesEndpoint: $this->nullableString($config, 'otlp_traces_endpoint', 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'),
            metricsEndpoint: $this->nullableString($config, 'otlp_metrics_endpoint', 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'),
            logsEndpoint: $this->nullableString($config, 'otlp_logs_endpoint', 'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT'),
            otlpProtocol: $this->normalizeProtocol($this->string($config, 'otlp_protocol', 'OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf')),
            tracesProtocol: $this->nullableProtocol($config, 'otlp_traces_protocol', 'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL'),
            metricsProtocol: $this->nullableProtocol($config, 'otlp_metrics_protocol', 'OTEL_EXPORTER_OTLP_METRICS_PROTOCOL'),
            logsProtocol: $this->nullableProtocol($config, 'otlp_logs_protocol', 'OTEL_EXPORTER_OTLP_LOGS_PROTOCOL'),
            headers: $headers,
            timeoutMillis: max(1, $this->intFromEnvList($config, 'timeout_millis', ['ELVEN_OTEL_EXPORT_TIMEOUT_MS', 'OTEL_EXPORTER_OTLP_TIMEOUT'], 1000)),
            exportRetryDelayMillis: max(0, $this->int($config, 'export_retry_delay_millis', 'ELVEN_OTEL_EXPORT_RETRY_DELAY_MS', 100)),
            exportMaxRetries: max(0, $this->int($config, 'export_max_retries', 'ELVEN_OTEL_EXPORT_MAX_RETRIES', 0)),
            exportCircuitBreakerEnabled: $this->bool($config, 'export_circuit_breaker_enabled', 'ELVEN_OTEL_EXPORT_CIRCUIT_BREAKER_ENABLED', true),
            exportCircuitBreakerFailureThreshold: max(1, $this->int($config, 'export_circuit_breaker_failure_threshold', 'ELVEN_OTEL_EXPORT_CIRCUIT_BREAKER_FAILURES', 3)),
            exportCircuitBreakerCooldownMillis: max(1, $this->int($config, 'export_circuit_breaker_cooldown_millis', 'ELVEN_OTEL_EXPORT_CIRCUIT_BREAKER_COOLDOWN_MS', 30000)),
            tracesExporter: $this->exporter($this->string($config, 'traces_exporter', 'OTEL_TRACES_EXPORTER', 'otlp')),
            metricsExporter: $this->exporter($this->string($config, 'metrics_exporter', 'OTEL_METRICS_EXPORTER', 'otlp')),
            logsExporter: $this->exporter($this->string($config, 'logs_exporter', 'OTEL_LOGS_EXPORTER', 'none')),
            propagators: $this->propagators($this->string($config, 'propagators', 'OTEL_PROPAGATORS', 'tracecontext,baggage')),
            sampler: strtolower($this->string($config, 'traces_sampler', 'OTEL_TRACES_SAMPLER', 'parentbased_traceidratio')),
            samplerArg: $this->float($config, 'traces_sampler_arg', 'OTEL_TRACES_SAMPLER_ARG', 1.0),
            spanProcessorMaxQueueSize: $spanMaxQueue,
            spanProcessorScheduleDelayMillis: max(1, $this->int($config, 'span_processor_schedule_delay_millis', 'OTEL_BSP_SCHEDULE_DELAY', 5000)),
            spanProcessorExportTimeoutMillis: max(1, $this->int($config, 'span_processor_export_timeout_millis', 'OTEL_BSP_EXPORT_TIMEOUT', 30000)),
            spanProcessorMaxExportBatchSize: $spanMaxBatch,
            logProcessorMaxQueueSize: $logMaxQueue,
            logProcessorScheduleDelayMillis: max(1, $this->int($config, 'log_processor_schedule_delay_millis', 'OTEL_BLRP_SCHEDULE_DELAY', 1000)),
            logProcessorExportTimeoutMillis: max(1, $this->int($config, 'log_processor_export_timeout_millis', 'OTEL_BLRP_EXPORT_TIMEOUT', 30000)),
            logProcessorMaxExportBatchSize: $logMaxBatch,
            redactionEnabled: $this->bool($config, 'redaction_enabled', 'ELVEN_OTEL_REDACTION_ENABLED', true),
            captureDbStatement: $this->bool($config, 'capture_db_statement', 'ELVEN_OTEL_CAPTURE_DB_STATEMENT', false),
            redactDbStatement: $this->bool($config, 'redact_db_statement', 'ELVEN_OTEL_REDACT_DB_STATEMENT', true),
            allowedRawAttributes: $allowRaw,
            allowedMetricLabels: $this->csv($this->string($config, 'allowed_metric_labels', 'ELVEN_OTEL_ALLOWED_METRIC_LABELS', '')),
            maxAttributeLength: max(16, $this->int($config, 'max_attribute_length', 'ELVEN_OTEL_MAX_ATTRIBUTE_LENGTH', 2048)),
            workerResetStrict: $this->bool($config, 'worker_reset_strict', 'ELVEN_OTEL_WORKER_RESET_STRICT', true),
            logCorrelationEnabled: $this->bool($config, 'log_correlation_enabled', 'ELVEN_OTEL_LOG_CORRELATION_ENABLED', true),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, scalar|null>
     */
    private function resourceAttributes(array $config): array
    {
        $attrs = [];
        $raw = isset($config['resource_attributes']) && is_array($config['resource_attributes'])
            ? $config['resource_attributes']
            : $this->keyValueList((string) ($this->env('OTEL_RESOURCE_ATTRIBUTES') ?: ''));

        foreach ($raw as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $attrs[(string) $key] = $value;
            }
        }

        return $attrs;
    }

    /** @return array<string, string> */
    private function headers(string $value): array
    {
        $headers = [];
        foreach ($this->keyValueList($value) as $key => $item) {
            $headers[$key] = (string) $item;
        }

        return $headers;
    }

    /** @return array<string, string> */
    private function keyValueList(string $value): array
    {
        $items = [];
        foreach (explode(',', $value) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$key, $item] = explode('=', $pair, 2);
            $key = trim(rawurldecode($key));
            if ($key === '') {
                continue;
            }
            $items[$key] = trim(rawurldecode($item));
        }

        return $items;
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        $out = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function propagators(string $value): array
    {
        $items = array_map(
            static fn (string $item): string => strtolower(trim($item)),
            explode(',', $value)
        );
        if (in_array('none', $items, true)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (($item === 'tracecontext' || $item === 'baggage') && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $config */
    private function nullableString(array $config, string $key, string $env): ?string
    {
        $value = $this->string($config, $key, $env, '');

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $config */
    private function nullableProtocol(array $config, string $key, string $env): ?string
    {
        $value = $this->string($config, $key, $env, '');

        return $value === '' ? null : $this->normalizeProtocol($value);
    }

    /** @param array<string, mixed> $config */
    private function string(array $config, string $key, string $env, string $default): string
    {
        if (array_key_exists($key, $config) && $config[$key] !== null) {
            return trim((string) $config[$key]);
        }

        $value = $this->env($env);

        return $value === null || $value === '' ? $default : trim($value);
    }

    /** @param array<string, mixed> $config */
    private function bool(array $config, string $key, string $env, bool $default): bool
    {
        if (array_key_exists($key, $config)) {
            return filter_var($config[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $this->envBool($env, $default);
    }

    private function envBool(string $env, bool $default): bool
    {
        $value = $this->env($env);
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @param array<string, mixed> $config */
    private function int(array $config, string $key, string $env, int $default): int
    {
        if (array_key_exists($key, $config) && is_numeric($config[$key])) {
            return (int) $config[$key];
        }

        $value = $this->env($env);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $envs
     */
    private function intFromEnvList(array $config, string $key, array $envs, int $default): int
    {
        if (array_key_exists($key, $config) && is_numeric($config[$key])) {
            return (int) $config[$key];
        }

        foreach ($envs as $env) {
            $value = $this->env($env);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return $default;
    }

    /** @param array<string, mixed> $config */
    private function float(array $config, string $key, string $env, float $default): float
    {
        if (array_key_exists($key, $config) && is_numeric($config[$key])) {
            return (float) $config[$key];
        }

        $value = $this->env($env);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function env(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return $value === false ? null : (is_scalar($value) ? (string) $value : null);
    }

    private function exporter(string $value): string
    {
        $value = strtolower(trim($value));

        return $value === 'otlp' ? 'otlp' : 'none';
    }

    private function normalizeProtocol(string $protocol): string
    {
        $protocol = strtolower(trim($protocol));

        return match ($protocol) {
            'http/json', 'http/protobuf', 'grpc' => $protocol,
            default => 'http/protobuf',
        };
    }
}
