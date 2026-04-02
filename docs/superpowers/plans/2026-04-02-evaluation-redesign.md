# 評估系統重新設計 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 修復 6 個根本性設計缺陷，建立完整的監控→評估→優化三階段架構，解耦 Analyzer/Evaluator/Optimizer

**Architecture:** 修改 7 個現有檔案 + 新增 4 個檔案（FcgiClient.php, Evaluator.php, Optimizer.php, bin/optimize）。Analyzer 保留純統計職責，Evaluator 負責評分診斷，Optimizer 負責資料驅動的設定建議。

**Tech Stack:** PHP 7.1+, CLI, FCGI protocol, CSV I/O

---

## File Structure

| 檔案 | 動作 | 職責 |
|------|------|------|
| `lib/SystemInfo.php` | 修改 | +getTotalMemory(), getWorkerMemory 改 RSS |
| `lib/Calculator.php` | 修改 | 支援 memory_mode=total |
| `lib/Analyzer.php` | 修改 | 移除 PES/建議，+delta 追蹤，+時序分析 |
| `lib/Evaluator.php` | 新增 | PES 評分 + 診斷（從 Analyzer 提取+修正） |
| `lib/FcgiClient.php` | 新增 | 最小 FCGI 協議客戶端 |
| `lib/Collector.php` | 修改 | +FCGI socket 支援，RSS 修正 |
| `lib/Optimizer.php` | 新增 | 資料驅動的設定建議 |
| `lib/bootstrap.php` | 修改 | 載入新模組 |
| `config/default.php` | 修改 | +memory_mode, +fpm_status_path |
| `bin/optimize` | 新增 | 優化建議入口 |
| `bin/analyze` | 修改 | 改用 Evaluator，引導至 optimize |

---

### Task 1: SystemInfo — RSS 修正 + getTotalMemory()

**Files:**
- Modify: `lib/SystemInfo.php`

- [ ] **Step 1: 修改 getWorkerMemory() 使用 RSS**

在 `lib/SystemInfo.php` 第 110 行，將：
```php
$psOutput = shell_exec('ps -eo size,command 2>/dev/null');
```
改為：
```php
$psOutput = shell_exec('ps -eo rss,command 2>/dev/null');
```

- [ ] **Step 2: 新增 getTotalMemory() 方法**

在 `getWorkerMemory()` 方法之前（第 98 行之後）插入：

```php
    /**
     * 取得系統總記憶體 (MB)
     *
     * @return int
     */
    public static function getTotalMemory()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('wmic ComputerSystem get TotalPhysicalMemory 2>nul');
            if ($output && preg_match('~(\d{6,})~', $output, $matches)) {
                return (int) round((float) $matches[1] / 1024 / 1024);
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $bytes = shell_exec('sysctl -n hw.memsize');
            if ($bytes) {
                return (int) round((float) trim($bytes) / 1024 / 1024);
            }
        } else {
            $meminfo = shell_exec('cat /proc/meminfo');
            if ($meminfo && preg_match('~MemTotal:\s+(\d+)\s+~', $meminfo, $matches)) {
                return (int) round((int) $matches[1] / 1024);
            }
        }

        return 0;
    }

```

- [ ] **Step 3: 更新 collect() 回傳值**

將 `collect()` 方法（第 16-25 行）替換為：

```php
    public static function collect(array $config = [])
    {
        $minWorkerMemory = isset($config['min_worker_memory']) ? $config['min_worker_memory'] : 32;

        return [
            'cpu_cores' => self::getCpuCores(),
            'total_memory' => self::getTotalMemory(),
            'free_memory' => self::getFreeMemory(),
            'worker_memory' => self::getWorkerMemory($minWorkerMemory),
        ];
    }
```

- [ ] **Step 4: 語法檢查**

Run: `php -l lib/SystemInfo.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add lib/SystemInfo.php
git commit -m "fix(systeminfo): 改用 RSS 記憶體指標，新增 getTotalMemory()"
```

---

### Task 2: Collector — RSS 修正

**Files:**
- Modify: `lib/Collector.php`

- [ ] **Step 1: 修改 getWorkerMemoryStats() 使用 RSS**

在 `lib/Collector.php` 第 85 行，將：
```php
        $psOutput = shell_exec('ps -eo size,command 2>/dev/null');
```
改為：
```php
        $psOutput = shell_exec('ps -eo rss,command 2>/dev/null');
```

- [ ] **Step 2: 語法檢查**

Run: `php -l lib/Collector.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/Collector.php
git commit -m "fix(collector): getWorkerMemoryStats 改用 RSS 記憶體指標"
```

---

### Task 3: Calculator — 支援 memory_mode + Config 更新

**Files:**
- Modify: `lib/Calculator.php`
- Modify: `config/default.php`

- [ ] **Step 1: 修改 Calculator::calculate() 支援 memory_mode**

