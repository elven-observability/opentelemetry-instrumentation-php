<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Unit;

use Elven\Observability\Php\Config\EnvConfigResolver;
use Elven\Observability\Php\Metrics\MetricFacade;
use Elven\Observability\Php\Privacy\AttributeRedactor;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricFacadeTest extends TestCase
{
    /**
     * @param list<int|float> $boundaries
     * @param array{ExplicitBucketBoundaries?: list<float>} $expectedAdvisory
     */
    #[DataProvider('boundaryCases')]
    public function testHistogramBoundaryAdvisory(array $boundaries, array $expectedAdvisory): void
    {
        $actualArguments = [];
        $instrument = $this->createStub(HistogramInterface::class);
        $meter = $this->createMock(MeterInterface::class);
        $meter->expects(self::once())
            ->method('createHistogram')
            ->willReturnCallback(static function (...$arguments) use (&$actualArguments, $instrument): HistogramInterface {
                $actualArguments = $arguments;

                return $instrument;
            });
        $metrics = new MetricFacade($meter, new AttributeRedactor((new EnvConfigResolver())->resolve([])));

        $histogram = $metrics->histogram('app.duration', 's', explicitBucketBoundaries: $boundaries);

        self::assertSame(['app.duration', 's', null, $expectedAdvisory], $actualArguments);
        self::assertSame($histogram, $metrics->histogram('app.duration', 's', explicitBucketBoundaries: [0.1, 0.5]));
    }

    /** @return iterable<string, array{list<int|float>, array{ExplicitBucketBoundaries?: list<float>}}> */
    public static function boundaryCases(): iterable
    {
        yield 'explicit' => [[0.1, 1], ['ExplicitBucketBoundaries' => [0.1, 1.0]]];
        yield 'single bucket' => [[], ['ExplicitBucketBoundaries' => []]];
        yield 'descending' => [[0.1, 0.01], []];
        yield 'duplicate' => [[1, 1.0], []];
        yield 'infinity' => [[INF], []];
        yield 'NaN' => [[NAN], []];
        yield 'too many' => [range(1, 129), []];
    }
}
