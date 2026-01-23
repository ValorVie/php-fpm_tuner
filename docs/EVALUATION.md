# PHP-FPM 參數評估方法

## 1. 評估指標定義

### 核心指標 (從 php-fpm status 取得)

| 指標 | 計算方式 | 理想範圍 | 說明 |
|------|----------|----------|------|
| **Worker 利用率** | `active / max_children` | 30% - 70% | 過低浪費資源，過高無緩衝 |
| **佇列發生率** | `listen queue > 0` 的頻率 | < 5% | 頻繁佇列表示 worker 不足 |
| **佇列深度** | `listen queue` 平均值 | < 10 | 深佇列 = 請求等待時間長 |
| **Spare 充足率** | `idle / min_spare_servers` | > 1.0 | < 1 表示閒置 worker 不足 |
| **Max 觸及率** | `max children reached` 增長 | 0 | 頻繁觸及上限需提高 max_children |

### 系統指標

| 指標 | 取得方式 | 理想範圍 | 說明 |
|------|----------|----------|------|
| **記憶體使用率** | `free -m` | < 85% | 留有緩衝應對突發 |
| **Worker RSS** | `ps aux \| grep php-fpm` | 穩定 | 持續增長可能有記憶體洩漏 |
| **CPU 使用率** | `top` / `htop` | < 80% | PHP-FPM 是 CPU bound |

---

## 2. 量化評估公式

### 2.1 參數效率分數 (Parameter Efficiency Score)

```
PES = (1 - 佇列發生率) × 0.4
    + (1 - |利用率 - 0.5| × 2) × 0.3
    + (1 - Max觸及率) × 0.2
    + Spare充足率因子 × 0.1

其中：
- Spare充足率因子 = min(1, idle / min_spare_servers)
- 分數範圍：0 ~ 1，越高越好
- 目標：PES > 0.8
```

### 2.2 各參數的評估標準

#### max_children 評估

```
若 Max觸及率 > 0：
    → max_children 過低，需提高
    → 建議值 = 當前值 × 1.25

若 利用率 < 20% 持續 30 分鐘：
    → max_children 過高，可降低
    → 建議值 = active_max × 1.5 (active_max = 觀察期間最大 active)

若 20% ≤ 利用率 ≤ 70% 且 Max觸及率 = 0：
    → max_children 合適
```

#### min_spare_servers 評估

```
若 idle < min_spare_servers 頻率 > 10%：
    → min_spare 過高，worker 來不及補充
    → 建議值 = 當前值 × 0.8

若 idle = 0 頻率 > 5%：
    → min_spare 過低
    → 建議值 = 當前值 × 1.5

若 idle ≥ min_spare_servers 頻率 > 90%：
    → min_spare 合適
```

#### max_spare_servers 評估

```
若 idle > max_spare_servers 頻率 > 20%：
    → max_spare 過低，頻繁 kill worker
    → 建議值 = 當前值 × 1.25

若 idle 長期接近 max_spare_servers：
    → max_spare 可能過高，浪費記憶體
    → 檢查是否可降低
```

#### start_servers 評估

```
啟動後 30 秒內：
若 佇列發生：
    → start_servers 過低
    → 建議值 = 當前流量需要的 active 數量

若 idle 遠大於需求：
    → start_servers 過高
    → 建議值 = 歷史平均 active × 1.2
```

---

## 3. 實際評估方法

### 3.1 啟用 PHP-FPM Status

```ini
; /etc/php-fpm.d/www.conf
pm.status_path = /fpm-status
pm.status_listen = 127.0.0.1:9001
```

### 3.2 使用內建工具收集指標（推薦）

PHP-FPM Tuner 提供內建的 PHP 收集工具，無需安裝 jq 或其他相依：

```bash
# 單次收集
php bin/collect --once --output /var/log/php-fpm/metrics.csv

# 持續收集（每 60 秒）
php bin/collect --interval 60

# 指定 PHP-FPM status URL
php bin/collect --once --url "http://127.0.0.1:9001/fpm-status?json"

# 使用 cron 每分鐘收集
* * * * * /usr/bin/php /path/to/bin/collect --once
```

### 3.3 使用內建工具分析（推薦）

```bash
# 基本分析
php bin/analyze --input /var/log/php-fpm/metrics.csv

# 指定當前配置以取得更準確的評估
php bin/analyze --input /var/log/php-fpm/metrics.csv --max-children 30 --min-spare 8

# JSON 輸出（便於整合其他工具）
php bin/analyze --input /var/log/php-fpm/metrics.csv --json
```

