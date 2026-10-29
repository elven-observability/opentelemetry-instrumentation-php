<?php

namespace Elven\Observability\Php\Tests\Integration;

use Elven\Observability\Php\Config\EnvConfigResolver;
use Elven\Observability\Php\Core\Diagnostics;
use Elven\Observability\Php\Export\BudgetedSpanProcessor;
use Elven\Observability\Php\Export\ExportBudget;
use Elven\Observability\Php\Metrics\MetricFacade;
use Elven\Observability\Php\Privacy\AttributeRedactor;
use Elven\Observability\Php\Tracing\SafeTracer;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricSink;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class ReviewRegressionTest extends TestCase
{
    public function testLogScopeCannotBypassQueueAccounting(): void
    {
        $sink = new \OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter();
        $budget = new ExportBudget();
        $provider = \OpenTelemetry\SDK\Logs\LoggerProvider::builder()
            ->setResource(\OpenTelemetry\SDK\Resource\ResourceInfo::create(\OpenTelemetry\SDK\Common\Attribute\Attributes::create([])))
            ->addLogRecordProcessor(new \Elven\Observability\Php\Export\BudgetedLogProcessor(
                new \OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor($sink),
                $budget
            ))->build();
        $provider->getLogger(str_repeat('x', 1048576))->logRecordBuilder()->setBody('safe')->emit();
        self::assertSame(0, $budget->queuedBytes());
        $provider->getLogger('small')->logRecordBuilder()->setBody('safe')->emit();
        self::assertGreaterThan(1024, $budget->queuedBytes());
        $provider->shutdown();
        self::assertSame(0, $budget->queuedBytes());
    }

    public function testExplicitAttributeLengthIsNotSilentlyClampedToEightKiB(): void
    {
        $config = (new EnvConfigResolver())->resolve(['max_attribute_length' => 16384]);
        self::assertSame(16384, $config->maxAttributeLength);
        self::assertSame(12000, strlen((new AttributeRedactor($config))->value('safe', str_repeat('x', 12000))));
    }

    public function testLinksCannotBypassRecordAndSharedQueueAccounting(): void
    {
        $sink = new InMemoryExporter();
        $budget = new ExportBudget();
        $provider = new TracerProvider(new BudgetedSpanProcessor(new SimpleSpanProcessor($sink), $budget), resource: \OpenTelemetry\SDK\Resource\ResourceInfo::create(\OpenTelemetry\SDK\Common\Attribute\Attributes::create([])));
        $tracer = $provider->getTracer('review');
        $context = SpanContext::create(str_repeat('1', 32), str_repeat('2', 16));
        for ($i = 0; $i < 12; $i++) {
            $tracer->spanBuilder('oversized')->addLink($context, ['bulk' => str_repeat('x', 1048576)])->startSpan()->end();
        }
        self::assertCount(0, $sink->getSpans());
        self::assertSame(0, $budget->queuedBytes());
        $tracer->spanBuilder('small')->addLink($context, ['safe' => 'value'])->startSpan()->end();
        self::assertCount(1, $sink->getSpans());
        self::assertGreaterThan(1024, $budget->queuedBytes());
        $provider->shutdown();
        self::assertSame(0, $budget->queuedBytes());
    }

    public function testReplacingOneAttributeKeepsTheLatestValue(): void
    {
        $sink = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($sink), resource: \OpenTelemetry\SDK\Resource\ResourceInfo::create(\OpenTelemetry\SDK\Common\Attribute\Attributes::create([])));
        $redactor = new AttributeRedactor((new EnvConfigResolver())->resolve());
        $span = (new SafeTracer($provider->getTracer('review'), $redactor, new Diagnostics(false)))->span('progress');
        for ($i = 0; $i < 100; $i++) {
            $span->setAttribute('progress', 'tick.' . $i . ':' . str_repeat('x', 1900));
        }
        $span->end();
        self::assertStringStartsWith('tick.99:', $sink->getSpans()[0]->getAttributes()->toArray()['progress']);
        $provider->shutdown();
    }

    public function testOverflowPreservesSumsAcrossDeltaCollectionsAndInstruments(): void
    {
        $redactor = new AttributeRedactor((new EnvConfigResolver())->resolve(['metric_cardinality_limit' => 32]));
        $sink = new MetricSink(new \ArrayObject(), Temporality::DELTA);
        $provider = MeterProvider::builder()->addReader(new ExportingReader($sink))->build();
        $facade = new MetricFacade($provider->getMeter('review'), $redactor);
        $counter = $facade->counter('review.jobs');
        for ($i = 0; $i < 100; $i++) {
            $counter->add(1, ['operation' => 'op.' . $i]);
            if ($i % 10 === 9) {
                $provider->forceFlush();
            }
        }
        $counter->add(1, ['operation' => 'op.0']);
        $facade->counter('review.other')->add(2, ['operation' => 'new']);
        $provider->forceFlush();
        $sum = $overflow = $other = 0;
        foreach ($sink->collect() as $metric) {
            self::assertInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Sum::class, $metric->data);
            foreach ($metric->data->dataPoints as $point) {
                if ($metric->name === 'review.jobs') {
                    $sum += $point->value;
                    if (isset($point->attributes->toArray()['otel.metric.overflow'])) {
                        $overflow += $point->value;
                    }
                } else {
                    $other += $point->value;
                    self::assertSame(['operation' => 'new'], $point->attributes->toArray());
                }
            }
        }
        self::assertSame(101, $sum);
        self::assertSame(68, $overflow);
        self::assertSame(2, $other);
        self::assertLessThan(100000, $redactor->budget()->queuedBytes());
        $provider->shutdown();
    }
}
