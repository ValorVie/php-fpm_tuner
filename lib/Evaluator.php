<?php

/**
 * 評估類別
 *
 * 根據統計數據和時序分析進行 PES 評分和診斷
 * 只判斷「什麼有問題」，不給出具體設定建議（由 Optimizer 負責）
 */
class Evaluator
{
    /**
     * 完整評估
     *
     * @param array $stats Analyzer::calculateStats() 的回傳值
     * @param array $timeSeries Analyzer::analyzeTimeSeries() 的回傳值
     * @param array $currentConfig 當前配置
     * @return array 評估結果
     */
    public static function evaluate(array $stats, array $timeSeries, array $currentConfig)
    {
        if (isset($stats['error']) && $stats['error']) {
            return ['error' => true, 'message' => $stats['message']];
        }

        $pes = self::calculatePES($stats, $timeSeries, $currentConfig);
        $diagnostics = self::generateDiagnostics($stats, $currentConfig);

        // 時序洞察
        $insights = [];
        if ($timeSeries['trend']['direction'] === 'increasing' && $timeSeries['trend']['confidence'] > 0.3) {
            $insights[] = [
                'type' => 'trend_warning',
                'priority' => 'medium',
                'message' => sprintf('負載呈上升趨勢（每小時 +%.2f active workers，信心度 %.0f%%）',
                    $timeSeries['trend']['slope'], $timeSeries['trend']['confidence'] * 100),
            ];
        }

        $peakStats = $timeSeries['peak_stats'];
        if ($peakStats && !isset($peakStats['error']) && $peakStats['utilization_avg'] > 0.85) {
            $insights[] = [
                'type' => 'peak_warning',
                'priority' => 'high',
                'message' => sprintf('峰值時段利用率 %.1f%% 過高', $peakStats['utilization_avg'] * 100),
            ];
        }

        if ($stats['fpm_restarts'] > 0) {
            $insights[] = [
                'type' => 'restart_notice',
                'priority' => $stats['fpm_restarts'] >= 3 ? 'high' : 'low',
                'message' => sprintf('觀測期間偵測到 %d 次 PHP-FPM 重啟', $stats['fpm_restarts']),
            ];
        }

        return [
            'error' => false,
            'pes' => $pes,
            'diagnostics' => $diagnostics,
            'insights' => $insights,
        ];
    }

    /**
     * 計算 PES (Parameter Efficiency Score)
     *
     * @param array $stats 統計值
     * @param array $timeSeries 時序分析
     * @param array $currentConfig 當前配置
     * @return array PES 分數和各項指標
     */
    public static function calculatePES(array $stats, array $timeSeries, array $currentConfig)
    {
        $maxChildren = isset($currentConfig['max_children']) ? (int) $currentConfig['max_children'] : $stats['total_max'];

        // 1. 佇列控制（權重 0.35）
        $queueScore = max(0, 1 - $stats['queue_occurred_ratio']);

        // 2. 利用率（權重 0.30，非對稱）
        $utilizationScore = self::utilizationScore($stats['utilization_avg']);

        // 3. 容量充足（權重 0.25，基於 event_rate）
        $eventRate = $stats['max_children_event_rate'];
        $capacityScore = max(0, 1 - $eventRate * 10);

        // 4. Spare 充足率（權重 0.10）
        $idleMin = $stats['idle_min'];
        $minSpare = isset($currentConfig['min_spare_servers']) ? (int) $currentConfig['min_spare_servers'] : 1;
        $spareScore = $idleMin >= $minSpare ? 1 : ($minSpare > 0 ? $idleMin / $minSpare : 0);

        // 加權總分
        $pes = $queueScore * 0.35
             + $utilizationScore * 0.30
             + $capacityScore * 0.25
             + $spareScore * 0.10;

        // 時序調整
        if ($timeSeries['trend']['direction'] === 'increasing' && $timeSeries['trend']['confidence'] > 0.5) {
            $pes *= 0.9;
        }

        $peakStats = $timeSeries['peak_stats'];
        if ($peakStats && !isset($peakStats['error']) && $peakStats['utilization_avg'] > 0.85) {
            $pes *= 0.95;
        }

        $pes = round(max(0, min(1, $pes)), 4);

        return [
            'pes_score' => $pes,
            'rating' => self::getRating($pes),
            'components' => [
                'queue_score' => round($queueScore, 4),
                'utilization_score' => round($utilizationScore, 4),
                'capacity_score' => round($capacityScore, 4),
                'spare_score' => round($spareScore, 4),
            ],
        ];
    }

    /**
     * 產生診斷（只描述問題，不給具體建議值）
     *
     * @param array $stats 統計值
     * @param array $currentConfig 當前配置
     * @return array 診斷列表
     */
    public static function generateDiagnostics(array $stats, array $currentConfig)
    {
        $diagnostics = [];
        $maxChildren = isset($currentConfig['max_children']) ? (int) $currentConfig['max_children'] : 0;
        $minSpare = isset($currentConfig['min_spare_servers']) ? (int) $currentConfig['min_spare_servers'] : 1;

        if ($stats['queue_occurred_ratio'] > 0.05) {
            $diagnostics[] = [
                'priority' => 'high',
                'type' => 'queue_frequent',
                'message' => sprintf('佇列發生率 %.1f%% 過高', $stats['queue_occurred_ratio'] * 100),
            ];
        }

        if ($stats['max_children_events'] > 0) {
            $diagnostics[] = [
                'priority' => 'high',
                'type' => 'capacity_reached',
                'message' => sprintf('max_children 在觀測期間觸及 %d 次', $stats['max_children_events']),
            ];
        }

        if ($stats['utilization_avg'] > 0.8) {
            $diagnostics[] = [
                'priority' => 'medium',
                'type' => 'high_utilization',
                'message' => sprintf('平均利用率 %.1f%% 偏高', $stats['utilization_avg'] * 100),
            ];
        }

        if ($stats['utilization_avg'] < 0.2 && $maxChildren > 10) {
            $diagnostics[] = [
                'priority' => 'medium',
                'type' => 'over_provisioned',
                'message' => sprintf('平均利用率僅 %.1f%%，資源可能過度配置', $stats['utilization_avg'] * 100),
            ];
        }

        if ($stats['idle_min'] < $minSpare) {
            $diagnostics[] = [
                'priority' => 'low',
                'type' => 'spare_shortage',
                'message' => sprintf('最低閒置 worker (%d) 低於 min_spare_servers (%d)', $stats['idle_min'], $minSpare),
            ];
        }

        if (empty($diagnostics)) {
            $diagnostics[] = [
                'priority' => 'info',
                'type' => 'optimal',
                'message' => '當前配置運作良好',
            ];
        }

        return $diagnostics;
    }

    /**
     * 非對稱利用率計分
     *
     * 40-70% 為理想區間，允許偏高但懲罰極端值
     *
     * @param float $util 利用率 (0-1)
     * @return float 分數 (0-1)
     */
    private static function utilizationScore($util)
    {
        if ($util <= 0.4) {
            return 0.5 + ($util / 0.4) * 0.5;
        } elseif ($util <= 0.7) {
            return 1.0;
        } elseif ($util <= 0.9) {
            return 1.0 - ($util - 0.7) / 0.2 * 0.5;
        } else {
            return max(0, 0.5 - ($util - 0.9) / 0.1 * 0.5);
        }
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
}
