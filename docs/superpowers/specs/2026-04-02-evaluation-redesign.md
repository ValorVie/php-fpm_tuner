# PHP-FPM Tuner 評估系統重新設計

## 目標

修復現有設計的根本性缺陷，建立完整的「監控 → 評估 → 優化」三階段架構，並將分析（客觀事實）與評估（主觀判斷）解耦。

## 問題摘要

| # | 問題 | 影響 |
|---|------|------|
| 1 | `ps -eo size` 取 VSZ 而非 RSS | Worker 記憶體高估 2-3x，max_children 算太低 |
| 2 | `max_children_reached` 當絕對值用 | PES 分數不準，累積計數器 vs 事件率 |
| 3 | 用可用記憶體（MemAvailable）計算 | 結果隨快取狀態波動，不可重現 |
| 4 | PES 利用率目標對稱於 50% | 70% 和 30% 得分一樣，無法區分 |
| 5 | Collector 只支援 HTTP 連接 | Unix socket 環境無法使用 |
| 6 | 無時序分析能力 | 無法區分峰值/離峰，無法偵測趨勢 |
| 7 | Analyzer 混合了事實與判斷 | 難以替換評分策略，職責不清 |
| 8 | 無資料驅動的優化建議 | Tuner 靜態計算，Evaluator 只給文字建議 |

---

## 架構

### 三階段流程

```
監控 (Monitor)          評估 (Evaluate)              優化 (Optimize)
bin/collect             bin/analyze                  bin/optimize
    │                       │                            │
    ▼                       ▼                            ▼
Collector.fetch()       Analyzer.calculateStats()    Analyzer + Evaluator
    │                   Analyzer.analyzeTimeSeries()      │
    ▼                       │                            ▼
CSV 檔案                    ▼                        Optimizer.optimize()
                        Evaluator.evaluate()             │
                            │                            ▼
                            ▼                        具體設定建議
                        PES 分數 + 診斷              + 計算依據
```

靜態計算入口 `bin/tuner` 保留，供無監控數據時使用。

### 模組結構

```
lib/
├── Config.php          # 配置管理（已修復，不動）
├── SystemInfo.php      # 系統資訊（修改）
│   ├── getCpuCores()
│   ├── getTotalMemory()    ← 新增
│   ├── getFreeMemory()     （保留，給 Collector 用）
│   └── getWorkerMemory()   （修改：RSS）
├── Calculator.php      # 靜態參數計算（修改：用總記憶體）
├── Collector.php       # 指標收集（修改：支援 FCGI）
├── FcgiClient.php      # 最小 FCGI 協議客戶端（新增）
├── Analyzer.php        # 純統計分析（修改：時序 + delta）
├── Evaluator.php       # 評分與診斷（新增，從 Analyzer 提取）
├── Optimizer.php       # 資料驅動的設定建議（新增）
└── bootstrap.php       # 初始化（更新：載入新模組）
```

### 模組職責與介面

#### Analyzer（客觀事實）

輸入：CSV 指標陣列
輸出：統計數據、時序分析、資料品質

```php
class Analyzer
{
    // 保留
    public static function loadCsv($path): array;

    // 修改：加入 delta 追蹤
    public static function calculateStats(array $metrics): array;
    // 回傳：
    // - basic stats (avg, max, p95, utilization...)
    // - max_children_events (delta-based, 非累積值)
    // - max_children_event_rate (每採樣觸及率)
    // - fpm_restarts (偵測到的重啟次數)

    // 新增
    public static function analyzeTimeSeries(array $metrics): array;
    // 回傳：
    // - peak_hours, peak_stats, offpeak_stats
    // - trend (direction, slope, confidence)
    // - data_quality (samples, gaps, staleness, fpm_restarts 引用自 stats)

    // 保留（私有工具方法）
    private static function percentile(array $data, $percentile): float;
    private static function calculateAvgUtilization(array $active, array $total): float;
}
```

#### Evaluator（主觀判斷）

輸入：Analyzer 輸出 + 當前配置
輸出：PES 分數、評級、診斷

```php
class Evaluator
{
    public static function evaluate(array $stats, array $timeSeries, array $currentConfig): array;
    // 回傳：
    // - pes_score, rating
    // - components (queue, utilization, capacity, spare)
    // - insights (時序洞察：趨勢警告、峰值分析)

    public static function calculatePES(array $stats, array $timeSeries, array $currentConfig): array;

    // 不再 generateSuggestions —— 具體建議由 Optimizer 負責
    // Evaluator 只說「什麼有問題」，不說「怎麼改」

    private static function utilizationScore(float $util): float;
    private static function getRating(float $score): string;
}
```

#### Optimizer（設定建議）

輸入：Analyzer 輸出 + Evaluator 輸出 + 系統資訊 + 當前配置
輸出：建議配置值 + 計算依據

