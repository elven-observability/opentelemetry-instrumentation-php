# Decisions

## 2026-07-07 - PHP support floor

Validated facts:

- The official PHP support table lists PHP 8.2, 8.3, 8.4 and 8.5 as currently supported on 2026-07-07.
- `open-telemetry/sdk` latest stable is 1.14.0 and requires PHP `^8.1`.
- `open-telemetry/exporter-otlp` latest stable is 1.4.0 and requires PHP `^8.2`.
- Official PHP zero-code instrumentation requires the OpenTelemetry PHP extension, Composer autoloading, SDK and instrumentation packages.

Decision:

- This new library supports `PHP >=8.2 <9.0`.
- PHP 8.0 and 8.1 are intentionally not supported by this modern package because they are outside the official PHP supported set as of July 2026 and the current upstream OTLP exporter requires PHP 8.2.
- The PHP 7/8.0 legacy use case remains the responsibility of `elven-observability/opentelemetry-instrumentation-php-legacy`.

Impact:

- Native support target includes PHP 8.2, 8.3, 8.4 and 8.5.
- Composer can use the upstream OpenTelemetry SDK and OTLP exporter directly.
- The package must not require `ext-opentelemetry`; it can suggest it and integrate with it when present.

## 2026-07-07 - Auto-instrumentation posture

Validated facts:

- Upstream zero-code PHP auto-instrumentation requires `ext-opentelemetry`.
- Upstream framework auto packages such as Laravel, Symfony and Slim require `ext-opentelemetry`.

Decision:

- This package provides production-safe framework integrations without requiring `ext-opentelemetry`: Laravel middleware/listeners/service provider, Symfony event subscribers/middleware, PSR-15 middleware, Guzzle middleware, log handlers/processors and manual dependency helpers.
- When `ext-opentelemetry` is installed, this package documents and validates compatibility with upstream auto packages instead of hiding that requirement.

Impact:

- Users get useful telemetry in normal Composer-only installs.
- Full zero-code engine hooks remain optional and explicit.

## 2026-07-07 - Validation evidence

Commands run:

- `docker run --rm -v "$PWD":/app -w /app composer:2 composer update --no-interaction --prefer-dist`
- `docker run --rm -v "$PWD":/app -w /app composer:2 composer check`
- `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit`
- `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit`
- `docker run --rm -v "$PWD":/app -w /app php:8.4-cli php vendor/bin/phpunit`
- `docker run --rm -v "$PWD":/app -w /app php:8.5-cli php vendor/bin/phpunit`
- `docker run --rm -v "$PWD":/app -w /app composer:2 composer validate --strict`
- `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php benchmarks/run.php`

Results:

- Initial Composer install/update succeeded before the hardening round. The hardening round later removed `config.platform.php` and validated clean temporary solves per PHP version.
- Composer validate passed.
- Lint, PHPCS, PHPStan and PHPUnit passed through `composer check`.
- PHPUnit passed on PHP 8.2.32, 8.3.32, 8.4.23 and 8.5.8.
- Fake collector integration validated OTLP HTTP/protobuf POSTs for `/v1/traces`, `/v1/metrics` and `/v1/logs`.
- Benchmark smoke on PHP 8.2 showed disabled p95 `0.417us`, enabled/no-export p95 `17.25us`, p95 delta `16.833us`.

Known risk:

- Full native zero-code auto-instrumentation still requires `ext-opentelemetry`; this package provides framework integrations without making that extension mandatory.
- Laravel integration is linted but not fully integration-tested against a live Laravel app in this repo because Laravel is optional and current latest Laravel requires PHP 8.3. The package registers through Composer extra and uses low-risk runtime checks.

## 2026-07-07 - Hardening and QA round

Validated issues found:

