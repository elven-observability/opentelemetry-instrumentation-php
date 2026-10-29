<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Export;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/** Shared trace/log guard: do not serialize another batch after the flush deadline. */
final class DeadlineExporter implements SpanExporterInterface, LogRecordExporterInterface
{
    public function __construct(
        private readonly SpanExporterInterface|LogRecordExporterInterface $inner,
        private readonly ExportBudget $budget,
    ) {
    }

    /** @param iterable<mixed> $batch @return FutureInterface<bool> */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return $this->budget->remainingMillis() === 0 ? new CompletedFuture(false) : $this->inner->export($batch, $cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }
}
