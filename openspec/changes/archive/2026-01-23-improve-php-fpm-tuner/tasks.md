# Tasks: 改進 PHP-FPM Tuner 腳本

## 1. 錯誤修正（高優先級）

- [x] 1.1 初始化 `getFreeMemory()` 中的 `$freeMemory` 變數
- [x] 1.2 初始化 `getWorkerMemory()` 中的 `$processMemory` 變數
- [x] 1.3 加入 `$workerMemory` 除以零保護（設定最小值 32 MB）

## 2. 記憶體計算改進（中優先級）

- [x] 2.1 修改 Linux 記憶體偵測，優先使用 `MemAvailable`
- [x] 2.2 若無 `MemAvailable`，回退到 `MemFree + Buffers + Cached`
- [x] 2.3 加入 `ini_parse_quantity()` polyfill 以支援 PHP < 8.2
- [x] 2.4 加入 `PHP_OS_FAMILY` polyfill 以支援 PHP 7.1
- [x] 2.5 加入 macOS (Darwin) 支援

## 3. 計算邏輯改進（中優先級）

- [x] 3.1 確保 `min_spare_servers <= start_servers <= max_spare_servers`
- [x] 3.2 加入 `$maxChildren < 1` 時的錯誤處理

## 4. 輸出增強（低優先級）

- [x] 4.1 輸出系統資訊註解（CPU 核心數、記憶體、worker 記憶體）
- [x] 4.2 加入記憶體不足時的警告訊息

## 5. 驗證

- [x] 5.1 在 macOS 系統測試腳本執行
- [x] 5.2 確認 PHP 語法正確（php -l 驗證通過）
- [x] 5.3 確認輸出格式符合 PHP-FPM 配置語法