將 `lib/Calculator.php` 的 `calculate()` 方法中第 19-20 行的記憶體取得邏輯：
```php
        $cpuCores = $systemInfo['cpu_cores'];
        $freeMemory = $systemInfo['free_memory'];
        $workerMemory = $systemInfo['worker_memory'];
```
替換為：
```php
        $cpuCores = $systemInfo['cpu_cores'];
        $workerMemory = $systemInfo['worker_memory'];

        // 根據 memory_mode 決定使用總記憶體或可用記憶體
        $memoryMode = isset($config['memory_mode']) ? $config['memory_mode'] : 'total';
        if ($memoryMode === 'total' && isset($systemInfo['total_memory']) && $systemInfo['total_memory'] > 0) {
            $baseMemory = $systemInfo['total_memory'];
        } else {
            $baseMemory = $systemInfo['free_memory'];
        }
```

然後將第 36-37 行：
```php
        $memoryReserve = round($memoryReserveRatio * $freeMemory);
        $maxChildren = floor(($freeMemory - $memoryReserve) / $workerMemory);
```
替換為：
```php
        $memoryReserve = round($memoryReserveRatio * $baseMemory);
        $maxChildren = floor(($baseMemory - $memoryReserve) / $workerMemory);
```

並將錯誤回報中的 `'free_memory' => $freeMemory` 改為 `'base_memory' => $baseMemory`。

- [ ] **Step 2: 在 config/default.php 新增 memory_mode 和 fpm_status_path**

在 `config/default.php` 的 `memory_reserve_ratio` 之前（第 14 行之後）插入：

```php
    /**
     * 記憶體計算模式
     *
     * - 'total'：使用系統總記憶體（推薦，結果穩定可重現）
     * - 'available'：使用當前可用記憶體（舊行為，結果隨系統狀態波動）
     */
    'memory_mode' => 'total',

```

在 `fpm_status_url` 設定之後（第 101 行之後）插入：

```php

    /**
     * PHP-FPM status 路徑（FCGI 模式用）
     *
     * 透過 Unix socket 或 TCP 直連 PHP-FPM 時需要此設定
     */
    'fpm_status_path' => '/fpm-status',
```

- [ ] **Step 3: 語法檢查**

Run: `php -l lib/Calculator.php && php -l config/default.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add lib/Calculator.php config/default.php
git commit -m "feat(calculator): 支援 memory_mode=total 使用總記憶體計算"
```

---

### Task 4: Analyzer — Delta 追蹤 + 移除評估邏輯

**Files:**
- Modify: `lib/Analyzer.php`

- [ ] **Step 1: 修改 calculateStats() 加入 delta 追蹤，移除舊的 max_children_reached**

將 `lib/Analyzer.php` 的 `calculateStats()` 方法（第 52-122 行）整個替換為：

```php
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

        // 計算基本統計
        $totalMax = max($total);
        $queueOccurred = count(array_filter($listenQueue, function ($v) { return $v > 0; }));
        $queueValues = array_filter($listenQueue, function ($v) { return $v > 0; });
        $avgQueueDepth = !empty($queueValues) ? round(array_sum($queueValues) / count($queueValues), 1) : 0;

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
            'queue_avg_depth' => $avgQueueDepth,

            // Max children 統計（delta-based）
            'max_children_events' => $maxChildrenEvents,
            'max_children_event_rate' => $count > 1 ? round($maxChildrenEvents / ($count - 1), 4) : 0,
            'fpm_restarts' => $fpmRestarts,

            // 記憶體統計
            'avg_worker_mb' => !empty($avgWorkerMb) ? round(array_sum($avgWorkerMb) / count($avgWorkerMb), 1) : 0,

            // 利用率（逐採樣點計算）
            'utilization_avg' => self::calculateAvgUtilization($active, $total),
            'utilization_max' => $totalMax > 0 ? round(max($active) / $totalMax, 4) : 0,
        ];
    }
```

- [ ] **Step 2: 移除 calculatePES, generateSuggestions, analyze, getRating 方法**

刪除 `lib/Analyzer.php` 中以下四個方法（第 124-323 行，即 `calculatePES()`, `generateSuggestions()`, `analyze()`, `getRating()`）。保留 `percentile()` 和 `calculateAvgUtilization()`。

刪除後，類別的 docblock 也需要更新：
```php
/**
 * 統計分析類別
 *
 * 從 CSV 數據計算統計指標和時序分析（純客觀事實，不含評分判斷）
 */
```

- [ ] **Step 3: 語法檢查**

Run: `php -l lib/Analyzer.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add lib/Analyzer.php
git commit -m "refactor(analyzer): delta 追蹤 max_children_reached，移除評估邏輯至 Evaluator"
```

---

### Task 5: Analyzer — 時序分析方法

**Files:**
- Modify: `lib/Analyzer.php`

- [ ] **Step 1: 在 calculateAvgUtilization() 之後新增時序分析方法**

