<?php

namespace Elven\Observability\Php\Export;

final class ExportBudget
{
    public const QUEUE_BYTES = 8388608;
    public const WIRE_BYTES = 4194304;
    public const RECORD_BYTES = 65536;
    public const HEADER_BYTES = 16384;
    public const RESPONSE_BYTES = 65536;

    private ?float $deadline = null;
    private int $wireBytes = 0;
    private int $queuedBytes = 0;
    private int $timeoutMillis;
    private int $queueLimit;
    /** @var array<string, true> */
    private $metricSeries = array();

    public function __construct(int $timeoutMillis = 1000, int $queueLimit = self::QUEUE_BYTES)
    {
        $this->timeoutMillis = max(1, min(30000, $timeoutMillis));
        $this->queueLimit = max(1, min(self::QUEUE_BYTES, $queueLimit));
    }

    public static function now(): float
    {
        return hrtime(true) / 1000000000;
    }

    public function enter(): bool
    {
        if ($this->deadline !== null) {
            return false;
        }
        $this->deadline = self::now() + $this->timeoutMillis / 1000;
        $this->wireBytes = 0;
        return true;
    }

    public function leave(bool $owner): void
    {
        if ($owner) {
            $this->deadline = null;
        }
    }

    public function remainingMillis(): int
    {
        return $this->deadline === null ? $this->timeoutMillis
            : max(0, (int) floor(($this->deadline - self::now()) * 1000));
    }

    public function cancel(): void
    {
        $this->deadline = self::now();
    }

    public function spendWire(int $bytes): bool
    {
        if ($bytes < 0 || $bytes > self::WIRE_BYTES - $this->wireBytes || $this->remainingMillis() === 0) {
            return false;
        }
        $this->wireBytes += $bytes;
        return true;
    }

    public function reserve(int $bytes): bool
    {
        if ($bytes < 0 || $bytes > $this->queueLimit - $this->queuedBytes) {
            return false;
        }
        $this->queuedBytes += $bytes;
        return true;
    }

    public function release(int $bytes): void
    {
        $this->queuedBytes = max(0, $this->queuedBytes - max(0, $bytes));
    }

    public function queuedBytes(): int
    {
        return $this->queuedBytes;
    }

    public function reserveMetric(string $identity, string $attributes): bool
    {
        $key = hash('sha256', $identity . ':' . $attributes);
        if (isset($this->metricSeries[$key])) {
            return true;
        }
        if (count($this->metricSeries) >= 512 || !$this->reserve(strlen($attributes) + strlen($identity) + 1024)) {
            return false;
        }
        $this->metricSeries[$key] = true;
        return true;
    }
}
