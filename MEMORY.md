# Project Memory

This repository is a new modern PHP observability package, separate from the PHP legacy package.

Current package name: `elven-observability/opentelemetry-instrumentation-php`

Namespace: `Elven\Observability\Php`

Runtime target: `PHP >=8.2 <9.0`

Design posture:

- Use the upstream OpenTelemetry PHP SDK and OTLP exporter for modern PHP.
- Do not require `ext-opentelemetry`.
- Be fail-open: telemetry failures must not break application code.
- Provide framework integrations through normal framework extension points.
- Treat zero-code instrumentation as optional because upstream auto packages require `ext-opentelemetry`.
- Stay opt-in by default: `ELVEN_OTEL_ENABLED=false` unless the app/operator enables it explicitly.
- Use batch processors for traces and OTLP logs; avoid per-span/per-log network export on the request path.
- Wrap OTLP transports in a circuit breaker by default to reduce repeated network attempts while a collector is down.

Validation snapshot on 2026-07-07:

- `composer check` passed in `composer:2`.
- PHPUnit passed on PHP 8.2, 8.3, 8.4 and 8.5 official Docker images after clean temporary Composer solves.
- PHP 8.2 `composer update --prefer-lowest` plus PHPUnit passed.
- Integration test fake collector received OTLP HTTP/protobuf payloads for traces, metrics and logs.
- Integration test validates OTLP HTTP/json content for resource attributes, span name, metric labels and log attributes.
- Dead collector test proves normal instrumentation path stays below 150ms with a closed endpoint and 20ms timeout.
- Circuit breaker unit tests prove repeated transport failures stop calling the inner transport during cooldown.
- Benchmark smoke p95 overhead in enabled/no-export mode was around 24-34 microseconds on PHP 8.2 Docker after batch/circuit-breaker hardening.

Hardening fixes applied on 2026-07-07:

- Guzzle now injects `traceparent` from the CLIENT span and detaches the client scope immediately for async safety.
- Jobs support carrier injection/extraction and flush after span end.
- Symfony cleans stale request scope before starting a new main request.
- Laravel records request duration on exception.
- `OTEL_PROPAGATORS` is implemented.
- `service.name` from `OTEL_RESOURCE_ATTRIBUTES` is preserved when `OTEL_SERVICE_NAME` is absent.
- `db.query.text` is emitted when DB statement capture is enabled.
- Fake collector tests use dynamic ports.
- OTLP transport circuit breaker is enabled by default and configurable through `ELVEN_OTEL_EXPORT_CIRCUIT_BREAKER_*`.

Current known limitation:

- Laravel provider/middleware are implemented and linted, but not exercised against a full Laravel application fixture yet.