```php
class Optimizer
{
    public static function optimize(
        array $stats,
        array $timeSeries,
        array $evaluation,
        array $systemInfo,
        array $currentConfig
    ): array;
    // 回傳：
    // - recommended: 建議配置值
    // - current: 當前配置值
    // - target: 理想目標值（可能需要多次調整才能到達）
    // - changes: 每個參數的變更 + 原因
    // - constraints: 記憶體上限、CPU 限制、生效的限制
    // - confidence: 信心度（基於資料品質）
    // - next_step: 建議的後續動作
}
```

---

## 各項修復設計

### Fix #1：RSS 取代 VSZ

**修改檔案：** `SystemInfo.php`, `Collector.php`

```php
// 修改前
shell_exec('ps -eo size,command 2>/dev/null')

// 修改後
shell_exec('ps -eo rss,command 2>/dev/null')
```

`rss` 欄位回報的是實際駐留在物理記憶體中的 KB 數，不含共享函式庫的虛擬映射。兩個檔案各一處修改。

---

### Fix #2：max_children_reached 差值追蹤

**修改檔案：** `Analyzer.php`

在 `calculateStats()` 中，改為計算相鄰樣本的差值：

```php
$maxChildrenEvents = 0;
$fpmRestarts = 0;
for ($i = 1; $i < $count; $i++) {
    $delta = $maxChildrenReached[$i] - $maxChildrenReached[$i - 1];
    if ($delta > 0) {
        $maxChildrenEvents += $delta;
    } elseif ($delta < 0) {
        $fpmRestarts++;  // 計數器減少 = FPM 重啟
    }
}
```

輸出新增：
- `max_children_events`：觀測期間的實際觸及次數
- `max_children_event_rate`：每採樣觸及率 `events / (count - 1)`
- `fpm_restarts`：偵測到的重啟次數

移除：`max_children_reached`（原本的累積值，不再輸出）

---

### Fix #3：改用總記憶體

**修改檔案：** `SystemInfo.php`, `Calculator.php`, `config/default.php`

#### SystemInfo 新增 getTotalMemory()

```php
public static function getTotalMemory()
{
    if (PHP_OS_FAMILY === 'Windows') {
        // wmic ComputerSystem get TotalPhysicalMemory
        $output = shell_exec('wmic ComputerSystem get TotalPhysicalMemory 2>nul');
        if (preg_match('~(\d+)~', $output, $m)) {
            return round((int) $m[1] / 1024 / 1024);
        }
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $bytes = shell_exec('sysctl -n hw.memsize');
        return round((int) $bytes / 1024 / 1024);
    } else {
        $meminfo = shell_exec('cat /proc/meminfo');
        if (preg_match('~MemTotal:\s+(\d+)\s+~', $meminfo, $m)) {
            return round((int) $m[1] / 1024);
        }
    }
    return 0;
}
```

#### Calculator 公式修改

```
舊：max_children = floor(free_memory × (1 - reserve) / worker_memory)
新：max_children = floor(total_memory × (1 - reserve) / worker_memory)
```

`memory_reserve_ratio = 0.20` 的語意變為「保留總記憶體的 20% 給 OS 和其他服務」。

#### 向後相容

Config 新增：

```php
'memory_mode' => 'total',  // 'total'（推薦）或 'available'（舊行為）
```

Calculator 根據 `memory_mode` 選擇使用 `getTotalMemory()` 或 `getFreeMemory()`。

---

### Fix #4：非對稱利用率計分

**修改位置：** `Evaluator.php`（新檔案）

```php
private static function utilizationScore($util)
{
    if ($util <= 0.4) {
        return 0.5 + ($util / 0.4) * 0.5;       // 0%→0.5, 40%→1.0
    } elseif ($util <= 0.7) {
        return 1.0;                                // sweet spot
    } elseif ($util <= 0.9) {
        return 1.0 - ($util - 0.7) / 0.2 * 0.5;  // 70%→1.0, 90%→0.5
    } else {
        return max(0, 0.5 - ($util - 0.9) / 0.1 * 0.5);  // 90%→0.5, 100%→0
    }
}
```

#### 修正後的 PES 公式

```
PES = queue_score      × 0.35
    + utilization_score × 0.30   (非對稱)
    + capacity_score    × 0.25   (基於 event_rate)
    + spare_score       × 0.10

時序調整：
  若 trend.direction == 'increasing' 且 confidence > 0.5：PES × 0.9
  若 peak_stats.utilization_avg > 0.85：PES × 0.95
```

capacity_score 改用事件率：

```php
$eventRate = $stats['max_children_event_rate'];
$capacityScore = max(0, 1 - $eventRate * 10);  // 10% 觸及率 → 0 分
```

---

### Feature #5：最小 FCGI 客戶端

