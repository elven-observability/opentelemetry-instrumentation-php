<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elven\Observability\Php\Instrumentation\CacheInstrumentation;
use Elven\Observability\Php\Instrumentation\DbInstrumentation;
use Elven\Observability\Php\Observability;

Observability::init([
    'enabled' => true,
    'service_name' => 'db-cache-example',
    'environment' => 'local',
    'capture_db_statement' => true,
    'redact_db_statement' => true,
]);

$db = new DbInstrumentation();
$db->trace('mysql', 'select', "select * from users where email='demo@example.com'", static fn (): array => []);

CacheInstrumentation::trace('redis', 'get', static fn (): ?string => null);

Observability::boot()->shutdown();
