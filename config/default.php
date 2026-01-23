<?php

/**
 * PHP-FPM Tuner 預設配置
 *
 * 此檔案定義所有可調整參數的預設值
 * 使用者可建立自訂配置檔覆蓋這些值
 */

return [
    // =========================================================================
    // 記憶體設定
    // =========================================================================

    /**
     * 記憶體保留比例
     *
     * 保留給系統和其他服務（nginx、MySQL 等）的記憶體比例
     * - 0.10 (10%)：適合 PHP-FPM 專用伺服器
     * - 0.20 (20%)：生產環境保守配置
     * - 0.30 (30%)：與其他重要服務共存時
     */
    'memory_reserve_ratio' => 0.10,

    /**
     * Worker 記憶體最小值 (MB)
     *
     * 當無法偵測實際 worker 記憶體時的最小估計值
     * - 32 MB：適合輕量 PHP 應用
     * - 64 MB：一般 Web 應用
     * - 128 MB：使用大型框架（Laravel、Symfony）
     */
    'min_worker_memory' => 32,

    // =========================================================================
    // PM 參數計算比例
    // =========================================================================

    /**
     * pm.start_servers 計算參數
     *
     * start_servers = min(max_children × ratio, CPU核心數 × cpu_multiplier)
     */
    'start_servers_ratio' => 0.25,
    'start_servers_cpu_multiplier' => 4,

    /**
     * pm.min_spare_servers 計算參數
     *
     * 閒置時保留的最少 worker 數
     */
    'min_spare_ratio' => 0.25,
    'min_spare_cpu_multiplier' => 2,

    /**
     * pm.max_spare_servers 計算參數
     *
     * 閒置時允許的最多 worker 數
     */
    'max_spare_ratio' => 0.75,
    'max_spare_cpu_multiplier' => 4,

    // =========================================================================
    // 請求處理設定
    // =========================================================================

    /**
     * pm.max_requests
     *
     * 每個 worker 處理多少請求後重啟
     * - 0：永不重啟
     * - 200-500：有輕微記憶體洩漏
     * - 500-1000：一般 Web 應用
     */
    'max_requests' => 500,

    /**
     * request_terminate_timeout (秒)
     *
     * 單一請求的最大執行時間
     */
    'request_terminate_timeout' => 30,

    /**
     * request_slowlog_timeout (秒)
     *
     * 超過此時間的請求會被記錄到 slowlog
     */
    'request_slowlog_timeout' => 5,

    // =========================================================================
    // 監控設定
    // =========================================================================

    /**
     * PHP-FPM status 端點 URL
     *
     * 需在 PHP-FPM pool 配置中啟用：
     * pm.status_path = /fpm-status
     */
    'fpm_status_url' => 'http://127.0.0.1:9000/fpm-status?json',

    /**
     * 監控指標輸出路徑
     */
    'metrics_output' => '/var/log/php-fpm/metrics.csv',

    /**
     * 收集間隔（秒）
     */
    'collect_interval' => 60,
];