在 `lib/Analyzer.php` 的 `calculateAvgUtilization()` 方法之後、類別結尾 `}` 之前，插入：

```php

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

        // 峰值偵測
        $peakResult = self::detectPeakHours($metrics);
        $peakRows = [];
        $offpeakRows = [];

        foreach ($metrics as $row) {
            $hour = (int) date('G', strtotime($row['timestamp']));
            if (in_array($hour, $peakResult['hours'])) {
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
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;
        $sumY2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $x = (strtotime($metrics[$i]['timestamp']) - $startTime) / 3600; // 小時
            $y = (int) $metrics[$i]['active'];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
            $sumY2 += $y * $y;
        }

        $denominator = $n * $sumX2 - $sumX * $sumX;
        if ($denominator == 0) {
            return ['direction' => 'stable', 'slope' => 0, 'confidence' => 0];
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;

        // R² 計算
        $ssRes = 0;
        $meanY = $sumY / $n;
        $ssTot = 0;
        $intercept = ($sumY - $slope * $sumX) / $n;

        for ($i = 0; $i < $n; $i++) {
            $x = (strtotime($metrics[$i]['timestamp']) - $startTime) / 3600;
            $y = (int) $metrics[$i]['active'];
            $predicted = $intercept + $slope * $x;
            $ssRes += ($y - $predicted) * ($y - $predicted);
            $ssTot += ($y - $meanY) * ($y - $meanY);
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

        // 計算平均間隔
        $firstTime = strtotime($metrics[0]['timestamp']);
        $lastTime = strtotime($metrics[$count - 1]['timestamp']);
        $totalSeconds = $lastTime - $firstTime;

        if ($totalSeconds <= 0) {
            return $result;
        }

        $avgInterval = $totalSeconds / ($count - 1);
        $result['avg_interval_seconds'] = round($avgInterval);
        $result['expected_samples'] = $avgInterval > 0 ? (int) round($totalSeconds / $avgInterval) + 1 : $count;

        // 偵測缺口（間隔 > 2 倍平均）
        $gapCount = 0;
        for ($i = 1; $i < $count; $i++) {
            $interval = strtotime($metrics[$i]['timestamp']) - strtotime($metrics[$i - 1]['timestamp']);
            if ($interval > $avgInterval * 2.5) {
                $gapCount++;
            }
        }
        $result['gap_count'] = $gapCount;

        // 檢查是否過舊
        $result['stale'] = (time() - $lastTime) > $avgInterval * 3;

        return $result;
    }
```

- [ ] **Step 2: 語法檢查**

Run: `php -l lib/Analyzer.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/Analyzer.php
git commit -m "feat(analyzer): 新增時序分析、峰值偵測和趨勢追蹤"
```

---

### Task 6: Evaluator（新增）

**Files:**
- Create: `lib/Evaluator.php`

- [ ] **Step 1: 建立 Evaluator.php**

建立 `lib/Evaluator.php`：

```php
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
```

- [ ] **Step 2: 語法檢查**

Run: `php -l lib/Evaluator.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/Evaluator.php
git commit -m "feat(evaluator): 新增 Evaluator 類別，非對稱 PES 計分和診斷"
```

---

### Task 7: FcgiClient（新增）

**Files:**
- Create: `lib/FcgiClient.php`

- [ ] **Step 1: 建立 FcgiClient.php**

建立 `lib/FcgiClient.php`：

