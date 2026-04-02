# PHP-FPM Tuner

根據系統資源和監控數據，自動計算、評估和優化 PHP-FPM pool 配置參數。

## 功能

- **靜態計算**（`bin/tuner`）：根據系統記憶體和 CPU 計算初始配置
- **監控收集**（`bin/collect`）：從 PHP-FPM status 端點收集運行指標到 CSV
- **配置評估**（`bin/analyze`）：分析監控數據，計算 PES 分數並診斷問題
- **優化建議**（`bin/optimize`）：根據監控數據產生具體的設定調整建議

## 系統需求

- PHP 7.1+
- 無外部相依（純 PHP 標準函式庫）

## 三階段調優流程

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌──────────────┐
│  bin/tuner  │────▶│ bin/collect │────▶│ bin/analyze │────▶│ bin/optimize │
│  靜態計算   │     │  監控收集   │     │  評估診斷   │     │  優化建議    │
└─────────────┘     └─────────────┘     └─────────────┘     └──────────────┘
       │                  │                    │                    │
       ▼                  ▼                    ▼                    ▼
   初始參數           CSV 數據          PES 分數+診斷        具體設定值
                                                                   │
                                                                   ▼
                                                            套用 → 觀察
                                                            → 再次 optimize
```

## 快速開始

### 1. 計算初始參數

```bash
php bin/tuner
```

輸出範例：
```
# PHP-FPM Tuner 計算結果
# ─────────────────────────────────────
# 系統資訊:
#   CPU 核心數: 4
#   總記憶體: 4096 MB
#   可用記憶體: 2048 MB
#   Worker 記憶體: 64 MB (RSS)
#   計算模式: total
#   記憶體保留: 20%
# ─────────────────────────────────────

pm = dynamic
pm.max_children = 51
pm.start_servers = 8
pm.min_spare_servers = 8
pm.max_spare_servers = 16
pm.max_requests = 500
```

將輸出的參數套用到 PHP-FPM pool 配置後重載：

```bash
sudo systemctl reload php-fpm
```

### 2. 啟用 PHP-FPM Status 並收集監控數據

在 pool 配置中啟用 status（通常是 `/etc/php-fpm.d/www.conf`）：

```ini
pm.status_path = /fpm-status
```

收集指標：

```bash
# 單次收集（搭配 cron 使用）
php bin/collect --once

# 持續收集（每 60 秒）
php bin/collect --interval 60

# 使用 cron 每分鐘收集
* * * * * /usr/bin/php /path/to/bin/collect --once
```

建議收集至少 **24 小時** 數據以涵蓋峰值和離峰時段。

### 3. 評估配置效果

```bash
php bin/analyze --input /var/log/php-fpm/metrics.csv --max-children 51
```

輸出範例：
```
# PHP-FPM 配置分析報告
# ═══════════════════════════════════════════════════════════════

## 效能指標
平均活躍 workers: 12.5 (最高: 28, P95: 24.0)
佇列發生次數: 3 (5.00%)
max_children 觸及次數: 0（觀測期間事件數）
平均利用率: 24.5%

## 峰值分析
峰值時段: 9:00, 10:00, 11:00, 14:00, 15:00, 16:00
峰值利用率: 41.2% (平均 active: 21.0)

## PES 評分
總分: 0.82 (良好)

## 診斷
!!  高優先: 佇列發生率 5.0% 過高

執行 php bin/optimize --input <path> 取得具體設定建議
```

### 4. 取得優化建議

```bash
php bin/optimize --input /var/log/php-fpm/metrics.csv \
    --max-children 51 --min-spare 8 --max-spare 16 --start-servers 8
```

輸出範例：
```
# PHP-FPM 優化建議
# ═══════════════════════════════════════════════════════════════

## 資料摘要
採樣數量: 1440 | 時間範圍: 24h
信心度: high | 資料缺口: 0

## PES 評分
當前: 0.82 (良好)

## 建議配置
                          當前    建議    目標    原因
