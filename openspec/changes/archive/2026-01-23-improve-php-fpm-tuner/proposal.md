# Change: 改進 PHP-FPM Tuner 腳本的穩定性與準確度

## Why

目前的 `php-fpm-tuner.php` 腳本存在多個潛在問題：
1. 未初始化變數可能導致執行時警告或錯誤
2. 記憶體計算使用 `MemFree` 而非更準確的 `MemAvailable`
3. 除以零風險可能導致 fatal error
4. `ini_parse_quantity()` 僅支援 PHP 8.2+，限制了相容性

## What Changes

- **修正**：初始化所有變數，避免未定義變數警告
- **修正**：加入除以零保護機制
- **改進**：Linux 系統改用 `MemAvailable` 取得更準確的可用記憶體
- **改進**：加入 `ini_parse_quantity()` 的 polyfill 以支援 PHP < 8.2
- **改進**：加入 `PHP_OS_FAMILY` 的 polyfill 以支援 PHP 7.1
- **改進**：加入輸入驗證與更詳細的輸出資訊
- **改進**：確保 `min_spare_servers <= start_servers <= max_spare_servers` 的邏輯一致性

## Impact

- Affected specs: `php-fpm-tuner`（新建立）
- Affected code: `php-fpm-tuner.php`
- 向後相容：無破壞性變更，僅改善現有行為
