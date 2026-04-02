<?php

/**
 * 統計分析類別
 *
 * 從 CSV 數據計算統計指標和時序分析（純客觀事實，不含評分判斷）
 */
class Analyzer
{
    /**
     * 載入並解析 CSV 檔案
     *
     * @param string $path CSV 檔案路徑
     * @return array 指標陣列（每行一個陣列）
     */
    public static function loadCsv($path)
    {
        if (!file_exists($path)) {
            fwrite(STDERR, "錯誤：檔案不存在 {$path}\n");
            return [];
        }

        $fp = fopen($path, 'r');
        if (!$fp) {
            fwrite(STDERR, "錯誤：無法開啟檔案 {$path}\n");
            return [];
        }

        $headers = fgetcsv($fp);
        if (!$headers) {
            fclose($fp);
            return [];
        }

        $data = [];
        while (($row = fgetcsv($fp)) !== false) {
            if (count($row) === count($headers)) {
                $data[] = array_combine($headers, $row);
            }
        }

        fclose($fp);
        return $data;
    }

    /**
     * 計算統計指標
     *
     * @param array $metrics loadCsv() 的回傳值
     * @return array 統計值
     */
    public static function calculateStats(array $metrics)
    {
        if (empty($metrics)) {
            return [
                'error' => true,
                'message' => '無數據可分析',
            ];
        }

        $count = count($metrics);

        $active = [];
        $idle = [];
        $total = [];
        $listenQueue = [];
        $maxActive = [];
        $maxChildrenReached = [];
        $avgWorkerMb = [];

        foreach ($metrics as $row) {
            $active[] = (int) $row['active'];
            $idle[] = (int) $row['idle'];
            $total[] = (int) $row['total'];
            $listenQueue[] = (int) $row['listen_queue'];
            $maxActive[] = (int) $row['max_active'];
            $maxChildrenReached[] = (int) $row['max_children_reached'];
            if (isset($row['avg_worker_mb']) && $row['avg_worker_mb'] > 0) {
                $avgWorkerMb[] = (float) $row['avg_worker_mb'];
            }
        }

        // max_children_reached delta 追蹤
        $maxChildrenEvents = 0;
        $fpmRestarts = 0;
        for ($i = 1; $i < $count; $i++) {
            $delta = $maxChildrenReached[$i] - $maxChildrenReached[$i - 1];
            if ($delta > 0) {
                $maxChildrenEvents += $delta;
            } elseif ($delta < 0) {
                $fpmRestarts++;
            }
        }

        $totalMax = max($total);
        $queueOccurred = count(array_filter($listenQueue, function ($v) { return $v > 0; }));
        $queueValues = array_filter($listenQueue, function ($v) { return $v > 0; });
        $avgQueueDepth = !empty($queueValues) ? round(array_sum($queueValues) / count($queueValues), 1) : 0;

        return [
            'error' => false,
            'sample_count' => $count,
            'period_start' => $metrics[0]['timestamp'],
            'period_end' => $metrics[$count - 1]['timestamp'],

            'active_avg' => round(array_sum($active) / $count, 1),
            'active_max' => max($active),
            'active_p95' => self::percentile($active, 95),

            'idle_avg' => round(array_sum($idle) / $count, 1),
            'idle_min' => min($idle),

            'total_max' => $totalMax,

            'queue_occurred_count' => $queueOccurred,
            'queue_occurred_ratio' => round($queueOccurred / $count, 4),
            'queue_max' => max($listenQueue),
            'queue_avg_depth' => $avgQueueDepth,

            'max_children_events' => $maxChildrenEvents,
            'max_children_event_rate' => $count > 1 ? round($maxChildrenEvents / ($count - 1), 4) : 0,
            'fpm_restarts' => $fpmRestarts,

            'avg_worker_mb' => !empty($avgWorkerMb) ? round(array_sum($avgWorkerMb) / count($avgWorkerMb), 1) : 0,

            'utilization_avg' => self::calculateAvgUtilization($active, $total),
            'utilization_max' => $totalMax > 0 ? round(max($active) / $totalMax, 4) : 0,
        ];
    }

    /**
     * 計算百分位數
     *
     * @param array $data 數據陣列
     * @param int $percentile 百分位（1-99）
     * @return float
     */
    private static function percentile(array $data, $percentile)
    {
        sort($data);
        $count = count($data);
        $index = ($percentile / 100) * ($count - 1);
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower === $upper) {
            return $data[$lower];
        }

