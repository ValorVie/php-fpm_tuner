# PHP-FPM Tuner

根據系統資源自動計算最佳的 PHP-FPM pool 配置參數，並提供完整的監控與分析流程。

## 功能

- **參數計算**：根據 CPU 核心數、可用記憶體、worker 記憶體自動計算最佳配置
- **監控收集**：從 PHP-FPM status 端點收集運行指標
- **配置分析**：分析監控數據，計算 PES 分數並提供調整建議

## 系統需求

- PHP 7.1+
- 無外部相依（純 PHP 標準函式庫）

## 快速開始

### 1. 計算建議參數

```bash
php bin/tuner
```

輸出範例：
```
# PHP-FPM Tuner 計算結果
# ─────────────────────────────────────
# 系統資訊:
#   CPU 核心數: 4
#   可用記憶體: 2048 MB
#   Worker 記憶體: 64 MB
#   記憶體保留: 10%
# ─────────────────────────────────────

pm = dynamic
pm.max_children = 28
pm.start_servers = 7
pm.min_spare_servers = 7
pm.max_spare_servers = 16
pm.max_requests = 500
```

### 2. 收集監控數據

啟用 PHP-FPM status（在 pool 配置中加入）：
```ini
pm.status_path = /fpm-status
```

收集指標：
```bash
# 單次收集
php bin/collect --once --output /var/log/php-fpm/metrics.csv

# 持續收集（每 60 秒）
php bin/collect --interval 60

# 使用 cron 每分鐘收集
* * * * * /usr/bin/php /path/to/bin/collect --once
```

### 3. 分析配置效果

```bash
php bin/analyze --input /var/log/php-fpm/metrics.csv --max-children 28
```

輸出範例：
```
# PHP-FPM 配置分析報告
# ═══════════════════════════════════════════════════════════════

## PES 評分
總分: 0.85 (良好)

## 調整建議
✅ 資訊: 當前配置運作良好，無需調整
```

## 完整調優流程

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  bin/tuner  │────▶│ bin/collect │────▶│ bin/analyze │
│  計算參數   │     │  監控收集   │     │  分析建議   │
└─────────────┘     └─────────────┘     └─────────────┘
       │                  │                    │
       ▼                  ▼                    ▼
   套用配置           CSV 數據            調整建議
                                              │
                                              ▼
                                      根據建議調整
                                      重新執行 tuner
```

### Step 1: 計算初始參數

在目標伺服器上執行：

```bash
cd /path/to/php-fpm_tuner
php bin/tuner
```

將輸出的參數套用到 PHP-FPM pool 配置：

```bash
# 備份現有配置
sudo cp /etc/php-fpm.d/www.conf /etc/php-fpm.d/www.conf.bak

# 編輯配置
sudo vim /etc/php-fpm.d/www.conf
```

套用建議的參數後重載 PHP-FPM：

```bash
sudo systemctl reload php-fpm
```

### Step 2: 啟用 PHP-FPM Status

在 pool 配置中加入（通常是 `/etc/php-fpm.d/www.conf`）：

```ini
; 啟用 status 端點
pm.status_path = /fpm-status
```

重載 PHP-FPM：

```bash
sudo systemctl reload php-fpm
```

#### 透過 Nginx 存取 Status（推薦）

建立專用的 Nginx 配置（如 `/etc/nginx/conf.d/fpm-status.local.conf`）：

```nginx
# 只綁定 127.0.0.1:8001，外部完全無法連線
server {
    listen 127.0.0.1:8001;
    server_name localhost;

    # 僅允許本機存取
    location = /fpm-status {
        include fastcgi_params;
        # 與你的 PHP-FPM listen 一致
        fastcgi_pass 127.0.0.1:9000;

        fastcgi_param SCRIPT_FILENAME /fpm-status;
        fastcgi_param SCRIPT_NAME     /fpm-status;

        # 雙重限制
        allow 127.0.0.1;
        deny  all;
    }

    # 其餘一律拒絕
    location / {
        return 403;
    }

    access_log /var/log/nginx/fpm-status.local.access.log;
    error_log  /var/log/nginx/fpm-status.local.error.log;
}
```

測試並重載 Nginx：

```bash
sudo nginx -t && sudo systemctl reload nginx
```

驗證 status 端點：

```bash
curl "http://127.0.0.1:8001/fpm-status?json"
```

收集時使用對應的 URL：

```bash
php bin/collect --once --url "http://127.0.0.1:8001/fpm-status?json"
```

### Step 3: 設定監控收集

**方法 A：使用 cron 定期收集（推薦）**

```bash
# 編輯 crontab
crontab -e

