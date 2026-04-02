# Code Review 問題修復計畫

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 修復 code review 發現的 12 個問題（1 CRITICAL, 4 HIGH, 4 MEDIUM, 3 LOW），提升安全性、正確性和可靠性

**Architecture:** 所有修改限於現有檔案，不新增模組。修復順序按嚴重程度排列，每個 task 對應一個或多個相關問題，確保修改後向後相容。

**Tech Stack:** PHP 7.1+, CLI tools, CSV file I/O

---

## File Structure

以下為需要修改的現有檔案及修改職責：

| 檔案 | 修改內容 |
|------|---------|
| `lib/Config.php` | 路徑驗證、布林 flag 解析、擴充驗證邏輯 |
| `lib/Collector.php` | 流式 prune、flock 檔案鎖定 |
| `lib/Analyzer.php` | 修正 utilization_avg 計算 |
| `lib/bootstrap.php` | ini_parse_quantity 空字串防守 |
| `config/default.php` | memory_reserve_ratio 統一為 0.20 |
| `php-fpm-tuner.php` | memory_reserve_ratio 統一為 0.20 |
| `bin/collect` | 修正 --once 模式清理策略 |
| `bin/analyze` | 路徑正規化 |

---

### Task 1: [CRITICAL] Config::load() 路徑驗證

**Files:**
- Modify: `lib/Config.php:16-32`

- [ ] **Step 1: 在 Config::load() 加入路徑驗證**

```php
public static function load($path = null)
{
    $defaultPath = dirname(__DIR__) . '/config/default.php';
    $default = file_exists($defaultPath) ? require $defaultPath : [];

    if ($path === null) {
        return $default;
    }

    if (!file_exists($path)) {
        fwrite(STDERR, "警告：配置檔不存在：{$path}，使用預設配置\n");
        return $default;
    }

    $realPath = realpath($path);
    if ($realPath === false || pathinfo($realPath, PATHINFO_EXTENSION) !== 'php') {
        fwrite(STDERR, "警告：配置檔路徑無效或非 .php 檔案：{$path}，使用預設配置\n");
        return $default;
    }

    $custom = require $realPath;
    if (!is_array($custom)) {
        fwrite(STDERR, "警告：配置檔格式錯誤（需回傳陣列）：{$path}，使用預設配置\n");
        return $default;
    }

    return self::merge($default, $custom);
}
```

- [ ] **Step 2: 手動驗證**

Run: `php -l lib/Config.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/Config.php
git commit -m "fix(config): 對 --config 路徑加入 realpath 驗證和副檔名檢查"
```

---

### Task 2: [HIGH] 統一 memory_reserve_ratio 預設值

**Files:**
- Modify: `config/default.php:23`
- Modify: `php-fpm-tuner.php:33`
- Modify: `lib/Calculator.php:24`

三處統一為 `0.20`（生產環境保守配置），同時更新註解。

- [ ] **Step 1: 修改 config/default.php**

將第 23 行從：
```php
'memory_reserve_ratio' => 0.25,
```
改為：
```php
'memory_reserve_ratio' => 0.20,
```

- [ ] **Step 2: 修改 php-fpm-tuner.php**

將第 33 行從：
```php
$config['memory_reserve_ratio'] = 0.10;
```
改為：
```php
$config['memory_reserve_ratio'] = 0.20;
```

同時更新第 30 行註解，將「預設 0.10 (10%)」改為「預設 0.20 (20%)」。

- [ ] **Step 3: 修改 lib/Calculator.php**

將第 24 行從：
```php
$memoryReserveRatio = isset($config['memory_reserve_ratio']) ? $config['memory_reserve_ratio'] : 0.10;
```
改為：
```php
$memoryReserveRatio = isset($config['memory_reserve_ratio']) ? $config['memory_reserve_ratio'] : 0.20;
```

- [ ] **Step 4: 語法檢查**

Run: `php -l config/default.php && php -l php-fpm-tuner.php && php -l lib/Calculator.php`
Expected: 三個檔案都 `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add config/default.php php-fpm-tuner.php lib/Calculator.php
git commit -m "fix(config): 統一 memory_reserve_ratio 預設值為 0.20"
```

---

