<?php

/**
 * PHP-FPM Tuner
 *
 * 根據系統資源自動計算最佳的 PHP-FPM pool 配置參數
 * 支援 PHP 7.1+
 *
 * 此檔案為向後相容入口，內部呼叫新的模組化架構。
 * 建議使用 bin/tuner 作為主要入口。
 *
 * 新架構說明：
 *   bin/tuner   - 參數計算
 *   bin/collect - 監控收集
 *   bin/analyze - 配置分析
 */

// =============================================================================
// 可調整參數 (Configuration)
// =============================================================================
// 這些參數可在此處直接修改（向後相容），
// 或使用 --config 指定配置檔（建議方式）。
// 詳細說明請參考 docs/FORMULA.md

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
// 執行
// =============================================================================

// 載入新架構
require_once __DIR__ . '/lib/bootstrap.php';

// 如果直接執行此檔案，使用上方定義的配置執行計算
if (php_sapi_name() === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    // 收集系統資訊
    $systemInfo = SystemInfo::collect($config);

    // 計算參數
    $params = Calculator::calculate($systemInfo, $config);

    // 錯誤處理
    if ($params['error']) {
        fwrite(STDERR, "# 警告：{$params['message']}\n");
        if (isset($params['details'])) {
            fwrite(STDERR, "# 可用記憶體: {$params['details']['free_memory']} MB\n");
            fwrite(STDERR, "# Worker 記憶體: {$params['details']['worker_memory']} MB\n");
            fwrite(STDERR, "# 記憶體保留: " . ($params['details']['memory_reserve_ratio'] * 100) . "%\n");
        }
        exit(1);
    }

    // 輸出結果（保持原格式）
    $reservePercent = $params['memory_reserve_ratio'] * 100;

    echo "# PHP-FPM Tuner 計算結果\n";
    echo "# ─────────────────────────────────────\n";
    echo "# 系統資訊:\n";
    echo "#   CPU 核心數: {$systemInfo['cpu_cores']}\n";
    echo "#   可用記憶體: {$systemInfo['free_memory']} MB\n";
    echo "#   Worker 記憶體: {$systemInfo['worker_memory']} MB\n";
    echo "#   記憶體保留: {$reservePercent}%\n";
    echo "# ─────────────────────────────────────\n";
    echo "\n";
    echo "; Process Manager 設定\n";
    echo "pm = dynamic\n";
    echo "pm.max_children = {$params['max_children']}\n";
    echo "pm.start_servers = {$params['start_servers']}\n";
    echo "pm.min_spare_servers = {$params['min_spare_servers']}\n";
    echo "pm.max_spare_servers = {$params['max_spare_servers']}\n";
    echo "pm.max_requests = {$params['max_requests']}\n";
    echo "\n";
    echo "; 請求處理設定\n";
    echo "request_terminate_timeout = {$params['request_terminate_timeout']}\n";
    echo "request_slowlog_timeout = {$params['request_slowlog_timeout']}\n";
    echo "; slowlog = /var/log/php-fpm/slow.log\n";
}
