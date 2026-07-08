<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elven\Observability\Php\Observability;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

Observability::init(['enabled' => true, 'service_name' => 'monolog-example', 'environment' => 'local']);

$logger = new Logger('app');
$logger->pushHandler(new StreamHandler('php://stdout'));
$logger->pushProcessor(Observability::logs()->monologProcessor());

Observability::tracer()->withSpan('log.example', static function () use ($logger): void {
    $logger->info('inside trace');
});

Observability::boot()->shutdown();