─────────────────────────────────────────────────────────
* max_children            51      51      51
  start_servers           8       8       10      離峰平均 active 6.5
* min_spare_servers       8       10      10      峰值平均 active 21.0 × 0.25
  max_spare_servers       16      16      38      配合目標 max_children 51 × 0.75

## 限制條件
記憶體上限: 51 workers (4096MB total, 64MB/worker, 20% reserve)
CPU 限制: 32 workers (4 cores × 8)
生效限制: none

## 下一步
1. 套用建議值到 PHP-FPM pool 配置
2. 執行 systemctl reload php-fpm
3. 觀察 24 小時
4. 再次執行 php bin/optimize 確認效果
```

---

## 連線方式

`bin/collect` 支援三種連線方式存取 PHP-FPM status：

### Unix Socket（推薦）

直接透過 FCGI 協議連接，無需 Web 伺服器代理：

```bash
php bin/collect --once --url /var/run/php-fpm.sock
```

### TCP 直連

```bash
php bin/collect --once --url 127.0.0.1:9000
```

### HTTP（透過 Nginx 代理）

建立 Nginx 配置（如 `/etc/nginx/conf.d/fpm-status.local.conf`）：

```nginx
server {
    listen 127.0.0.1:8001;
    server_name localhost;

    location = /fpm-status {
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME /fpm-status;
        fastcgi_param SCRIPT_NAME     /fpm-status;
        allow 127.0.0.1;
        deny  all;
    }

    location / { return 403; }
}
```

```bash
php bin/collect --once --url "http://127.0.0.1:8001/fpm-status?json"
```

---

## 記憶體計算模式

| 模式 | 設定值 | 行為 | 適用場景 |
|------|--------|------|----------|
| **total**（預設） | `memory_mode=total` | 使用系統總記憶體計算，結果穩定可重現 | 生產環境 |
| **available** | `memory_mode=available` | 使用當前可用記憶體，結果隨系統狀態波動 | 向後相容 |

`memory_reserve_ratio` 的語意：保留系統記憶體的 X% 給 OS 和其他服務（nginx、MySQL 等）。

| 場景 | 建議值 |
|------|--------|
| PHP-FPM 專用伺服器 | 0.10-0.15 |
| 生產環境保守配置 | 0.20（預設） |
| 與 MySQL/Redis 共存 | 0.40-0.50 |

---

## CLI 參考

### bin/tuner

根據系統資源靜態計算 PHP-FPM 參數（不需要監控數據）。

```
php bin/tuner [選項]

  --config <path>              指定配置檔路徑
  --memory-reserve <ratio>     記憶體保留比例 (0-1)
  --json                       以 JSON 格式輸出
  -h, --help                   顯示說明
```

### bin/collect

從 PHP-FPM status 端點收集監控指標。

```
php bin/collect [選項]

收集選項：
  --once                     單次收集後退出
  --interval <seconds>       收集間隔（秒），預設 60
  --output <path>            CSV 輸出路徑
  --url <url>                PHP-FPM status 地址（socket 路徑、TCP 或 HTTP URL）
  --config <path>            指定配置檔路徑
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

分析監控數據，計算 PES 分數並診斷問題。

```
php bin/analyze --input <path> [選項]

  --input <path>             CSV 輸入路徑（必要）
  --max-children <n>         當前 max_children 設定
  --min-spare <n>            當前 min_spare_servers 設定
  --json                     以 JSON 格式輸出
  -h, --help                 顯示說明
```

### bin/optimize

根據監控數據和系統資源產生具體的設定調整建議。

```
php bin/optimize --input <path> [選項]

  --input <path>             CSV 輸入路徑（必要）
  --max-children <n>         當前 max_children 設定
  --min-spare <n>            當前 min_spare_servers 設定
  --max-spare <n>            當前 max_spare_servers 設定
  --start-servers <n>        當前 start_servers 設定
  --config <path>            指定配置檔路徑
  --json                     以 JSON 格式輸出
  --aggressive               使用目標值而非漸進值
  -h, --help                 顯示說明
```

