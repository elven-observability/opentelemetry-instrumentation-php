<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Metrics;

use Elven\Observability\Php\Privacy\AttributeRedactor;
use OpenTelemetry\API\Metrics\GaugeInterface;
use Throwable;

final class Gauge
{
    public function __construct(
        private readonly ?GaugeInterface $gauge,
        private readonly AttributeRedactor $redactor,
        private readonly string $identity = '',
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function record(int|float $amount, array $attributes = []): void
    {
        if ($this->gauge === null) {
            return;
        }

        try {
            if (!is_finite((float) $amount)) {
                return;
            }
            $safe = $this->redactor->attributes($attributes, true);
            $safe = $this->redactor->budget()->metricAttributes('gauge:' . $this->identity, $safe);
            if ($safe === null) {
                return;
            }
            $this->gauge->record($amount, $safe);
        } catch (Throwable) {
        }
    }
}
