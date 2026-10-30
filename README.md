# Elven OpenTelemetry Instrumentation for PHP

Modern, fail-open observability for PHP 8.2+ applications.

Authoritative documentation: [docs.elven.works](https://docs.elven.works/).
Documentation source and corrections: [elvenworks-ps/docs.elven.works](https://github.com/elvenworks-ps/docs.elven.works).

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

### Runtime Security

The PHP version range above describes API compatibility, not a secure patch level. Before production adoption, use PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or a later patched release in the corresponding branch, or verify an equivalent vendor backport. The PHP project's [October 2026 security notice](https://www.php.net/index.php) identifies critical runtime RCE vulnerabilities CVE-2026-109618 and CVE-2026-109619. Updating or disabling this Elven library does not patch PHP.

Also review the notice's remaining conditional session-upload issue, CVE-2026-115560, and Windows rebuilt packages for Brotli CVE-2026-111497 where applicable. The library does not change PHP memory/session settings to mitigate these issues. The original PHP 8.5.10 CLI consumer receipt is compatibility and signal-delivery evidence, not secure web-runtime qualification; any newer runtime results are separate addenda. `composer audit` does not certify the PHP engine or its native dependencies.

Supported test matrix:

- PHP 8.2
- PHP 8.3
- PHP 8.4
- PHP 8.5

PHP 8.0 and 8.1 are outside this package's supported matrix. This is the Elven package policy, not an assertion that the upstream SDK/exporter requires PHP 8.2; verify upstream package requirements separately.

## Public-Tag CLI Qualification

The [2026-10-30 UTC hosted run](https://github.com/elven-observability/opentelemetry-instrumentation-php/actions/runs/40997492213) (attempt 1) passed 12 healthy CLI consumer cases across the modern and legacy public RC tags, using PHP 8.5.12 CLI, Node 26.13.0 and Composer 2.11.0 on Linux x64. Product source came from the public tags, not the workflow checkout. The [four-JSON metadata artifact](https://github.com/elven-observability/opentelemetry-instrumentation-php/actions/runs/40997492213/artifacts/12952450208) and [separate runtime addendum](https://github.com/elven-observability/opentelemetry-instrumentation-php/releases/download/v0.2.0-rc.1/php-hosted-runtime-addendum-40997492213.json) bind the run, sources, locked graphs and reported binary hashes.

For this package's `v0.2.0-rc.1` tag, six cases (1/10 operations, each repeated three times) recorded 33 decoded spans, 33 logs and a metric operation sum of 33. All three OTLP signals arrived during forceFlush; no known synthetic canaries were observed. The approved harness checked 49 own-source files and the frozen 35-package installed graph, with Composer plugins/scripts disabled and no dependency updates. These are scoped synthetic privacy checks, not universal PII detection or a fresh dependency-security audit.

The hosted PHP `memory_limit` was `-1`; the runner reported 4 logical CPUs and 16,759,304,192 bytes of host memory. This does not qualify 256/512 MiB or 2-CPU profiles, RSS/CPU/overhead budgets, FPM/mod_php, frameworks, other PHP versions, Windows, TLS/proxy/DNS, failure/backpressure or customer environments. The runtime-security caveats above still apply; these CLI cases do not waive the conditional session issue or native-library/Windows checks.

The [original PHP 8.5.10 receipt](https://github.com/elven-observability/opentelemetry-instrumentation-php/releases/download/v0.2.0-rc.1/modern-public-receipt.json) remains unchanged as dated compatibility/delivery evidence; it has not been relabeled as PHP 8.5.12 qualification. The addendum is additional evidence, not a new package version, repack, tag or automatic upgrade.

## Install

The verified distribution method is an explicit Git/VCS repository. Public Packagist lookup returned HTTP 404 on 2026-10-29; this is not a target-network diagnosis and no Packagist token is required to consume Git tags.

Installation from Git tag `v0.1.0` was verified, but that baseline contains the audit findings. The optional [0.2.0-rc.1 Git/VCS prerelease](https://github.com/elven-observability/opentelemetry-instrumentation-php/releases/tag/v0.2.0-rc.1) is now published and checked through a fresh public-tag consumer. The release includes the source/CI/consumer receipt. This is not the previously planned transparent `0.1.1` patch. Existing stable `^0.1` constraints do not select this new minor RC. Review [MIGRATION.md](MIGRATION.md) before opting in:

```bash
composer config repositories.elven-php git https://github.com/elven-observability/opentelemetry-instrumentation-php
# Explicit opt-in after reviewing the migration contract:
composer require elven-observability/opentelemetry-instrumentation-php:0.2.0-rc.1
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

Database, cache and event helpers require explicit calls; the provider does not automatically hook those framework events.

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
- Export is synchronous. The configured export timeout (clamped to 1-30000ms) is shared by all signals and retries in a flush/shutdown, not restarted per attempt. Checks are cooperative around bounded serialization and I/O; this is not a hard real-time CPU/RSS guarantee.
- The modern circuit breaker is per transport instance, not shared across PHP-FPM requests or signals.
- The exporter uses a dedicated HTTP transport, never application HTTP handlers. Application PSR discovery, custom handlers and client-specific mTLS/compression/proxy settings are not inherited: this is an explicit migration boundary, not feature-equivalent PSR transport replacement. Retries are capped at three and local waits at 100ms and the remaining shared deadline. A longer `Retry-After` defers that transport instead of retrying early or sleeping beyond the deadline. Redirects stay disabled and TLS verification stays enabled.
- Queued trace/log data and retained metric series share an 8MiB logical-data budget, with 64KiB per trace/log record, including SDK links/resource/scope data. Records exceeding admission limits are dropped. This accounting is not process RSS; direct SDK metadata is accounted, not a promise of automatic PII sanitization outside the safe facade.
- Serialized payload acceptance/transmission is capped at 4MiB per flush in total, including retry attempts. The official SDK serializer has bounded queue input; an oversized encoded batch is rejected before network I/O. Responses are capped at 16KiB headers and 64KiB body; later batches are skipped after the shared deadline. These are logical admission/transport limits, not an exact allocator ceiling.
- The default compatibility profile accepts OS-resolved collector hostnames, endpoint Basic-auth userinfo and explicit headers. CLI `HTTP_PROXY` is honored, while CGI/FPM's uppercase `HTTP_PROXY` is ignored against httpoxy; lowercase configured proxies and `NO_PROXY` remain supported. Streams support HTTP proxies, including HTTPS CONNECT; other proxy profiles require cURL and fail telemetry rather than bypassing required egress.
- **DNS limit:** compatibility mode cannot preempt synchronous OS DNS resolution. Socket/transfer deadlines do not establish a hard DNS/whole-request deadline. Opt in with `ELVEN_OTEL_STRICT_RESOLVER=true` to require async-DNS cURL for hostnames, or literal IP/localhost for streams. This stricter resolver profile is not enabled silently. TLS verification and redirect protection are identical in both profiles. Qualified proxy/framing behavior does not imply universal HTTP-client compatibility.
- Internal OpenTelemetry logging remains application-owned (`OTEL_LOG_LEVEL`); bootstrap does not replace the process-wide log writer.
- Instrument caches default to 256 names per kind per SDK lifetime (`max_metric_instruments_per_kind` / `ELVEN_OTEL_MAX_METRIC_INSTRUMENTS_PER_KIND`); further names are no-op. Use static names. Review explicit larger budgets for existing workloads.
- Each instrument defaults to 512 admitted metric series (`metric_cardinality_limit` / `ELVEN_OTEL_METRIC_CARDINALITY_LIMIT`) plus a reserved `otel.metric.overflow=true` series. Later combinations aggregate there, including across successful DELTA collections; existing series remain writable. Series share the 8MiB admission budget, so actual backpressure can still drop new instruments. Count limits do not bound all allocator overhead or preserve every high-cardinality label combination.
- Attributes are limited to 128 entries per call; nested values have a depth limit of 4 and a 64-node traversal budget. Exception events omit raw stack traces.
- Do not install both manual middleware and upstream auto-instrumentation for the same operation without checking for duplicate spans.

## What This Package Does Not Promise

- It does not provide native zero-code hooks without `ext-opentelemetry`.
- It does not support PHP 8.0/8.1.
- It does not mutate tenant identity into resource attributes per request.
- It does not capture bodies/payloads by default.
- Laravel has a provider, HTTP middleware and queue listeners; DB/cache/event helpers are manual, not automatic framework hooks. Slim/Mezzio support is PSR-15 middleware, not a version-tested native adapter. Framework and worker acceptance must be tested in the target application.
