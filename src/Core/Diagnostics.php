<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Core;

use Throwable;

final class Diagnostics
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    public function __construct(private readonly bool $debug)
    {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->records[] = ['level' => 'debug', 'message' => $message, 'context' => $this->sanitize($context)];
        }
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->records[] = ['level' => 'warning', 'message' => $message, 'context' => $this->sanitize($context)];
        }
    }

    public function exception(string $message, Throwable $throwable): void
    {
        $this->warning($message, [
            'exception_class' => $throwable::class,
            'exception_message' => $throwable->getMessage(),
        ]);
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function records(): array
    {
        return $this->records;
    }

    /** @param array<string, mixed> $context */
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/authorization|cookie|token|password|secret|key/i', (string) $key)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitize($value);
            }
        }

        return $context;
    }
}
