<?php

require $argv[1] . '/vendor/autoload.php';

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

function field($object, $name)
{
    return (new ReflectionProperty($object, $name))->getValue($object);
}

if ($argv[2] === 'modern') {
class DeliveryTransportProbe implements TransportInterface
{
    public $events = array();
    private $inner;
    private $budget;
    private $phase;

    public function __construct($inner, $budget, $phase)
    {
        $this->inner = $inner;
        $this->budget = $budget;
        $this->phase = $phase;
    }

    public function contentType(): string { return $this->inner->contentType(); }
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $before = $this->budget->remainingMillis();
        $wire = field($this->budget, 'wireBytes');
        $future = $this->inner->send($payload, $cancellation);
        $this->events[] = array('phase' => $this->phase->name, 'bytes' => strlen($payload),
            'remaining_before_send_ms' => $before, 'remaining_after_send_ms' => $this->budget->remainingMillis(),
            'wire_before' => $wire, 'wire_after' => field($this->budget, 'wireBytes'));
        return $future;
    }
    public function forceFlush(?CancellationInterface $cancellation = null): bool { return $this->inner->forceFlush($cancellation); }
    public function shutdown(?CancellationInterface $cancellation = null): bool { return $this->inner->shutdown($cancellation); }
}
}

$legacy = $argv[2] === 'legacy';
$class = $legacy ? 'Elven\\Observability\\PhpLegacy\\Observability' : 'Elven\\Observability\\Php\\Observability';
$count = (int) $argv[4];
$timeout = (int) $argv[5];
$shape = $argv[6];
$diagnostic = $argv[7] === '1';
$phaseFile = $argv[8];
$phase = (object) array('name' => 'work');
file_put_contents($phaseFile, $phase->name);
$config = array('enabled' => true, 'service_name' => 'php-audit-synthetic',
    'otlp_endpoint' => $argv[3], 'otlp_protocol' => 'http/json', 'export_max_retries' => 0,
    'traces_exporter' => 'otlp', 'metrics_exporter' => 'otlp', 'logs_exporter' => 'otlp');
if ($timeout >= 0) { $config['timeout_millis'] = $timeout; }
if ($legacy) { $config['endpoint'] = $argv[3]; $config['protocol'] = 'http/json'; }
$handle = $class::init($config);
$wrappers = $processors = $probes = array();
$budget = $state = null;
if (!$legacy) {
    $state = field($handle, 'state');
    $budget = $state->redactor->budget();
    $wrappers['traces'] = field($state->tracerProvider, 'tracerSharedState')->getSpanProcessor();
    $wrappers['logs'] = field($state->loggerProvider, 'loggerSharedState')->getProcessor();
    foreach ($wrappers as $signal => $wrapper) {
        $processors[$signal] = field($wrapper, 'inner');
        if ($diagnostic) {
            // Runtime pass-through decoration in this child only, never a source edit.
            $exporter = field(field($processors[$signal], 'exporter'), 'inner');
            $probe = new DeliveryTransportProbe(field($exporter, 'transport'), $budget, $phase);
            (new ReflectionProperty($exporter, 'transport'))->setValue($exporter, $probe);
            $probes[$signal] = $probe;
        }
    }
}
$counts = array('traces' => array('attempted' => 0, 'budget_admitted' => 0, 'sdk_retained' => 0),
    'logs' => array('attempted' => 0, 'budget_admitted' => 0, 'sdk_retained' => 0));
