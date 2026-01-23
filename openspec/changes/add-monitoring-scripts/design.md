# Design: PHP-FPM Tuner 架構重構與監控分析功能

## Context

目前 `php-fpm-tuner.php` 是單一檔案，存在以下問題：
1. 配置與邏輯混合，難以被其他工具引用
2. 新增監控和分析功能會導致重複程式碼
3. 無法獨立測試各功能模組

需要重構為模組化架構，支援完整的調優流程：計算 → 監控 → 分析 → 調整建議。

## Goals / Non-Goals

### Goals
- 將現有功能重構為可複用模組
- 新增監控收集和配置分析功能
- 保持向後相容（原 `php-fpm-tuner.php` 仍可使用）
- 各模組可獨立執行，也可組合使用
- 支援 PHP 7.1+

### Non-Goals
- 不實作自動調整功能（僅提供建議）
- 不引入 Composer 或外部相依
- 不使用 namespace（保持簡單，相容 PHP 7.1）

## Decisions

### 1. 目錄結構

```
php-fpm_tuner/
├── bin/                        # CLI 入口（僅處理 I/O）
│   ├── tuner                   # 參數計算
│   ├── collect                 # 監控收集
│   └── analyze                 # 配置分析
├── lib/                        # 核心邏輯（純函式/類別）
│   ├── bootstrap.php           # 初始化
│   ├── Config.php              # 配置管理
│   ├── SystemInfo.php          # 系統資訊
│   ├── Calculator.php          # 參數計算
│   ├── Collector.php           # 指標收集
│   └── Analyzer.php            # 分析評估
├── config/
│   └── default.php             # 預設配置
├── docs/
└── php-fpm-tuner.php           # 向後相容入口
```

**原因**：
- `bin/` 與 `lib/` 分離，符合 Unix 慣例
- 便於未來加入測試（只測 lib）
- 配置獨立，便於使用者自訂

### 2. 模組解耦策略

**原則：資料進、資料出，不共享狀態**

```
┌─────────────────────────────────────────────────────────────────┐
│                        Data Flow                                 │
└─────────────────────────────────────────────────────────────────┘

                    ┌──────────────┐
                    │    Config    │
                    │   (設定值)    │
                    └──────┬───────┘
                           │
         ┌─────────────────┼─────────────────┐
         ▼                 ▼                 ▼
┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│ SystemInfo  │    │  Collector  │    │  Analyzer   │
│ (系統資源)  │    │ (FPM 指標)  │    │ (CSV 數據)  │
└──────┬──────┘    └──────┬──────┘    └──────┬──────┘
       │                  │                  │
       │ 陣列             │ CSV              │ 陣列
       ▼                  ▼                  ▼
┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│ Calculator  │    │  CSV 檔案   │    │  報告輸出   │
│ (計算參數)  │    │             │    │  + 建議     │
└──────┬──────┘    └─────────────┘    └─────────────┘
       │
       │ 陣列
       ▼
┌─────────────┐
│  參數輸出   │
└─────────────┘
```

**各模組介面定義**：

```php
// SystemInfo - 收集系統資源
class SystemInfo {
    public static function collect(): array;
    // 回傳: ['cpu_cores' => int, 'free_memory' => int, 'worker_memory' => int]
}

// Config - 配置管理
class Config {
    public static function load(string $path = null): array;
    public static function merge(array $default, array $override): array;
    // 回傳: 完整配置陣列
}

// Calculator - 參數計算
class Calculator {
    public static function calculate(array $systemInfo, array $config): array;
    // 回傳: ['max_children' => int, 'start_servers' => int, ...]
}

// Collector - 指標收集
class Collector {
    public static function fetch(string $statusUrl): ?array;
    public static function appendToCsv(string $path, array $metrics): void;
    // fetch 回傳: PHP-FPM status 指標陣列
}

// Analyzer - 分析評估
class Analyzer {
    public static function loadCsv(string $path): array;
    public static function analyze(array $metrics, array $currentConfig): array;
    // 回傳: ['pes_score' => float, 'utilization' => float, 'suggestions' => [...]]
}
```

### 3. CLI 入口設計

**原則：入口腳本只做三件事**
1. 解析命令列參數
2. 呼叫 lib 模組
3. 格式化輸出

```php
#!/usr/bin/env php
<?php
// bin/tuner

require_once __DIR__ . '/../lib/bootstrap.php';

// 1. 解析參數
$options = getopt('h', ['help', 'config:']);

// 2. 呼叫模組
$config = Config::load($options['config'] ?? null);
$systemInfo = SystemInfo::collect();
$params = Calculator::calculate($systemInfo, $config);

// 3. 輸出
Output::printParams($params, $systemInfo);
```

### 4. 配置檔格式

```php
<?php
// config/default.php
return [
    // 記憶體
    'memory_reserve_ratio' => 0.10,
    'min_worker_memory' => 32,

    // PM 參數比例
    'start_servers_ratio' => 0.25,
    'start_servers_cpu_multiplier' => 4,
    'min_spare_ratio' => 0.25,
    'min_spare_cpu_multiplier' => 2,
    'max_spare_ratio' => 0.75,
    'max_spare_cpu_multiplier' => 4,

    // 其他參數
    'max_requests' => 500,
    'request_terminate_timeout' => 30,
    'request_slowlog_timeout' => 5,

    // 監控設定
    'fpm_status_url' => 'http://127.0.0.1:9001/fpm-status?json',
    'metrics_output' => '/var/log/php-fpm/metrics.csv',
];
```

### 5. 向後相容

```php
<?php
// php-fpm-tuner.php（保留原檔名）

// 載入新架構
require_once __DIR__ . '/lib/bootstrap.php';

// 如果直接執行此檔案，呼叫 tuner
if (php_sapi_name() === 'cli' && realpath($argv[0]) === __FILE__) {
    require __DIR__ . '/bin/tuner';
}
```

### 6. 完整調優流程

```bash
# Step 1: 計算初始參數
php bin/tuner > /etc/php-fpm.d/www.conf.suggested
# 套用後 reload php-fpm

# Step 2: 收集監控數據（cron 每分鐘）
* * * * * php /path/to/bin/collect --once

# Step 3: 分析並取得建議（手動或定期）
php bin/analyze --input /var/log/php-fpm/metrics.csv

# Step 4: 若有建議，重新計算參數
php bin/tuner --memory-reserve 0.20  # 根據建議調整配置
```

## Risks / Trade-offs

| 風險 | 緩解措施 |
|------|----------|
| 重構可能引入 bug | 保留原檔案向後相容，分階段重構 |
| 類別增加複雜度 | 使用靜態方法，避免物件狀態管理 |
| PHP 7.1 不支援某些語法 | 避免使用 typed properties、union types |

## CSV Schema

```csv
timestamp,pool,active,idle,total,listen_queue,max_listen_queue,max_active,max_children_reached,slow_requests,memory_mb,avg_worker_mb
```

## Open Questions

1. 是否需要支援 JSON 輸出格式？（便於整合其他工具）
2. 是否需要支援多 pool 監控？
