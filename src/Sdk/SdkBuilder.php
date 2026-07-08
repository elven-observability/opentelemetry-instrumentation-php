<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Sdk;

use Elven\Observability\Php\Config\ObservabilityConfig;
use Elven\Observability\Php\Core\Diagnostics;
use Elven\Observability\Php\Export\CircuitBreakerTransport;
use Elven\Observability\Php\Logs\LogFacade;
use Elven\Observability\Php\Metrics\MetricFacade;
use Elven\Observability\Php\Privacy\AttributeRedactor;
use Elven\Observability\Php\Propagation\CompositePropagator;
use Elven\Observability\Php\Tracing\SafeTracer;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

final class SdkBuilder
{
    public function build(ObservabilityConfig $config): SdkState
    {
        $diagnostics = new Diagnostics($config->debug);
        $redactor = new AttributeRedactor($config);
        $propagator = new CompositePropagator($this->propagators($config), $diagnostics);
        if (!$config->debug) {
            try {
                Logging::disable();
            } catch (Throwable) {
            }
        }

        if (!$config->enabled) {
            $diagnostics->debug('Observability disabled by configuration');

            return $this->noop($config, $diagnostics, $redactor, $propagator);
        }

        $resource = $this->resource($config);
        $meterProvider = $this->buildMeterProvider($config, $resource, $diagnostics);
        $tracerProvider = $this->buildTracerProvider($config, $resource, $diagnostics);
        $loggerProvider = $this->buildLoggerProvider($config, $resource, $diagnostics);

        $tracer = new SafeTracer(
            $tracerProvider?->getTracer('elven-observability-php', ObservabilityVersion::VERSION),
            $redactor,
            $diagnostics,
        );
        $metrics = new MetricFacade($meterProvider?->getMeter('elven-observability-php', ObservabilityVersion::VERSION), $redactor);
        $logs = new LogFacade($config, $loggerProvider, $redactor);

        return new SdkState(
            $config,
            $diagnostics,
            $redactor,
            $propagator,
            $tracerProvider,
            $meterProvider,
            $loggerProvider,
            $tracer,
            $metrics,
            $logs,
        );
    }

    private function noop(
        ObservabilityConfig $config,
        Diagnostics $diagnostics,
        AttributeRedactor $redactor,
        CompositePropagator $propagator,
    ): SdkState {
        $tracer = new SafeTracer(null, $redactor, $diagnostics);
        $metrics = new MetricFacade(null, $redactor);
        $logs = new LogFacade($config, null, $redactor);

        return new SdkState($config, $diagnostics, $redactor, $propagator, null, null, null, $tracer, $metrics, $logs);
    }

    private function resource(ObservabilityConfig $config): ResourceInfo
    {
        $attributes = $config->resourceAttributes;
        $attributes['service.name'] = $config->serviceName;
        if ($config->serviceNamespace !== null) {
            $attributes['service.namespace'] = $config->serviceNamespace;
        }
        if ($config->serviceVersion !== null) {
            $attributes['service.version'] = $config->serviceVersion;
        }
        $attributes['deployment.environment.name'] = $config->environment;
        $attributes['telemetry.sdk.name'] = 'elven-observability-php';
        $attributes['telemetry.sdk.language'] = 'php';
        $attributes['telemetry.sdk.version'] = ObservabilityVersion::VERSION;

        return ResourceInfo::create(Attributes::create($attributes));
    }

    private function buildTracerProvider(
        ObservabilityConfig $config,
        ResourceInfo $resource,
        Diagnostics $diagnostics,
    ): ?TracerProviderInterface {
        if (!$config->shouldExportTraces()) {
            return new TracerProvider([], $this->sampler($config), $resource);
        }

        try {
            $transport = $this->transport($config, 'traces');
            $exporter = new SpanExporter($transport);
            $processor = new BatchSpanProcessor(
                $exporter,
                Clock::getDefault(),
                $config->spanProcessorMaxQueueSize,
                $config->spanProcessorScheduleDelayMillis,
                $config->spanProcessorExportTimeoutMillis,
                $config->spanProcessorMaxExportBatchSize,
                false,
                null,
            );

            return new TracerProvider($processor, $this->sampler($config), $resource);
        } catch (Throwable $throwable) {
            $diagnostics->exception('Trace provider initialization failed; traces are no-op', $throwable);

            return null;
        }
    }

