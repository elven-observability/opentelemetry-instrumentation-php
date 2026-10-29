<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Export;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

final class BudgetedSpanProcessor implements SpanProcessorInterface
{
    private int $queued = 0;

    public function __construct(private readonly SpanProcessorInterface $inner, private readonly ExportBudget $budget)
    {
    }

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        $this->inner->onStart($span, $parentContext);
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        if (!$span->getContext()->isSampled()) {
            return;
        }
        $data = $span->toSpanData();
        $events = [];
        foreach ($data->getEvents() as $event) {
            $events[] = [$event->getName(), $event->getAttributes()->toArray()];
        }
        $encoded = LimitedJson::encode([$data->getName(), $data->getAttributes()->toArray(),
            $data->getStatus()->getDescription(), $events], ExportBudget::RECORD_BYTES);
        if ($encoded === null || !$this->budget->reserve(strlen($encoded) + 1024)) {
            return;
        }
        $this->queued += strlen($encoded) + 1024;
        $this->inner->onEnd($span);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->inner->forceFlush($cancellation);
        } finally {
            $this->budget->release($this->queued);
            $this->queued = 0;
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->inner->shutdown($cancellation);
        } finally {
            $this->budget->release($this->queued);
            $this->queued = 0;
        }
    }
}
