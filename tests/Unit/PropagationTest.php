<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Unit;

use Elven\Observability\Php\Observability;
use Elven\Observability\Php\Propagation\CompositePropagator;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PropagationTest extends TestCase
{
    protected function tearDown(): void
    {
        Observability::resetForTests();
    }

    public function testInjectsTraceparentInsideActiveSpan(): void
    {
        Observability::init(['enabled' => false]);

        $headers = [];
        Observability::tracer()->withSpan('noop', static function () use (&$headers): void {
            Observability::propagator()->inject($headers);
        });

        self::assertSame([], $headers);
    }

    public function testExtractsTraceparentBaggageAndCorrelatesLogs(): void
    {
        Observability::init([
            'enabled' => true,
            'service_name' => 'propagation-test',
            'environment' => 'qa',
            'traces_exporter' => 'none',
            'metrics_exporter' => 'none',
            'logs_exporter' => 'none',
        ]);

        $incoming = [
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'baggage' => 'tenant=kontik',
        ];
        $scope = Observability::propagator()->extract($incoming)->activate();

        try {
            Observability::tracer()->withSpan('child', static function (): void {
                $carrier = [];
                Observability::propagator()->inject($carrier);
                $traceparent = $carrier['traceparent'] ?? '';
                $context = Observability::logs()->correlate(['operation' => 'demo']);

                self::assertStringStartsWith('00-4bf92f3577b34da6a3ce929d0e0e4736-', $traceparent);
                self::assertSame('tenant=kontik', $carrier['baggage'] ?? null);
                self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $context['trace_id']);
                self::assertArrayHasKey('span_id', $context);
                self::assertSame('01', $context['trace_flags']);
                self::assertSame('propagation-test', $context['service_name']);
                self::assertSame('qa', $context['environment']);
            });
        } finally {
            $scope->detach();
        }
    }

    public function testPropagatorsNoneDisablesInjection(): void
    {
        Observability::init([
            'enabled' => true,
            'traces_exporter' => 'none',
            'metrics_exporter' => 'none',
            'logs_exporter' => 'none',
            'propagators' => 'none',
        ]);

        Observability::tracer()->withSpan('local', static function (): void {
            $carrier = [];
            Observability::propagator()->inject($carrier);

            self::assertSame([], $carrier);
        });
    }

    public function testCompositePropagatorIsFailOpen(): void
    {
        $propagator = new CompositePropagator([
            new class implements TextMapPropagatorInterface {
                public function fields(): array
                {
                    return ['traceparent'];
                }

                public function inject(
                    mixed &$carrier,
                    ?PropagationSetterInterface $setter = null,
                    ?ContextInterface $context = null,
                ): void {
                    throw new RuntimeException('inject failed');
                }

                public function extract(
                    mixed $carrier,
                    ?PropagationGetterInterface $getter = null,
                    ?ContextInterface $context = null,
                ): ContextInterface {
                    throw new RuntimeException('extract failed');
                }
            },
        ]);

        $carrier = [];
        $propagator->inject($carrier);
        $context = $propagator->extract([]);

        self::assertSame([], $carrier);
        self::assertInstanceOf(ContextInterface::class, $context);
    }
}