輸出範例：
```
# PHP-FPM 配置分析報告
# ═══════════════════════════════════════════════════════════════

## 數據概覽
採樣數量: 60
時間範圍: 2024-01-01 10:00:00 ~ 2024-01-01 11:00:00

## 效能指標
平均活躍 workers: 12.5 (最高: 28, P95: 24.0)
平均閒置 workers: 8.2 (最低: 2)
總 workers 最高: 30

佇列發生次數: 3 (5.00%)
佇列最大深度: 5
max_children 觸及次數: 0

平均利用率: 41.7%
最高利用率: 93.3%

## PES 評分
總分: 0.85 (良好)

各項得分：
  佇列控制 (40%): 0.95
  利用率平衡 (30%): 0.83
  容量充足 (20%): 1.00
  Spare 充足 (10%): 0.75

## 調整建議
✅ 資訊: 當前配置運作良好，無需調整
   建議: 維持現有配置
```

### 3.4 手動收集指標腳本（備用）

如果無法使用 PHP，可使用以下 bash 腳本：

```bash
#!/bin/bash
# collect-fpm-metrics.sh
# 每分鐘執行一次，收集 30 分鐘數據

OUTPUT_FILE="/var/log/fpm-metrics.csv"

# 首次執行時建立標題
if [ ! -f "$OUTPUT_FILE" ]; then
    echo "timestamp,active,idle,total,listen_queue,max_reached,memory_mb" > "$OUTPUT_FILE"
fi

# 取得 php-fpm status (JSON 格式)
STATUS=$(curl -s "http://127.0.0.1:9001/fpm-status?json")

# 解析指標
ACTIVE=$(echo "$STATUS" | jq -r '.["active processes"]')
IDLE=$(echo "$STATUS" | jq -r '.["idle processes"]')
TOTAL=$(echo "$STATUS" | jq -r '.["total processes"]')
QUEUE=$(echo "$STATUS" | jq -r '.["listen queue"]')
MAX_REACHED=$(echo "$STATUS" | jq -r '.["max children reached"]')

# 取得 PHP-FPM 總記憶體使用 (MB)
MEMORY=$(ps aux | grep '[p]hp-fpm' | awk '{sum+=$6} END {print int(sum/1024)}')

# 記錄
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
echo "$TIMESTAMP,$ACTIVE,$IDLE,$TOTAL,$QUEUE,$MAX_REACHED,$MEMORY" >> "$OUTPUT_FILE"
```

### 3.5 手動分析腳本（備用）

```bash
#!/bin/bash
# analyze-fpm-metrics.sh
# 分析收集的數據

CSV_FILE="/var/log/fpm-metrics.csv"
MAX_CHILDREN=30  # 替換為你的設定值
MIN_SPARE=8      # 替換為你的設定值

echo "=== PHP-FPM 參數評估報告 ==="
echo ""

# 計算各項指標
awk -F',' -v max="$MAX_CHILDREN" -v min_spare="$MIN_SPARE" '
NR > 1 {
    count++
    active_sum += $2
    idle_sum += $3
    queue_sum += $4

    if ($2 > active_max) active_max = $2
    if ($4 > 0) queue_count++
    if ($3 < min_spare) low_spare_count++

    # 檢查 max_reached 是否增加
    if (NR == 2) prev_max_reached = $5
    if ($5 > prev_max_reached) max_reached_events++
    prev_max_reached = $5

    utilization = $2 / max
    util_sum += utilization
}
END {
    avg_active = active_sum / count
    avg_idle = idle_sum / count
    avg_queue = queue_sum / count
    avg_util = util_sum / count * 100
    queue_rate = queue_count / count * 100
    low_spare_rate = low_spare_count / count * 100

    printf "樣本數: %d\n", count
    printf "\n"
    printf "--- 利用率分析 ---\n"
    printf "平均 Worker 利用率: %.1f%%\n", avg_util
    printf "最大 Active Workers: %d / %d\n", active_max, max
    printf "平均 Active: %.1f\n", avg_active
    printf "平均 Idle: %.1f\n", avg_idle
    printf "\n"
    printf "--- 佇列分析 ---\n"
    printf "佇列發生率: %.1f%%\n", queue_rate
    printf "平均佇列深度: %.2f\n", avg_queue
    printf "\n"
    printf "--- 異常事件 ---\n"
    printf "Max Children 觸及次數: %d\n", max_reached_events
    printf "Spare 不足率: %.1f%%\n", low_spare_rate
    printf "\n"
    printf "--- 評估結論 ---\n"

    # 評估 max_children
    if (max_reached_events > 0) {
        printf "⚠️  max_children 不足，建議提高到 %d\n", int(max * 1.25)
    } else if (avg_util < 20) {
        printf "💡 max_children 可能過高，可考慮降低到 %d\n", int(active_max * 1.5)
    } else {
        printf "✅ max_children 設定合適\n"
    }

    # 評估 min_spare
    if (low_spare_rate > 10) {
        printf "⚠️  min_spare_servers 可能過高，建議降低\n"
    } else {
        printf "✅ min_spare_servers 設定合適\n"
    }

    # 評估佇列
    if (queue_rate > 5) {
        printf "⚠️  請求經常排隊，考慮提高 max_children\n"
    } else {
        printf "✅ 佇列狀況良好\n"
    }

    # 計算 PES 分數
    queue_factor = 1 - queue_rate / 100
    util_factor = 1 - (avg_util/100 - 0.5) * 2
    if (util_factor < 0) util_factor = -util_factor
    util_factor = 1 - util_factor
    max_factor = max_reached_events > 0 ? 0 : 1
    spare_factor = avg_idle / min_spare
    if (spare_factor > 1) spare_factor = 1

    pes = queue_factor * 0.4 + util_factor * 0.3 + max_factor * 0.2 + spare_factor * 0.1

    printf "\n"
    printf "--- 效率分數 (PES) ---\n"
    printf "分數: %.2f / 1.00\n", pes
    if (pes >= 0.8) printf "評級: 優秀 ✅\n"
    else if (pes >= 0.6) printf "評級: 良好 💡\n"
    else printf "評級: 需改善 ⚠️\n"
}
' "$CSV_FILE"
```