$dynamicNoop = 0;
$firstBudgetFailure = array();
$beforeWork = microtime(true);
for ($i = 0; $i < $count; $i++) {
    $before = array();
    if ($diagnostic) foreach ($wrappers as $signal => $wrapper) {
        $before[$signal] = array(field($wrapper, 'queued'), field($processors[$signal], 'queueSize'));
    }
    $class::tracer()->withSpan('audit.operation', function ($span) use ($class, $legacy, $i, $shape, &$dynamicNoop) {
        $span->setAttribute('Authorization', 'Bearer SYNTHETIC_TOKEN_CANARY');
        $span->setAttribute('safe', array('password' => 'SYNTHETIC_PASSWORD_CANARY', 'email' => 'audit-canary@example.test'));
        $span->recordException(new RuntimeException('audit-canary@example.test Bearer SYNTHETIC_TOKEN_CANARY'));
        $class::metrics()->counter('audit.operations')->add(1, array('operation' => 'audit'));
        if ($shape === 'pressure') {
            $class::metrics()->counter('audit.cardinality')->add(1, array('operation' => 'dynamic.' . $i));
            $counter = $class::metrics()->counter('dynamic.' . $i);
            if (!$legacy && field($counter, 'counter') === null) { $dynamicNoop++; }
            $counter->add(1);
        }
        if ($legacy) {
            $class::logs()->emit('INFO', 'audit-canary@example.test', array('password' => 'SYNTHETIC_PASSWORD_CANARY'));
        } else {
            $class::logs()->emit('audit-canary@example.test', 'info', array('password' => 'SYNTHETIC_PASSWORD_CANARY'));
        }
    });
    if ($diagnostic) foreach ($wrappers as $signal => $wrapper) {
        $counts[$signal]['attempted']++;
        $admitted = field($wrapper, 'queued') > $before[$signal][0];
        $counts[$signal]['budget_admitted'] += (int) $admitted;
        $counts[$signal]['sdk_retained'] += (int) (field($processors[$signal], 'queueSize') > $before[$signal][1]);
        if (!$admitted && !isset($firstBudgetFailure[$signal])) {
            $firstBudgetFailure[$signal] = array('operation' => $i + 1, 'shared_bytes' => $budget->queuedBytes());
        }
    }
}
$workMillis = (microtime(true) - $beforeWork) * 1000;
$snapshot = function () use ($budget, $wrappers, $processors, $state) {
    if ($budget === null) { return null; }
    $out = array('shared_bytes' => $budget->queuedBytes(), 'wire_bytes' => field($budget, 'wireBytes'),
        'deadline_active' => field($budget, 'deadline') !== null,
        'metric_cached_counters' => count(field($state->metrics, 'counters')),
        'metric_series_instruments' => count(field($budget, 'metricSeries')),
        'normal_metric_series' => array_sum(array_map('count', field($budget, 'metricSeries'))), 'signals' => array());
    foreach ($wrappers as $signal => $wrapper) {
        $processor = $processors[$signal];
        $batches = array();
        foreach (field($processor, 'queue') as $batch) { $batches[] = count($batch); }
        if (field($processor, 'batch')) { $batches[] = count(field($processor, 'batch')); }
        $out['signals'][$signal] = array('reserved_logical_bytes' => field($wrapper, 'queued'),
            'sdk_queue_size' => field($processor, 'queueSize'), 'batches' => $batches,
            'sdk_batch_id' => field($processor, 'batchId'));
    }
    return $out;
};
$snapshots = array('before_flush' => $snapshot());
$times = array();
foreach (array('forceFlush', 'shutdown') as $method) {
    $phase->name = $method;
    file_put_contents($phaseFile, $method);
    $start = microtime(true);
    $result = $handle->$method();
    $times[$method] = array('elapsed_ms_noisy' => (microtime(true) - $start) * 1000, 'result' => $result);
    $snapshots['after_' . $method] = $snapshot();
}
$phase->name = 'done';
file_put_contents($phaseFile, 'done');
$effectiveTimeout = $legacy ? $handle->config()->timeoutMillis() : $state->config->timeoutMillis;
echo json_encode(array('runtime' => PHP_VERSION, 'kind' => $argv[2], 'operations' => $count,
    'timeout_config' => $timeout, 'effective_timeout_millis' => $effectiveTimeout,
    'shape' => $shape, 'diagnostic_runtime_decoration' => $diagnostic, 'work_ms_noisy' => $workMillis,
    'counts' => $diagnostic ? $counts : null, 'dynamic_noop_counters' => $dynamicNoop,
    'first_budget_admission_failure' => $firstBudgetFailure,
    'snapshots' => $snapshots, 'phases' => $times,
    'transport_probes' => array_map(function ($probe) { return $probe->events; }, $probes)), JSON_PRETTY_PRINT) . "\n";
