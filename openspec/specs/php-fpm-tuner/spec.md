# php-fpm-tuner Specification

## Purpose
TBD - created by archiving change improve-php-fpm-tuner. Update Purpose after archive.
## Requirements
### Requirement: 變數初始化

腳本 SHALL 在使用任何變數前進行初始化，避免未定義變數警告。

#### Scenario: 記憶體偵測失敗時的預設值
- **WHEN** 系統記憶體資訊無法讀取
- **THEN** `$freeMemory` 應回傳 0
- **AND** 腳本應輸出錯誤訊息並正常退出

#### Scenario: Worker 記憶體偵測失敗時的預設值
- **WHEN** 無法從 `ps` 指令取得 PHP-FPM worker 記憶體
- **THEN** 應回退使用 `memory_limit` 設定值

---

### Requirement: 除以零保護

腳本 SHALL 確保除法運算不會因為分母為零而導致錯誤。

#### Scenario: Worker 記憶體為零時的處理
- **WHEN** `$workerMemory` 計算結果為 0
- **THEN** 應使用最小值 32 MB 作為預設值
- **AND** 繼續執行計算

---

### Requirement: 準確的可用記憶體計算

腳本 SHALL 在 Linux 系統上使用最準確的方式取得可用記憶體。

#### Scenario: Linux 系統有 MemAvailable
- **WHEN** `/proc/meminfo` 包含 `MemAvailable` 欄位
- **THEN** 應使用 `MemAvailable` 作為可用記憶體值

#### Scenario: Linux 系統無 MemAvailable（舊核心）
- **WHEN** `/proc/meminfo` 不包含 `MemAvailable` 欄位
- **THEN** 應使用 `MemFree + Buffers + Cached` 計算可用記憶體

---

### Requirement: PHP 版本相容性

腳本 SHALL 支援 PHP 7.1 及以上版本。

#### Scenario: PHP 8.2 以下版本執行
- **WHEN** 腳本在 PHP 8.1 或更早版本執行
- **AND** 需要解析 `memory_limit` 設定值
- **THEN** 應使用內建 polyfill 函式取代 `ini_parse_quantity()`

#### Scenario: PHP 7.1 版本執行
- **WHEN** 腳本在 PHP 7.1 執行
- **AND** `PHP_OS_FAMILY` 常數不存在
- **THEN** 應使用 `PHP_OS` 常數判斷作業系統類型

---

### Requirement: 參數邏輯一致性

腳本 SHALL 確保輸出的 PHP-FPM 參數符合邏輯約束。

#### Scenario: spare servers 參數關係
- **WHEN** 計算 `start_servers`、`min_spare_servers`、`max_spare_servers`
- **THEN** 應確保 `min_spare_servers <= start_servers <= max_spare_servers`

---

### Requirement: 錯誤處理與使用者回饋

腳本 SHALL 在資源不足時提供明確的錯誤訊息。

#### Scenario: 記憶體不足以啟動 worker
- **WHEN** 計算結果 `max_children < 1`
- **THEN** 應輸出警告訊息說明記憶體不足
- **AND** 以非零狀態碼退出

#### Scenario: 正常執行時的資訊輸出
- **WHEN** 計算成功完成
- **THEN** 應在輸出中包含系統資訊註解（CPU 核心數、可用記憶體、worker 記憶體）

