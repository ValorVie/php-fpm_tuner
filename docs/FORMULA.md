# PHP-FPM Tuner 公式說明

## 概述

本工具根據系統資源計算 PHP-FPM pool 的建議配置參數，適用於 `pm = dynamic` 模式。

---

## 核心公式

### pm.max_children

```
max_children = floor((可用記憶體 × 0.9) / 單一 worker 記憶體)
```

**原理**：
- 保留 10% 記憶體給系統和其他服務（nginx、MySQL、系統程序等）
- 每個 PHP-FPM worker 都是獨立的程序，佔用獨立記憶體
- 此公式確保所有 worker 同時運作時不會耗盡記憶體

**範例**：
- 可用記憶體：4000 MB
- Worker 記憶體：128 MB
- max_children = floor(4000 × 0.9 / 128) = **28**

---

### pm.start_servers

```
start_servers = min(max_children × 0.25, CPU 核心數 × 4)
```

**原理**：
- PHP-FPM 啟動時預先 fork 的 worker 數量
- 設為 max_children 的 25%，在啟動速度和資源佔用間取得平衡
- 上限為 CPU 核心數 × 4，避免過多程序競爭 CPU

---

### pm.min_spare_servers

```
min_spare_servers = min(max_children × 0.25, CPU 核心數 × 2)
```

**原理**：
- 閒置時保留的最少 worker 數量
- 確保有足夠的 worker 立即處理突發請求
- 過高會浪費記憶體，過低會導致請求延遲

---

### pm.max_spare_servers

```
max_spare_servers = min(max_children × 0.75, CPU 核心數 × 4)
```

**原理**：
- 閒置時允許的最多 worker 數量
- 超過此數量的閒置 worker 會被終止
- 設為 max_children 的 75%，在回應能力和資源效率間取得平衡

---

## 參數關係約束

PHP-FPM 要求：
```
min_spare_servers ≤ start_servers ≤ max_spare_servers
```

本工具會自動調整以確保此關係成立。

---

## 記憶體取得方式

### Linux
1. **優先**：`MemAvailable`（核心 3.14+ 提供，最準確）
2. **回退**：`MemFree + Buffers + Cached`（舊核心）

### macOS
使用 `vm_stat` 計算：
```
可用記憶體 = (Pages free + Pages inactive + Pages purgeable) × page_size
```

### Windows
使用 WMI 查詢 `FreePhysicalMemory`。

---

## Worker 記憶體取得方式

1. **優先**：從 `ps` 指令取得實際運行中 PHP-FPM worker 的平均記憶體
2. **回退**：使用 `php.ini` 的 `memory_limit` 設定值
3. **最小值**：32 MB（防止除以零）

---

## 可信度評估

### 高可信度情境 ⭐⭐⭐⭐⭐

- PHP-FPM 已經運行，有實際 worker 記憶體數據
- 系統記憶體充足（> 2GB 可用）
- 應用程式記憶體使用穩定

### 中等可信度情境 ⭐⭐⭐

- 使用 `memory_limit` 回退值（通常高估實際使用量）
- 系統記憶體偏低（< 1GB 可用）
- 應用程式記憶體使用波動大

### 低可信度情境 ⭐⭐

- 全新安裝，無歷史數據
- 應用有記憶體洩漏
- 使用大量第三方套件，記憶體使用難預測

---

## 調整建議

腳本頂部提供可調整參數，修改後重新執行即可套用。

### 保守配置（生產環境建議）

```php
// 記憶體保留從 10% 增加到 20%
$config['memory_reserve_ratio'] = 0.20;

// 若使用大型框架，提高 worker 記憶體估計
$config['min_worker_memory'] = 128;
```

### 激進配置（測試或低流量環境）

```php
// 記憶體保留從 10% 降為 5%
$config['memory_reserve_ratio'] = 0.05;
```

### 參數速查表

| 參數 | 預設值 | 保守值 | 說明 |
|------|--------|--------|------|
| `memory_reserve_ratio` | 0.10 | 0.20 | 系統記憶體保留比例 |
| `min_worker_memory` | 32 | 128 | Worker 記憶體最小估計 (MB) |
| `start_servers_ratio` | 0.25 | 0.20 | 啟動 worker 比例 |
| `min_spare_ratio` | 0.25 | 0.20 | 最小閒置 worker 比例 |
| `max_spare_ratio` | 0.75 | 0.60 | 最大閒置 worker 比例 |

---

## 監控與調整

計算出的值是**起始點**，實際部署後應監控：

| 指標 | 檢查方式 | 調整建議 |
|------|----------|----------|
| 記憶體使用 | `free -m` 或 `htop` | OOM → 降低 max_children |
| 請求佇列 | `php-fpm status` 的 `listen queue` | 佇列常滿 → 提高 max_children |
| Worker 利用率 | `active processes / max_children` | 常 > 80% → 提高 max_children |
| 回應時間 | 應用程式 APM | 變慢 → 檢查是否記憶體交換 |

---

## 參考資料

- [PHP-FPM Configuration](https://www.php.net/manual/en/install.fpm.configuration.php)
- [Tuning PHP-FPM for Performance](https://www.php.net/manual/en/install.fpm.configuration.php#pm)
- [Linux /proc/meminfo](https://www.kernel.org/doc/Documentation/filesystems/proc.txt)

---

## 公式來源

本工具的公式基於以下常見實踐：

1. **記憶體計算**：業界標準做法，確保不超賣記憶體
2. **25% / 75% 比例**：PHP 官方文件建議的 spare servers 範圍
3. **CPU 倍數限制**：避免過多程序導致上下文切換開銷

這些是經驗法則，適用於大多數 Web 應用。特殊場景（如長時間運行的任務、高記憶體消耗的 API）可能需要手動調整。
