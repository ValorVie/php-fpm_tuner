#!/usr/bin/env php
<?php
/**
 * 整合測試 — 驗證 CLI 入口點端到端運作
 */

$passed = 0;
$failed = 0;
$projectDir = dirname(__DIR__);
$tmpCsv = sys_get_temp_dir() . '/php-fpm-tuner-test-' . getmypid() . '.csv';

function assert_cli($label, $command, $expectExit = 0) {
    global $passed, $failed, $projectDir;
    $output = [];
    $exitCode = 0;
    exec("cd " . escapeshellarg($projectDir) . " && php -d error_reporting=E_ALL\\&~E_DEPRECATED {$command} 2>&1", $output, $exitCode);
    $outputStr = implode("\n", $output);

    if ($exitCode === $expectExit) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n  expected exit: {$expectExit}, got: {$exitCode}\n  output: " . substr($outputStr, 0, 300) . "\n");
    }
    return $outputStr;
}

function assert_contains($label, $haystack, $needle) {
    global $passed, $failed;
    if (strpos($haystack, $needle) !== false) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n  needle '{$needle}' not found in output\n");
    }
}

// =========================================================================
// bin/tuner
// =========================================================================

echo "## bin/tuner\n";

$out = assert_cli('tuner: runs', 'bin/tuner');
assert_contains('tuner: has pm.max_children', $out, 'pm.max_children');
assert_contains('tuner: has total memory', $out, '總記憶體');
assert_contains('tuner: has memory mode', $out, '計算模式');

$out = assert_cli('tuner: --json', 'bin/tuner --json');
$json = json_decode($out, true);
if (is_array($json) && isset($json['parameters'])) {
    $passed++;
} else {
    $failed++;
    fwrite(STDERR, "FAIL: tuner --json: invalid JSON output\n");
}

assert_cli('tuner: --help', 'bin/tuner --help');

// =========================================================================
// 建立測試 CSV
// =========================================================================

echo "## 建立測試 CSV\n";

$headers = 'timestamp,pool,active,idle,total,listen_queue,max_listen_queue,max_active,max_children_reached,slow_requests,memory_mb,avg_worker_mb';
$rows = [$headers];
for ($i = 0; $i < 120; $i++) {
    $ts = date('Y-m-d H:i:s', strtotime('2024-06-01 08:00:00') + $i * 60);
    $active = 10 + ($i % 10);
    $idle = 20 - ($i % 10);
    $total = 30;
    $queue = $i > 100 ? 3 : 0;
    $maxReached = $i > 50 ? 2 : 0;
    $rows[] = "{$ts},www,{$active},{$idle},{$total},{$queue},5,{$active},{$maxReached},0,1920,64";
}
file_put_contents($tmpCsv, implode("\n", $rows) . "\n");
echo "  CSV: {$tmpCsv} ({$i} rows)\n";

// =========================================================================
// bin/analyze
// =========================================================================

echo "## bin/analyze\n";

$out = assert_cli('analyze: runs', "bin/analyze --input {$tmpCsv} --max-children 30");
assert_contains('analyze: has PES', $out, 'PES');
assert_contains('analyze: has diagnostics', $out, '診斷');
assert_contains('analyze: suggests optimize', $out, 'bin/optimize');
assert_contains('analyze: has peak info or queue info', $out, '佇列');

$out = assert_cli('analyze: --json', "bin/analyze --input {$tmpCsv} --json");
$json = json_decode($out, true);
if (is_array($json) && isset($json['stats']) && isset($json['evaluation'])) {
    $passed++;
    // Verify key stats fields
    if (isset($json['stats']['max_children_events'])) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: analyze --json: missing max_children_events\n");
    }
} else {
    $failed++;
    fwrite(STDERR, "FAIL: analyze --json: invalid structure\n");
}

assert_cli('analyze: --help', 'bin/analyze --help');
assert_cli('analyze: missing input', 'bin/analyze', 1);

// =========================================================================
// bin/optimize
// =========================================================================

echo "## bin/optimize\n";

$out = assert_cli('optimize: runs', "bin/optimize --input {$tmpCsv} --max-children 30 --min-spare 3 --max-spare 16 --start-servers 5");
assert_contains('optimize: has recommendations', $out, '建議配置');
assert_contains('optimize: has constraints', $out, '限制條件');
assert_contains('optimize: has next step', $out, '下一步');

$out = assert_cli('optimize: --json', "bin/optimize --input {$tmpCsv} --max-children 30 --json");
$json = json_decode($out, true);
if (is_array($json) && isset($json['optimization']['recommended'])) {
    $passed++;
    $rec = $json['optimization']['recommended'];
    // Verify parameter ordering constraint
    if ($rec['min_spare_servers'] <= $rec['start_servers'] &&
        $rec['start_servers'] <= $rec['max_spare_servers'] &&
        $rec['max_spare_servers'] <= $rec['max_children']) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: optimize: parameter ordering violated\n");
    }
} else {
    $failed++;
    fwrite(STDERR, "FAIL: optimize --json: invalid structure\n");
}

assert_cli('optimize: --help', 'bin/optimize --help');
assert_cli('optimize: missing input', 'bin/optimize', 1);

// =========================================================================
// php-fpm-tuner.php (向後相容)
// =========================================================================

echo "## php-fpm-tuner.php (向後相容)\n";

$out = assert_cli('legacy: runs', 'php-fpm-tuner.php');
assert_contains('legacy: has pm.max_children', $out, 'pm.max_children');

// =========================================================================
// Cleanup
// =========================================================================

@unlink($tmpCsv);

echo "\n";
echo "─────────────────────────────\n";
echo "Tests: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "─────────────────────────────\n";

exit($failed > 0 ? 1 : 0);