- Guzzle middleware injected propagation headers before creating the CLIENT span. Downstream services could receive the SERVER/parent span as parent instead of the CLIENT span.
- Guzzle async requests left the CLIENT span active until the promise resolved. Code running after scheduling an async request could be correlated to the wrong span.
- Trace/log processors used synchronous simple processors. Collector slowness could add latency on span/log paths.
- Laravel package auto-discovery could enable telemetry by default and try `localhost:4318` without explicit opt-in.
- `OTEL_PROPAGATORS` was documented but ignored.
- Laravel HTTP middleware did not record request duration on exception.
- Symfony subscriber could retain previous span/scope if a previous request did not reach a response event in a persistent worker.
- Generic HTTP server fallback could use raw/high-cardinality path as `http.route`.
- Jobs flushed before ending their span and did not support carrier extraction/injection.
- `OTEL_RESOURCE_ATTRIBUTES=service.name=...` was overwritten by `unknown_service:php` when `OTEL_SERVICE_NAME` was absent.
- Fake collector integration used a fixed port and could be flaky.
- Metrics JSON validation initially inspected the shutdown payload, which can be empty after a prior successful force flush.

Corrections applied:

- Guzzle now creates/activates the CLIENT span, injects headers, detaches scope immediately, and ends the span when the promise resolves/rejects.
- CLIENT HTTP 4xx/5xx responses mark spans as ERROR and set low-cardinality `error.type`.
- Traces now use `BatchSpanProcessor`; OTLP logs now use `BatchLogRecordProcessor`; auto-flush per span/log is disabled.
- OpenTelemetry internal SDK logging is suppressed when `ELVEN_OTEL_DEBUG=false` to avoid noisy exporter stack traces on collector outage.
- OTLP transports are wrapped by an internal circuit breaker with failure threshold/cooldown envs to avoid repeated network attempts during outages.
- `ELVEN_OTEL_ENABLED` default changed to `false`; Laravel config is opt-in.
- Added support for `OTEL_PROPAGATORS=tracecontext,baggage|tracecontext|baggage|none`.
- HTTP server integrations only set `http.route` when a real framework route/template exists.
- Laravel/Symfony scope detach is fail-open; Symfony cleans stale scope before a new main request.
- Job instrumentation now supports carrier injection/extraction and flushes after the span ends.
- DB statement capture emits `db.query.text` plus legacy `db.statement`.
- Composer `config.platform.php` and local `composer.lock` were removed from the library repo; CI now performs clean dependency resolution per PHP version and a PHP 8.2 prefer-lowest job.
- Fake collector integration uses a dynamic port and readiness polling.

Validation commands and results:

- `docker run --rm -v "$PWD":/app -w /app composer:2 composer check`
  - Passed: lint, PHPCS, PHPStan, PHPUnit.
  - PHPUnit: 26 tests, 73 assertions.
- `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit --testsuite Unit`
  - Passed: 21 tests, 43 assertions during incremental hardening.
- `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit --testsuite Integration`
  - Passed: 3 tests, 28 assertions.
- Clean temporary Composer solve with `platform.php=8.2.0` plus PHP 8.2 PHPUnit passed.
- Clean temporary Composer solve with `platform.php=8.3.0` plus PHP 8.3 PHPUnit passed.
- Clean temporary Composer solve with `platform.php=8.4.0` plus PHP 8.4 PHPUnit passed.
- Clean temporary Composer solve with `platform.php=8.5.0` plus PHP 8.5 PHPUnit passed.
- PHP 8.2 `composer update --prefer-lowest` plus PHPUnit passed.
- After adding circuit breaker, PHPUnit passed again on PHP 8.2.32, 8.3.32, 8.4.23 and 8.5.8 with 26 tests and 73 assertions.
- `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php benchmarks/run.php`
  - disabled p95: `0.792us`
  - enabled/no-export p95: `34us`
  - p95 overhead: `33.208us`
  - note: a prior post-batch run showed `6.917us` overhead; after adding circuit breaker, observed p95 varied between roughly `24us` and `34us`.

Residual risks:

- Laravel integration still lacks a full Laravel fixture app test in this repo.
- Symfony/Slim/PSR behavior is covered by code-level tests and examples, not by full framework fixture apps.
- Native upstream zero-code instrumentation remains outside this package unless `ext-opentelemetry` and upstream auto packages are installed.
