#!/usr/bin/env php
<?php
/**
 * 單元測試 — 驗證核心模組邏輯
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$passed = 0;
$failed = 0;

function assert_eq($label, $expected, $actual) {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
    }
}

function assert_true($label, $condition) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

// =========================================================================
// Calculator
// =========================================================================

echo "## Calculator\n";

// resolveBaseMemory: total mode
$si = ['total_memory' => 4096, 'free_memory' => 2048];
assert_eq('resolveBaseMemory(total)', 4096, Calculator::resolveBaseMemory($si, ['memory_mode' => 'total']));

// resolveBaseMemory: available mode
assert_eq('resolveBaseMemory(available)', 2048, Calculator::resolveBaseMemory($si, ['memory_mode' => 'available']));

// resolveBaseMemory: default is total
assert_eq('resolveBaseMemory(default)', 4096, Calculator::resolveBaseMemory($si, []));

// resolveBaseMemory: fallback when total=0
$si2 = ['total_memory' => 0, 'free_memory' => 2048];
assert_eq('resolveBaseMemory(total=0 fallback)', 2048, Calculator::resolveBaseMemory($si2, ['memory_mode' => 'total']));

// validateParams: ensures min <= start <= max <= max_children
$params = Calculator::validateParams([
    'max_children' => 30,
    'start_servers' => 2,
    'min_spare_servers' => 10,
    'max_spare_servers' => 5,
    'max_requests' => 500,
    'request_terminate_timeout' => 30,
    'request_slowlog_timeout' => 5,
]);
assert_true('validateParams: min <= start', $params['min_spare_servers'] <= $params['start_servers']);
assert_true('validateParams: start <= max_spare', $params['start_servers'] <= $params['max_spare_servers']);
assert_true('validateParams: max_spare <= max_children', $params['max_spare_servers'] <= $params['max_children']);

// calculate: basic test with memory_mode=total
$result = Calculator::calculate(
    ['cpu_cores' => 4, 'total_memory' => 4096, 'free_memory' => 2048, 'worker_memory' => 64],
    ['memory_mode' => 'total', 'memory_reserve_ratio' => 0.20]
);
assert_eq('calculate: no error', false, $result['error']);
// max_children = floor(4096 * 0.8 / 64) = 51 (floor returns float in PHP)
assert_eq('calculate: max_children', 51.0, $result['max_children']);

// =========================================================================
// Analyzer
// =========================================================================

echo "## Analyzer\n";

// calculateStats: basic
$metrics = [];
for ($i = 0; $i < 60; $i++) {
    $metrics[] = [
        'timestamp' => date('Y-m-d H:i:s', strtotime('2024-01-01 10:00:00') + $i * 60),
        'active' => 10 + ($i % 5),
        'idle' => 20 - ($i % 5),
        'total' => 30,
        'listen_queue' => $i < 3 ? 2 : 0,
        'max_listen_queue' => 5,
        'max_active' => 15,
        'max_children_reached' => $i < 30 ? 5 : 6,  // delta = 1 at i=30
        'slow_requests' => 0,
        'avg_worker_mb' => 64,
    ];
}

$stats = Analyzer::calculateStats($metrics);
assert_eq('stats: no error', false, $stats['error']);
assert_eq('stats: sample_count', 60, $stats['sample_count']);
assert_eq('stats: max_children_events', 1, $stats['max_children_events']);  // only 1 delta
assert_eq('stats: fpm_restarts', 0, $stats['fpm_restarts']);
assert_eq('stats: queue_occurred_count', 3, $stats['queue_occurred_count']);
assert_true('stats: queue_avg_depth > 0', $stats['queue_avg_depth'] > 0);
assert_true('stats: utilization_avg > 0', $stats['utilization_avg'] > 0);

// calculateStats: detect fpm restart (counter decreases)
$metrics2 = [
    ['timestamp' => '2024-01-01 10:00:00', 'active' => 5, 'idle' => 10, 'total' => 15,
     'listen_queue' => 0, 'max_listen_queue' => 0, 'max_active' => 5,
     'max_children_reached' => 10, 'slow_requests' => 0, 'avg_worker_mb' => 64],
    ['timestamp' => '2024-01-01 10:01:00', 'active' => 5, 'idle' => 10, 'total' => 15,
     'listen_queue' => 0, 'max_listen_queue' => 0, 'max_active' => 5,
     'max_children_reached' => 0, 'slow_requests' => 0, 'avg_worker_mb' => 64],  // restart!
    ['timestamp' => '2024-01-01 10:02:00', 'active' => 5, 'idle' => 10, 'total' => 15,
     'listen_queue' => 0, 'max_listen_queue' => 0, 'max_active' => 5,
     'max_children_reached' => 2, 'slow_requests' => 0, 'avg_worker_mb' => 64],
];
$stats2 = Analyzer::calculateStats($metrics2);
assert_eq('fpm_restart: detected', 1, $stats2['fpm_restarts']);
assert_eq('fpm_restart: events after restart', 2, $stats2['max_children_events']);

// analyzeTimeSeries: trend detection
$trendMetrics = [];
for ($i = 0; $i < 60; $i++) {
    $trendMetrics[] = [
        'timestamp' => date('Y-m-d H:i:s', strtotime('2024-01-01 10:00:00') + $i * 60),
        'active' => 5 + $i,  // linearly increasing
        'idle' => 10,
        'total' => 100,
        'listen_queue' => 0, 'max_listen_queue' => 0, 'max_active' => 0,
        'max_children_reached' => 0, 'slow_requests' => 0, 'avg_worker_mb' => 64,
    ];
}
$ts = Analyzer::analyzeTimeSeries($trendMetrics);
assert_eq('trend: direction', 'increasing', $ts['trend']['direction']);
assert_true('trend: confidence > 0.5', $ts['trend']['confidence'] > 0.5);
assert_true('trend: slope > 0', $ts['trend']['slope'] > 0);

// data quality
assert_true('data_quality: total_samples', $ts['data_quality']['total_samples'] === 60);
assert_true('data_quality: gap_count >= 0', $ts['data_quality']['gap_count'] >= 0);

// =========================================================================
// Evaluator
// =========================================================================

echo "## Evaluator\n";

// utilizationScore via PES (test asymmetric scoring indirectly)
$goodStats = $stats;
$goodStats['queue_occurred_ratio'] = 0;
$goodStats['max_children_event_rate'] = 0;
$goodStats['utilization_avg'] = 0.55;  // sweet spot
$goodStats['idle_min'] = 10;

$goodTs = ['trend' => ['direction' => 'stable', 'slope' => 0, 'confidence' => 0],
           'peak_stats' => null, 'offpeak_stats' => null, 'peak_hours' => [],
           'data_quality' => ['total_samples' => 60, 'gap_count' => 0]];

$eval = Evaluator::evaluate($goodStats, $goodTs, ['max_children' => 30, 'min_spare_servers' => 5]);
assert_eq('evaluator: no error', false, $eval['error']);
assert_true('evaluator: good PES > 0.8', $eval['pes']['pes_score'] > 0.8);
assert_eq('evaluator: good rating', '優秀', $eval['pes']['rating']);

// Bad scenario: high queue rate
$badStats = $goodStats;
$badStats['queue_occurred_ratio'] = 0.3;
$badStats['max_children_event_rate'] = 0.1;
$badStats['utilization_avg'] = 0.95;
$badStats['idle_min'] = 0;

$evalBad = Evaluator::evaluate($badStats, $goodTs, ['max_children' => 30, 'min_spare_servers' => 5]);
assert_true('evaluator: bad PES < 0.5', $evalBad['pes']['pes_score'] < 0.5);
assert_true('evaluator: has diagnostics', count($evalBad['diagnostics']) > 1);

// =========================================================================
// Optimizer
// =========================================================================

echo "## Optimizer\n";

$optResult = Optimizer::optimize(
    $stats, $ts,
    ['cpu_cores' => 4, 'total_memory' => 4096, 'free_memory' => 2048, 'worker_memory' => 64],
    ['max_children' => 30, 'start_servers' => 5, 'min_spare_servers' => 3, 'max_spare_servers' => 16,
     'memory_mode' => 'total', 'memory_reserve_ratio' => 0.20]
);
assert_eq('optimizer: no error', false, $optResult['error']);
assert_true('optimizer: has recommended', isset($optResult['recommended']['max_children']));
assert_true('optimizer: has target', isset($optResult['target']['max_children']));
assert_true('optimizer: has constraints', isset($optResult['constraints']['memory_limit']));
assert_true('optimizer: confidence set', in_array($optResult['confidence'], ['high', 'medium', 'low']));

// constrainStep: verify max 25% change
$current = $optResult['current']['max_children'];
$rec = $optResult['recommended']['max_children'];
if ($current > 0) {
    $changeRatio = abs($rec - $current) / $current;
    assert_true('optimizer: change <= 25%', $changeRatio <= 0.26);  // small float tolerance
}

// validateParams integration: min <= start <= max_spare <= max_children
$r = $optResult['recommended'];
assert_true('optimizer: min <= start', $r['min_spare_servers'] <= $r['start_servers']);
assert_true('optimizer: start <= max_spare', $r['start_servers'] <= $r['max_spare_servers']);
assert_true('optimizer: max_spare <= max_children', $r['max_spare_servers'] <= $r['max_children']);

// =========================================================================
// Config validate
// =========================================================================

echo "## Config\n";

$errors = Config::validate(['min_spare_ratio' => 0.8, 'max_spare_ratio' => 0.3]);
assert_true('config: min > max spare ratio error', count($errors) > 0);

$errors2 = Config::validate(['max_requests' => -1]);
assert_true('config: negative max_requests error', count($errors2) > 0);

$errors3 = Config::validate(['memory_reserve_ratio' => 0.20, 'min_spare_ratio' => 0.25, 'max_spare_ratio' => 0.75]);
assert_eq('config: valid config no errors', 0, count($errors3));

// =========================================================================
// Summary
// =========================================================================

echo "\n";
echo "─────────────────────────────\n";
echo "Tests: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "─────────────────────────────\n";

exit($failed > 0 ? 1 : 0);
