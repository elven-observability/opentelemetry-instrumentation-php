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
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function add(int|float $amount, array $attributes = []): void
    {
        if ($this->counter === null || $amount < 0) {
            return;
        }

        try {
            $this->counter->add($amount, $this->redactor->attributes($attributes, true));
        } catch (Throwable) {
        }
    }
}
