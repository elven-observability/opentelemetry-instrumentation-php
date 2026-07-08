<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Sdk;

use Elven\Observability\Php\Config\ObservabilityConfig;
use Elven\Observability\Php\Core\Diagnostics;
use Elven\Observability\Php\Logs\LogFacade;
use Elven\Observability\Php\Metrics\MetricFacade;
use Elven\Observability\Php\Privacy\AttributeRedactor;
use Elven\Observability\Php\Propagation\CompositePropagator;
use Elven\Observability\Php\Tracing\SafeTracer;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

final class SdkState
{
    public function __construct(
        public readonly ObservabilityConfig $config,
        public readonly Diagnostics $diagnostics,
        public readonly AttributeRedactor $redactor,
        public readonly CompositePropagator $propagator,
        public readonly ?TracerProviderInterface $tracerProvider,
        public readonly ?MeterProviderInterface $meterProvider,
        public readonly ?LoggerProviderInterface $loggerProvider,
        public readonly SafeTracer $tracer,
        public readonly MetricFacade $metrics,
        public readonly LogFacade $logs,
    ) {
    }
}
