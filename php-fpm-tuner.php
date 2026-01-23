<?php

/**
 * PHP-FPM Tuner
 *
 * 根據系統資源自動計算最佳的 PHP-FPM pool 配置參數
 * 支援 PHP 7.1+
 */

// =============================================================================
// 可調整參數 (Configuration)
// =============================================================================
// 修改以下參數可自訂計算行為，詳細說明請參考 docs/FORMULA.md

/**
 * 記憶體保留比例
 *
 * 保留給系統和其他服務（nginx、MySQL 等）的記憶體比例
 * - 預設 0.10 (10%)：適合 PHP-FPM 專用伺服器
 * - 建議 0.20 (20%)：生產環境保守配置
 * - 建議 0.30 (30%)：與其他重要服務共存時
 */
$config['memory_reserve_ratio'] = 0.10;

/**
 * Worker 記憶體最小值 (MB)
 *
 * 當無法偵測實際 worker 記憶體時的最小估計值
 * 也作為除以零保護的安全下限
 * - 預設 32 MB：適合輕量 PHP 應用
 * - 建議 64 MB：一般 Web 應用
 * - 建議 128 MB：使用大型框架（Laravel、Symfony）
 */
$config['min_worker_memory'] = 32;

/**
 * pm.start_servers 計算參數
 *
 * start_servers = min(max_children × 比例, CPU 核心數 × 倍數)
 * - ratio: 佔 max_children 的比例（預設 0.25 = 25%）
 * - cpu_multiplier: CPU 核心數的倍數上限（預設 4）
 */
$config['start_servers_ratio'] = 0.25;
$config['start_servers_cpu_multiplier'] = 4;

/**
 * pm.min_spare_servers 計算參數
 *
 * 閒置時保留的最少 worker 數，確保能快速響應突發請求
 * - ratio: 佔 max_children 的比例（預設 0.25 = 25%）
 * - cpu_multiplier: CPU 核心數的倍數上限（預設 2）
 */
$config['min_spare_ratio'] = 0.25;
$config['min_spare_cpu_multiplier'] = 2;

/**
 * pm.max_spare_servers 計算參數
 *
 * 閒置時允許的最多 worker 數，超過會被終止以節省記憶體
 * - ratio: 佔 max_children 的比例（預設 0.75 = 75%）
 * - cpu_multiplier: CPU 核心數的倍數上限（預設 4）
 */
$config['max_spare_ratio'] = 0.75;
$config['max_spare_cpu_multiplier'] = 4;

/**
 * pm.max_requests
 *
 * 每個 worker 處理多少請求後重啟，用於防止記憶體洩漏
 * - 0：永不重啟（僅適用於確認無記憶體洩漏的應用）
 * - 200-500：有輕微記憶體洩漏
 * - 500-1000：一般 Web 應用（預設建議）
 * - 1000+：記憶體穩定的應用
 */
$config['max_requests'] = 500;

/**
 * request_terminate_timeout (秒)
 *
 * 單一請求的最大執行時間，超過會被強制終止
 * - 0：不限制（危險，可能導致 worker 卡死）
 * - 30：一般 Web 請求
 * - 300：需要長時間處理的 API（如報表生成）
 * - 注意：應大於 PHP 的 max_execution_time
 */
$config['request_terminate_timeout'] = 30;

/**
 * request_slowlog_timeout (秒)
 *
 * 超過此時間的請求會被記錄到 slowlog
 * - 0：停用 slowlog
 * - 5-10：一般建議，用於找出慢請求
 * - 需配合 slowlog 設定路徑
 */
$config['request_slowlog_timeout'] = 5;

// =============================================================================
// Polyfills for PHP < 7.2 and PHP < 8.2
// =============================================================================

// PHP_OS_FAMILY polyfill (PHP 7.2+)
if (!defined('PHP_OS_FAMILY')) {
    if (stripos(PHP_OS, 'WIN') === 0) {
        define('PHP_OS_FAMILY', 'Windows');
    } elseif (stripos(PHP_OS, 'Darwin') === 0) {
        define('PHP_OS_FAMILY', 'Darwin');
    } else {
        define('PHP_OS_FAMILY', 'Linux');
    }
}

// ini_parse_quantity polyfill (PHP 8.2+)
if (!function_exists('ini_parse_quantity')) {
    function ini_parse_quantity($value) {
        if (is_numeric($value)) {
            return (int) $value;
        }

        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) substr($value, 0, -1);

        switch ($unit) {
            case 'g':
                $number *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $number *= 1024 * 1024;
                break;
            case 'k':
                $number *= 1024;
                break;
        }

        return $number;
    }
}

// =============================================================================
// Core Functions
// =============================================================================

function getCpuCores() {
    if (PHP_OS_FAMILY === 'Windows') {
        $cores = shell_exec('echo %NUMBER_OF_PROCESSORS%');
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $cores = shell_exec('sysctl -n hw.ncpu');
    } else {
        $cores = shell_exec('nproc');
    }

    return max(1, (int) $cores);
}

