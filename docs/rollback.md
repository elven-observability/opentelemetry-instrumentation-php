# Rollback

Fast rollback:

```dotenv
ELVEN_OTEL_ENABLED=false
```

This disables all traces, metrics and logs from this package.

Signal-specific rollback:

```dotenv
OTEL_TRACES_EXPORTER=none
OTEL_METRICS_EXPORTER=none
OTEL_LOGS_EXPORTER=none
```

Privacy rollback:

```dotenv
ELVEN_OTEL_REDACTION_ENABLED=true
ELVEN_OTEL_CAPTURE_DB_STATEMENT=false
ELVEN_OTEL_REDACT_DB_STATEMENT=true
ELVEN_OTEL_ALLOW_RAW_ATTRIBUTES=
```

Worker runtime rollback:

Keep lifecycle hooks in place. They are safe no-ops when telemetry is disabled and protect against context leakage.

