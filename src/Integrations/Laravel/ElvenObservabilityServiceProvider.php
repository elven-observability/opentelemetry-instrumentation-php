<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Integrations\Laravel;

use Elven\Observability\Php\Observability;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class ElvenObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/elven-observability.php', 'elven-observability');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../../config/elven-observability.php' => config_path('elven-observability.php'),
        ], 'elven-observability-config');

        try {
            Observability::boot((array) config('elven-observability', []));
            $this->registerHttpMiddleware();
            $this->registerQueueListeners();
            $this->registerLogProcessor();
        } catch (Throwable) {
        }
    }

    private function registerHttpMiddleware(): void
    {
        if (!$this->app->bound(HttpKernel::class)) {
            return;
        }

        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(LaravelObservabilityMiddleware::class);
        }
    }

    private function registerQueueListeners(): void
    {
        if (!class_exists(Event::class)) {
            return;
        }

        Event::listen('Illuminate\Queue\Events\JobProcessing', static function ($event): void {
            LaravelQueueContext::start($event);
        });
        Event::listen('Illuminate\Queue\Events\JobProcessed', static function (): void {
            LaravelQueueContext::finish();
        });
        Event::listen('Illuminate\Queue\Events\JobFailed', static function ($event): void {
            LaravelQueueContext::fail($event->exception ?? null);
        });
    }

    private function registerLogProcessor(): void
    {
        if (!$this->app->bound('log')) {
            return;
        }

        $logger = $this->app->make('log');
        if (method_exists($logger, 'getLogger')) {
            $monolog = $logger->getLogger();
            if (method_exists($monolog, 'pushProcessor')) {
                $monolog->pushProcessor(Observability::logs()->monologProcessor());
            }
        }
    }
}