**新增檔案：** `lib/FcgiClient.php`

```php
class FcgiClient
{
    const FCGI_BEGIN_REQUEST = 1;
    const FCGI_PARAMS = 4;
    const FCGI_STDIN = 5;
    const FCGI_STDOUT = 6;
    const FCGI_END_REQUEST = 3;
    const FCGI_RESPONDER = 1;

    /**
     * 透過 FCGI 協議取得 PHP-FPM status
     *
     * @param string $address  Unix socket 路徑（如 /var/run/php-fpm.sock）
     *                         或 TCP 地址（如 127.0.0.1:9000）
     * @param string $statusPath  status 端點路徑，預設 /fpm-status
     * @return array|null  解析後的 JSON 陣列或 null
     */
    public static function getStatus($address, $statusPath = '/fpm-status')
    {
        // 1. stream_socket_client 連接
        // 2. 送 FCGI_BEGIN_REQUEST (type=1, role=RESPONDER)
        // 3. 送 FCGI_PARAMS: SCRIPT_NAME, QUERY_STRING=json, REQUEST_METHOD=GET
        // 4. 送空 FCGI_PARAMS + 空 FCGI_STDIN
        // 5. 讀 FCGI_STDOUT
        // 6. 剝除 HTTP headers, json_decode body
    }

    // 私有工具方法
    private static function buildRecord($type, $content, $requestId = 1): string;
    private static function buildParamsRecord(array $params): string;
    private static function encodeParamLength($length): string;
}
```

#### Collector 整合

```php
// Collector::fetch() 修改
public static function fetch($statusUrl, $statusPath = '/fpm-status')
{
    if (strpos($statusUrl, '/') === 0 || strpos($statusUrl, 'unix:') === 0) {
        // Unix socket → FCGI
        $address = str_replace('unix:', '', $statusUrl);
        $data = FcgiClient::getStatus($address, $statusPath);
    } elseif (preg_match('~^\d+\.\d+\.\d+\.\d+:\d+$~', $statusUrl)) {
        // TCP without http → FCGI
        $data = FcgiClient::getStatus($statusUrl, $statusPath);
    } else {
        // HTTP URL → 現有邏輯
        $data = self::fetchHttp($statusUrl);
    }

    if (!$data) return null;
    return self::formatMetrics($data);
}
```

#### Config 修改

```php
'fpm_status_url' => '/var/run/php-fpm.sock',     // 新預設：Unix socket
// 可選：'http://127.0.0.1:9000/fpm-status?json' // HTTP 向後相容
'fpm_status_path' => '/fpm-status',               // 新增：status 路徑（FCGI 模式用）
```

---

### Feature #6：時序分析

**修改檔案：** `Analyzer.php`

#### analyzeTimeSeries() 方法

```php
public static function analyzeTimeSeries(array $metrics)
{
    return [
        'peak_hours'    => self::detectPeakHours($metrics),
        'peak_stats'    => self::calculateStatsForPeriod($metrics, $peakRows),
        'offpeak_stats' => self::calculateStatsForPeriod($metrics, $offpeakRows),
        'trend'         => self::detectTrend($metrics),
        'data_quality'  => self::assessDataQuality($metrics),
    ];
}
```

#### 峰值偵測

按小時分桶，計算每小時的平均利用率。利用率 > 40% 的小時標記為峰值。

```php
private static function detectPeakHours(array $metrics)
{
    // 1. 按小時分桶
    // 2. 計算每小時 avg(active / total)
    // 3. 利用率 > 0.4 的小時 = 峰值
    // 回傳：['hours' => [9,10,11,14,15,16], 'threshold' => 0.4]
}
```

#### 趨勢偵測

對 active workers 做簡單線性迴歸。

```php
private static function detectTrend(array $metrics)
{
    // 1. 以時間戳為 X，active 為 Y
    // 2. 計算斜率和 R²
    // 3. R² > 0.5 才報告趨勢
    // 回傳：
    // [
    //     'direction' => 'increasing|stable|decreasing',
    //     'slope' => 0.15,         // 每小時增加 0.15 個 active worker
    //     'confidence' => 0.72,    // R² 值
    // ]
}
```

#### 資料品質評估

```php
private static function assessDataQuality(array $metrics)
{
    // 1. 計算首尾時間差和樣本數，推算預期間隔
    // 2. 偵測間隔 > 2× 預期的缺口
    // 3. 檢查最後樣本是否過舊
    // 回傳：
    // [
    //     'total_samples' => 1440,
    //     'expected_samples' => 1440,
    //     'gap_count' => 3,
    //     'avg_interval_seconds' => 60,
    //     'fpm_restarts' => 1,
    //     'stale' => false,
    // ]
}
```

---

### Optimizer 設計

**新增檔案：** `lib/Optimizer.php`

