# Laravel Example

Install:

```bash
composer require elven-observability/opentelemetry-instrumentation-php
php artisan vendor:publish --tag=elven-observability-config
```

The Composer-discovered provider is passive until `ELVEN_OTEL_ENABLED=true`.

Env:

```dotenv
ELVEN_OTEL_ENABLED=true
OTEL_SERVICE_NAME=laravel-api
OTEL_SERVICE_NAMESPACE=backend
OTEL_SERVICE_VERSION=1.0.0
ELVEN_ENVIRONMENT=staging
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=otlp
```

Business metric:

```php
use Elven\Observability\Php\Observability;

Observability::metrics()->counter('checkout.started')->add(1, [
    'operation' => 'checkout_start',
]);
```

Manual span:

```php
Observability::tracer()->withSpan('payment.authorize', function ($span) use ($gateway) {
    $span->setAttribute('payment.gateway', 'safe-provider-name');
    $gateway->authorize();
});
```
