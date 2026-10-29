<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Privacy;

use Elven\Observability\Php\Config\ObservabilityConfig;

final class AttributeRedactor
{
    private const MAX_ATTRIBUTES = 128;
    private const MAX_VALUE_ITEMS = 64;
    private const MAX_DEPTH = 4;
    private const MAX_METRIC_ATTRIBUTE_SETS = 512;
    /** @var array<string, true> */
    private array $metricAttributeSets = [];
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
            if (count($out) >= self::MAX_ATTRIBUTES) {
                break;
            }
            $key = substr((string) $key, 0, 255);
            if ($key === '') {
                continue;
            }
            if ($forMetric && !$this->metricLabelAllowed($key)) {
                continue;
            }
            $out[$key] = $this->value($key, $value);
        }

        if ($forMetric) {
            ksort($out);
            $encoded = json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
            $fingerprint = hash('sha256', $encoded === false ? '' : $encoded);
            if (!isset($this->metricAttributeSets[$fingerprint])) {
                if (count($this->metricAttributeSets) >= self::MAX_METRIC_ATTRIBUTE_SETS) {
                    return ['otel.metric.overflow' => true];
                }
                $this->metricAttributeSets[$fingerprint] = true;
            }
        }

        return $out;
    }

    public function value(string $key, mixed $value): mixed
    {
        $remaining = self::MAX_VALUE_ITEMS;

        return $this->boundedValue($key, $value, 0, $remaining);
    }

    private function boundedValue(string $key, mixed $value, int $depth, int &$remaining, bool $raw = false): mixed
    {
        $remaining--;
        $raw = $raw || !$this->config->redactionEnabled || $this->config->isRawAttributeAllowed($key);

        if (!$raw && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
            return '[REDACTED]';
        }

        if (is_string($value)) {
            $value = $this->truncateString($value);

            return $raw ? $value : $this->redactString($value);
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[MAX_DEPTH]';
            }
            $redacted = [];
            foreach ($value as $itemKey => $itemValue) {
                if ($remaining <= 0) {
                    break;
                }
                $itemKey = is_string($itemKey) ? substr($itemKey, 0, 255) : $itemKey;
                $redacted[$itemKey] = $this->boundedValue((string) $itemKey, $itemValue, $depth + 1, $remaining, $raw);
            }

            return $redacted;
        }

        return is_object($value) ? '[object ' . $value::class . ']' : (is_resource($value) ? '[resource]' : $value);
    }

    public function redactString(string $value): string
    {
        if (!$this->config->redactionEnabled) {
            return $this->truncateString($value);
        }

        return $this->truncateString(self::redactText($value));
    }

    public static function redactText(string $value): string
    {
        $value = preg_replace(self::JWT_PATTERN, '[REDACTED_JWT]', $value) ?? $value;
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/\b(password|passwd|pwd|secret|token|api[_-]?key|authorization|cookie)\s*[:=]\s*[^\s,;&]+/i', '$1=[REDACTED]', $value) ?? $value;
        $value = preg_replace(self::EMAIL_PATTERN, '[REDACTED_EMAIL]', $value) ?? $value;
        $value = preg_replace(self::CPF_PATTERN, '[REDACTED_CPF]', $value) ?? $value;
        $value = preg_replace(self::CARD_PATTERN, '[REDACTED_CARD]', $value) ?? $value;

        return $value;
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

    private function truncateString(string $value): string
    {
        if (strlen($value) <= $this->config->maxAttributeLength) {
            return $value;
        }

        return substr($value, 0, $this->config->maxAttributeLength) . '...[TRUNCATED]';
    }
}