```php
<?php

/**
 * 最小 FCGI 協議客戶端
 *
 * 透過 Unix socket 或 TCP 直連 PHP-FPM 取得 status 資訊
 * 實作最小子集的 FastCGI 協議，僅支援 GET 請求
 */
class FcgiClient
{
    const VERSION = 1;
    const FCGI_BEGIN_REQUEST = 1;
    const FCGI_END_REQUEST = 3;
    const FCGI_PARAMS = 4;
    const FCGI_STDIN = 5;
    const FCGI_STDOUT = 6;
    const FCGI_STDERR = 7;
    const FCGI_RESPONDER = 1;

    /**
     * 透過 FCGI 協議取得 PHP-FPM status
     *
     * @param string $address Unix socket 路徑或 host:port
     * @param string $statusPath status 端點路徑
     * @return array|null 解析後的 JSON 陣列或 null
     */
    public static function getStatus($address, $statusPath = '/fpm-status')
    {
        // 建立連接
        if (strpos($address, '/') === 0) {
            $uri = 'unix://' . $address;
        } elseif (strpos($address, ':') !== false) {
            $uri = 'tcp://' . $address;
        } else {
            return null;
        }

        $socket = @stream_socket_client($uri, $errno, $errstr, 5);
        if (!$socket) {
            fwrite(STDERR, "FCGI 連線失敗：{$errstr} ({$errno})\n");
            return null;
        }

        stream_set_timeout($socket, 5);

        // 送出請求
        $requestId = 1;

        // BEGIN_REQUEST
        $body = pack('xCx5', self::FCGI_RESPONDER); // role=RESPONDER, flags=0
        fwrite($socket, self::buildRecord(self::FCGI_BEGIN_REQUEST, $body, $requestId));

        // PARAMS
        $params = [
            'SCRIPT_NAME' => $statusPath,
            'SCRIPT_FILENAME' => $statusPath,
            'QUERY_STRING' => 'json',
            'REQUEST_METHOD' => 'GET',
            'SERVER_SOFTWARE' => 'php-fpm-tuner',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
        ];
        fwrite($socket, self::buildParamsRecord($params, $requestId));
        fwrite($socket, self::buildRecord(self::FCGI_PARAMS, '', $requestId)); // 空 PARAMS 結束

        // STDIN（空，結束請求）
        fwrite($socket, self::buildRecord(self::FCGI_STDIN, '', $requestId));

        // 讀取回應
        $stdout = '';
        while (true) {
            $header = fread($socket, 8);
            if (strlen($header) < 8) {
                break;
            }

            $record = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $header);

            $content = '';
            if ($record['contentLength'] > 0) {
                $content = fread($socket, $record['contentLength']);
            }
            if ($record['paddingLength'] > 0) {
                fread($socket, $record['paddingLength']);
            }

            if ($record['type'] === self::FCGI_STDOUT) {
                $stdout .= $content;
            } elseif ($record['type'] === self::FCGI_END_REQUEST) {
                break;
            }
        }

        fclose($socket);

        // 解析 HTTP 回應：剝除 headers
        $parts = preg_split('~\r?\n\r?\n~', $stdout, 2);
        if (count($parts) < 2) {
            return null;
        }

        $json = json_decode(trim($parts[1]), true);
        return is_array($json) ? $json : null;
    }

    /**
     * 建構 FCGI 記錄
     *
     * @param int $type 記錄類型
     * @param string $content 內容
     * @param int $requestId 請求 ID
     * @return string 二進位記錄
     */
    private static function buildRecord($type, $content, $requestId = 1)
    {
        $contentLength = strlen($content);
        $paddingLength = (8 - ($contentLength % 8)) % 8;

        $header = pack('CCnnCC',
            self::VERSION,
            $type,
            $requestId,
            $contentLength,
            $paddingLength,
            0 // reserved
        );

        return $header . $content . str_repeat("\0", $paddingLength);
    }

    /**
     * 建構 FCGI PARAMS 記錄
     *
     * @param array $params 參數鍵值對
     * @param int $requestId 請求 ID
     * @return string 二進位記錄
     */
    private static function buildParamsRecord(array $params, $requestId = 1)
    {
        $body = '';
        foreach ($params as $name => $value) {
            $nameLen = strlen($name);
            $valueLen = strlen($value);

            $body .= self::encodeLength($nameLen);
            $body .= self::encodeLength($valueLen);
            $body .= $name;
            $body .= $value;
        }

        return self::buildRecord(self::FCGI_PARAMS, $body, $requestId);
    }

    /**
     * 編碼 FCGI 長度欄位
     *
     * @param int $length
     * @return string
     */
    private static function encodeLength($length)
    {
        if ($length < 128) {
            return chr($length);
        }
        return pack('N', $length | 0x80000000);
    }
}
```

- [ ] **Step 2: 語法檢查**

Run: `php -l lib/FcgiClient.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/FcgiClient.php
git commit -m "feat(fcgi): 新增最小 FCGI 協議客戶端，支援 Unix socket 和 TCP"
```

---

### Task 8: Collector — FCGI 整合

**Files:**
- Modify: `lib/Collector.php`

- [ ] **Step 1: 重構 fetch() 支援多種連線方式**

將 `lib/Collector.php` 的 `fetch()` 方法（第 34-70 行）替換為：

```php
    /**
     * 從 PHP-FPM status 端點取得指標
     *
     * 支援三種連線方式：
     * - Unix socket 路徑（如 /var/run/php-fpm.sock）
     * - TCP 地址（如 127.0.0.1:9000）
     * - HTTP URL（如 http://127.0.0.1:9000/fpm-status?json）
     *
     * @param string $statusUrl 連線地址
     * @param string $statusPath status 端點路徑（FCGI 模式用）
     * @return array|null 指標陣列或 null（失敗時）
     */
    public static function fetch($statusUrl, $statusPath = '/fpm-status')
    {
        // 判斷連線方式
        if (strpos($statusUrl, '/') === 0 || strpos($statusUrl, 'unix:') === 0) {
            // Unix socket → FCGI
            $address = str_replace('unix:', '', $statusUrl);
            $data = FcgiClient::getStatus($address, $statusPath);
        } elseif (preg_match('~^\d+\.\d+\.\d+\.\d+:\d+$~', $statusUrl)) {
            // TCP 地址 → FCGI
            $data = FcgiClient::getStatus($statusUrl, $statusPath);
        } else {
            // HTTP URL → 現有邏輯
            $data = self::fetchHttp($statusUrl);
        }

        if (!is_array($data)) {
            return null;
        }

        return self::formatMetrics($data);
    }

    /**
     * 透過 HTTP 取得 PHP-FPM status
     *
     * @param string $url HTTP URL
     * @return array|null
     */
    private static function fetchHttp($url)
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 將原始 status 資料格式化為指標陣列
     *
     * @param array $data PHP-FPM status JSON
     * @return array
     */
    private static function formatMetrics(array $data)
    {
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
```

