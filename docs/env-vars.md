# Environment Variables

## Global

| Variable | Default | Description |
|---|---:|---|
| `ELVEN_OTEL_ENABLED` | `false` | Total opt-in and kill switch. `true` enables configured signals; `false` disables all signals. |
| `OTEL_SDK_DISABLED` | `false` | Standard OpenTelemetry SDK kill switch. `true` disables all signals. |
| `ELVEN_OTEL_DEBUG` | `false` | Stores sanitized startup diagnostics. |

## Service Identity

| Variable | Default |
|---|---:|
| `OTEL_SERVICE_NAME` | `unknown_service:php` |
| `OTEL_SERVICE_NAMESPACE` | empty |
| `OTEL_SERVICE_VERSION` | empty |
| `ELVEN_ENVIRONMENT` | `production` |
| `OTEL_DEPLOYMENT_ENVIRONMENT` | fallback for environment |
| `OTEL_RESOURCE_ATTRIBUTES` | empty |

## OTLP

| Variable | Default |
|---|---:|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` |
| `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT` | base + `/v1/traces` |
| `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` | base + `/v1/metrics` |
| `OTEL_EXPORTER_OTLP_LOGS_ENDPOINT` | base + `/v1/logs` |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` |
| `OTEL_EXPORTER_OTLP_TRACES_PROTOCOL` | fallback to generic protocol |
| `OTEL_EXPORTER_OTLP_METRICS_PROTOCOL` | fallback to generic protocol |
| `OTEL_EXPORTER_OTLP_LOGS_PROTOCOL` | fallback to generic protocol |
| `OTEL_EXPORTER_OTLP_TIMEOUT` | `1000` ms |
| `ELVEN_OTEL_EXPORT_TIMEOUT_MS` | fallback to `OTEL_EXPORTER_OTLP_TIMEOUT` |
| `ELVEN_OTEL_EXPORT_RETRY_DELAY_MS` | `100` |
| `ELVEN_OTEL_EXPORT_MAX_RETRIES` | `0` |
| `ELVEN_OTEL_EXPORT_CIRCUIT_BREAKER_ENABLED` | `true` |
| `ELVEN_OTEL_EXPORT_CIRCUIT_BREAKER_FAILURES` | `3` |
| `ELVEN_OTEL_EXPORT_CIRCUIT_BREAKER_COOLDOWN_MS` | `30000` |
| `OTEL_EXPORTER_OTLP_HEADERS` | empty |

Use `http/protobuf` in production. `http/json` is useful for fake collector/debug validation.
`grpc` is parsed so misconfiguration is explicit in diagnostics, but this package does not bundle a gRPC transport; configured `grpc` signals become no-op instead of breaking the app.

## Signals

| Variable | Default |
|---|---:|
| `OTEL_TRACES_EXPORTER` | `otlp` |
| `OTEL_METRICS_EXPORTER` | `otlp` |
| `OTEL_LOGS_EXPORTER` | `none` |

Logs are supported through OTLP, but enable them intentionally to avoid duplicate ingestion when your app already ships logs through files/agents.

## Propagation

| Variable | Default |
|---|---:|
| `OTEL_PROPAGATORS` | `tracecontext,baggage` |

Supported propagators:

- `tracecontext`
- `baggage`
- `none`

Unknown propagator names are ignored. `none` disables extraction and injection.

## Sampling

| Variable | Default |
|---|---:|
| `OTEL_TRACES_SAMPLER` | `parentbased_traceidratio` |
| `OTEL_TRACES_SAMPLER_ARG` | `1.0` |

Supported sampler names:

- `parentbased_traceidratio`
- `traceidratio`
- `always_on`
- `always_off`

## Privacy

| Variable | Default |
|---|---:|
| `ELVEN_OTEL_REDACTION_ENABLED` | `true` |
| `ELVEN_OTEL_CAPTURE_DB_STATEMENT` | `false` |
| `ELVEN_OTEL_REDACT_DB_STATEMENT` | `true` |
| `ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES` | empty |
| `ELVEN_OTEL_MAX_ATTRIBUTE_LENGTH` | `2048` |

Captured DB SQL is emitted as `db.query.text` and `db.statement` for backend compatibility.
Keep `ELVEN_OTEL_CAPTURE_DB_STATEMENT=false` unless the customer accepts the privacy risk.

## Batch Processors

| Variable | Default |
|---|---:|
| `OTEL_BSP_MAX_QUEUE_SIZE` | `2048` |
| `OTEL_BSP_SCHEDULE_DELAY` | `5000` ms |
| `OTEL_BSP_EXPORT_TIMEOUT` | `30000` ms |
| `OTEL_BSP_MAX_EXPORT_BATCH_SIZE` | `512` |
| `OTEL_BLRP_MAX_QUEUE_SIZE` | `2048` |
| `OTEL_BLRP_SCHEDULE_DELAY` | `1000` ms |
| `OTEL_BLRP_EXPORT_TIMEOUT` | `30000` ms |
| `OTEL_BLRP_MAX_EXPORT_BATCH_SIZE` | `512` |

The package disables per-span/per-log auto-flush and flushes on shutdown or worker lifecycle hooks.
This avoids blocking every request when the collector is slow or unavailable.

## Worker Mode

| Variable | Default |
|---|---:|
| `ELVEN_OTEL_WORKER_RESET_STRICT` | `true` |
