# Troubleshooting

## Nothing arrives

Check:

```bash
php -r 'var_dump(getenv("ELVEN_OTEL_ENABLED"), getenv("OTEL_EXPORTER_OTLP_ENDPOINT"));'
```

`ELVEN_OTEL_ENABLED` must be `true`. The package is disabled by default so a Composer install cannot start exporting to `localhost` by surprise.

Then confirm collector endpoints:

```bash
curl -i http://localhost:4318/v1/traces
```

`405 Method Not Allowed` for GET usually means the OTLP HTTP route exists.

## Works in CLI but not HTTP/FPM

PHP-FPM may hide environment variables. Add explicit `env[...]` entries or configure `clear_env = no` only if your security model allows it.

Example:

```ini
env[ELVEN_OTEL_ENABLED] = $ELVEN_OTEL_ENABLED
env[OTEL_SERVICE_NAME] = $OTEL_SERVICE_NAME
env[OTEL_EXPORTER_OTLP_ENDPOINT] = $OTEL_EXPORTER_OTLP_ENDPOINT
```

## Traces arrive but metrics do not

Validate:

```dotenv
OTEL_METRICS_EXPORTER=otlp
OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=http://collector:4318/v1/metrics
```

Make sure your collector has an OTLP receiver and a metrics exporter.

## Logs duplicate

If your infrastructure already ships application logs with Filebeat/Vector/Promtail/Alloy, start with:

```dotenv
OTEL_LOGS_EXPORTER=none
ELVEN_OTEL_LOG_CORRELATION_ENABLED=true
```

Enable `OTEL_LOGS_EXPORTER=otlp` only when the log pipeline expects OTLP logs.

## FrankenPHP leak symptoms

Symptoms:

- trace IDs appear across unrelated requests;
- tenant/source attributes bleed between requests;
- spans flush only when workers stop.

Fix:

- use `WorkerLifecycle::beforeRequest()` and `WorkerLifecycle::afterRequest()`;
- keep `ELVEN_OTEL_WORKER_RESET_STRICT=true`.
