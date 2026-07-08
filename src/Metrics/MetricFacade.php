<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Metrics;

use Elven\Observability\Php\Privacy\AttributeRedactor;
use OpenTelemetry\API\Metrics\MeterInterface;
use Throwable;

final class MetricFacade
{
    /** @var array<string, Counter> */
    private array $counters = [];
    /** @var array<string, Histogram> */
    private array $histograms = [];
    /** @var array<string, Gauge> */
    private array $gauges = [];

    public function __construct(
        private readonly ?MeterInterface $meter,
        private readonly AttributeRedactor $redactor,
    ) {
    }

    public function counter(string $name, ?string $unit = null, ?string $description = null): Counter
    {
        $key = $name . '|' . $unit;
        if (!isset($this->counters[$key])) {
            $instrument = null;
            if ($this->meter !== null) {
                try {
                    $instrument = $this->meter->createCounter($name, $unit, $description);
                } catch (Throwable) {
                    $instrument = null;
                }
            }
            $this->counters[$key] = new Counter($instrument, $this->redactor);
        }

        return $this->counters[$key];
    }

    public function histogram(string $name, ?string $unit = null, ?string $description = null): Histogram
    {
        $key = $name . '|' . $unit;
        if (!isset($this->histograms[$key])) {
            $instrument = null;
            if ($this->meter !== null) {
                try {
                    $instrument = $this->meter->createHistogram($name, $unit, $description);
                } catch (Throwable) {
                    $instrument = null;
                }
            }
            $this->histograms[$key] = new Histogram($instrument, $this->redactor);
        }

        return $this->histograms[$key];
    }

    public function gauge(string $name, ?string $unit = null, ?string $description = null): Gauge
    {
        $key = $name . '|' . $unit;
        if (!isset($this->gauges[$key])) {
            $instrument = null;
            if ($this->meter !== null) {
                try {
                    $instrument = $this->meter->createGauge($name, $unit, $description);
                } catch (Throwable) {
                    $instrument = null;
                }
            }
            $this->gauges[$key] = new Gauge($instrument, $this->redactor);
        }

        return $this->gauges[$key];
    }
}
