<?php

/**
 * 配置分析類別
 *
 * 分析監控數據並計算 PES 分數、產生調整建議
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

        // 收集各指標
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

        // 計算基本統計
        $totalMax = max($total);
        $queueOccurred = count(array_filter($listenQueue, function ($v) { return $v > 0; }));
        $maxChildrenReachedTotal = max($maxChildrenReached);

        return [
            'error' => false,
            'sample_count' => $count,
            'period_start' => $metrics[0]['timestamp'],
            'period_end' => $metrics[$count - 1]['timestamp'],

            // 活躍度統計
            'active_avg' => round(array_sum($active) / $count, 1),
            'active_max' => max($active),
            'active_p95' => self::percentile($active, 95),

            // 閒置統計
            'idle_avg' => round(array_sum($idle) / $count, 1),
            'idle_min' => min($idle),

            // 總數統計
            'total_max' => $totalMax,

            // 佇列統計
            'queue_occurred_count' => $queueOccurred,
            'queue_occurred_ratio' => round($queueOccurred / $count, 4),
            'queue_max' => max($listenQueue),

            // Max children 統計
            'max_children_reached' => $maxChildrenReachedTotal,

            // 記憶體統計
            'avg_worker_mb' => !empty($avgWorkerMb) ? round(array_sum($avgWorkerMb) / count($avgWorkerMb), 1) : 0,

            // 利用率
            'utilization_avg' => $totalMax > 0 ? round(array_sum($active) / $count / $totalMax, 4) : 0,
            'utilization_max' => $totalMax > 0 ? round(max($active) / $totalMax, 4) : 0,
        ];
    }

    /**
     * 計算 PES (Parameter Efficiency Score)
     *
     * @param array $stats calculateStats() 的回傳值
     * @param array $currentConfig 當前配置（需包含 max_children）
     * @return array PES 分數和各項指標
     */
    public static function calculatePES(array $stats, array $currentConfig)
    {
        if (isset($stats['error']) && $stats['error']) {
            return ['error' => true, 'message' => $stats['message']];
        }

        $maxChildren = isset($currentConfig['max_children']) ? (int) $currentConfig['max_children'] : $stats['total_max'];

        // 1. 佇列發生率（權重 0.4）
        $queueRatio = $stats['queue_occurred_ratio'];
        $queueScore = 1 - $queueRatio;

        // 2. 利用率偏離度（權重 0.3，目標 50%）
        $utilization = $stats['utilization_avg'];
        $utilizationDeviation = abs($utilization - 0.5) * 2;
        $utilizationScore = 1 - $utilizationDeviation;

        // 3. Max children 觸及率（權重 0.2）
        $maxReachRate = $maxChildren > 0 ? $stats['max_children_reached'] / $maxChildren : 0;
        $maxReachScore = 1 - min(1, $maxReachRate);

        // 4. Spare 充足率（權重 0.1）
        $idleMin = $stats['idle_min'];
        $minSpare = isset($currentConfig['min_spare_servers']) ? (int) $currentConfig['min_spare_servers'] : 1;
        $spareScore = $idleMin >= $minSpare ? 1 : ($minSpare > 0 ? $idleMin / $minSpare : 0);

        // 計算加權總分
        $pes = $queueScore * 0.4 +
               $utilizationScore * 0.3 +
               $maxReachScore * 0.2 +
               $spareScore * 0.1;

        return [
            'error' => false,
            'pes_score' => round($pes, 4),
            'rating' => self::getRating($pes),
            'components' => [
                'queue_score' => round($queueScore, 4),
                'utilization_score' => round($utilizationScore, 4),
                'max_reach_score' => round($maxReachScore, 4),
                'spare_score' => round($spareScore, 4),
            ],
            'raw_values' => [
                'queue_ratio' => $queueRatio,
                'utilization_avg' => $utilization,
                'max_reach_rate' => round($maxReachRate, 4),
                'idle_min' => $idleMin,
            ],
        ];
    }

    /**
     * 產生調整建議
     *
     * @param array $stats 統計值
     * @param array $pes PES 計算結果
     * @param array $currentConfig 當前配置
     * @return array 建議列表
     */
    public static function generateSuggestions(array $stats, array $pes, array $currentConfig)
    {
        $suggestions = [];

        if (isset($stats['error']) && $stats['error']) {
            return $suggestions;
        }

        $maxChildren = isset($currentConfig['max_children']) ? (int) $currentConfig['max_children'] : 0;
        $minSpare = isset($currentConfig['min_spare_servers']) ? (int) $currentConfig['min_spare_servers'] : 1;

        // 檢查佇列問題
        if ($stats['queue_occurred_ratio'] > 0.05) {
            $suggestions[] = [
                'priority' => 'high',
                'type' => 'increase_capacity',
                'message' => sprintf(
                    '佇列發生率 %.1f%% 過高，建議增加 max_children',
                    $stats['queue_occurred_ratio'] * 100
                ),
                'action' => 'max_children += ' . max(5, round($maxChildren * 0.2)),
            ];
        }

        // 檢查 max_children_reached
        if ($stats['max_children_reached'] > 0) {
            $suggestions[] = [
                'priority' => 'high',
                'type' => 'max_children_reached',
                'message' => sprintf(
                    'max_children 已達上限 %d 次，需要增加容量或檢查記憶體配置',
                    $stats['max_children_reached']
                ),
                'action' => '檢查系統記憶體或減少 memory_reserve_ratio',
            ];
        }

        // 檢查利用率過低
        if ($stats['utilization_avg'] < 0.2 && $maxChildren > 10) {
            $suggestions[] = [
                'priority' => 'medium',
                'type' => 'over_provisioned',
                'message' => sprintf(
                    '平均利用率僅 %.1f%%，資源可能過度配置',
                    $stats['utilization_avg'] * 100
                ),
                'action' => '可考慮增加 memory_reserve_ratio 以釋放記憶體給其他服務',
            ];
        }

        // 檢查利用率過高
        if ($stats['utilization_avg'] > 0.8) {
            $suggestions[] = [
                'priority' => 'medium',
                'type' => 'high_utilization',
                'message' => sprintf(
                    '平均利用率 %.1f%% 偏高，突發流量可能導致佇列',
                    $stats['utilization_avg'] * 100
                ),
                'action' => '建議增加 max_children 或減少 memory_reserve_ratio',
            ];
        }

        // 檢查 spare servers 不足
        if ($stats['idle_min'] < $minSpare) {
            $suggestions[] = [
                'priority' => 'low',
                'type' => 'spare_shortage',
                'message' => sprintf(
                    '最低閒置 worker 數 (%d) 低於 min_spare_servers (%d)',
                    $stats['idle_min'],
                    $minSpare
                ),
                'action' => '可考慮降低 min_spare_servers 或增加 max_children',
            ];
        }

        // 無問題時
        if (empty($suggestions)) {
            $suggestions[] = [
                'priority' => 'info',
                'type' => 'optimal',
                'message' => '當前配置運作良好，無需調整',
                'action' => '維持現有配置',
            ];
        }

        return $suggestions;
    }

    /**
     * 完整分析（統合所有功能）
     *
     * @param array $metrics CSV 數據
     * @param array $currentConfig 當前配置
     * @return array 完整分析結果
     */
    public static function analyze(array $metrics, array $currentConfig)
    {
        $stats = self::calculateStats($metrics);

        if (isset($stats['error']) && $stats['error']) {
            return $stats;
        }

        $pes = self::calculatePES($stats, $currentConfig);
        $suggestions = self::generateSuggestions($stats, $pes, $currentConfig);

        return [
            'error' => false,
            'stats' => $stats,
            'pes' => $pes,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * 根據 PES 分數給出評級
     *
     * @param float $score PES 分數
     * @return string 評級
     */
    private static function getRating($score)
    {
        if ($score >= 0.9) {
            return '優秀';
        } elseif ($score >= 0.7) {
            return '良好';
        } elseif ($score >= 0.5) {
            return '需改善';
        } else {
            return '需緊急處理';
        }
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
}