- [ ] **Step 2: 語法檢查**

Run: `php -l lib/Collector.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/Collector.php
git commit -m "feat(collector): 支援 Unix socket 和 TCP 直連 PHP-FPM"
```

---

### Task 9: Optimizer（新增）

**Files:**
- Create: `lib/Optimizer.php`

- [ ] **Step 1: 建立 Optimizer.php**

建立 `lib/Optimizer.php`：

```php
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
     * @param array $evaluation Evaluator::evaluate() 結果
     * @param array $systemInfo SystemInfo::collect() 結果
     * @param array $currentConfig 當前配置
     * @return array 優化建議
     */
    public static function optimize(
        array $stats,
        array $timeSeries,
        array $evaluation,
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

        $memoryMode = isset($currentConfig['memory_mode']) ? $currentConfig['memory_mode'] : 'total';
        if ($memoryMode === 'total' && isset($systemInfo['total_memory']) && $systemInfo['total_memory'] > 0) {
            $baseMemory = $systemInfo['total_memory'];
        } else {
            $baseMemory = $systemInfo['free_memory'];
        }

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

        // 確保邏輯一致性
        $recMinSpare = max(1, $recMinSpare);
        $recMaxSpare = max($recMinSpare, min($recMaxSpare, $recMaxChildren));
        $recStartServers = max($recMinSpare, min($recStartServers, $recMaxSpare));

        $appliedLimit = $targetMaxChildren >= $cpuLimit ? 'cpu' : ($targetMaxChildren >= $memoryLimit ? 'memory' : 'none');

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
            sprintf('配合 max_children %d × 0.75', $recMaxChildren),
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
```

- [ ] **Step 2: 語法檢查**

Run: `php -l lib/Optimizer.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/Optimizer.php
git commit -m "feat(optimizer): 新增資料驅動的 PHP-FPM 設定優化建議"
```

---

### Task 10: bin/optimize（新增）

**Files:**
- Create: `bin/optimize`

- [ ] **Step 1: 建立 bin/optimize**

建立 `bin/optimize`：

