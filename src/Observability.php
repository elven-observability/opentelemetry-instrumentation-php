<?php

declare(strict_types=1);

namespace Elven\Observability\Php;

use Elven\Observability\Php\Config\EnvConfigResolver;
use Elven\Observability\Php\Config\ObservabilityConfig;
use Elven\Observability\Php\Logs\LogFacade;
use Elven\Observability\Php\Metrics\MetricFacade;
use Elven\Observability\Php\Propagation\CompositePropagator;
use Elven\Observability\Php\Sdk\ObservabilityVersion;
use Elven\Observability\Php\Sdk\SdkBuilder;
use Elven\Observability\Php\Sdk\SdkState;
use Elven\Observability\Php\Tracing\SafeTracer;

final class Observability
{
    public const VERSION = ObservabilityVersion::VERSION;

    private static ?SdkState $state = null;
    private static ?ObservabilityHandle $handle = null;

    /** @param array<string, mixed> $config */
    public static function boot(array $config = []): ObservabilityHandle
    {
        if (self::$handle !== null) {
            return self::$handle;
        }

        $resolved = (new EnvConfigResolver())->resolve($config);
        self::$state = (new SdkBuilder())->build($resolved);
        self::$handle = new ObservabilityHandle(self::$state);

        if ($resolved->enabled) {
            register_shutdown_function(static function (): void {
                self::$handle?->shutdown();
            });
        }

        return self::$handle;
    }

    /** @param array<string, mixed> $config */
    public static function init(array $config = []): ObservabilityHandle
    {
        return self::boot($config);
    }

    public static function isEnabled(): bool
    {
        return self::state()->config->enabled;
    }

    public static function tracer(): SafeTracer
    {
        return self::state()->tracer;
    }

    public static function metrics(): MetricFacade
    {
        return self::state()->metrics;
    }

    public static function logs(): LogFacade
    {
        return self::state()->logs;
    }

    public static function config(): ObservabilityConfig
    {
        return self::state()->config;
    }

    public static function propagator(): CompositePropagator
    {
        return self::state()->propagator;
    }

    /**
     * Test-only reset hook. Production code should not call it.
     *
     * @internal
     */
    public static function resetForTests(): void
    {
        self::$handle?->shutdown();
        self::$state = null;
        self::$handle = null;
    }

    private static function state(): SdkState
    {
        if (self::$state === null) {
            self::boot();
        }

        return self::$state;
    }
}
