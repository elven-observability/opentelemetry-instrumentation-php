<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Export;

use Closure;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Throwable;

/** @implements FutureInterface<mixed> */
final class CircuitBreakerFuture implements FutureInterface
{
    /** @var FutureInterface<mixed> */
    private readonly FutureInterface $inner;
    private bool $settled = false;

    /** @param FutureInterface<mixed> $inner */
    public function __construct(
        FutureInterface $inner,
        Closure $onSuccess,
        Closure $onFailure,
    ) {
        // Observe the transport outcome before an exporter catches its failure.
        $this->inner = $inner->map(function (mixed $result) use ($onSuccess): mixed {
            if (!$this->settled) {
                $this->settled = true;
                $onSuccess();
            }

            return $result;
        })->catch(function (Throwable $throwable) use ($onFailure): never {
            if (!$this->settled) {
                $this->settled = true;
                $onFailure();
            }
            throw $throwable;
        });
    }

    public function await(): mixed
    {
        return $this->inner->await();
    }

    public function map(Closure $closure): FutureInterface
    {
        return $this->inner->map($closure);
    }

    public function catch(Closure $closure): FutureInterface
    {
        return $this->inner->catch($closure);
    }
}
