# PHP-FPM Tuner 架構重構與監控分析規格

## ADDED Requirements

### Requirement: 模組化架構

系統 SHALL 採用模組化架構，將功能分離為獨立的 lib 類別。

#### Scenario: 各模組可獨立使用
- **WHEN** 開發者只需要計算功能
- **THEN** 可以只引用 `lib/Calculator.php`
- **AND** 不需要載入監控或分析相關模組

#### Scenario: 模組透過資料結構通訊
- **WHEN** Calculator 需要系統資訊
- **THEN** 應接收 SystemInfo::collect() 回傳的陣列
- **AND** 不應直接呼叫 SystemInfo 的內部方法

---

### Requirement: 配置管理

系統 SHALL 提供獨立的配置管理機制。

#### Scenario: 載入預設配置
- **WHEN** 未指定配置檔
- **THEN** 應載入 `config/default.php`

#### Scenario: 自訂配置覆蓋預設
- **WHEN** 使用者指定自訂配置檔
- **THEN** 自訂值應覆蓋預設值
- **AND** 未指定的項目應使用預設值

#### Scenario: CLI 參數覆蓋配置
- **WHEN** 使用者透過 CLI 指定參數（如 `--memory-reserve 0.20`）
- **THEN** CLI 參數應覆蓋配置檔的值

---

### Requirement: 參數計算入口 (bin/tuner)

系統 SHALL 提供 `bin/tuner` 作為參數計算的 CLI 入口。

#### Scenario: 基本計算
- **WHEN** 使用者執行 `php bin/tuner`
- **THEN** 應輸出 PHP-FPM pool 配置參數
- **AND** 包含系統資訊註解

#### Scenario: 指定配置檔
- **WHEN** 使用者執行 `php bin/tuner --config /path/to/config.php`
- **THEN** 應使用指定的配置檔計算參數

#### Scenario: JSON 輸出
- **WHEN** 使用者執行 `php bin/tuner --json`
- **THEN** 應以 JSON 格式輸出結果
- **AND** 便於其他程式解析

---

### Requirement: 監控收集入口 (bin/collect)

系統 SHALL 提供 `bin/collect` 作為監控收集的 CLI 入口。

#### Scenario: 單次收集
- **WHEN** 使用者執行 `php bin/collect --once`
- **THEN** 應從 PHP-FPM status 端點收集一次指標
- **AND** 將數據追加到 CSV 檔案

#### Scenario: 持續收集
- **WHEN** 使用者執行 `php bin/collect --interval 60`
- **THEN** 應每 60 秒收集一次指標
- **AND** 持續運行直到中斷

#### Scenario: PHP-FPM status 不可用
- **WHEN** 無法連接 PHP-FPM status 端點
- **THEN** 應輸出錯誤訊息到 stderr
- **AND** 以非零狀態碼退出（單次模式）或繼續等待（持續模式）

---

### Requirement: 配置分析入口 (bin/analyze)

系統 SHALL 提供 `bin/analyze` 作為配置分析的 CLI 入口。

#### Scenario: 基本分析
- **WHEN** 使用者執行 `php bin/analyze --input metrics.csv`
- **THEN** 應讀取 CSV 數據並計算統計指標
- **AND** 輸出評估報告

#### Scenario: 指定當前配置
- **WHEN** 使用者執行 `php bin/analyze --max-children 30`
- **THEN** 應使用指定的參數值進行評估

#### Scenario: PES 分數計算
- **WHEN** 分析完成
- **THEN** 應輸出 Parameter Efficiency Score (0-1)
- **AND** 給出評級（優秀/良好/需改善）

#### Scenario: 調整建議輸出
- **WHEN** 分析發現配置問題
- **THEN** 應輸出具體的調整建議
- **AND** 可選擇輸出建議的新配置參數

---

### Requirement: 向後相容

系統 SHALL 保持向後相容，現有使用方式不受影響。

#### Scenario: 原入口檔案可用
- **WHEN** 使用者執行 `php php-fpm-tuner.php`
- **THEN** 應輸出與重構前相同格式的結果

#### Scenario: 原配置方式可用
- **WHEN** 使用者在 `php-fpm-tuner.php` 開頭修改 `$config` 陣列
- **THEN** 修改應生效（雖然建議使用新的配置檔方式）

---

### Requirement: CSV 輸出格式

監控收集的 CSV SHALL 符合標準格式。

#### Scenario: CSV 標題列
- **WHEN** 輸出檔案不存在
- **THEN** 應先寫入標題列
- **AND** 包含：timestamp, pool, active, idle, total, listen_queue, max_listen_queue, max_active, max_children_reached, slow_requests, memory_mb, avg_worker_mb

#### Scenario: CSV 數據列
- **WHEN** 收集指標
- **THEN** 應輸出符合標題順序的數據
- **AND** 時間戳記使用 `YYYY-MM-DD HH:MM:SS` 格式

---

### Requirement: 命令列介面一致性

所有 CLI 入口 SHALL 提供一致的介面風格。

#### Scenario: 顯示說明
- **WHEN** 使用者執行 `php bin/<command> --help`
- **THEN** 應顯示使用方式、參數說明和範例

#### Scenario: 錯誤處理
- **WHEN** 發生錯誤
- **THEN** 應輸出錯誤訊息到 stderr
- **AND** 以非零狀態碼退出
