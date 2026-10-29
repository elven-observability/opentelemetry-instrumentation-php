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
        if (strlen($name) > 255 || strlen($unit ?? '') > 63 || strlen($description ?? '') > 1024) {
            return new Counter(null, $this->redactor);
        }
        $key = $name . '|' . $unit;
        if (!isset($this->counters[$key])) {
            if (count($this->counters) >= $this->redactor->instrumentLimit()) {
                return new Counter(null, $this->redactor);
            }
            $instrument = null;
            if ($this->meter !== null) {
                try {
                    $instrument = $this->meter->createCounter($name, $unit, $description);
                } catch (Throwable) {
                    $instrument = null;
                }
            }
            $this->counters[$key] = new Counter($instrument, $this->redactor, $key);
        }

        return $this->counters[$key];
    }

    public function histogram(string $name, ?string $unit = null, ?string $description = null): Histogram
    {
        if (strlen($name) > 255 || strlen($unit ?? '') > 63 || strlen($description ?? '') > 1024) {
            return new Histogram(null, $this->redactor);
        }
        $key = $name . '|' . $unit;
        if (!isset($this->histograms[$key])) {
            if (count($this->histograms) >= $this->redactor->instrumentLimit()) {
                return new Histogram(null, $this->redactor);
            }
            $instrument = null;
            if ($this->meter !== null) {
                try {
                    $instrument = $this->meter->createHistogram($name, $unit, $description);
                } catch (Throwable) {
                    $instrument = null;
                }
            }
            $this->histograms[$key] = new Histogram($instrument, $this->redactor, $key);
        }

        return $this->histograms[$key];
    }

    public function gauge(string $name, ?string $unit = null, ?string $description = null): Gauge
    {
        if (strlen($name) > 255 || strlen($unit ?? '') > 63 || strlen($description ?? '') > 1024) {
            return new Gauge(null, $this->redactor);
        }
        $key = $name . '|' . $unit;
        if (!isset($this->gauges[$key])) {
            if (count($this->gauges) >= $this->redactor->instrumentLimit()) {
                return new Gauge(null, $this->redactor);
            }
            $instrument = null;
            if ($this->meter !== null) {
                try {
                    $instrument = $this->meter->createGauge($name, $unit, $description);
                } catch (Throwable) {
                    $instrument = null;
                }
            }
            $this->gauges[$key] = new Gauge($instrument, $this->redactor, $key);
        }

        return $this->gauges[$key];
    }
}
