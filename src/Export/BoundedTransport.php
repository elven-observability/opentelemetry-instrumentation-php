<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Export;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use RuntimeException;
use Throwable;

/** @implements TransportInterface<string> */
final class BoundedTransport implements TransportInterface
{
    private bool $closed = false;
    private bool $sending = false;
    private float $retryAfterUntil = 0;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly ExportBudget $budget,
        private readonly string $endpoint,
        private readonly string $type,
        private readonly array $headers = [],
        private readonly int $retryDelayMillis = 100,
        private readonly int $maxRetries = 0,
    ) {
    }

    public function contentType(): string
    {
        return $this->type;
    }

    /** @return FutureInterface<mixed> */
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->closed || $this->sending || ExportBudget::now() < $this->retryAfterUntil) {
            return new ErrorFuture(new RuntimeException('OTLP transport unavailable'));
        }
        $this->sending = true;
        $owner = $this->budget->enter();
        $subscription = null;
        try {
            $subscription = $cancellation?->subscribe(function (): void {
                $this->budget->cancel();
            });
            for ($attempt = 0; $attempt <= min(3, max(0, $this->maxRetries)); $attempt++) {
                if (!$this->budget->spendWire(strlen($payload))) {
                    break;
                }
                try {
                    $result = (new BoundedHttpExchange())->post(
                        $this->endpoint,
                        $payload,
                        ['Content-Type' => $this->type] + $this->headers,
                        $this->budget->remainingMillis()
                    );
                } catch (Throwable) {
                    $result = ['ok' => false, 'status' => 0, 'headers' => [], 'body' => ''];
                }
                if ($this->budget->remainingMillis() === 0) {
                    break;
                }
                if ($result['ok']) {
                    return new CompletedFuture($result['body']);
                }
                if ($attempt >= min(3, max(0, $this->maxRetries)) || !in_array($result['status'], [0, 408, 429], true) && $result['status'] < 500) {
                    break;
                }
                $delay = min(100, max(0, $this->retryDelayMillis));
                if (isset($result['headers']['retry-after'])) {
                    $retry = $result['headers']['retry-after'];
                    $seconds = ctype_digit($retry) ? (float) $retry : max(0, (strtotime(substr($retry, 0, 128)) ?: time()) - time());
                    // Never retry earlier than the collector requested. Drop rather
                    // than sleep beyond our local wait/deadline budget.
                    if ($seconds * 1000 > min(100, $this->budget->remainingMillis())) {
                        $this->retryAfterUntil = ExportBudget::now() + $seconds;
                        break;
                    }
                    $delay = max($delay, (int) ceil($seconds * 1000));
                }
                if ($this->budget->remainingMillis() <= $delay) {
                    break;
                }
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
            return new ErrorFuture(new RuntimeException('OTLP export failed within bounded retry/deadline budget'));
        } finally {
            if ($cancellation !== null && $subscription !== null) {
                $cancellation->unsubscribe($subscription);
            }
            $this->budget->leave($owner);
            $this->sending = false;
        }
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return !$this->closed;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        $this->closed = true;
        return true;
    }
}
