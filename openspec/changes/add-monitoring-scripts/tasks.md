# Tasks: 重構專案架構並新增監控分析功能

## 1. 基礎架構 (Phase 1)

- [x] 1.1 建立目錄結構 (`bin/`, `lib/`, `config/`)
- [x] 1.2 建立 `lib/bootstrap.php`（polyfills、require 所有 lib）
- [x] 1.3 建立 `config/default.php`（從現有 `$config` 抽取）

## 2. 核心模組抽取 (Phase 2)

- [x] 2.1 建立 `lib/SystemInfo.php`
  - 從 php-fpm-tuner.php 抽取 getCpuCores()、getFreeMemory()、getWorkerMemory()
  - 新增 `SystemInfo::collect()` 靜態方法

- [x] 2.2 建立 `lib/Config.php`
  - `Config::load($path)` - 載入配置檔
  - `Config::merge($default, $override)` - 合併配置
  - `Config::fromCliArgs($argv)` - 解析 CLI 參數

- [x] 2.3 建立 `lib/Calculator.php`
  - 從 php-fpm-tuner.php 抽取計算邏輯
  - `Calculator::calculate($systemInfo, $config)` - 回傳參數陣列
  - `Calculator::validateParams($params)` - 確保參數邏輯一致性

## 3. 新功能模組 (Phase 3)

- [x] 3.1 建立 `lib/Collector.php`
  - `Collector::fetch($statusUrl)` - 取得 PHP-FPM status
  - `Collector::getWorkerMemoryStats()` - 從 ps 取得 worker 記憶體
  - `Collector::appendToCsv($path, $metrics)` - 寫入 CSV

- [x] 3.2 建立 `lib/Analyzer.php`
  - `Analyzer::loadCsv($path)` - 讀取並解析 CSV
  - `Analyzer::calculateStats($metrics)` - 計算統計值
  - `Analyzer::calculatePES($stats, $config)` - 計算效率分數
  - `Analyzer::generateSuggestions($stats, $config)` - 產生調整建議

## 4. CLI 入口 (Phase 4)

- [x] 4.1 建立 `bin/tuner`
  - 解析 --config, --help, --json 參數
  - 呼叫 SystemInfo、Config、Calculator
  - 格式化輸出

- [x] 4.2 建立 `bin/collect`
  - 解析 --once, --interval, --output, --url 參數
  - 呼叫 Collector
  - 支援持續執行模式

- [x] 4.3 建立 `bin/analyze`
  - 解析 --input, --max-children, --min-spare, --config 參數
  - 呼叫 Analyzer
  - 輸出報告和建議

## 5. 向後相容與整合 (Phase 5)

- [x] 5.1 修改 `php-fpm-tuner.php` 為包裝入口
  - 保留原檔名
  - 內部呼叫新架構模組
  - 確保原有輸出格式不變

- [x] 5.2 整合測試
  - 確認 `php php-fpm-tuner.php` 輸出與重構前一致
  - 確認 `php bin/tuner` 正常運作
  - 確認 `php bin/collect --once` 可收集指標
  - 確認 `php bin/analyze` 可分析並給建議

## 6. 文件更新 (Phase 6)

- [x] 6.1 建立 README.md
  - 專案介紹
  - 安裝與使用說明
  - 完整調優流程

- [x] 6.2 更新 docs/EVALUATION.md
  - 更新腳本路徑
  - 新增使用範例

- [x] 6.3 新增 docs/ARCHITECTURE.md
  - 模組說明
  - API 參考

## 驗收標準

- [x] 所有 bin 腳本可獨立執行
- [x] `php php-fpm-tuner.php` 輸出與重構前一致
- [x] PHP 7.1 環境可正常執行
- [x] 無外部相依（純 PHP 標準函式庫）