### Task 3: [HIGH] 修正布林 flag 解析

**Files:**
- Modify: `lib/Config.php:55-110`
- Modify: `bin/collect:98-100`

- [ ] **Step 1: 在 Config::fromCliArgs() 加入 --prune、--stats、--no-prune 布林 flag 處理**

在 `Config.php` 的 `fromCliArgs()` 方法中，在 `--once` 的 if 區塊後面加入：

```php
if ($arg === '--prune') {
    $result['options']['prune'] = true;
    $i++;
    continue;
}

if ($arg === '--stats') {
    $result['options']['stats'] = true;
    $i++;
    continue;
}

if ($arg === '--no-prune') {
    $result['options']['no_prune'] = true;
    $i++;
    continue;
}
```

- [ ] **Step 2: 更新 bin/collect 使用 options 而非 overrides**

將 `bin/collect` 第 98-100 行從：
```php
$pruneOnly = isset($cliArgs['overrides']['prune']) || in_array('--prune', $argv);
$showStats = isset($cliArgs['overrides']['stats']) || in_array('--stats', $argv);
$noPrune   = isset($cliArgs['overrides']['no_prune']) || in_array('--no-prune', $argv);
```
改為：
```php
$pruneOnly = isset($cliArgs['options']['prune']);
$showStats = isset($cliArgs['options']['stats']);
$noPrune   = isset($cliArgs['options']['no_prune']);
```

- [ ] **Step 3: 語法檢查**

Run: `php -l lib/Config.php && php -l bin/collect`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add lib/Config.php bin/collect
git commit -m "fix(cli): 在 Config::fromCliArgs() 正確處理布林 flag"
```

---

### Task 4: [HIGH] Collector::pruneData() 流式寫入 + 檔案鎖定

**Files:**
- Modify: `lib/Collector.php:110-258`

- [ ] **Step 1: 重寫 pruneData() 使用流式處理**

將 `Collector::pruneData()` 整個方法替換為：

```php
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

    $fp = fopen($path, 'r');
    if (!$fp) {
        return $result;
    }

    // 取得共享鎖讀取
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return $result;
    }

    $headers = fgetcsv($fp);
    if (!$headers) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    }

    $timestampIndex = array_search('timestamp', $headers);
    $cutoffTime = $retentionHours > 0 ? strtotime("-{$retentionHours} hours") : 0;

    // 第一遍：計算總行數和需要按時間刪除的行數
    $totalRows = 0;
    $keepFromRow = 0;
    $timeFilteredCount = 0;

    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) !== count($headers)) {
            continue;
        }
        $totalRows++;

        // 時間過濾：記錄第一筆需要保留的行
        if ($retentionHours > 0 && $timestampIndex !== false && $keepFromRow === 0) {
            $rowTime = strtotime($row[$timestampIndex]);
            if ($rowTime !== false && $rowTime < $cutoffTime) {
                $timeFilteredCount++;
                continue;
            }
            if ($keepFromRow === 0) {
                $keepFromRow = $totalRows;
            }
        }
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    // 計算實際要保留的行數
    $keepCount = $totalRows - $timeFilteredCount;
    $action = $timeFilteredCount > 0 ? 'time_limit' : 'none';

    // 行數限制
    if ($maxRows > 0 && $keepCount > $maxRows) {
        $keepCount = $maxRows;
        $action = 'row_limit';
    }

    // 大小限制
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

    // 跳過讀取端的 header
    $headers = fgetcsv($fpIn);
    fputcsv($fpOut, $headers);

    $rowIndex = 0;
    $written = 0;
    while (($row = fgetcsv($fpIn)) !== false) {
        if (count($row) !== count($headers)) {
            continue;
        }
        $rowIndex++;

        // 跳過前 N 行（最舊的）
        if ($rowIndex <= $skipCount) {
            continue;
        }

        // 時間過濾
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

    // 原子替換
    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return $result;
    }

    $result['removed'] = $totalRows - $written;
    $result['remaining'] = $written;
    $result['action'] = $action;

    return $result;
}
```

- [ ] **Step 2: 在 appendToCsv() 加入 flock**

將 `Collector::appendToCsv()` 方法中 fopen 後的部分改為：

```php
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
if ($writeHeader && filesize($path) > 0) {
    $writeHeader = false;
}

