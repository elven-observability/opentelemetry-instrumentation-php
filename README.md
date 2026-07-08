# Elven OpenTelemetry Instrumentation for PHP

Modern, fail-open observability for PHP 8.2+ applications.

This package provides a clean Elven layer on top of the official OpenTelemetry PHP SDK:

- traces, metrics and OTLP logs;
- Laravel integration;
- Symfony integration;
- FrankenPHP worker-mode helpers;
- PSR-15 middleware for Slim, Mezzio/Laminas and other PSR stacks;
- Guzzle middleware;
- Monolog trace correlation;
- privacy and low-cardinality defaults;
- fake collector and Docker validation.

## Requirements

- PHP `>=8.2 <9.0`
- Composer 2
- An OTLP collector reachable over HTTP
- Recommended in production: `ext-protobuf`

Supported test matrix:

- PHP 8.2
- PHP 8.3
- PHP 8.4
- PHP 8.5

PHP 8.0 and 8.1 are intentionally not supported because they are end-of-life in July 2026 and current upstream OTLP exporter support starts at PHP 8.2.

## Install

```bash
composer require elven-observability/opentelemetry-instrumentation-php
```

The package is safe by default: installing it does not export telemetry until `ELVEN_OTEL_ENABLED=true`
or an explicit config value enables it.

## Minimal Env

Copy this first. It works for most apps that send telemetry to a local collector.

```dotenv
ELVEN_OTEL_ENABLED=true
ELVEN_OTEL_DEBUG=false

OTEL_SERVICE_NAME=my-php-api
OTEL_SERVICE_NAMESPACE=my-team
OTEL_SERVICE_VERSION=1.0.0
ELVEN_ENVIRONMENT=prod

OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_TIMEOUT=1000

OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=otlp

OTEL_PROPAGATORS=tracecontext,baggage
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=1.0

ELVEN_OTEL_REDACTION_ENABLED=true
ELVEN_OTEL_CAPTURE_DB_STATEMENT=false
ELVEN_OTEL_REDACT_DB_STATEMENT=true
ELVEN_OTEL_LOG_CORRELATION_ENABLED=true
```

Emergency kill switch:

```dotenv
ELVEN_OTEL_ENABLED=false
```

## Plain PHP

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Elven\Observability\Php\Observability;

$handle = Observability::init([
    'enabled' => true,
    'service_name' => 'checkout-api',
    'service_namespace' => 'commerce',
    'environment' => 'prod',
]);

Observability::tracer()->withSpan('payment.authorize', function ($span): void {
    $span->setAttribute('payment.provider', 'stripe');

    Observability::metrics()
        ->counter('business.payment.authorized')
        ->add(1, ['operation' => 'payment_authorize']);
});

Observability::logs()->emit('payment authorized', 'info', [
    'operation' => 'payment_authorize',
]);

$handle->forceFlush();
$handle->shutdown();
```

## Laravel

Laravel auto-discovers the service provider through Composer.

Publish the config when you want Laravel config files:

```bash
php artisan vendor:publish --tag=elven-observability-config
```

The provider automatically:

- boots the SDK from env/config;
- prepends HTTP middleware;
- adds queue job spans;
- adds Monolog trace correlation when possible.

Manual business metric:

```php
use Elven\Observability\Php\Observability;

Observability::metrics()
    ->counter('orders.created')
    ->add(1, ['operation' => 'order_create']);
```

Manual DB span when you need explicit statement control:

```php
use Elven\Observability\Php\Instrumentation\DbInstrumentation;

$result = (new DbInstrumentation())->trace(
    system: 'mysql',
    operation: 'select',
    statement: 'select * from orders where id = ?',
    callback: fn () => DB::select('select * from orders where id = ?', [$id]),
    attributes: ['db.name' => 'app']
);
```

## Symfony

Register `Elven\Observability\Php\Integrations\Symfony\HttpKernelSubscriber` as a service and tag it as an event subscriber.

```yaml
services:
  Elven\Observability\Php\Integrations\Symfony\HttpKernelSubscriber:
    tags: ['kernel.event_subscriber']
```

Boot early, usually in `public/index.php` or your kernel bootstrap:

```php
use Elven\Observability\Php\Observability;

Observability::init();
```

## Slim / Mezzio / PSR-15

```php
use Elven\Observability\Php\Integrations\Psr\ObservabilityMiddleware;

$app->add(new ObservabilityMiddleware());
```

For route names, set a request attribute named `http.route`, `route`, `route_pattern` or `routePattern` before the middleware finishes.

## FrankenPHP

In worker mode, call the lifecycle hooks around every request/job:

```php
use Elven\Observability\Php\Integrations\FrankenPhp\WorkerLifecycle;

while (frankenphp_handle_request(function () {
    WorkerLifecycle::beforeRequest();

    try {
        require __DIR__ . '/public/index.php';
    } finally {
        WorkerLifecycle::afterRequest();
    }
})) {
}
```

This prevents span, baggage or tenant context from leaking between requests in long-lived workers.

## Guzzle

```php
use Elven\Observability\Php\Integrations\Guzzle\GuzzleMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$stack = HandlerStack::create();
$stack->push(GuzzleMiddleware::middleware());

$client = new Client(['handler' => $stack]);
$client->get('https://example.com/api');
```

The middleware creates client spans and injects `traceparent`/`baggage`.

## Monolog

```php
use Elven\Observability\Php\Observability;
use Monolog\Logger;

$logger = new Logger('app');
$logger->pushProcessor(Observability::logs()->monologProcessor());
```

Every record gets correlation fields when a span is active:

- `trace_id`
- `span_id`
- `trace_flags`
- `service_name`
- `environment`
- `hostname`

## DB Statements

Default:

```dotenv
ELVEN_OTEL_CAPTURE_DB_STATEMENT=false
ELVEN_OTEL_REDACT_DB_STATEMENT=true
```

To capture sanitized statements:

```dotenv
ELVEN_OTEL_CAPTURE_DB_STATEMENT=true
ELVEN_OTEL_REDACT_DB_STATEMENT=true
```

To capture raw statements for a customer that explicitly accepts the privacy risk:

```dotenv
ELVEN_OTEL_REDACTION_ENABLED=false
ELVEN_OTEL_CAPTURE_DB_STATEMENT=true
ELVEN_OTEL_REDACT_DB_STATEMENT=false
ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES=db.query.text,db.statement
```

Captured SQL is emitted as `db.query.text` and, for backend compatibility, `db.statement`.
Raw SQL can include PII or secrets. Keep this off by default.

## Local Validation

Install dependencies:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
```

For a clean dependency solve without a committed lock file:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer update --no-interaction --prefer-dist
```

Run all checks:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer check
```

Run matrix tests:

```bash
docker compose run --rm php82
docker compose run --rm php83
docker compose run --rm php84
docker compose run --rm php85
```

Fake collector:

```bash
docker compose up fake-collector
```

Point your app to:

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:43189
```

## Production Notes

- Prefer sending to a local OpenTelemetry Collector.
- Use `OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf`.
- Install `ext-protobuf` for lower export overhead.
- Keep `ELVEN_OTEL_ENABLED=false` ready as a rollback switch.
- Do not put high-cardinality values in metric labels.
- Do not use request IDs, trace IDs, user IDs, order IDs, emails, CPF, tokens or raw paths as metric labels.
- In worker mode, always call lifecycle reset/flush hooks.

## What This Package Does Not Promise

- It does not provide native zero-code hooks without `ext-opentelemetry`.
- It does not support PHP 8.0/8.1.
- It does not mutate tenant identity into resource attributes per request.
- It does not capture bodies/payloads by default.
