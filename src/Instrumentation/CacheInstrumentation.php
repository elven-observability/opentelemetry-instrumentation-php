<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Instrumentation;

use Elven\Observability\Php\Observability;

final class CacheInstrumentation
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function trace(string $cacheName, string $operation, callable $callback): mixed
    {
        return DependencyInstrumentation::trace(
            'cache',
            $cacheName,
            $operation,
            static function () use ($callback, $cacheName, $operation): mixed {
                $result = $callback();
                Observability::metrics()->counter('elven.php.cache.operations')->add(1, [
                    'cache.name' => $cacheName,
                    'cache.operation' => $operation,
                ]);

                return $result;
            },
            [
                'cache.name' => $cacheName,
                'cache.operation' => $operation,
            ],
        );
    }
}