    private function buildMeterProvider(
        ObservabilityConfig $config,
        ResourceInfo $resource,
        Diagnostics $diagnostics,
    ): ?MeterProviderInterface {
        if (!$config->shouldExportMetrics()) {
            return null;
        }

        try {
            $transport = $this->transport($config, 'metrics');
            $exporter = new MetricExporter($transport, Temporality::DELTA);
            $reader = new ExportingReader($exporter);

            return MeterProvider::builder()->setResource($resource)->addReader($reader)->build();
        } catch (Throwable $throwable) {
            $diagnostics->exception('Meter provider initialization failed; metrics are no-op', $throwable);

            return null;
        }
    }

    private function buildLoggerProvider(
        ObservabilityConfig $config,
        ResourceInfo $resource,
        Diagnostics $diagnostics,
    ): ?LoggerProviderInterface {
        if (!$config->shouldExportLogs()) {
            return null;
        }

        try {
            $transport = $this->transport($config, 'logs');
            $exporter = new LogsExporter($transport);
            $processor = new BatchLogRecordProcessor(
                $exporter,
                Clock::getDefault(),
                $config->logProcessorMaxQueueSize,
                $config->logProcessorScheduleDelayMillis,
                $config->logProcessorExportTimeoutMillis,
                $config->logProcessorMaxExportBatchSize,
                false,
                null,
            );

            return LoggerProvider::builder()->setResource($resource)->addLogRecordProcessor($processor)->build();
        } catch (Throwable $throwable) {
            $diagnostics->exception('Logger provider initialization failed; OTLP logs are no-op', $throwable);

            return null;
        }
    }

    private function sampler(ObservabilityConfig $config): SamplerInterface
    {
        return match ($config->sampler) {
            'always_on' => new AlwaysOnSampler(),
            'always_off' => new AlwaysOffSampler(),
            'traceidratio' => new TraceIdRatioBasedSampler($this->clampRatio($config->samplerArg)),
            default => new ParentBased(new TraceIdRatioBasedSampler($this->clampRatio($config->samplerArg))),
        };
    }

    /** @return list<\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface> */
    private function propagators(ObservabilityConfig $config): array
    {
        $propagators = [];
        foreach ($config->propagators as $propagator) {
            if ($propagator === 'tracecontext') {
                $propagators[] = TraceContextPropagator::getInstance();
            } elseif ($propagator === 'baggage') {
                $propagators[] = BaggagePropagator::getInstance();
            }
        }

        return $propagators;
    }

    private function clampRatio(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }

    private function transport(ObservabilityConfig $config, string $signal): mixed
    {
        $protocol = $config->signalProtocol($signal);
        if ($protocol === 'grpc') {
            throw new \RuntimeException('OTLP gRPC transport is not bundled; use http/protobuf or install a dedicated gRPC transport.');
        }

        $transport = (new OtlpHttpTransportFactory())->create(
            $config->signalEndpoint($signal),
            Protocols::contentType($protocol),
            $config->headers,
            'none',
            $config->timeoutMillis / 1000,
            $config->exportRetryDelayMillis,
            $config->exportMaxRetries,
        );

        if (!$config->exportCircuitBreakerEnabled) {
            return $transport;
        }

        return new CircuitBreakerTransport(
            $transport,
            $config->exportCircuitBreakerFailureThreshold,
            $config->exportCircuitBreakerCooldownMillis,
        );
    }
}