#### 核心方法

```php
class Optimizer
{
    // 最低樣本數門檻
    const MIN_SAMPLES = 60;

    public static function optimize(
        array $stats,
        array $timeSeries,
        array $evaluation,
        array $systemInfo,
        array $currentConfig
    ): array;
}
```

#### max_children 計算（複合法）

```
記憶體上限 = total_memory × (1 - reserve) / worker_rss
P95 需求  = P95_active × 1.3
峰值需求  = peak_active × 1.1
佇列補償  = 若 queue_rate > 5%：avg_queue_depth × 2

目標值 = max(P95 需求, 峰值需求) + 佇列補償
建議值 = min(目標值, 記憶體上限)
```

#### spare servers 計算

```
min_spare = round(peak_stats.avg_active × 0.25)
           下限 = max(1, cpu_cores)
max_spare = round(建議 max_children × 0.75)
           上限 = cpu_cores × 4
start     = round(offpeak_stats.avg_active × 1.2)
           確保 min_spare ≤ start ≤ max_spare
```

#### 漸進式調整

```php
// 理想目標值
$target = self::calculateTarget(...);

// 本次建議值（最多 ±25%）
$step = self::constrainStep($current, $target, 0.25);

return [
    'recommended' => $step,         // 本次應套用的值
    'target' => $target,            // 最終理想值（可能需要多次調整）
    'current' => $current,
    'changes' => [...],             // 每個參數的 from/to/reason
    'constraints' => [
        'memory_limit' => $memoryLimit,
        'cpu_limit' => $cpuLimit,
        'applied_limit' => 'memory', // 或 'cpu'
    ],
    'confidence' => $confidence,    // high/medium/low（基於資料品質）
    'next_step' => $target === $step
        ? '套用建議值後觀察 24 小時，再次執行 optimize 確認效果'
        : '套用建議值後觀察 24 小時，再次執行 optimize 繼續調整至目標值',
];
```

#### 信心度判定

```
樣本數 >= 1440（24h）且 gap_count < 5% → high
樣本數 >= 60（1h）且 gap_count < 10%  → medium
其他 → low（仍輸出建議，但附帶警告）
```

---

## bin/ 入口點

### bin/optimize（新增）

```
用法：
  php bin/optimize --input <path> [選項]

選項：
  --input <path>           CSV 輸入路徑（必要）
  --max-children <n>       當前 max_children 設定
  --min-spare <n>          當前 min_spare_servers 設定
  --max-spare <n>          當前 max_spare_servers 設定
  --config <path>          指定配置檔路徑
  --json                   以 JSON 格式輸出
  --aggressive             使用目標值而非漸進值
  -h, --help               顯示說明
```

輸出範例：

```
# PHP-FPM 優化建議
# ═══════════════════════════════════════════════════════════════

## 資料摘要
採樣數量: 1440 | 時間範圍: 24h | 信心度: 高

## PES 評分
當前: 0.65 (需改善) | 預估調整後: 0.82 (良好)

## 建議配置

                     當前    建議    目標    原因
max_children         30      38      38      峰值利用率 88%, 佇列率 12%
start_servers        5       8       10      離峰平均 active 6.5
min_spare_servers    3       6       8       峰值時段 idle 最低值 1
max_spare_servers    16      22      28      配合 max_children 調整

## 限制條件
記憶體上限: 45 workers (4096MB total, 72MB/worker, 20% reserve)
CPU 限制: 32 workers (8 cores × 4)
生效限制: CPU

## 下一步
1. 套用建議值到 PHP-FPM pool 配置
2. 執行 systemctl reload php-fpm
3. 觀察 24 小時
4. 再次執行 php bin/optimize 確認效果
```

### bin/analyze（修改）

移除 `generateSuggestions()` 的具體建議，只保留診斷。輸出改為：

```
## 調整建議
⚠️  高優先: 佇列發生率 12% 過高
⚠️  高優先: max_children 觸及 5 次
📌 中優先: 峰值利用率 88% 偏高

執行 php bin/optimize --input <path> 取得具體設定建議
```

---

## 向後相容

| 項目 | 處理方式 |
|------|---------|
| `php-fpm-tuner.php` | 保留，使用 `memory_mode=available` 維持舊行為 |
| `bin/tuner` | 預設 `memory_mode=total`，可切換 |
| `bin/analyze` | 輸出格式變更（不再有具體建議值），引導至 `bin/optimize` |
| `config/default.php` | 新增 `memory_mode`, `fpm_status_path` 鍵 |
| `fpm_status_url` 預設值 | 從 HTTP URL 改為 Unix socket 路徑（既有使用者需在 config 中指定原 HTTP URL） |
| CSV 格式 | 不變，向後相容 |
| `memory_mode` | Optimizer 同樣遵循此設定計算記憶體上限 |