if ($writeHeader) {
    fputcsv($fp, self::CSV_HEADERS);
}

$row = [];
foreach (self::CSV_HEADERS as $header) {
    $row[] = isset($metrics[$header]) ? $metrics[$header] : '';
}
fputcsv($fp, $row);

flock($fp, LOCK_UN);
fclose($fp);
return true;
```

- [ ] **Step 3: 語法檢查**

Run: `php -l lib/Collector.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add lib/Collector.php
git commit -m "fix(collector): 使用流式寫入避免 OOM，加入 flock 防止並發損毀"
```

---

### Task 5: [HIGH] 修正 --once 模式的清理策略

**Files:**
- Modify: `bin/collect:210-218`

- [ ] **Step 1: 在 --once 模式下僅在檔案超過大小限制時才觸發清理**

將 `bin/collect` 第 211-217 行從：
```php
// 定期清理（持續模式）或單次收集時清理
if (!$noPrune && ($once || $collectCount % $pruneInterval === 0)) {
    $pruneResult = Collector::pruneData($outputPath, $pruneConfig);
    if ($pruneResult['removed'] > 0 && !$once) {
        echo date('Y-m-d H:i:s') . " 已清理 {$pruneResult['removed']} 筆過期記錄\n";
    }
}
```
改為：
```php
// 定期清理
$shouldPrune = false;
if (!$noPrune) {
    if (!$once && $collectCount % $pruneInterval === 0) {
        // 持續模式：每 N 次收集清理一次
        $shouldPrune = true;
    } elseif ($once && $maxSizeMb > 0) {
        // 單次模式：僅在檔案超過大小限制時清理
        $currentFileSize = file_exists($outputPath) ? filesize($outputPath) : 0;
        $shouldPrune = ($currentFileSize > $maxSizeMb * 1024 * 1024);
    }
}

if ($shouldPrune) {
    $pruneResult = Collector::pruneData($outputPath, $pruneConfig);
    if ($pruneResult['removed'] > 0 && !$once) {
        echo date('Y-m-d H:i:s') . " 已清理 {$pruneResult['removed']} 筆過期記錄\n";
    }
}
```

- [ ] **Step 2: 語法檢查**

Run: `php -l bin/collect`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add bin/collect
git commit -m "fix(collect): --once 模式僅在檔案超過大小限制時清理，避免每次 cron 全掃 CSV"
```

---

### Task 6: [MEDIUM] 擴充 Config::validate()

**Files:**
- Modify: `lib/Config.php:118-134`

- [ ] **Step 1: 擴充驗證邏輯**

將 `Config::validate()` 方法替換為：

```php
public static function validate(array $config)
{
    $errors = [];

    if (isset($config['memory_reserve_ratio'])) {
        $ratio = $config['memory_reserve_ratio'];
        if ($ratio < 0 || $ratio >= 1) {
            $errors[] = 'memory_reserve_ratio 必須介於 0 和 1 之間';
        }
    }

    if (isset($config['min_worker_memory']) && $config['min_worker_memory'] < 1) {
        $errors[] = 'min_worker_memory 必須大於 0';
    }

    // spare ratio 交叉驗證
    $minSpare = isset($config['min_spare_ratio']) ? $config['min_spare_ratio'] : 0;
    $maxSpare = isset($config['max_spare_ratio']) ? $config['max_spare_ratio'] : 1;
    $startServers = isset($config['start_servers_ratio']) ? $config['start_servers_ratio'] : 0;

    if ($minSpare > $maxSpare) {
        $errors[] = 'min_spare_ratio 不能大於 max_spare_ratio';
    }
    if ($startServers > $maxSpare) {
        $errors[] = 'start_servers_ratio 不能大於 max_spare_ratio';
    }

    // 數值非負驗證
    if (isset($config['max_requests']) && $config['max_requests'] < 0) {
        $errors[] = 'max_requests 不能為負數';
    }
    if (isset($config['request_terminate_timeout']) && $config['request_terminate_timeout'] < 0) {
        $errors[] = 'request_terminate_timeout 不能為負數';
    }
    if (isset($config['metrics_retention_hours']) && $config['metrics_retention_hours'] < 0) {
        $errors[] = 'metrics_retention_hours 不能為負數';
    }

    return $errors;
}
```

