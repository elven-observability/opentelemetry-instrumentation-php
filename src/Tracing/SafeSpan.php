<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tracing;

use Elven\Observability\Php\Core\Diagnostics;
use Elven\Observability\Php\Export\LimitedJson;
use Elven\Observability\Php\Privacy\AttributeRedactor;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class SafeSpan
{
    private bool $ended = false;
    private int $remainingBytes = 61440;

    public function __construct(
        private readonly ?SpanInterface $span,
        private ?ScopeInterface $scope,
        private readonly ?AttributeRedactor $redactor,
        private readonly ?Diagnostics $diagnostics,
    ) {
    }

    public static function noop(): self
    {
        return new self(null, null, null, null);
    }

    public function isRecording(): bool
    {
        return $this->span !== null && $this->span->isRecording();
    }

    public function setAttribute(string $key, mixed $value): self
    {
        if ($this->span === null || $this->redactor === null) {
            return $this;
        }

        try {
            if (strlen($key) > 255) {
                return $this;
            }
            $safe = $this->redactor->value($key, $value);
            $encoded = LimitedJson::encode([$key => $safe], $this->remainingBytes);
            if ($encoded === null) {
                return $this;
            }
            $this->remainingBytes -= strlen($encoded);
            $this->span->setAttribute($key, $safe);
        } catch (Throwable $throwable) {
            $this->diagnostics?->exception('Span attribute failed', $throwable);
        }

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function addEvent(string $name, array $attributes = []): self
    {
        if ($this->span === null || $this->redactor === null) {
            return $this;
        }

        try {
            $name = substr($this->redactor->redactString($name), 0, 255);
            $safe = $this->redactor->attributes($attributes);
            $encoded = LimitedJson::encode([$name, $safe], $this->remainingBytes);
            if ($encoded === null) {
                return $this;
            }
            $this->remainingBytes -= strlen($encoded);
            $this->span->addEvent($name, $safe);
        } catch (Throwable $throwable) {
            $this->diagnostics?->exception('Span event failed', $throwable);
        }

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function recordException(Throwable $throwable, array $attributes = []): self
    {
        if ($this->span === null || $this->redactor === null) {
            return $this;
        }

        try {
            $this->addEvent('exception', array_merge($attributes, [
                'exception.type' => $throwable::class,
                'exception.message' => $throwable->getMessage(),
            ]));
        } catch (Throwable $recordFailure) {
            $this->diagnostics?->exception('Span exception recording failed', $recordFailure);
        }

        return $this;
    }

    public function setStatus(string $code, ?string $description = null): self
    {
        if ($this->span === null) {
            return $this;
        }

        try {
            $this->span->setStatus($code, $description === null ? null : $this->redactor?->redactString($description));
        } catch (Throwable $throwable) {
            $this->diagnostics?->exception('Span status failed', $throwable);
        }

        return $this;
    }

    public function markError(?string $description = null): self
    {
        return $this->setStatus(StatusCode::STATUS_ERROR, $description);
    }

    public function detachScope(): void
    {
        if ($this->scope === null) {
            return;
        }

        try {
            $this->scope->detach();
        } catch (Throwable $throwable) {
            $this->diagnostics?->exception('Span scope detach failed', $throwable);
        } finally {
            $this->scope = null;
        }
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        try {
            $this->span?->end();
        } catch (Throwable $throwable) {
            $this->diagnostics?->exception('Span end failed', $throwable);
        } finally {
            $this->detachScope();
        }
    }
}
