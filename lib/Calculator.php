<?php

/**
 * 參數計算類別
 *
 * 根據系統資訊和配置計算 PHP-FPM pool 參數
 */
class Calculator
{
    /**
     * 計算 PHP-FPM pool 參數
     *
     * @param array $systemInfo SystemInfo::collect() 的回傳值
     * @param array $config 配置陣列
     * @return array 計算結果
     */
    public static function calculate(array $systemInfo, array $config)
    {
        $cpuCores = $systemInfo['cpu_cores'];
        $freeMemory = $systemInfo['free_memory'];
        $workerMemory = $systemInfo['worker_memory'];

        // 取得配置值（使用預設值作為後備）
        $memoryReserveRatio = isset($config['memory_reserve_ratio']) ? $config['memory_reserve_ratio'] : 0.20;
        $startServersRatio = isset($config['start_servers_ratio']) ? $config['start_servers_ratio'] : 0.25;
        $startServersCpuMultiplier = isset($config['start_servers_cpu_multiplier']) ? $config['start_servers_cpu_multiplier'] : 4;
        $minSpareRatio = isset($config['min_spare_ratio']) ? $config['min_spare_ratio'] : 0.25;
        $minSpareCpuMultiplier = isset($config['min_spare_cpu_multiplier']) ? $config['min_spare_cpu_multiplier'] : 2;
        $maxSpareRatio = isset($config['max_spare_ratio']) ? $config['max_spare_ratio'] : 0.75;
        $maxSpareCpuMultiplier = isset($config['max_spare_cpu_multiplier']) ? $config['max_spare_cpu_multiplier'] : 4;
        $maxRequests = isset($config['max_requests']) ? $config['max_requests'] : 500;
        $requestTerminateTimeout = isset($config['request_terminate_timeout']) ? $config['request_terminate_timeout'] : 30;
        $requestSlowlogTimeout = isset($config['request_slowlog_timeout']) ? $config['request_slowlog_timeout'] : 5;

        // 計算 max_children
        $memoryReserve = round($memoryReserveRatio * $freeMemory);
        $maxChildren = floor(($freeMemory - $memoryReserve) / $workerMemory);

        // 記憶體不足處理
        if ($maxChildren < 1) {
            return [
                'error' => true,
                'message' => '可用記憶體不足，無法啟動 PHP-FPM worker',
                'details' => [
                    'free_memory' => $freeMemory,
                    'worker_memory' => $workerMemory,
                    'memory_reserve_ratio' => $memoryReserveRatio,
                ],
            ];
        }

        // 計算 spare servers 參數
        $minSpareServers = min(
            round($minSpareRatio * $maxChildren),
            $cpuCores * $minSpareCpuMultiplier
        );
        $maxSpareServers = min(
            round($maxSpareRatio * $maxChildren),
            $cpuCores * $maxSpareCpuMultiplier
        );
        $startServers = min(
            round($startServersRatio * $maxChildren),
            $cpuCores * $startServersCpuMultiplier
        );

        // 確保邏輯一致性
        $params = self::validateParams([
            'max_children' => $maxChildren,
            'start_servers' => $startServers,
            'min_spare_servers' => $minSpareServers,
            'max_spare_servers' => $maxSpareServers,
            'max_requests' => $maxRequests,
            'request_terminate_timeout' => $requestTerminateTimeout,
            'request_slowlog_timeout' => $requestSlowlogTimeout,
        ]);

        $params['error'] = false;
        $params['memory_reserve'] = $memoryReserve;
        $params['memory_reserve_ratio'] = $memoryReserveRatio;

        return $params;
    }

    /**
     * 驗證並修正參數邏輯一致性
     *
     * 確保：min_spare <= start <= max_spare <= max_children
     *
     * @param array $params 參數陣列
     * @return array 修正後的參數
     */
    public static function validateParams(array $params)
    {
        $maxChildren = $params['max_children'];
        $minSpare = max(1, $params['min_spare_servers']);
        $maxSpare = max($minSpare, $params['max_spare_servers']);
        $start = max($minSpare, min($params['start_servers'], $maxSpare));

        // 確保不超過 max_children
        $maxSpare = min($maxSpare, $maxChildren);
        $start = min($start, $maxSpare);

        $params['min_spare_servers'] = $minSpare;
        $params['max_spare_servers'] = $maxSpare;
        $params['start_servers'] = $start;

        return $params;
    }
}
