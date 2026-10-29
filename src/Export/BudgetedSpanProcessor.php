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
        if (count($data->getEvents()) > 128 || count($data->getLinks()) > 128) {
            return;
        }
        $events = [];
        foreach ($data->getEvents() as $event) {
            $events[] = [$event->getName(), $event->getAttributes()->toArray()];
        }
        $links = [];
        foreach ($data->getLinks() as $link) {
            $links[] = [$link->getAttributes()->toArray(), $link->getSpanContext()->getTraceState()?->toString()];
        }
        $scope = $data->getInstrumentationScope();
        $encoded = LimitedJson::encode([$data->getName(), $data->getAttributes()->toArray(),
            $data->getStatus()->getDescription(), $events, $links,
            $data->getResource()->getAttributes()->toArray(), $data->getResource()->getSchemaUrl(),
            $scope->getName(), $scope->getVersion(), $scope->getSchemaUrl(), $scope->getAttributes()->toArray()], ExportBudget::RECORD_BYTES);
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
