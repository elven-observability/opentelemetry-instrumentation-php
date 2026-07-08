# Production Guide

## Recommended Topology

Application sends OTLP HTTP/protobuf to a local or private OpenTelemetry Collector:

```dotenv
ELVEN_OTEL_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
```

The application does not need vendor tokens when it sends to a customer/local collector.

## Rollout

1. Deploy with `ELVEN_OTEL_ENABLED=false`.
2. Enable in staging.
3. Validate traces, metrics and logs independently.
4. Enable one canary replica or one worker group.
5. Validate latency and exporter failure counters.
6. Expand gradually.

Rollback:

```dotenv
ELVEN_OTEL_ENABLED=false
```

No code rollback should be needed for telemetry-only failures.

## Privacy Defaults

Keep these defaults unless the customer explicitly accepts the risk:

```dotenv
ELVEN_OTEL_REDACTION_ENABLED=true
ELVEN_OTEL_CAPTURE_DB_STATEMENT=false
ELVEN_OTEL_REDACT_DB_STATEMENT=true
```

## Worker Runtimes

For FrankenPHP, Octane or long-lived workers:

- clear context before every request/job;
- close request spans in `finally`;
- force flush after every request/job or bounded batch;
- never store tenant/user/request data in global resource attributes.

## Collector Slowness

The package batches spans and OTLP logs so normal span/log end does not perform a network export.
Keep export timeout and retries conservative:

```dotenv
OTEL_EXPORTER_OTLP_TIMEOUT=1000
ELVEN_OTEL_EXPORT_MAX_RETRIES=0
ELVEN_OTEL_EXPORT_CIRCUIT_BREAKER_ENABLED=true
```

Increase these only when the collector is local/private and the latency budget is understood.