---

## 模組架構

```
lib/
├── Config.php          # 配置管理：載入、合併、驗證
├── SystemInfo.php      # 系統資訊：CPU、記憶體（總量+可用）、worker RSS
├── Calculator.php      # 靜態計算：根據系統資源計算 PM 參數
├── FcgiClient.php      # FCGI 客戶端：透過 Unix socket/TCP 直連 PHP-FPM
├── Collector.php       # 指標收集：從 PHP-FPM status 收集數據到 CSV
├── Analyzer.php        # 統計分析：純客觀統計、時序分析、趨勢偵測
├── Evaluator.php       # 評估診斷：PES 評分、問題診斷、時序洞察
├── Optimizer.php       # 優化建議：資料驅動的設定建議、漸進式調整
└── bootstrap.php       # 初始化：polyfills 和模組載入
```

### 三模組職責分離

| 模組 | 職責 | 輸出 |
|------|------|------|
| **Analyzer** | 純統計事實：平均值、P95、delta 追蹤、峰值/離峰分離、趨勢偵測 | 數據事實 |
| **Evaluator** | 主觀判斷：PES 評分（非對稱利用率計分）、診斷問題、時序洞察 | 分數+診斷 |
| **Optimizer** | 具體建議：根據數據計算參數、漸進式調整（±25%/次）、信心度判定 | 設定值 |

---

## PES 評分說明

PES (Parameter Efficiency Score) 衡量當前配置的效率，範圍 0-1。

### 計分公式

```
PES = 佇列控制     × 0.35    (queue_occurred_ratio → 越低越好)
    + 利用率平衡   × 0.30    (40-70% 為滿分區間，非對稱計分)
    + 容量充足     × 0.25    (max_children 觸及事件率 → 越低越好)
    + Spare 充足   × 0.10    (idle_min / min_spare_servers)
```

### 時序調整

- 負載呈上升趨勢（R² > 0.5）：PES × 0.9
- 峰值利用率 > 85%：PES × 0.95

### 評級

| 分數 | 評級 | 行動 |
|------|------|------|
| ≥ 0.9 | 優秀 | 維持現有配置 |
| ≥ 0.7 | 良好 | 可選擇性優化 |
| ≥ 0.5 | 需改善 | 建議執行 `bin/optimize` |
| < 0.5 | 需緊急處理 | 立即優化 |

---

## 數據保留與清理

| 設定 | 預設值 | 說明 |
|------|--------|------|
| `metrics_retention_hours` | 48 | 數據保留時間（小時） |
| `metrics_max_size_mb` | 50 | CSV 檔案大小上限（MB） |
| `metrics_max_rows` | 0 | 數據筆數上限（0=不限制） |

```bash
# 查看數據統計
php bin/collect --stats

# 手動清理
php bin/collect --prune

# 保留 7 天數據
php bin/collect --once --retention 168
```

---

## 配置

透過配置檔自訂所有參數：

```bash
php bin/tuner --config /path/to/config.php
```

完整配置項目請參考 `config/default.php`。

## 向後相容

原有的單檔使用方式仍然支援：

```bash
php php-fpm-tuner.php
```

此入口使用 `memory_mode=available` 維持舊行為。建議改用 `bin/tuner`。

## 效能影響

每分鐘 cron 收集一次，對系統影響極小：

| 指標 | 影響 |
|------|------|
| 執行時間 | 50-100 ms |
| CPU 佔用率 | < 0.2% |
| 記憶體 | ~0.5 MB（瞬時） |
| 每日磁碟寫入 | ~144 KB |

無常駐進程、零外部相依。

## 文件

- [計算公式說明](docs/FORMULA.md)
- [動態調優方案](docs/DYNAMIC_TUNING.md)
- [配置評估方法](docs/EVALUATION.md)
- [架構說明](docs/ARCHITECTURE.md)

## 授權

MIT License
