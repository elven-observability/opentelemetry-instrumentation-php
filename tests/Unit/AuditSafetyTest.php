<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Unit;

use Elven\Observability\Php\Config\EnvConfigResolver;
use Elven\Observability\Php\Core\Diagnostics;
use Elven\Observability\Php\Export\CircuitBreakerTransport;
use Elven\Observability\Php\Metrics\MetricFacade;
use Elven\Observability\Php\Privacy\AttributeRedactor;
use Elven\Observability\Php\Privacy\UrlSanitizer;
use Elven\Observability\Php\Sdk\SdkBuilder;
use Elven\Observability\Php\Tracing\SafeSpan;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditSafetyTest extends TestCase
{
    public function testExporterCatchDoesNotResetCircuitFailures(): void
    {
        $inner = new class implements TransportInterface {
            public int $calls = 0;

            public function contentType(): string
            {
                return 'application/json';
            }

            /** @return FutureInterface<mixed> */
            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                $this->calls++;

                return new ErrorFuture(new RuntimeException('collector down'));
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
        $exporter = new SpanExporter(new CircuitBreakerTransport($inner, 2, 30000));
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($exporter->export([])->await());
        }
        self::assertSame(2, $inner->calls);
    }

    public function testExceptionAndStatusAreRedactedWithoutRawStackTrace(): void
    {
        $provider = new TracerProvider(resource: \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([]),
        ));
        $span = $provider->getTracer('audit')->spanBuilder('audit')->startSpan();
        $redactor = new AttributeRedactor((new EnvConfigResolver())->resolve());
        $safe = new SafeSpan($span, null, $redactor, new Diagnostics(false));
        $safe->recordException(new RuntimeException('person@example.com Bearer topsecret'));
        $safe->markError('person@example.com Bearer topsecret');
        $safe->end();

        self::assertInstanceOf(\OpenTelemetry\SDK\Trace\Span::class, $span);
        $data = $span->toSpanData();
        $events = $data->getEvents();
        $attributes = $events[0]->getAttributes()->toArray();
        self::assertSame('RuntimeException', $attributes['exception.type']);
        self::assertStringNotContainsString('person@example.com', $attributes['exception.message']);
        self::assertStringNotContainsString('topsecret', $attributes['exception.message']);
        self::assertArrayNotHasKey('exception.stacktrace', $attributes);
        self::assertStringNotContainsString('topsecret', $data->getStatus()->getDescription());
        $provider->shutdown();
    }

    public function testDynamicInstrumentNamesDoNotGrowCachesWithoutBound(): void
    {
        $redactor = new AttributeRedactor((new EnvConfigResolver())->resolve());
        $metrics = new MetricFacade(null, $redactor);
        for ($i = 0; $i < 10000; $i++) {
            $metrics->counter('dynamic.' . $i)->add(1);
            $metrics->histogram('dynamic.' . $i)->record(1);
            $metrics->gauge('dynamic.' . $i)->record(1);
        }
        foreach (['counters', 'histograms', 'gauges'] as $property) {
            $cache = (new \ReflectionProperty($metrics, $property))->getValue($metrics);
            self::assertLessThanOrEqual(256, count($cache));
        }
    }

    public function testRecursiveAndWideAttributesAreBounded(): void
    {
        $redactor = new AttributeRedactor((new EnvConfigResolver())->resolve());
        $recursive = [];
        $recursive['self'] = &$recursive;
        self::assertStringContainsString('[MAX_DEPTH]', json_encode($redactor->value('safe', $recursive)));
        self::assertCount(63, $redactor->value('safe', array_fill(0, 10000, 'value')));
        self::assertCount(128, $redactor->attributes(array_fill_keys(array_map(
            static fn (int $i): string => 'key.' . $i,
            range(1, 10000),
        ), 'value')));
        self::assertStringNotContainsString('topsecret', $redactor->redactString('token=topsecret'));
    }

    public function testDisabledBootstrapPreservesTheApplicationsOtelLogWriter(): void
    {
        $writer = \OpenTelemetry\API\Behavior\Internal\Logging::logWriter();
        (new SdkBuilder())->build((new EnvConfigResolver())->resolve(['enabled' => false]));
        self::assertSame($writer, \OpenTelemetry\API\Behavior\Internal\Logging::logWriter());
    }

    public function testEnvironmentKillSwitchOverridesExplicitEnable(): void
    {
        $_SERVER['ELVEN_OTEL_ENABLED'] = 'false';
        try {
            self::assertFalse((new EnvConfigResolver())->resolve(['enabled' => true])->enabled);
        } finally {
            unset($_SERVER['ELVEN_OTEL_ENABLED']);
        }
    }

    public function testMetricAttributeSetsOverflowWithoutUnboundedRetention(): void
    {
        $redactor = new AttributeRedactor((new EnvConfigResolver())->resolve());
        for ($i = 0; $i < 512; $i++) {
            self::assertSame(['operation' => 'value.' . $i], $redactor->budget()->metricAttributes('counter:test', $redactor->attributes(['operation' => 'value.' . $i], true)));
        }
        self::assertSame(['otel.metric.overflow' => true], $redactor->budget()->metricAttributes('counter:test', $redactor->attributes(['operation' => 'new.value'], true)));
        self::assertSame(['operation' => 'value.0'], $redactor->budget()->metricAttributes('counter:test', $redactor->attributes(['operation' => 'value.0'], true)));
    }

    public function testUrlPrivacyRunsBeforePercentEncoding(): void
    {
        $sanitizer = new UrlSanitizer();
        $url = $sanitizer->sanitize('/lookup/audit-canary%40example.test?search=audit-canary@example.test');
        self::assertStringNotContainsString('audit-canary', $url);
        self::assertSame('/token/[REDACTED]', $sanitizer->path('/token/opaque-secret'));
    }
}
