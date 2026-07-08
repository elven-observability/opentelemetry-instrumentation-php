# Architecture

This package is a modern PHP observability layer for PHP 8.2+ applications. It intentionally differs from the PHP legacy package:

- It uses the upstream OpenTelemetry PHP SDK as the primary telemetry engine.
- It does not require the `ext-opentelemetry` PHP extension.
- It exposes stable Elven APIs and framework integrations that are fail-open by design.
- It keeps privacy, low cardinality and worker-mode isolation as first-class requirements.

## Runtime Support

Supported runtimes:

- PHP 8.2
- PHP 8.3
- PHP 8.4
- PHP 8.5

Unsupported:

- PHP 8.0 and 8.1, because they are end-of-life on 2026-07-07.
- PHP 7.x, covered by the separate legacy package.

## High-Level Modules

- `Config`: environment and explicit config resolution.
- `Core`: public `Observability` facade, lifecycle handle, fail-open guard, diagnostics.
- `Resource`: resource attributes and service identity.
- `Sdk`: OpenTelemetry SDK provider construction.
- `Tracing`: safe span helper API.
- `Metrics`: low-cardinality metric facade over the upstream meter API.
- `Logs`: Monolog processor/handler helpers and OTLP log support.
- `Privacy`: redaction, URL sanitization, DB statement sanitization and cardinality policy.
- `Instrumentation`: framework-agnostic HTTP, dependency and job helpers.
- `Integrations`: Laravel, Symfony, FrankenPHP and PSR middleware.
- `Testing`: fake collector and in-memory helpers for validation.

## Fail-Open Contract

All public entrypoints catch telemetry exceptions and return no-op behavior. Exporter failures are never allowed to escape into application code.

Kill switches:

- `ELVEN_OTEL_ENABLED=false`
- `OTEL_SDK_DISABLED=true`

The package is disabled by default. When disabled, it does not build exporters,
does not register shutdown hooks and does not emit logs/metrics/traces.

## Worker Mode

FrankenPHP, Laravel Octane, Swoole-like runtimes and queue workers keep PHP memory alive across requests/jobs. This package treats every request/job as an isolation boundary:

- start request/job context explicitly;
- clear request-local attributes in `finally`;
- force flush with bounded timeout;
- never mutate global resource attributes per tenant;
- keep tenant/source attributes low-cardinality and allowlisted.

## Export

Primary export path:

- OTLP via upstream `open-telemetry/exporter-otlp`.

Supported signals:

- Traces: `OTEL_TRACES_EXPORTER=otlp|none`
- Metrics: `OTEL_METRICS_EXPORTER=otlp|none`
- Logs: `OTEL_LOGS_EXPORTER=otlp|none`

Protocol:

- `http/protobuf` is the production default because the upstream PHP OTLP exporter uses protobuf.
- `http/json` is supported and useful for fake collector/debug validation.
- `grpc` is not bundled. If configured, affected providers become no-op with diagnostics instead of breaking the app.
- `ext-protobuf` is strongly recommended in production for lower overhead.

Processors:

- traces use `BatchSpanProcessor`;
- OTLP logs use `BatchLogRecordProcessor`;
- auto-flush on every span/log is disabled to avoid request-path blocking when the collector is slow;
- shutdown and worker lifecycle hooks perform bounded flushes.

## Auto-Instrumentation

Composer-only installs get first-class integrations through framework extension points:

- Laravel service provider, middleware and event listeners.
- Symfony event subscribers and bundle-style setup.
- PSR-15 middleware for Slim, Mezzio/Laminas and other PSR stacks.
- Guzzle middleware and PSR-18 helper wrapping.
- Monolog processor/handler.

Native zero-code hooks are optional and require `ext-opentelemetry` plus upstream `open-telemetry/opentelemetry-auto-*` packages.

## Privacy And Cardinality

Default posture:

- redaction enabled;
- no payload/body capture;
- DB statements off unless explicitly enabled;
- sensitive headers and query params redacted;
- high-cardinality metric labels dropped;
- request IDs, trace IDs, user IDs, tokens, email, CPF/card-like values never used as metric labels by default.