---

## 4. 壓力測試方法

### 4.1 使用 Apache Bench

```bash
# 基準測試：100 並發，10000 請求
ab -n 10000 -c 100 http://localhost/test.php

# 關注指標：
# - Requests per second (RPS)
# - Time per request
# - Failed requests
```

### 4.2 使用 wrk

```bash
# 更真實的負載測試：12 線程，400 連接，30 秒
wrk -t12 -c400 -d30s http://localhost/test.php

# 關注指標：
# - Requests/sec
# - Latency (avg, max, stdev)
# - Socket errors
```

### 4.3 測試矩陣

| 測試場景 | 並發數 | 持續時間 | 預期結果 |
|----------|--------|----------|----------|
| 輕負載 | 10 | 1 分鐘 | 無佇列，利用率 < 30% |
| 中負載 | 50 | 5 分鐘 | 佇列 < 5，利用率 30-60% |
| 高負載 | 100 | 5 分鐘 | 佇列 < 20，利用率 60-80% |
| 壓力測試 | 200 | 1 分鐘 | 找到臨界點 |

---

## 5. 參數調優流程

```
┌─────────────────────────────────────────────────────────────┐
│                    開始調優                                  │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Step 1: 收集基準數據 (30 分鐘正常流量)                      │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Step 2: 分析指標，計算 PES 分數                            │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
              ┌───────┴───────┐
              ▼               ▼
        PES ≥ 0.8        PES < 0.8
        (保持現狀)        (需調整)
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  Step 3: 根據具體指標調整參數                                │
│  - Max 觸及 → 提高 max_children                             │
│  - 佇列頻繁 → 提高 max_children 或 min_spare               │
│  - 利用率低 → 降低 max_children                             │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Step 4: 套用新參數，reload php-fpm                         │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Step 5: 觀察 30 分鐘，重新評估                             │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
                   返回 Step 2
```

---

## 6. 快速評估清單

在沒有完整監控的情況下，可用以下命令快速評估：

```bash
# 1. 當前狀態快照
curl -s "http://127.0.0.1:9001/fpm-status" | grep -E "active|idle|listen queue|max children"

# 2. 記憶體使用
ps aux | grep '[p]hp-fpm' | awk '{sum+=$6; count++} END {print "Workers:", count, "Memory:", int(sum/1024), "MB", "Avg:", int(sum/count/1024), "MB"}'

# 3. 系統記憶體
free -m | grep Mem | awk '{print "Total:", $2, "MB, Used:", $3, "MB, Available:", $7, "MB"}'

# 4. 歷史 max_children 觸及
curl -s "http://127.0.0.1:9001/fpm-status" | grep "max children reached"
```

---

## 7. 常見情境與建議

| 情境 | 症狀 | 建議調整 |
|------|------|----------|
| 響應變慢 | listen queue > 0 頻繁 | ↑ max_children |
| 記憶體不足 | OOM 或 swap 使用高 | ↓ max_children |
| 啟動後短暫卡頓 | 啟動時佇列 | ↑ start_servers |
| 閒置時記憶體高 | idle 接近 max_spare | ↓ max_spare_servers |
| 流量突增響應慢 | idle 常 = 0 | ↑ min_spare_servers |

---

## 8. 參考基準值

基於經驗法則的參考值（需根據實際測試調整）：

| 伺服器記憶體 | max_children | start | min_spare | max_spare |
|--------------|--------------|-------|-----------|-----------|
| 1 GB | 10-15 | 3 | 2 | 8 |
| 2 GB | 20-30 | 5 | 4 | 15 |
| 4 GB | 40-60 | 10 | 8 | 30 |
| 8 GB | 80-120 | 20 | 15 | 60 |
| 16 GB | 150-200 | 30 | 25 | 100 |

**假設條件**：
- Worker 平均記憶體 ~50-80 MB
- 記憶體保留 20% 給系統
- 非專用 PHP-FPM 伺服器需再降低
