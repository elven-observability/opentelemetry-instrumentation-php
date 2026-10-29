<?php

declare(strict_types=1);

namespace Elven\Observability\Php;

use Elven\Observability\Php\Sdk\SdkState;
use Throwable;

final class ObservabilityHandle
{
    private bool $shutdown = false;
    private bool $flushing = false;

    public function __construct(private readonly SdkState $state)
    {
    }

    public function forceFlush(): void
    {
        if ($this->flushing) {
            return;
        }
        $this->flushing = true;
        $owner = $this->state->redactor->budget()->enter();
        try {
            foreach ([$this->state->tracerProvider, $this->state->meterProvider, $this->state->loggerProvider] as $provider) {
                if ($provider === null) {
                    continue;
                }
                try {
                    $provider->forceFlush();
                } catch (Throwable $throwable) {
                    $this->state->diagnostics->exception('Telemetry forceFlush failed', $throwable);
                }
            }
        } finally {
            $this->state->redactor->budget()->leave($owner);
            $this->flushing = false;
        }
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }
        $this->shutdown = true;
        $owner = $this->state->redactor->budget()->enter();
        try {
            foreach ([$this->state->tracerProvider, $this->state->meterProvider, $this->state->loggerProvider] as $provider) {
                if ($provider === null) {
                    continue;
                }
                try {
                    $provider->shutdown();
                } catch (Throwable $throwable) {
                    $this->state->diagnostics->exception('Telemetry shutdown failed', $throwable);
                }
            }
        } finally {
            $this->state->redactor->budget()->leave($owner);
        }
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function diagnostics(): array
    {
        return $this->state->diagnostics->records();
    }
}
