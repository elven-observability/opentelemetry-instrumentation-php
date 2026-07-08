<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elven\Observability\Php\Observability;

Observability::init([
    'enabled' => true,
    'service_name' => 'plain-php-example',
    'environment' => 'local',
]);

Observability::tracer()->withSpan('example.work', static function ($span): void {
    $span->setAttribute('example.safe_attribute', 'value');
    Observability::metrics()->counter('example.work.started')->add(1, ['operation' => 'example_work']);
    Observability::logs()->emit('example work finished', 'info', ['operation' => 'example_work']);
});

Observability::boot()->shutdown();
