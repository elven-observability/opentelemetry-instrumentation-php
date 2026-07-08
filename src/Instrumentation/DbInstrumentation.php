<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Instrumentation;

use Elven\Observability\Php\Observability;
use Elven\Observability\Php\Privacy\DbStatementSanitizer;

final class DbInstrumentation
{
    public function __construct(private readonly DbStatementSanitizer $sanitizer = new DbStatementSanitizer())
    {
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param array<string, mixed> $attributes
     * @return T
     */
    public function trace(string $system, string $operation, string $statement, callable $callback, array $attributes = []): mixed
    {
        $summary = $this->sanitizer->summary($statement);
        $attrs = [
            'db.system.name' => $system,
            'db.operation.name' => $operation,
            'db.query.summary' => $summary,
        ] + $attributes;

        $config = Observability::config();
        if ($config->captureDbStatement) {
            $queryText = $config->redactDbStatement ? $this->sanitizer->sanitize($statement) : $statement;
            $attrs['db.query.text'] = $queryText;
            $attrs['db.statement'] = $queryText;
        }

        return DependencyInstrumentation::trace('db', $system, $operation, $callback, $attrs);
    }
}
