<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elven\Observability\Php\Integrations\FrankenPhp\WorkerLifecycle;
use Elven\Observability\Php\Observability;

Observability::init([
    'enabled' => true,
    'service_name' => 'frankenphp-api',
    'environment' => 'local',
]);

if (!function_exists('frankenphp_handle_request')) {
    return;
}

$handler = static function (): void {
    WorkerLifecycle::beforeRequest();

    try {
        require __DIR__ . '/public/index.php';
    } finally {
        WorkerLifecycle::afterRequest();
    }
};

while (frankenphp_handle_request($handler)) {
}