```php
#!/usr/bin/env php
<?php

/**
 * PHP-FPM Optimizer - 資料驅動的設定優化入口
 *
 * 用法：
 *   php bin/optimize --input <path> [選項]
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// =============================================================================
// 解析參數
// =============================================================================

$cliArgs = Config::fromCliArgs($argv);

if (isset($cliArgs['options']['help'])) {
    echo <<<HELP
PHP-FPM Optimizer - 根據監控數據產生具體設定建議

用法：
  php bin/optimize --input <path> [選項]

選項：
  --input <path>             CSV 輸入路徑（必要）
  --max-children <n>         當前 max_children 設定
  --min-spare <n>            當前 min_spare_servers 設定
  --max-spare <n>            當前 max_spare_servers 設定
  --start-servers <n>        當前 start_servers 設定
  --config <path>            指定配置檔路徑
  --json                     以 JSON 格式輸出
  --aggressive               使用目標值而非漸進值
  -h, --help                 顯示此說明

範例：
  # 基本優化（需指定當前配置以取得精確建議）
  php bin/optimize --input metrics.csv --max-children 30 --min-spare 5

  # JSON 輸出
  php bin/optimize --input metrics.csv --json

HELP;
    exit(0);
}

// =============================================================================
// 載入配置
// =============================================================================

$config = Config::load($cliArgs['config_path']);
$config = Config::merge($config, $cliArgs['overrides']);

$inputPath = isset($config['input']) ? $config['input'] : null;
if (!$inputPath) {
    fwrite(STDERR, "錯誤：請指定 --input 參數\n");
    fwrite(STDERR, "使用 --help 查看說明\n");
    exit(1);
}

$realInputPath = realpath($inputPath);
if ($realInputPath === false) {
    fwrite(STDERR, "錯誤：找不到檔案 {$inputPath}\n");
    exit(1);
}
$inputPath = $realInputPath;

$aggressive = isset($cliArgs['options']['aggressive']);

$currentConfig = [
    'max_children' => isset($config['max_children']) ? (int) $config['max_children'] : 0,
    'start_servers' => isset($config['start_servers']) ? (int) $config['start_servers'] : 0,
    'min_spare_servers' => isset($config['min_spare']) ? (int) $config['min_spare'] : 1,
    'max_spare_servers' => isset($config['max_spare']) ? (int) $config['max_spare'] : 0,
    'memory_reserve_ratio' => isset($config['memory_reserve_ratio']) ? $config['memory_reserve_ratio'] : 0.20,
    'memory_mode' => isset($config['memory_mode']) ? $config['memory_mode'] : 'total',
];

// =============================================================================
// 執行分析 → 評估 → 優化
// =============================================================================

$metrics = Analyzer::loadCsv($inputPath);
if (empty($metrics)) {
    fwrite(STDERR, "錯誤：無法載入數據或檔案為空\n");
    exit(1);
}

$stats = Analyzer::calculateStats($metrics);
if (isset($stats['error']) && $stats['error']) {
    fwrite(STDERR, "錯誤：{$stats['message']}\n");
    exit(1);
}

$timeSeries = Analyzer::analyzeTimeSeries($metrics);
$evaluation = Evaluator::evaluate($stats, $timeSeries, $currentConfig);
$systemInfo = SystemInfo::collect($config);
$result = Optimizer::optimize($stats, $timeSeries, $evaluation, $systemInfo, $currentConfig);

if (isset($result['error']) && $result['error']) {
    fwrite(STDERR, "錯誤：{$result['message']}\n");
    exit(1);
}

// =============================================================================
// 輸出結果
// =============================================================================

if (isset($cliArgs['options']['json'])) {
    $output = [
        'stats' => $stats,
        'evaluation' => $evaluation,
        'optimization' => $result,
    ];
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// 標準文字輸出
$pes = $evaluation['pes'];
$values = $aggressive ? $result['target'] : $result['recommended'];

echo "# PHP-FPM 優化建議\n";
echo "# ═══════════════════════════════════════════════════════════════\n";
echo "\n";

echo "## 資料摘要\n";
echo "# ───────────────────────────────────────\n";
$dq = $timeSeries['data_quality'];
printf("採樣數量: %d | 時間範圍: %s ~ %s\n", $stats['sample_count'], $stats['period_start'], $stats['period_end']);
printf("信心度: %s | 資料缺口: %d\n", $result['confidence'], $dq['gap_count']);
echo "\n";

echo "## PES 評分\n";
echo "# ───────────────────────────────────────\n";
printf("當前: %.2f (%s)\n", $pes['pes_score'], $pes['rating']);
echo "\n";

echo "## 建議配置\n";
echo "# ───────────────────────────────────────\n";
printf("%-25s %-8s %-8s %-8s %s\n", '', '當前', '建議', '目標', '原因');
echo str_repeat('-', 80) . "\n";

$paramNames = ['max_children', 'start_servers', 'min_spare_servers', 'max_spare_servers'];
foreach ($paramNames as $param) {
    $cur = $result['current'][$param];
    $rec = $values[$param];
    $tgt = $result['target'][$param];
    $reason = '';
    foreach ($result['changes'] as $change) {
        if ($change['param'] === $param) {
            $reason = $change['reason'];
            break;
        }
    }
    $marker = $cur !== $rec ? '*' : ' ';
    printf("%s %-24s %-8d %-8d %-8d %s\n", $marker, $param, $cur, $rec, $tgt, $reason);
}
echo "\n";

echo "## 限制條件\n";
echo "# ───────────────────────────────────────\n";
$c = $result['constraints'];
printf("記憶體上限: %d workers (%dMB %s, %dMB/worker, %.0f%% reserve)\n",
    $c['memory_limit'], $c['base_memory_mb'],
    isset($currentConfig['memory_mode']) && $currentConfig['memory_mode'] === 'available' ? 'available' : 'total',
    $c['worker_memory_mb'], $c['memory_reserve_ratio'] * 100);
printf("CPU 限制: %d workers (%d cores × 8)\n", $c['cpu_limit'], $systemInfo['cpu_cores']);
printf("生效限制: %s\n", $c['applied_limit']);
echo "\n";

echo "## 下一步\n";
echo "# ───────────────────────────────────────\n";
if (empty($result['changes'])) {
    echo "當前配置已接近最佳，無需調整\n";
} else {
    echo "1. 套用建議值到 PHP-FPM pool 配置\n";
    echo "2. 執行 systemctl reload php-fpm\n";
    echo "3. 觀察 24 小時\n";
    echo "4. 再次執行 php bin/optimize 確認效果\n";
}
echo "\n";

// 診斷摘要
if (!empty($evaluation['diagnostics'])) {
    echo "## 診斷\n";
    echo "# ───────────────────────────────────────\n";
    $icons = ['high' => '!!', 'medium' => '! ', 'low' => '- ', 'info' => '  '];
    foreach ($evaluation['diagnostics'] as $diag) {
        $icon = isset($icons[$diag['priority']]) ? $icons[$diag['priority']] : '  ';
        echo "{$icon} {$diag['message']}\n";
    }
}
```

- [ ] **Step 2: 設定可執行權限**

Run: `chmod +x bin/optimize`

