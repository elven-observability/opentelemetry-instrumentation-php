<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Export;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use OpenTelemetry\SDK\Logs\ReadWriteLogRecord;

final class BudgetedLogProcessor implements LogRecordProcessorInterface
{
    private int $queued = 0;

    public function __construct(private readonly LogRecordProcessorInterface $inner, private readonly ExportBudget $budget)
    {
    }

    public function onEmit(ReadWriteLogRecord $record, ?ContextInterface $context = null): void
    {
        $encoded = LimitedJson::encode([$record->getBody(), $record->getAttributes()->toArray()], ExportBudget::RECORD_BYTES);
        if ($encoded === null || !$this->budget->reserve(strlen($encoded) + 1024)) {
            return;
        }
        $this->queued += strlen($encoded) + 1024;
        $this->inner->onEmit($record, $context);
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
