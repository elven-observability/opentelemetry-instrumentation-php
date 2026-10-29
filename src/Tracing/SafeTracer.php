<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tracing;

use Elven\Observability\Php\Core\Diagnostics;
use Elven\Observability\Php\Privacy\AttributeRedactor;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Throwable;

final class SafeTracer
{
    public function __construct(
        private readonly ?TracerInterface $tracer,
        private readonly AttributeRedactor $redactor,
        private readonly Diagnostics $diagnostics,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     * @phpstan-param SpanKind::KIND_* $kind
     */
    public function span(string $name, int $kind = SpanKind::KIND_INTERNAL, array $attributes = []): SafeSpan
    {
        if ($this->tracer === null || !$this->tracer->isEnabled()) {
            return SafeSpan::noop();
        }

        try {
            $span = $this->tracer
                ->spanBuilder(substr($this->redactor->redactString($name), 0, 255))
                ->setSpanKind($kind)
                ->startSpan();
            $scope = Context::getCurrent()->withContextValue($span)->activate();

            return (new SafeSpan($span, $scope, $this->redactor, $this->diagnostics))->setAttributes($attributes);
        } catch (Throwable $throwable) {
            $this->diagnostics->exception('Span creation failed', $throwable);

            return SafeSpan::noop();
        }
    }

    /**
     * @template T
     * @param callable(SafeSpan): T $callback
     * @param array<string, mixed> $attributes
     * @phpstan-param SpanKind::KIND_* $kind
     * @return T
     */
    public function withSpan(string $name, callable $callback, int $kind = SpanKind::KIND_INTERNAL, array $attributes = []): mixed
    {
        $span = $this->span($name, $kind, $attributes);

        try {
            return $callback($span);
        } catch (Throwable $throwable) {
            $span->recordException($throwable);
            $span->setStatus(StatusCode::STATUS_ERROR, $throwable::class);
            throw $throwable;
        } finally {
            $span->end();
        }
    }
}