        return $data[$lower] + ($index - $lower) * ($data[$upper] - $data[$lower]);
    }

    /**
     * 計算平均利用率（逐採樣點）
     *
     * @param array $active 各時間點的活躍 worker 數
     * @param array $total 各時間點的總 worker 數
     * @return float
     */
    private static function calculateAvgUtilization(array $active, array $total)
    {
        $count = count($active);
        if ($count === 0) {
            return 0;
        }

        $sum = 0;
        $validCount = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($total[$i] > 0) {
                $sum += $active[$i] / $total[$i];
                $validCount++;
            }
        }

        return $validCount > 0 ? round($sum / $validCount, 4) : 0;
    }

    /**
     * 時序分析
     *
     * @param array $metrics loadCsv() 的回傳值
     * @return array 時序分析結果
     */
    public static function analyzeTimeSeries(array $metrics)
    {
        if (count($metrics) < 2) {
            return [
                'peak_hours' => [],
                'peak_stats' => null,
                'offpeak_stats' => null,
                'trend' => ['direction' => 'stable', 'slope' => 0, 'confidence' => 0],
                'data_quality' => self::assessDataQuality($metrics),
            ];
        }

        $peakResult = self::detectPeakHours($metrics);
        $peakHoursMap = array_flip($peakResult['hours']);
        $peakRows = [];
        $offpeakRows = [];

        foreach ($metrics as $row) {
            $hour = (int) date('G', strtotime($row['timestamp']));
            if (isset($peakHoursMap[$hour])) {
                $peakRows[] = $row;
            } else {
                $offpeakRows[] = $row;
            }
        }

        return [
            'peak_hours' => $peakResult['hours'],
            'peak_stats' => !empty($peakRows) ? self::calculateStats($peakRows) : null,
            'offpeak_stats' => !empty($offpeakRows) ? self::calculateStats($offpeakRows) : null,
            'trend' => self::detectTrend($metrics),
            'data_quality' => self::assessDataQuality($metrics),
        ];
    }

    /**
     * 偵測峰值時段
     *
     * @param array $metrics
     * @return array ['hours' => [...], 'threshold' => float]
     */
    private static function detectPeakHours(array $metrics)
    {
        $hourlyUtil = [];

        foreach ($metrics as $row) {
            $hour = (int) date('G', strtotime($row['timestamp']));
            $total = (int) $row['total'];
            $active = (int) $row['active'];

            if (!isset($hourlyUtil[$hour])) {
                $hourlyUtil[$hour] = ['sum' => 0, 'count' => 0];
            }
            $hourlyUtil[$hour]['sum'] += $total > 0 ? $active / $total : 0;
            $hourlyUtil[$hour]['count']++;
        }

        $threshold = 0.4;
        $peakHours = [];

        foreach ($hourlyUtil as $hour => $data) {
            $avg = $data['count'] > 0 ? $data['sum'] / $data['count'] : 0;
            if ($avg > $threshold) {
                $peakHours[] = $hour;
            }
        }

        sort($peakHours);
        return ['hours' => $peakHours, 'threshold' => $threshold];
    }

    /**
     * 偵測趨勢（簡單線性迴歸）
     *
     * @param array $metrics
     * @return array ['direction' => string, 'slope' => float, 'confidence' => float]
     */
    private static function detectTrend(array $metrics)
    {
        $n = count($metrics);
        if ($n < 10) {
            return ['direction' => 'stable', 'slope' => 0, 'confidence' => 0];
        }

        $startTime = strtotime($metrics[0]['timestamp']);

        // 預先計算所有時間和值，避免重複 strtotime()
        $xVals = [];
        $yVals = [];
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $x = (strtotime($metrics[$i]['timestamp']) - $startTime) / 3600;
            $y = (int) $metrics[$i]['active'];
            $xVals[$i] = $x;
            $yVals[$i] = $y;
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = $n * $sumX2 - $sumX * $sumX;
        if ($denominator == 0) {
            return ['direction' => 'stable', 'slope' => 0, 'confidence' => 0];
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;
        $intercept = ($sumY - $slope * $sumX) / $n;
        $meanY = $sumY / $n;

        $ssRes = 0;
        $ssTot = 0;
        for ($i = 0; $i < $n; $i++) {
            $predicted = $intercept + $slope * $xVals[$i];
            $ssRes += ($yVals[$i] - $predicted) * ($yVals[$i] - $predicted);
            $ssTot += ($yVals[$i] - $meanY) * ($yVals[$i] - $meanY);
        }

        $rSquared = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 0;

        $direction = 'stable';
        if ($rSquared > 0.3) {
            $direction = $slope > 0.1 ? 'increasing' : ($slope < -0.1 ? 'decreasing' : 'stable');
        }

        return [
            'direction' => $direction,
            'slope' => round($slope, 4),
            'confidence' => round(max(0, $rSquared), 4),
        ];
    }

    /**
     * 評估資料品質
     *
     * @param array $metrics
     * @return array
     */
    private static function assessDataQuality(array $metrics)
    {
        $count = count($metrics);
        $result = [
            'total_samples' => $count,
            'expected_samples' => 0,
            'gap_count' => 0,
            'avg_interval_seconds' => 0,
            'stale' => false,
        ];

        if ($count < 2) {
            return $result;
        }

        $firstTime = strtotime($metrics[0]['timestamp']);
        $lastTime = strtotime($metrics[$count - 1]['timestamp']);
        $totalSeconds = $lastTime - $firstTime;

        if ($totalSeconds <= 0) {
            return $result;
        }

        $avgInterval = $totalSeconds / ($count - 1);
        $result['avg_interval_seconds'] = round($avgInterval);
        $result['expected_samples'] = $avgInterval > 0 ? (int) round($totalSeconds / $avgInterval) + 1 : $count;

        $gapCount = 0;
        for ($i = 1; $i < $count; $i++) {
            $interval = strtotime($metrics[$i]['timestamp']) - strtotime($metrics[$i - 1]['timestamp']);
            if ($interval > $avgInterval * 2.5) {
                $gapCount++;
            }
        }
        $result['gap_count'] = $gapCount;

        $result['stale'] = (time() - $lastTime) > $avgInterval * 3;

        return $result;
    }
}
