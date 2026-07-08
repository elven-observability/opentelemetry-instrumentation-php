<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Privacy;

final class UrlSanitizer
{
    private const SENSITIVE_QUERY_PATTERN = '/token|password|passwd|pwd|secret|authorization|auth|session|cookie|key|jwt|cpf|email/i';

    public function sanitize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            foreach ($params as $key => $value) {
                if (preg_match(self::SENSITIVE_QUERY_PATTERN, (string) $key)) {
                    $params[$key] = '[REDACTED]';
                } elseif (is_array($value)) {
                    $params[$key] = '[ARRAY]';
                }
            }
            $query = http_build_query($params);
        }

        return $query === '' ? $path : $path . '?' . $query;
    }

    public function path(string $url): string
    {
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['path']) ? $parts['path'] : '/';
    }

    public function host(string $url): ?string
    {
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['host']) ? $parts['host'] : null;
    }
}
