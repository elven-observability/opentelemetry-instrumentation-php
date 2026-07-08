<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elven\Observability\Php\Integrations\Psr\ObservabilityMiddleware;
use Elven\Observability\Php\Observability;

Observability::init([
    'enabled' => true,
    'service_name' => 'slim-api',
    'environment' => 'local',
]);

// Slim 4:
// $app->add(new ObservabilityMiddleware());
//
// Any PSR-15 stack can register the same middleware.
$middleware = new ObservabilityMiddleware();
unset($middleware);
