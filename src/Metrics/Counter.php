<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Metrics;

use Elven\Observability\Php\Privacy\AttributeRedactor;
use OpenTelemetry\API\Metrics\CounterInterface;
use Throwable;

final class Counter
{
    public function __construct(
        private readonly ?CounterInterface $counter,
        private readonly AttributeRedactor $redactor,
        private readonly string $identity = '',
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function add(int|float $amount, array $attributes = []): void
    {
        if ($this->counter === null || $amount < 0) {
            return;
        }

        try {
            if (!is_finite((float) $amount)) {
                return;
            }
            $safe = $this->redactor->attributes($attributes, true);
            $encoded = json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false || !$this->redactor->budget()->reserveMetric('counter:' . $this->identity, $encoded)) {
                return;
            }
            $this->counter->add($amount, $safe);
        } catch (Throwable) {
        }
    }
}