# 加入以下內容（每分鐘收集一次）
* * * * * /usr/bin/php /path/to/php-fpm_tuner/bin/collect --once --output /var/log/php-fpm/metrics.csv 2>/dev/null
```

**方法 B：背景持續收集**

```bash
# 使用 nohup 背景執行
nohup php bin/collect --interval 60 --output /var/log/php-fpm/metrics.csv &

# 或使用 systemd service（更穩定）
```

### Step 4: 收集足夠數據

建議收集至少 **24 小時** 的數據，以涵蓋：
- 正常流量時段
- 尖峰流量時段
- 低流量時段

檢查收集狀態：

```bash
# 查看已收集的數據量
wc -l /var/log/php-fpm/metrics.csv

# 查看最新幾筆數據
tail -5 /var/log/php-fpm/metrics.csv
```

### Step 5: 分析並取得建議

```bash
# 基本分析
php bin/analyze --input /var/log/php-fpm/metrics.csv

# 指定當前配置以取得更精確的評估
php bin/analyze --input /var/log/php-fpm/metrics.csv \
    --max-children 30 \
    --min-spare 8
```

### Step 6: 根據建議調整

根據分析結果調整參數：

| 建議類型 | 說明 | 調整方式 |
|----------|------|----------|
| 增加容量 | 佇列發生率過高 | `php bin/tuner --memory-reserve 0.05` |
| Max 觸及 | max_children 不足 | `php bin/tuner --memory-reserve 0.05` |
| 過度配置 | 利用率過低 | `php bin/tuner --memory-reserve 0.20` |
| Spare 不足 | 閒置 worker 不夠 | 調整 min_spare_ratio |

重新計算參數並套用：

```bash
# 例如：減少記憶體保留以增加 worker 數量
php bin/tuner --memory-reserve 0.05

# 或：增加記憶體保留（保守配置）
php bin/tuner --memory-reserve 0.20
```

### Step 7: 持續監控（可選）

建立監控迴圈，定期評估配置效果：

```bash
#!/bin/bash
# weekly-analyze.sh - 每週執行一次

# 分析過去一週的數據
php /path/to/bin/analyze \
    --input /var/log/php-fpm/metrics.csv \
    --max-children 30 \
    --json > /var/log/php-fpm/weekly-report.json

# 可整合到告警系統
```

---

## CLI 參考

### bin/tuner

```bash
php bin/tuner [選項]

選項：
  --config <path>              指定配置檔路徑
  --memory-reserve <ratio>     記憶體保留比例 (0-1)
  --json                       以 JSON 格式輸出
  -h, --help                   顯示說明
```

### bin/collect

```bash
php bin/collect [選項]

選項：
  --once                     單次收集後退出
  --interval <seconds>       收集間隔（秒），預設 60
  --output <path>            CSV 輸出路徑
  --url <url>                PHP-FPM status URL
  -h, --help                 顯示說明

數據管理選項：
  --prune                    只執行數據清理，不收集
  --stats                    顯示數據檔案統計
  --retention <hours>        數據保留時間（小時），預設 48
  --max-size <mb>            檔案大小上限（MB），預設 50
  --max-rows <n>             數據筆數上限
  --no-prune                 停用自動清理
```

### bin/analyze

```bash
php bin/analyze --input <path> [選項]

選項：
  --input <path>             CSV 輸入路徑（必要）
  --max-children <n>         當前 max_children 設定
  --min-spare <n>            當前 min_spare_servers 設定
  --json                     以 JSON 格式輸出
  -h, --help                 顯示說明
```

## 向後相容

原有的使用方式仍然支援：

```bash
php php-fpm-tuner.php
```

## 配置

可透過配置檔自訂參數：

```bash
php bin/tuner --config /path/to/config.php
```

配置檔範例請參考 `config/default.php`。

## 數據保留與清理

監控數據預設保留 **48 小時**，超過時間或大小限制的舊數據會自動清除。

### 預設設定

| 設定 | 預設值 | 說明 |
|------|--------|------|
| `metrics_retention_hours` | 48 | 數據保留時間（小時） |
| `metrics_max_size_mb` | 50 | CSV 檔案大小上限（MB） |
| `metrics_max_rows` | 0 | 數據筆數上限（0=不限制） |

### 查看數據統計

```bash
php bin/collect --stats
```

輸出範例：
```
# 數據檔案統計
# ─────────────────────────────────────
檔案路徑: /var/log/php-fpm/metrics.csv
檔案大小: 2.35 MB (2463744 bytes)
記錄筆數: 2880
最舊記錄: 2024-01-01 00:00:00
最新記錄: 2024-01-02 23:59:00