- [ ] **Step 3: 語法檢查**

Run: `php -l bin/optimize`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add bin/optimize
git commit -m "feat(cli): 新增 bin/optimize 資料驅動的設定優化入口"
```

---

### Task 11: bin/analyze — 改用 Evaluator

**Files:**
- Modify: `bin/analyze`

- [ ] **Step 1: 重寫 bin/analyze 的分析和輸出邏輯**

將 `bin/analyze` 的分析和輸出區段（從 `// 執行分析` 開始，約第 88 行到檔案結尾）替換為：

```php
// =============================================================================
// 執行分析
// =============================================================================

$metrics = Analyzer::loadCsv($inputPath);
if (empty($metrics)) {
    fwrite(STDERR, "錯誤：無法載入數據或檔案為空\n");
    exit(1);
}

$stats = Analyzer::calculateStats($metrics);
if (isset($stats['error']) && $stats['error']) {
    fwrite(STDERR, "錯誤：{$stats['message']}\n");
    exit(1);
}

$timeSeries = Analyzer::analyzeTimeSeries($metrics);
$evaluation = Evaluator::evaluate($stats, $timeSeries, $currentConfig);

if (isset($evaluation['error']) && $evaluation['error']) {
    fwrite(STDERR, "錯誤：{$evaluation['message']}\n");
    exit(1);
}

// =============================================================================
// 輸出結果
// =============================================================================

if (isset($cliArgs['options']['json'])) {
    $output = [
        'stats' => $stats,
        'time_series' => $timeSeries,
        'evaluation' => $evaluation,
    ];
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// 標準文字輸出
$pes = $evaluation['pes'];
$diagnostics = $evaluation['diagnostics'];
$insights = $evaluation['insights'];

echo "# PHP-FPM 配置分析報告\n";
echo "# ═══════════════════════════════════════════════════════════════\n";
echo "\n";

echo "## 數據概覽\n";
echo "# ───────────────────────────────────────\n";
echo "採樣數量: {$stats['sample_count']}\n";
echo "時間範圍: {$stats['period_start']} ~ {$stats['period_end']}\n";
$dq = $timeSeries['data_quality'];
if ($dq['gap_count'] > 0) {
    echo "資料缺口: {$dq['gap_count']}\n";
}
if ($stats['fpm_restarts'] > 0) {
    echo "FPM 重啟: {$stats['fpm_restarts']} 次\n";
}
echo "\n";

echo "## 效能指標\n";
echo "# ───────────────────────────────────────\n";
printf("平均活躍 workers: %.1f (最高: %d, P95: %.1f)\n",
    $stats['active_avg'], $stats['active_max'], $stats['active_p95']);
printf("平均閒置 workers: %.1f (最低: %d)\n",
    $stats['idle_avg'], $stats['idle_min']);
printf("總 workers 最高: %d\n", $stats['total_max']);
echo "\n";

printf("佇列發生次數: %d (%.2f%%)\n",
    $stats['queue_occurred_count'], $stats['queue_occurred_ratio'] * 100);
printf("佇列最大深度: %d\n", $stats['queue_max']);
printf("max_children 觸及次數: %d（觀測期間事件數）\n", $stats['max_children_events']);
echo "\n";

printf("平均利用率: %.1f%%\n", $stats['utilization_avg'] * 100);
printf("最高利用率: %.1f%%\n", $stats['utilization_max'] * 100);
if ($stats['avg_worker_mb'] > 0) {
    printf("Worker 平均記憶體: %.1f MB (RSS)\n", $stats['avg_worker_mb']);
}
echo "\n";

// 峰值分析
if (!empty($timeSeries['peak_hours'])) {
    echo "## 峰值分析\n";
    echo "# ───────────────────────────────────────\n";
    echo "峰值時段: " . implode(', ', array_map(function ($h) { return $h . ':00'; }, $timeSeries['peak_hours'])) . "\n";
    $ps = $timeSeries['peak_stats'];
    if ($ps && !isset($ps['error'])) {
        printf("峰值利用率: %.1f%% (平均 active: %.1f)\n", $ps['utilization_avg'] * 100, $ps['active_avg']);
    }
    $trend = $timeSeries['trend'];
    if ($trend['direction'] !== 'stable') {
        printf("趨勢: %s (斜率: %.2f/hr, 信心度: %.0f%%)\n",
            $trend['direction'] === 'increasing' ? '上升' : '下降',
            $trend['slope'], $trend['confidence'] * 100);
    }
    echo "\n";
}

echo "## PES 評分\n";
echo "# ───────────────────────────────────────\n";
printf("總分: %.2f (%s)\n", $pes['pes_score'], $pes['rating']);
echo "\n";
echo "各項得分：\n";
printf("  佇列控制 (35%%): %.2f\n", $pes['components']['queue_score']);
printf("  利用率平衡 (30%%): %.2f\n", $pes['components']['utilization_score']);
printf("  容量充足 (25%%): %.2f\n", $pes['components']['capacity_score']);
printf("  Spare 充足 (10%%): %.2f\n", $pes['components']['spare_score']);
echo "\n";

echo "## 診斷\n";
echo "# ───────────────────────────────────────\n";
$priorityLabel = [
    'high' => '!!  高優先',
    'medium' => '!   中優先',
    'low' => '-   低優先',
    'info' => '    資訊',
];
foreach ($diagnostics as $diag) {
    $label = isset($priorityLabel[$diag['priority']]) ? $priorityLabel[$diag['priority']] : $diag['priority'];
    echo "{$label}: {$diag['message']}\n";
}

if (!empty($insights)) {
    echo "\n";
    foreach ($insights as $insight) {
        $label = isset($priorityLabel[$insight['priority']]) ? $priorityLabel[$insight['priority']] : $insight['priority'];
        echo "{$label}: {$insight['message']}\n";
    }
}
echo "\n";
echo "執行 php bin/optimize --input <path> 取得具體設定建議\n";
```

