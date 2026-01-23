<?php

/**
 * 指標收集類別
 *
 * 從 PHP-FPM status 端點收集指標並輸出到 CSV
 */
class Collector
{
    /**
     * CSV 標題列
     */
    const CSV_HEADERS = [
        'timestamp',
        'pool',
        'active',
        'idle',
        'total',
        'listen_queue',
        'max_listen_queue',
        'max_active',
        'max_children_reached',
        'slow_requests',
        'memory_mb',
        'avg_worker_mb',
    ];

    /**
     * 從 PHP-FPM status 端點取得指標
     *
     * @param string $statusUrl PHP-FPM status URL（需帶 ?json）
     * @return array|null 指標陣列或 null（失敗時）
     */
    public static function fetch($statusUrl)
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($statusUrl, false, $context);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        // 取得 worker 記憶體統計
        $memoryStats = self::getWorkerMemoryStats();

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'pool' => isset($data['pool']) ? $data['pool'] : 'unknown',
            'active' => isset($data['active processes']) ? (int) $data['active processes'] : 0,
            'idle' => isset($data['idle processes']) ? (int) $data['idle processes'] : 0,
            'total' => isset($data['total processes']) ? (int) $data['total processes'] : 0,
            'listen_queue' => isset($data['listen queue']) ? (int) $data['listen queue'] : 0,
            'max_listen_queue' => isset($data['max listen queue']) ? (int) $data['max listen queue'] : 0,
            'max_active' => isset($data['max active processes']) ? (int) $data['max active processes'] : 0,
            'max_children_reached' => isset($data['max children reached']) ? (int) $data['max children reached'] : 0,
            'slow_requests' => isset($data['slow requests']) ? (int) $data['slow requests'] : 0,
            'memory_mb' => $memoryStats['total_mb'],
            'avg_worker_mb' => $memoryStats['avg_mb'],
        ];
    }

    /**
     * 從 ps 取得 PHP-FPM worker 記憶體統計
     *
     * @return array ['total_mb' => int, 'avg_mb' => int, 'count' => int]
     */
    public static function getWorkerMemoryStats()
    {
        $result = ['total_mb' => 0, 'avg_mb' => 0, 'count' => 0];

        if (PHP_OS_FAMILY === 'Windows') {
            return $result;
        }

        $psOutput = shell_exec('ps -eo size,command 2>/dev/null');
        if (!$psOutput) {
            return $result;
        }

        if (preg_match_all('~(\d+).*php-fpm: pool~', $psOutput, $matches, PREG_PATTERN_ORDER)) {
            $count = count($matches[1]);
            if ($count > 0) {
                $totalKb = array_sum($matches[1]);
                $result['count'] = $count;
                $result['total_mb'] = round($totalKb / 1024);
                $result['avg_mb'] = round($totalKb / $count / 1024);
            }
        }

        return $result;
    }

    /**
     * 將指標追加到 CSV 檔案
     *
     * @param string $path CSV 檔案路徑
     * @param array $metrics 指標陣列
     * @return bool 是否成功
     */
    public static function appendToCsv($path, array $metrics)
    {
        $writeHeader = !file_exists($path) || filesize($path) === 0;

        // 確保目錄存在
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                fwrite(STDERR, "錯誤：無法建立目錄 {$dir}\n");
                return false;
            }
        }

        $fp = @fopen($path, 'a');
        if (!$fp) {
            fwrite(STDERR, "錯誤：無法開啟檔案 {$path}\n");
            return false;
        }

        // 寫入標題列
        if ($writeHeader) {
            fputcsv($fp, self::CSV_HEADERS);
        }

        // 寫入數據列
        $row = [];
        foreach (self::CSV_HEADERS as $header) {
            $row[] = isset($metrics[$header]) ? $metrics[$header] : '';
        }
        fputcsv($fp, $row);

        fclose($fp);
        return true;
    }
}
