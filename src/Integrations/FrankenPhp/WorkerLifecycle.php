<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Integrations\FrankenPhp;

use Elven\Observability\Php\Observability;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class WorkerLifecycle
{
    private static ?ScopeInterface $rootScope = null;

    public static function beforeRequest(): void
    {
        if (Observability::config()->workerResetStrict) {
            try {
                self::$rootScope?->detach();
            } catch (Throwable) {
            }
            try {
                self::$rootScope = Context::getRoot()->activate();
            } catch (Throwable) {
                self::$rootScope = null;
            }
        }
    }

    public static function afterRequest(): void
    {
        try {
            Observability::boot()->forceFlush();
        } catch (Throwable) {
        }
        try {
            self::$rootScope?->detach();
        } catch (Throwable) {
        }
        self::$rootScope = null;
    }
}
