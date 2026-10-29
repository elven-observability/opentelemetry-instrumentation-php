<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Export;

use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;

final class DeadlineMetricExporter implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface
{
    public function __construct(private readonly MetricExporter $inner, private readonly ExportBudget $budget)
    {
    }

    /** @param iterable<int, Metric> $batch */
    public function export(iterable $batch): bool
    {
        return $this->budget->remainingMillis() > 0 && $this->inner->export($batch);
    }

    public function temporality(MetricMetadataInterface $metric): Temporality|string|null
    {
        return $this->inner->temporality($metric);
    }

    public function forceFlush(): bool
    {
        return $this->inner->forceFlush();
    }

    public function shutdown(): bool
    {
        return $this->inner->shutdown();
    }
}
