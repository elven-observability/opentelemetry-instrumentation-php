<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Metrics;

use Elven\Observability\Php\Privacy\AttributeRedactor;
use OpenTelemetry\API\Metrics\MeterInterface;
use Throwable;

final class MetricFacade
{
    private const MAX_EXPLICIT_BUCKET_BOUNDARIES = 128;

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

    /** @param list<int|float>|null $explicitBucketBoundaries Strictly increasing boundaries in the instrument unit. */
    public function histogram(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        ?array $explicitBucketBoundaries = null,
    ): Histogram {
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
                    $advisory = $this->histogramAdvisory($explicitBucketBoundaries);
                    $instrument = $advisory === []
                        ? $this->meter->createHistogram($name, $unit, $description)
                        : $this->meter->createHistogram($name, $unit, $description, $advisory);
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

    /**
     * @param array<array-key, mixed>|null $boundaries
     * @return array{ExplicitBucketBoundaries?: list<float>}
     */
    private function histogramAdvisory(?array $boundaries): array
    {
        if ($boundaries === null || count($boundaries) > self::MAX_EXPLICIT_BUCKET_BOUNDARIES || !array_is_list($boundaries)) {
            return [];
        }

        $normalized = [];
        $previous = -INF;
        foreach ($boundaries as $boundary) {
            if (!is_int($boundary) && !is_float($boundary)) {
                return [];
            }
            $value = (float) $boundary;
            if (!is_finite($value) || $value <= $previous) {
                return [];
            }
            $normalized[] = $value;
            $previous = $value;
        }

        return ['ExplicitBucketBoundaries' => $normalized];
    }
}
