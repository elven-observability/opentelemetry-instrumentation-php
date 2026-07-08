<?php

declare(strict_types=1);

namespace Elven\Observability\Php;

use Elven\Observability\Php\Sdk\SdkState;
use Throwable;

final class ObservabilityHandle
{
    private bool $shutdown = false;

    public function __construct(private readonly SdkState $state)
    {
    }

    public function forceFlush(): void
    {
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
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }
        $this->shutdown = true;

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
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function diagnostics(): array
    {
        return $this->state->diagnostics->records();
    }
}
