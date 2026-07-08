<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Privacy;

final class DbStatementSanitizer
{
    public function sanitize(string $statement): string
    {
        $statement = preg_replace("/'(?:''|[^'])*'/", '?', $statement) ?? $statement;
        $statement = preg_replace('/"(?:\\"|[^"])*"/', '?', $statement) ?? $statement;
        $statement = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $statement) ?? $statement;
        $statement = preg_replace('/\s+/', ' ', trim($statement)) ?? $statement;

        return $statement;
    }

    public function summary(string $statement): string
    {
        $sanitized = $this->sanitize($statement);
        if (preg_match('/^\s*(select|insert|update|delete|merge|call|exec|create|alter|drop)\b/i', $sanitized, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'SQL';
    }
}
