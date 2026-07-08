<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Export;

use Closure;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Throwable;

/** @implements FutureInterface<mixed> */
final class CircuitBreakerFuture implements FutureInterface
{
    /** @param FutureInterface<mixed> $inner */
    public function __construct(
        private readonly FutureInterface $inner,
        private readonly Closure $onSuccess,
        private readonly Closure $onFailure,
    ) {
    }

    public function await(): mixed
    {
        try {
            $result = $this->inner->await();
            ($this->onSuccess)();

            return $result;
        } catch (Throwable $throwable) {
            ($this->onFailure)();
            throw $throwable;
        }
    }

    public function map(Closure $closure): FutureInterface
    {
        return new self($this->inner->map($closure), $this->onSuccess, $this->onFailure);
    }

    public function catch(Closure $closure): FutureInterface
    {
        try {
            return new self($this->inner->catch($closure), $this->onSuccess, $this->onFailure);
        } catch (Throwable $throwable) {
            ($this->onFailure)();

            return new ErrorFuture($throwable);
        }
    }
}