# 保留設定
# ─────────────────────────────────────
保留時間: 48 小時
大小上限: 50 MB
筆數上限: 不限制
```

### 手動清理

```bash
# 執行清理（使用預設設定）
php bin/collect --prune

# 清理超過 24 小時的數據
php bin/collect --prune --retention 24

# 限制檔案大小為 10 MB
php bin/collect --prune --max-size 10
```

### 調整保留設定

**方法 A：透過 CLI 參數**

```bash
# 保留 7 天數據
php bin/collect --once --retention 168

# 限制檔案大小為 100 MB
php bin/collect --once --max-size 100

# 停用自動清理
php bin/collect --once --no-prune
```

**方法 B：透過配置檔**

編輯 `config/default.php` 或建立自訂配置檔：

```php
return [
    // ... 其他設定 ...

    // 數據保留設定
    'metrics_retention_hours' => 168,  // 保留 7 天
    'metrics_max_size_mb' => 100,      // 100 MB 上限
    'metrics_max_rows' => 10080,       // 最多 10080 筆（7天 × 24小時 × 60分鐘）
];
```

### 清理時機

- **單次收集模式**（`--once`）：每次收集後檢查並清理
- **持續收集模式**：每 100 次收集後檢查並清理
- **手動清理**：使用 `--prune` 參數

## 效能影響分析

在背景每分鐘執行一次監控收集，對系統效能的影響極小。

### 單次執行資源消耗

| 項目 | 消耗 | 說明 |
|------|------|------|
| **執行時間** | 50-100 ms | 包含 PHP 啟動、HTTP 請求、CSV 寫入 |
| **CPU 時間** | ~20-50 ms | user + system time |
| **記憶體** | ~0.5 MB | 峰值使用，執行後立即釋放 |
| **I/O 寫入** | ~100 bytes | 單筆 CSV 記錄 |

### 詳細時間分解

```
PHP CLI 啟動      ████████████░░░░░░░░  30-50 ms
HTTP 請求（本地） ██████░░░░░░░░░░░░░░  5-20 ms
ps 命令執行       ████████████████████  40-50 ms（可選）
模組載入          █░░░░░░░░░░░░░░░░░░░  ~2 ms
CSV 寫入          █░░░░░░░░░░░░░░░░░░░  ~1 ms
───────────────────────────────────────
總計                                   50-100 ms
```

### 每分鐘執行的整體影響

| 指標 | 影響 | 計算方式 |
|------|------|----------|
| **CPU 佔用率** | < 0.2% | 0.1s ÷ 60s = 0.17% |
| **每日 CPU 時間** | ~2.4 分鐘 | 0.1s × 1440 = 144s |
| **每日磁碟寫入** | ~144 KB | 100 bytes × 1440 |
| **每日新增記錄** | 1440 筆 | 每分鐘 1 筆 |

### 與其他監控方案比較

| 方案 | 記憶體 | CPU | 部署複雜度 |
|------|--------|-----|------------|
| **本工具** | 0.5 MB（瞬時） | < 0.2% | 簡單 |
| Prometheus + php-fpm_exporter | 50-100 MB | 1-2% | 中等 |
| Datadog Agent | 200-400 MB | 2-5% | 中等 |
| 完整 APM（New Relic） | 100-200 MB | 3-8% | 複雜 |

### 結論

- **影響可忽略**：每分鐘 0.1 秒的執行時間對任何生產伺服器都微不足道
- **無常駐進程**：使用 cron 觸發，不佔用常駐記憶體
- **零外部相依**：不需要額外的監控代理或服務
- **適合場景**：輕量監控需求、資源受限環境、簡單部署

### 效能優化建議

如需進一步降低影響：

```bash
# 1. 降低收集頻率（每 5 分鐘）
*/5 * * * * php /path/to/bin/collect --once

# 2. 停用 worker 記憶體統計（省略 ps 命令，節省 40ms）
# 編輯 lib/Collector.php 的 getWorkerMemoryStats() 直接回傳空值

# 3. 使用 nice 降低優先權
* * * * * nice -n 19 php /path/to/bin/collect --once
```

## 文件

- [計算公式說明](docs/FORMULA.md)
- [動態調優方案](docs/DYNAMIC_TUNING.md)
- [配置評估方法](docs/EVALUATION.md)
- [架構說明](docs/ARCHITECTURE.md)

## 授權

MIT License
