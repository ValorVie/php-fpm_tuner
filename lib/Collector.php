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

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            fwrite(STDERR, "錯誤：無法取得檔案鎖定 {$path}\n");
            return false;
        }

        // 重新檢查是否需要寫入標題（可能在等待鎖定期間被其他進程寫入）
        clearstatcache(true, $path);
        if ($writeHeader && filesize($path) > 0) {
            $writeHeader = false;
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

        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    /**
     * 清理過期數據
     *
     * @param string $path CSV 檔案路徑
     * @param array $config 配置（retention_hours, max_size_mb, max_rows）
     * @return array ['removed' => int, 'remaining' => int]
     */
    public static function pruneData($path, array $config)
    {
        $result = ['removed' => 0, 'remaining' => 0, 'action' => 'none'];

        if (!file_exists($path)) {
            return $result;
        }

        $retentionHours = isset($config['metrics_retention_hours']) ? (int) $config['metrics_retention_hours'] : 48;
        $maxSizeMb = isset($config['metrics_max_size_mb']) ? (int) $config['metrics_max_size_mb'] : 0;
        $maxRows = isset($config['metrics_max_rows']) ? (int) $config['metrics_max_rows'] : 0;

        // 快速檢查：若無任何限制，直接返回
        if ($retentionHours <= 0 && $maxSizeMb <= 0 && $maxRows <= 0) {
            return $result;
        }

        // 第一遍：計算總行數
        $fp = fopen($path, 'r');
        if (!$fp) {
            return $result;
        }

        $headers = fgetcsv($fp);
        if (!$headers) {
            fclose($fp);
            return $result;
        }

        $timestampIndex = array_search('timestamp', $headers);
        $cutoffTime = $retentionHours > 0 ? strtotime("-{$retentionHours} hours") : 0;

        $totalRows = 0;
        $expiredRows = 0;

        while (($row = fgetcsv($fp)) !== false) {
            if (count($row) !== count($headers)) {
                continue;
            }
            $totalRows++;

            if ($retentionHours > 0 && $timestampIndex !== false) {
                $rowTime = strtotime($row[$timestampIndex]);
                if ($rowTime !== false && $rowTime < $cutoffTime) {
                    $expiredRows++;
                }
            }
        }
        fclose($fp);

        // 計算要保留的行數
        $keepCount = $totalRows - $expiredRows;
        $action = $expiredRows > 0 ? 'time_limit' : 'none';

        if ($maxRows > 0 && $keepCount > $maxRows) {
            $keepCount = $maxRows;
            $action = 'row_limit';
        }

        if ($maxSizeMb > 0) {
            $currentSize = filesize($path);
            if ($currentSize > $maxSizeMb * 1024 * 1024) {
                $estimatedRowSize = $currentSize / ($totalRows + 1);
                $targetRows = (int) (($maxSizeMb * 1024 * 1024 * 0.8) / $estimatedRowSize);
                if ($keepCount > $targetRows) {
                    $keepCount = $targetRows;
                    $action = 'size_limit';
                }
            }
        }

        $removedCount = $totalRows - $keepCount;
        if ($removedCount <= 0) {
            return $result;
        }

        // 第二遍：流式寫入到暫存檔
        $skipCount = $totalRows - $keepCount;
        $tmpPath = $path . '.tmp.' . getmypid();

        $fpIn = fopen($path, 'r');
        if (!$fpIn) {
            return $result;
        }

        $fpOut = fopen($tmpPath, 'w');
        if (!$fpOut) {
            fclose($fpIn);
            return $result;
        }

        $headers = fgetcsv($fpIn);
        fputcsv($fpOut, $headers);

        $rowIndex = 0;
        $written = 0;
        while (($row = fgetcsv($fpIn)) !== false) {
            if (count($row) !== count($headers)) {
                continue;
            }
            $rowIndex++;

            if ($rowIndex <= $skipCount) {
                continue;
            }

            if ($retentionHours > 0 && $timestampIndex !== false) {
                $rowTime = strtotime($row[$timestampIndex]);
                if ($rowTime !== false && $rowTime < $cutoffTime) {
                    continue;
                }
            }

            fputcsv($fpOut, $row);
            $written++;
        }

        fclose($fpIn);
        fclose($fpOut);

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return $result;
        }

        $result['removed'] = $totalRows - $written;
        $result['remaining'] = $written;
        $result['action'] = $action;

        return $result;
    }

    /**
     * 取得 CSV 檔案統計資訊
     *
     * @param string $path CSV 檔案路徑
     * @return array 統計資訊
     */
    public static function getFileStats($path)
    {
        $stats = [
            'exists' => false,
            'size_bytes' => 0,
            'size_mb' => 0,
            'row_count' => 0,
            'oldest' => null,
            'newest' => null,
        ];

        if (!file_exists($path)) {
            return $stats;
        }

        $stats['exists'] = true;
        $stats['size_bytes'] = filesize($path);
        $stats['size_mb'] = round($stats['size_bytes'] / 1024 / 1024, 2);

        $fp = fopen($path, 'r');
        if (!$fp) {
            return $stats;
        }

        $headers = fgetcsv($fp);
        if (!$headers) {
            fclose($fp);
            return $stats;
        }

        $timestampIndex = array_search('timestamp', $headers);
        $firstTimestamp = null;
        $lastTimestamp = null;
        $rowCount = 0;

        while (($row = fgetcsv($fp)) !== false) {
            $rowCount++;
            if ($timestampIndex !== false && isset($row[$timestampIndex])) {
                if ($firstTimestamp === null) {
                    $firstTimestamp = $row[$timestampIndex];
                }
                $lastTimestamp = $row[$timestampIndex];
            }
        }
        fclose($fp);

        $stats['row_count'] = $rowCount;
        $stats['oldest'] = $firstTimestamp;
        $stats['newest'] = $lastTimestamp;

        return $stats;
    }
}
