<?php

/**
 * 優化建議類別
 *
 * 根據監控數據和評估結果，計算具體的 PHP-FPM 設定建議值
 */
class Optimizer
{
    const MIN_SAMPLES = 60;
    const MAX_STEP_RATIO = 0.25;

    /**
     * 計算優化建議
     *
     * @param array $stats Analyzer::calculateStats() 結果
     * @param array $timeSeries Analyzer::analyzeTimeSeries() 結果
     * @param array $systemInfo SystemInfo::collect() 結果
     * @param array $currentConfig 當前配置
     * @return array 優化建議
     */
    public static function optimize(
        array $stats,
        array $timeSeries,
        array $systemInfo,
        array $currentConfig
    ) {
        if (isset($stats['error']) && $stats['error']) {
            return ['error' => true, 'message' => $stats['message']];
        }

        // 信心度判定
        $quality = $timeSeries['data_quality'];
        $confidence = self::assessConfidence($quality);

        // 取得當前值
        $currentMaxChildren = isset($currentConfig['max_children']) ? (int) $currentConfig['max_children'] : $stats['total_max'];
        $currentStartServers = isset($currentConfig['start_servers']) ? (int) $currentConfig['start_servers'] : 0;
        $currentMinSpare = isset($currentConfig['min_spare_servers']) ? (int) $currentConfig['min_spare_servers'] : 1;
        $currentMaxSpare = isset($currentConfig['max_spare_servers']) ? (int) $currentConfig['max_spare_servers'] : 0;

        // 計算限制
        $workerMemory = max(1, $systemInfo['worker_memory']);
        $cpuCores = max(1, $systemInfo['cpu_cores']);
        $memoryReserve = isset($currentConfig['memory_reserve_ratio']) ? $currentConfig['memory_reserve_ratio'] : 0.20;

        $baseMemory = Calculator::resolveBaseMemory($systemInfo, $currentConfig);

        $memoryLimit = (int) floor($baseMemory * (1 - $memoryReserve) / $workerMemory);
        $cpuLimit = $cpuCores * 8;

        // 計算 max_children 目標
        $peakStats = $timeSeries['peak_stats'];
        $offpeakStats = $timeSeries['offpeak_stats'];

        $p95Need = (int) ceil($stats['active_p95'] * 1.3);
        $peakNeed = (int) ceil($stats['active_max'] * 1.1);
        $target = max($p95Need, $peakNeed);

        // 佇列補償
        if ($stats['queue_occurred_ratio'] > 0.05) {
            $target += (int) ceil($stats['queue_avg_depth'] * 2);
        }

        // max_children_events 補償
        if ($stats['max_children_events'] > 0) {
            $target = max($target, (int) ceil($currentMaxChildren * 1.3));
        }

        $targetMaxChildren = min($target, $memoryLimit, $cpuLimit);
        $targetMaxChildren = max(2, $targetMaxChildren);

        // 計算 spare servers 目標
        $peakActive = $peakStats && !isset($peakStats['error']) ? $peakStats['active_avg'] : $stats['active_avg'];
        $offpeakActive = $offpeakStats && !isset($offpeakStats['error']) ? $offpeakStats['active_avg'] : $stats['active_avg'];

        $targetMinSpare = max(1, $cpuCores, (int) round($peakActive * 0.25));
        $targetMaxSpare = min((int) round($targetMaxChildren * 0.75), $cpuCores * 4);
        $targetMaxSpare = max($targetMinSpare, $targetMaxSpare);
        $targetStartServers = max($targetMinSpare, min((int) round($offpeakActive * 1.2), $targetMaxSpare));

        // 漸進式調整（最多 ±25%）
        $recMaxChildren = self::constrainStep($currentMaxChildren, $targetMaxChildren);
        $recMinSpare = self::constrainStep($currentMinSpare, $targetMinSpare);
        $recMaxSpare = self::constrainStep($currentMaxSpare, $targetMaxSpare);
        $recStartServers = self::constrainStep($currentStartServers, $targetStartServers);

        // 確保邏輯一致性（min_spare ≤ start ≤ max_spare ≤ max_children）
        $validated = Calculator::validateParams([
            'max_children' => $recMaxChildren,
            'start_servers' => $recStartServers,
            'min_spare_servers' => $recMinSpare,
            'max_spare_servers' => $recMaxSpare,
            'max_requests' => 0,
            'request_terminate_timeout' => 0,
            'request_slowlog_timeout' => 0,
        ]);
        $recMinSpare = $validated['min_spare_servers'];
        $recMaxSpare = $validated['max_spare_servers'];
        $recStartServers = $validated['start_servers'];

        $appliedLimit = $memoryLimit <= $cpuLimit ? 'memory' : 'cpu';
        if ($target <= min($memoryLimit, $cpuLimit)) {
            $appliedLimit = 'none';
        }

        // 構建變更列表
        $changes = [];
        $params = ['max_children', 'start_servers', 'min_spare_servers', 'max_spare_servers'];
        $currentVals = [$currentMaxChildren, $currentStartServers, $currentMinSpare, $currentMaxSpare];
        $recVals = [$recMaxChildren, $recStartServers, $recMinSpare, $recMaxSpare];
        $targetVals = [$targetMaxChildren, $targetStartServers, $targetMinSpare, $targetMaxSpare];
        $reasons = [
            self::maxChildrenReason($stats, $currentMaxChildren, $targetMaxChildren),
            sprintf('離峰平均 active %.1f', $offpeakActive),
            sprintf('峰值平均 active %.1f × 0.25', $peakActive),
            sprintf('配合目標 max_children %d × 0.75', $targetMaxChildren),
        ];

        for ($i = 0; $i < 4; $i++) {
            if ($recVals[$i] !== $currentVals[$i]) {
                $changes[] = [
                    'param' => $params[$i],
                    'from' => $currentVals[$i],
                    'to' => $recVals[$i],
                    'target' => $targetVals[$i],
                    'reason' => $reasons[$i],
                ];
            }
        }

        $reachedTarget = ($recMaxChildren === $targetMaxChildren);

        return [
            'error' => false,
            'recommended' => [
                'max_children' => $recMaxChildren,
                'start_servers' => $recStartServers,
                'min_spare_servers' => $recMinSpare,
                'max_spare_servers' => $recMaxSpare,
            ],
            'target' => [
                'max_children' => $targetMaxChildren,
                'start_servers' => $targetStartServers,
                'min_spare_servers' => $targetMinSpare,
                'max_spare_servers' => $targetMaxSpare,
            ],
            'current' => [
                'max_children' => $currentMaxChildren,
                'start_servers' => $currentStartServers,
                'min_spare_servers' => $currentMinSpare,
                'max_spare_servers' => $currentMaxSpare,
            ],
            'changes' => $changes,
            'constraints' => [
                'memory_limit' => $memoryLimit,
                'cpu_limit' => $cpuLimit,
                'applied_limit' => $appliedLimit,
                'base_memory_mb' => $baseMemory,
                'worker_memory_mb' => $workerMemory,
                'memory_reserve_ratio' => $memoryReserve,
            ],
            'confidence' => $confidence,
            'next_step' => $reachedTarget
                ? '套用建議值後觀察 24 小時，再次執行 optimize 確認效果'
                : '套用建議值後觀察 24 小時，再次執行 optimize 繼續調整至目標值',
        ];
    }

