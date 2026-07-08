<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elven\Observability\Php\Observability;

function percentile(array $values, float $percentile): float
{
    sort($values);
    $index = (int) floor((count($values) - 1) * $percentile);

    return $values[$index] ?? 0.0;
}

function run_case(string $name, bool $enabled): array
{
    Observability::resetForTests();
    Observability::init([
        'enabled' => $enabled,
        'traces_exporter' => 'none',
        'metrics_exporter' => 'none',
        'logs_exporter' => 'none',
    ]);

    $samples = [];
    for ($i = 0; $i < 1000; $i++) {
        $start = hrtime(true);
        Observability::tracer()->withSpan('bench.operation', static function (): void {
            Observability::metrics()->counter('bench.count')->add(1, ['operation' => 'bench']);
        });
        $samples[] = (hrtime(true) - $start) / 1000;
    }

    return [
        'name' => $name,
        'p50_us' => percentile($samples, 0.50),
        'p95_us' => percentile($samples, 0.95),
    ];
}

$off = run_case('disabled', false);
$on = run_case('enabled_no_export', true);

echo json_encode([
    'disabled' => $off,
    'enabled_no_export' => $on,
    'p95_overhead_us' => $on['p95_us'] - $off['p95_us'],
], JSON_PRETTY_PRINT) . PHP_EOL;
