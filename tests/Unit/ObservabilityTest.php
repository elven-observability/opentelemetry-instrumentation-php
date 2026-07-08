<?php

declare(strict_types=1);

namespace Elven\Observability\Php\Tests\Unit;

use Elven\Observability\Php\Observability;
use PHPUnit\Framework\TestCase;

final class ObservabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Observability::resetForTests();
    }

    public function testInitIsIdempotent(): void
    {
        $a = Observability::init(['enabled' => false]);
        $b = Observability::init(['enabled' => true]);

        self::assertSame($a, $b);
        self::assertFalse(Observability::isEnabled());
    }

    public function testDisabledApiIsNoop(): void
    {
        Observability::init(['enabled' => false]);

        Observability::tracer()->withSpan('operation', static function ($span): void {
            $span->setAttribute('safe', 'value');
        });
        Observability::metrics()->counter('business.operation.started')->add(1, ['operation' => 'demo']);

        self::assertFalse(Observability::isEnabled());
    }
}