    /**
     * 漸進式調整（最多 ±25%）
     */
    private static function constrainStep($current, $target)
    {
        if ($current <= 0) {
            return $target;
        }

        $maxDelta = max(1, (int) round($current * self::MAX_STEP_RATIO));
        $delta = $target - $current;

        if (abs($delta) <= $maxDelta) {
            return $target;
        }

        return $current + ($delta > 0 ? $maxDelta : -$maxDelta);
    }

    /**
     * 評估信心度
     */
    private static function assessConfidence(array $quality)
    {
        $samples = $quality['total_samples'];
        $gapRatio = $samples > 0 ? $quality['gap_count'] / $samples : 1;

        if ($samples >= 1440 && $gapRatio < 0.05) {
            return 'high';
        } elseif ($samples >= self::MIN_SAMPLES && $gapRatio < 0.10) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * 產生 max_children 變更原因
     */
    private static function maxChildrenReason(array $stats, $current, $target)
    {
        $parts = [];
        if ($stats['utilization_avg'] > 0.7) {
            $parts[] = sprintf('利用率 %.0f%%', $stats['utilization_avg'] * 100);
        }
        if ($stats['queue_occurred_ratio'] > 0.05) {
            $parts[] = sprintf('佇列率 %.0f%%', $stats['queue_occurred_ratio'] * 100);
        }
        if ($stats['max_children_events'] > 0) {
            $parts[] = sprintf('觸及上限 %d 次', $stats['max_children_events']);
        }
        if ($stats['utilization_avg'] < 0.3 && $target < $current) {
            $parts[] = sprintf('利用率僅 %.0f%%', $stats['utilization_avg'] * 100);
        }

        return !empty($parts) ? implode(', ', $parts) : 'P95 active × 1.3';
    }
}
