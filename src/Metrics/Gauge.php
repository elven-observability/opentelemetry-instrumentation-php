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
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function record(int|float $amount, array $attributes = []): void
    {
        if ($this->gauge === null) {
            return;
        }

        try {
            $this->gauge->record($amount, $this->redactor->attributes($attributes, true));
        } catch (Throwable) {
        }
    }
}