- [ ] **Step 2: 語法檢查**

Run: `php -l lib/Config.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/Config.php
git commit -m "fix(config): 擴充 validate() 增加 spare ratio 交叉驗證和非負檢查"
```

---

### Task 7: [MEDIUM] 修正 utilization_avg 計算

**Files:**
- Modify: `lib/Analyzer.php:119`

- [ ] **Step 1: 改為逐採樣點計算利用率再取平均**

將 `Analyzer::calculateStats()` 中計算利用率的部分（約第 117-120 行）從：

```php
// 利用率
'utilization_avg' => $totalMax > 0 ? round(array_sum($active) / $count / $totalMax, 4) : 0,
'utilization_max' => $totalMax > 0 ? round(max($active) / $totalMax, 4) : 0,
```

改為：

```php
// 利用率（逐採樣點計算）
'utilization_avg' => self::calculateAvgUtilization($active, $total),
'utilization_max' => $totalMax > 0 ? round(max($active) / $totalMax, 4) : 0,
```

然後在類別中新增私有方法：

```php
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
```

- [ ] **Step 2: 語法檢查**

Run: `php -l lib/Analyzer.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/Analyzer.php
git commit -m "fix(analyzer): 修正 utilization_avg 改為逐採樣點計算"
```

---

### Task 8: [MEDIUM] bin/analyze 路徑正規化

**Files:**
- Modify: `bin/analyze:72-77`

- [ ] **Step 1: 對 --input 路徑使用 realpath**

將 `bin/analyze` 第 72-77 行從：

```php
$inputPath = isset($config['input']) ? $config['input'] : null;
if (!$inputPath) {
    fwrite(STDERR, "錯誤：請指定 --input 參數\n");
    fwrite(STDERR, "使用 --help 查看說明\n");
    exit(1);
}
```

改為：

```php
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
```

- [ ] **Step 2: 語法檢查**

Run: `php -l bin/analyze`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add bin/analyze
git commit -m "fix(analyze): 對 --input 路徑使用 realpath 正規化"
```

---

### Task 9: [LOW] bootstrap.php ini_parse_quantity 空字串防守

**Files:**
- Modify: `lib/bootstrap.php:26-48`

- [ ] **Step 1: 加入空字串檢查**

將 `lib/bootstrap.php` 中 `ini_parse_quantity` 函式的開頭從：

```php
function ini_parse_quantity($value) {
    if (is_numeric($value)) {
        return (int) $value;
    }

    $value = trim($value);
```

改為：

```php
function ini_parse_quantity($value) {
    if (is_numeric($value)) {
        return (int) $value;
    }

    $value = trim($value);
    if ($value === '') {
        return 0;
    }
```

- [ ] **Step 2: 語法檢查**

Run: `php -l lib/bootstrap.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/bootstrap.php
git commit -m "fix(bootstrap): ini_parse_quantity polyfill 加入空字串防守"
```

---

### Task 10: [MEDIUM] SystemInfo 記憶體 fallback 加註解 + 文件更新

此項不修改邏輯，僅在程式碼中加入版本限制註解，因 fallback 邏輯本身正確。

**Files:**
- Modify: `lib/SystemInfo.php:83-92`

- [ ] **Step 1: 在 fallback 區塊加入註解**

將 `lib/SystemInfo.php` 第 83 行的註解從：

```php
// 回退：MemFree + Buffers + Cached
```

改為：

```php
// 回退：MemFree + Buffers + Cached（適用於 Linux < 3.14，無 MemAvailable）
// 注意：在有大量 tmpfs 的系統上可能高估可用記憶體
```

- [ ] **Step 2: Commit**

```bash
git add lib/SystemInfo.php
git commit -m "docs(systeminfo): 補充記憶體 fallback 的核心版本限制說明"
```

---

## 不修復項目說明

| Review # | 原因 |
|----------|------|
| #12 (LOW: --no-prune key 轉換一致性) | Task 3 已將 --no-prune 移至 options，此問題隨之消除 |
