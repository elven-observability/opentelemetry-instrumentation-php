<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Metrics;

use Elven\Observability\Php\Privacy\AttributeRedactor;
use OpenTelemetry\API\Metrics\HistogramInterface;
use Throwable;

final class Histogram
{
    public function __construct(
        private readonly ?HistogramInterface $histogram,
        private readonly AttributeRedactor $redactor,
        private readonly string $identity = '',
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function record(int|float $amount, array $attributes = []): void
    {
        if ($this->histogram === null || $amount < 0) {
            return;
        }

        try {
            if (!is_finite((float) $amount)) {
                return;
            }
            $safe = $this->redactor->attributes($attributes, true);
            $safe = $this->redactor->budget()->metricAttributes('histogram:' . $this->identity, $safe);
            if ($safe === null) {
                return;
            }
            $this->histogram->record($amount, $safe);
        } catch (Throwable) {
        }
    }
}
