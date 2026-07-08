<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Propagation;

use Elven\Observability\Php\Core\Diagnostics;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Throwable;

final class CompositePropagator implements TextMapPropagatorInterface
{
    /** @var list<TextMapPropagatorInterface> */
    private array $propagators;

    /** @param list<TextMapPropagatorInterface>|null $propagators */
    public function __construct(?array $propagators = null, private readonly ?Diagnostics $diagnostics = null)
    {
        $this->propagators = $propagators ?? [
            TraceContextPropagator::getInstance(),
            BaggagePropagator::getInstance(),
        ];
    }

    public function fields(): array
    {
        $fields = [];
        foreach ($this->propagators as $propagator) {
            $fields = array_merge($fields, $propagator->fields());
        }

        return array_values(array_unique($fields));
    }

    public function inject(mixed &$carrier, ?PropagationSetterInterface $setter = null, ?ContextInterface $context = null): void
    {
        $setter ??= new HeaderSetter();
        $context ??= Context::getCurrent();
        foreach ($this->propagators as $propagator) {
            try {
                $propagator->inject($carrier, $setter, $context);
            } catch (Throwable $throwable) {
                $this->diagnostics?->exception('Context injection failed', $throwable);
            }
        }
    }

    /** @param mixed $carrier */
    public function extract($carrier, ?PropagationGetterInterface $getter = null, ?ContextInterface $context = null): ContextInterface
    {
        $getter ??= new HeaderGetter();
        $context ??= Context::getCurrent();
        foreach ($this->propagators as $propagator) {
            try {
                $context = $propagator->extract($carrier, $getter, $context);
            } catch (Throwable $throwable) {
                $this->diagnostics?->exception('Context extraction failed', $throwable);
            }
        }

        return $context;
    }
}
