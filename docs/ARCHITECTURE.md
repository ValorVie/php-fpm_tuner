# PHP-FPM Tuner 架構說明

## 目錄結構

```
php-fpm_tuner/
├── bin/                        # CLI 入口（僅處理 I/O）
│   ├── tuner                   # 參數計算
│   ├── collect                 # 監控收集
│   └── analyze                 # 配置分析
├── lib/                        # 核心邏輯（純函式/類別）
│   ├── bootstrap.php           # 初始化（polyfills、載入模組）
│   ├── Config.php              # 配置管理
│   ├── SystemInfo.php          # 系統資訊收集
│   ├── Calculator.php          # 參數計算
│   ├── Collector.php           # 指標收集
│   └── Analyzer.php            # 分析評估
├── config/
│   └── default.php             # 預設配置
├── docs/                       # 文件
└── php-fpm-tuner.php           # 向後相容入口
```

## 模組解耦

**原則：資料進、資料出，不共享狀態**

```
                    ┌──────────────┐
                    │    Config    │
                    │   (設定值)   │
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

## API 參考

### Config

配置管理類別。

```php
// 載入配置檔
$config = Config::load('/path/to/config.php');

// 合併配置（自訂值覆蓋預設值）
$config = Config::merge($default, $override);

// 從 CLI 參數解析
$cliArgs = Config::fromCliArgs($argv);
// 回傳：['config_path' => ?string, 'overrides' => array, 'options' => array]

// 驗證配置
$errors = Config::validate($config);
```

### SystemInfo

系統資訊收集類別。

```php
// 收集所有系統資訊
$info = SystemInfo::collect($config);
// 回傳：['cpu_cores' => int, 'free_memory' => int, 'worker_memory' => int]

// 個別方法
$cores = SystemInfo::getCpuCores();
$memory = SystemInfo::getFreeMemory();
$workerMem = SystemInfo::getWorkerMemory($minMemory);
```

### Calculator

參數計算類別。

```php
// 計算 PHP-FPM pool 參數
$params = Calculator::calculate($systemInfo, $config);
// 回傳：[
//   'error' => bool,
//   'max_children' => int,
//   'start_servers' => int,
//   'min_spare_servers' => int,
//   'max_spare_servers' => int,
//   'max_requests' => int,
//   ...
// ]

// 驗證參數邏輯一致性
$params = Calculator::validateParams($params);
```

### Collector

指標收集類別。

```php
// 從 PHP-FPM status 取得指標
$metrics = Collector::fetch('http://127.0.0.1:9000/fpm-status?json');
// 回傳：指標陣列或 null（失敗時）

// 取得 worker 記憶體統計
$stats = Collector::getWorkerMemoryStats();
// 回傳：['total_mb' => int, 'avg_mb' => int, 'count' => int]

// 追加到 CSV
Collector::appendToCsv('/path/to/metrics.csv', $metrics);
```

### Analyzer

分析評估類別。

```php
// 載入 CSV
$metrics = Analyzer::loadCsv('/path/to/metrics.csv');

// 計算統計值
$stats = Analyzer::calculateStats($metrics);

// 計算 PES 分數
$pes = Analyzer::calculatePES($stats, $currentConfig);
// 回傳：['pes_score' => float, 'rating' => string, 'components' => array]

// 產生建議
$suggestions = Analyzer::generateSuggestions($stats, $pes, $currentConfig);

// 完整分析（統合以上功能）
$result = Analyzer::analyze($metrics, $currentConfig);
```

## CLI 入口設計原則

入口腳本只做三件事：

1. 解析命令列參數
2. 呼叫 lib 模組
3. 格式化輸出

```php
#!/usr/bin/env php
<?php
require_once __DIR__ . '/../lib/bootstrap.php';

// 1. 解析參數
$cliArgs = Config::fromCliArgs($argv);

// 2. 呼叫模組
$config = Config::load($cliArgs['config_path']);
$systemInfo = SystemInfo::collect($config);
$params = Calculator::calculate($systemInfo, $config);

// 3. 輸出
echo "pm.max_children = {$params['max_children']}\n";
```

## CSV Schema

監控收集的 CSV 格式：

| 欄位 | 類型 | 說明 |
|------|------|------|
| timestamp | string | YYYY-MM-DD HH:MM:SS |
| pool | string | Pool 名稱 |
| active | int | 活躍 workers |
| idle | int | 閒置 workers |
| total | int | 總 workers |
| listen_queue | int | 當前佇列長度 |
| max_listen_queue | int | 歷史最大佇列 |
| max_active | int | 歷史最大活躍 |
| max_children_reached | int | 達到上限次數 |
| slow_requests | int | 慢請求數 |
| memory_mb | int | 總記憶體使用 (MB) |
| avg_worker_mb | int | 平均 worker 記憶體 (MB) |

## 設計決策

### 為什麼使用靜態方法？

- 簡化使用：不需要實例化
- 避免狀態管理複雜度
- 適合工具型腳本

### 為什麼不使用 namespace？

- 保持 PHP 7.1 相容性
- 單一專案不需要命名空間隔離
- 簡化引用

### 為什麼不使用 Composer？

- 零外部相依是設計目標
- 適合在任何 PHP 環境直接執行
- 避免 Composer 安裝步驟