- [ ] **Step 2: 語法檢查**

Run: `php -l bin/analyze`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add bin/analyze
git commit -m "refactor(analyze): 改用 Evaluator 評分，引導至 optimize 取得設定建議"
```

---

### Task 12: bootstrap + bin/tuner + bin/collect 更新

**Files:**
- Modify: `lib/bootstrap.php`
- Modify: `bin/tuner`
- Modify: `bin/collect`

- [ ] **Step 1: 更新 bootstrap.php 載入新模組**

將 `lib/bootstrap.php` 的模組載入區段（第 55-63 行）替換為：

```php
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/SystemInfo.php';
require_once __DIR__ . '/Calculator.php';
require_once __DIR__ . '/FcgiClient.php';
require_once __DIR__ . '/Collector.php';
require_once __DIR__ . '/Analyzer.php';
require_once __DIR__ . '/Evaluator.php';
require_once __DIR__ . '/Optimizer.php';
```

- [ ] **Step 2: 更新 bin/tuner 顯示 total_memory**

在 `bin/tuner` 的標準輸出區段（約第 119 行），將系統資訊輸出改為：

```php
    echo "# PHP-FPM Tuner 計算結果\n";
    echo "# ─────────────────────────────────────\n";
    echo "# 系統資訊:\n";
    echo "#   CPU 核心數: {$systemInfo['cpu_cores']}\n";
    echo "#   總記憶體: {$systemInfo['total_memory']} MB\n";
    echo "#   可用記憶體: {$systemInfo['free_memory']} MB\n";
    echo "#   Worker 記憶體: {$systemInfo['worker_memory']} MB (RSS)\n";
    $memMode = isset($config['memory_mode']) ? $config['memory_mode'] : 'total';
    echo "#   計算模式: {$memMode}\n";
    echo "#   記憶體保留: {$reservePercent}%\n";
    echo "# ─────────────────────────────────────\n";
```

- [ ] **Step 3: 更新 bin/collect 的 fetch() 呼叫**

在 `bin/collect` 中找到 `Collector::fetch($statusUrl)` 的呼叫（約第 187 行），改為：

```php
    $statusPath = isset($config['fpm_status_path']) ? $config['fpm_status_path'] : '/fpm-status';
    $metrics = Collector::fetch($statusUrl, $statusPath);
```

- [ ] **Step 4: 在 Config::fromCliArgs() 加入 --aggressive flag**

在 `lib/Config.php` 的 `fromCliArgs()` 方法中，在 `--no-prune` 處理之後加入：

```php
            if ($arg === '--aggressive') {
                $result['options']['aggressive'] = true;
                $i++;
                continue;
            }
```

- [ ] **Step 5: 語法檢查全部**

Run: `php -l lib/bootstrap.php && php -l bin/tuner && php -l bin/collect && php -l lib/Config.php`
Expected: 全部 `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add lib/bootstrap.php bin/tuner bin/collect lib/Config.php
git commit -m "chore: 更新 bootstrap 載入新模組，整合 FCGI 和 memory_mode"
```

---

## Spec Coverage Check

| Spec 需求 | Task |
|-----------|------|
| Fix #1: RSS 取代 VSZ | Task 1, 2 |
| Fix #2: max_children_reached delta | Task 4 |
| Fix #3: 總記憶體 + memory_mode | Task 1, 3 |
| Fix #4: 非對稱利用率計分 | Task 6 |
| Feature #5: FCGI 客戶端 | Task 7, 8 |
| Feature #6: 時序分析 | Task 5 |
| Evaluator 解耦 | Task 4, 6 |
| Optimizer 資料驅動建議 | Task 9 |
| bin/optimize | Task 10 |
| bin/analyze 更新 | Task 11 |
| 向後相容 (memory_mode, fpm_status_path) | Task 3, 12 |
| bootstrap 更新 | Task 12 |
