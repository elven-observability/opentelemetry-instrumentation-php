<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Privacy;

use Elven\Observability\Php\Config\ObservabilityConfig;

final class AttributeRedactor
{
    private const SENSITIVE_KEY_PATTERN = '/authorization|cookie|set-cookie|x-api-key|api[_-]?key|token|password|passwd|pwd|secret|bearer|session|csrf|jwt/i';
    private const JWT_PATTERN = '/\beyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\b/';
    private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
    private const CPF_PATTERN = '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/';
    private const CARD_PATTERN = '/\b(?:\d[ -]*?){13,19}\b/';

    public function __construct(private readonly ObservabilityConfig $config)
    {
    }

    /**
     * @param iterable<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function attributes(iterable $attributes, bool $forMetric = false): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            if ($forMetric && !$this->metricLabelAllowed($key)) {
                continue;
            }
            $out[$key] = $this->value($key, $value);
        }

        return $out;
    }

    public function value(string $key, mixed $value): mixed
    {
        if (!$this->config->redactionEnabled || $this->config->isRawAttributeAllowed($key)) {
            return $this->truncate($value);
        }

        if (preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
            return '[REDACTED]';
        }

        if (is_string($value)) {
            return $this->redactString($this->truncateString($value));
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $itemKey => $itemValue) {
                $redacted[$itemKey] = $this->value((string) $itemKey, $itemValue);
            }

            return $redacted;
        }

        return $value;
    }

    public function redactString(string $value): string
    {
        if (!$this->config->redactionEnabled) {
            return $this->truncateString($value);
        }

        $value = preg_replace(self::JWT_PATTERN, '[REDACTED_JWT]', $value) ?? $value;
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace(self::EMAIL_PATTERN, '[REDACTED_EMAIL]', $value) ?? $value;
        $value = preg_replace(self::CPF_PATTERN, '[REDACTED_CPF]', $value) ?? $value;
        $value = preg_replace(self::CARD_PATTERN, '[REDACTED_CARD]', $value) ?? $value;

        return $this->truncateString($value);
    }

    public function hashIdentifier(string $value): string
    {
        return hash('sha256', $value);
    }

    private function metricLabelAllowed(string $key): bool
    {
        $default = [
            'service.name',
            'service.namespace',
            'deployment.environment.name',
            'http.route',
            'http.request.method',
            'http.response.status_code',
            'server.address',
            'dependency.type',
            'dependency.name',
            'operation',
            'error.type',
            'db.system.name',
            'db.operation.name',
            'cache.operation',
            'cache.name',
            'messaging.system',
            'messaging.operation.name',
        ];

        return in_array($key, $default, true) || in_array($key, $this->config->allowedMetricLabels, true);
    }

    private function truncate(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->truncateString($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->truncate($item);
            }
        }

        return $value;
    }

    private function truncateString(string $value): string
    {
        if (strlen($value) <= $this->config->maxAttributeLength) {
            return $value;
        }

        return substr($value, 0, $this->config->maxAttributeLength) . '...[TRUNCATED]';
    }
}