function getFreeMemory() {
    $freeMemory = 0;

    if (PHP_OS_FAMILY === 'Windows') {
        if (preg_match('~(\d+)~', shell_exec('wmic OS get FreePhysicalMemory'), $matches)) {
            $freeMemory = round((int) $matches[1] / 1024);
        }
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        // macOS: 使用 vm_stat 計算可用記憶體
        $pageSize = (int) shell_exec('pagesize');
        $vmStat = shell_exec('vm_stat');
        if ($pageSize && $vmStat) {
            $free = 0;
            if (preg_match('~Pages free:\s+(\d+)~', $vmStat, $matches)) {
                $free += (int) $matches[1];
            }
            if (preg_match('~Pages inactive:\s+(\d+)~', $vmStat, $matches)) {
                $free += (int) $matches[1];
            }
            if (preg_match('~Pages purgeable:\s+(\d+)~', $vmStat, $matches)) {
                $free += (int) $matches[1];
            }
            $freeMemory = round($free * $pageSize / 1024 / 1024);
        }
    } else {
        // Linux
        $meminfo = shell_exec('cat /proc/meminfo');

        // 優先使用 MemAvailable（更準確）
        if ($meminfo && preg_match('~MemAvailable:\s+(\d+)\s+~', $meminfo, $matches)) {
            $freeMemory = $matches[1] / 1024;
        }
        // 回退：MemFree + Buffers + Cached
        elseif ($meminfo && preg_match_all('~(MemFree|Buffers|Cached):\s+(\d+)\s+~', $meminfo, $matches, PREG_SET_ORDER)) {
            $total = 0;
            foreach ($matches as $match) {
                // 只計算 MemFree, Buffers, 第一個 Cached（避免 SwapCached）
                if ($match[1] === 'Cached' || $match[1] === 'MemFree' || $match[1] === 'Buffers') {
                    $total += (int) $match[2];
                }
            }
            $freeMemory = $total / 1024;
        }
    }

    return (int) $freeMemory;
}

function getWorkerMemory() {
    $processMemory = 0;

    if (PHP_OS_FAMILY !== 'Windows') {
        $psOutput = shell_exec('ps -eo size,command 2>/dev/null');
        if ($psOutput && preg_match_all('~(\d+).*php-fpm: pool~', $psOutput, $matches, PREG_PATTERN_ORDER)) {
            if (count($matches[1]) > 0) {
                $processMemory = round(array_sum($matches[1]) / count($matches[1]) / 1024);
            }
        }
    }

    // 回退：使用 memory_limit
    if ($processMemory <= 0) {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit && $memoryLimit !== '-1') {
            $processMemory = round(ini_parse_quantity($memoryLimit) / 1048576);
        }
    }

    // 確保最小值，避免除以零
    global $config;
    return max($config['min_worker_memory'], (int) $processMemory);
}

// =============================================================================
// Main Calculation
// =============================================================================

$cpuCores = getCpuCores();
$freeMemory = getFreeMemory();
$workerMemory = getWorkerMemory();

// 計算 max_children（使用配置的記憶體保留比例）
$memoryReserve = round($config['memory_reserve_ratio'] * $freeMemory);
$maxChildren = floor(($freeMemory - $memoryReserve) / $workerMemory);

// 錯誤處理：記憶體不足
if ($maxChildren < 1) {
    fwrite(STDERR, "# 警告：可用記憶體不足，無法啟動 PHP-FPM worker\n");
    fwrite(STDERR, "# 可用記憶體: {$freeMemory} MB\n");
    fwrite(STDERR, "# Worker 記憶體: {$workerMemory} MB\n");
    fwrite(STDERR, "# 記憶體保留: " . ($config['memory_reserve_ratio'] * 100) . "%\n");
    exit(1);
}

// 計算 spare servers 參數（使用配置的比例和 CPU 倍數）
$minSpareServers = min(
    round($config['min_spare_ratio'] * $maxChildren),
    $cpuCores * $config['min_spare_cpu_multiplier']
);
$maxSpareServers = min(
    round($config['max_spare_ratio'] * $maxChildren),
    $cpuCores * $config['max_spare_cpu_multiplier']
);
$startServers = min(
    round($config['start_servers_ratio'] * $maxChildren),
    $cpuCores * $config['start_servers_cpu_multiplier']
);

// 確保邏輯一致性：min_spare <= start <= max_spare
$minSpareServers = max(1, $minSpareServers);
$maxSpareServers = max($minSpareServers, $maxSpareServers);
$startServers = max($minSpareServers, min($startServers, $maxSpareServers));

// =============================================================================
// Output
// =============================================================================

$reservePercent = $config['memory_reserve_ratio'] * 100;

echo "# PHP-FPM Tuner 計算結果\n";
echo "# ─────────────────────────────────────\n";
echo "# 系統資訊:\n";
echo "#   CPU 核心數: {$cpuCores}\n";
echo "#   可用記憶體: {$freeMemory} MB\n";
echo "#   Worker 記憶體: {$workerMemory} MB\n";
echo "#   記憶體保留: {$reservePercent}%\n";
echo "# ─────────────────────────────────────\n";
echo "\n";
echo "; Process Manager 設定\n";
echo "pm = dynamic\n";
echo "pm.max_children = {$maxChildren}\n";
echo "pm.start_servers = {$startServers}\n";
echo "pm.min_spare_servers = {$minSpareServers}\n";
echo "pm.max_spare_servers = {$maxSpareServers}\n";
echo "pm.max_requests = {$config['max_requests']}\n";
echo "\n";
echo "; 請求處理設定\n";
echo "request_terminate_timeout = {$config['request_terminate_timeout']}\n";
echo "request_slowlog_timeout = {$config['request_slowlog_timeout']}\n";
echo "; slowlog = /var/log/php-fpm/slow.log\n";
