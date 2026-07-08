# Symfony Example

Register the HTTP kernel subscriber:

```yaml
services:
  Elven\Observability\Php\Integrations\Symfony\HttpKernelSubscriber:
    tags: ['kernel.event_subscriber']
```

Boot in `public/index.php` before handling the request:

```php
use Elven\Observability\Php\Observability;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

Observability::init();
```

Env:

```dotenv
ELVEN_OTEL_ENABLED=true
OTEL_SERVICE_NAME=symfony-api
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=otlp
```

