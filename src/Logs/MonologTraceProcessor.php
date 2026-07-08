<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Logs;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class MonologTraceProcessor implements ProcessorInterface
{
    public function __construct(private readonly LogFacade $logs)
    {
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     * @return array<string, mixed>|LogRecord
     */
    public function __invoke(array|LogRecord $record): array|LogRecord
    {
        if ($record instanceof LogRecord) {
            return $record->with(context: $this->logs->correlate($record->context));
        }

        $record['context'] = $this->logs->correlate($record['context'] ?? []);

        return $record;
    }
}
