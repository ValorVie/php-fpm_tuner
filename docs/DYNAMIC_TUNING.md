# PHP-FPM 動態調整設計文件

## 1. 背景與動機

### 問題陳述

靜態配置的 PHP-FPM 參數在以下情境會遇到問題：

| 情境 | 靜態配置的問題 |
|------|----------------|
| 流量高峰 | max_children 不足，請求排隊 |
| 流量低谷 | 閒置 worker 浪費記憶體 |
| 記憶體洩漏 | worker 記憶體逐漸增長 |
| 共享主機 | 其他服務佔用資源，可用記憶體變化 |

### 目標

設計一套動態調整機制，能夠：

1. 根據即時負載自動調整 worker 數量
2. 避免 OOM（Out of Memory）
3. 最小化請求延遲
4. 保持系統穩定性

---

## 2. 方案評估

### 方案 A：PHP-FPM 內建 `pm = dynamic` 模式

**現狀**：PHP-FPM 已內建動態模式。

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
```

**運作方式**：
- 根據 `min_spare_servers` 和 `max_spare_servers` 自動調整
- 閒置 worker 超過 `max_spare_servers` 會被終止
- 閒置 worker 低於 `min_spare_servers` 會 fork 新的

**優點**：
- 零開發成本
- 原生支援，穩定性高
- 無外部依賴

**缺點**：
- `max_children` 是硬上限，無法動態調整
- 無法根據記憶體壓力調整
- 調整速度受限於 `pm.process_idle_timeout`

**適用場景**：大多數標準 Web 應用

**評估**：⭐⭐⭐⭐ 推薦作為基礎

---

### 方案 B：PHP-FPM `pm = ondemand` 模式

```ini
pm = ondemand
pm.max_children = 50
pm.process_idle_timeout = 10s
```

**運作方式**：
- 啟動時不 fork 任何 worker
- 有請求時才 fork
- 閒置超過 `process_idle_timeout` 就終止

**優點**：
- 記憶體使用最小化
- 適合低流量或突發流量場景

**缺點**：
- 首次請求延遲高（需要 fork）
- 高流量下頻繁 fork/kill 開銷大
- 不適合穩定高流量網站

**適用場景**：低流量網站、開發環境、共享主機

**評估**：⭐⭐⭐ 特定場景適用

---

### 方案 C：外部腳本動態修改配置

**架構**：

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│ 監控收集器   │────▶│ 決策引擎     │────▶│ 配置更新器   │
│ (Collector)  │     │ (Decision)   │     │ (Applier)    │
└──────────────┘     └──────────────┘     └──────────────┘
       │                    │                    │
       ▼                    ▼                    ▼
  php-fpm status      演算法計算           修改 pool.conf
  /proc/meminfo       新參數值            reload php-fpm
```

**優點**：
- 可根據記憶體壓力調整 `max_children`
- 可整合外部指標（APM、負載均衡器）
- 高度客製化

**缺點**：
- 需要開發維護
- 引入新的故障點
- 配置錯誤風險

**適用場景**：有特殊需求、資源受限的環境

**評估**：⭐⭐⭐ 需謹慎實作

---

### 方案 D：容器化 + 水平擴展

**架構**：

```
┌─────────────┐
│ Load Balancer│
└──────┬──────┘
       │
  ┌────┴────┐
  ▼         ▼
┌─────┐  ┌─────┐
│Pod 1│  │Pod 2│  ... (自動擴展)
│PHP  │  │PHP  │
│FPM  │  │FPM  │
└─────┘  └─────┘
```

**優點**：
- 雲原生，彈性最佳
- 隔離性好，一個 Pod 故障不影響其他
- 可利用 Kubernetes HPA

**缺點**：
- 架構複雜度高
- 需要容器化基礎設施
- 冷啟動延遲

**適用場景**：已容器化的現代架構

**評估**：⭐⭐⭐⭐⭐ 長期最佳方案（若已有基礎設施）

---

## 3. 推薦方案

### 決策樹

```
你的環境是？
│
├─ 已容器化 (K8s/Docker Swarm)
│  └─ 推薦：方案 D（水平擴展）
│
├─ 傳統 VM/實體機
│  │
│  ├─ 流量穩定
│  │  └─ 推薦：方案 A（pm = dynamic）+ 靜態調優
│  │
│  ├─ 流量波動大，有運維能力
│  │  └─ 推薦：方案 A + 方案 C（外部腳本輔助）
│  │
│  └─ 流量極低或突發
│     └─ 推薦：方案 B（pm = ondemand）
│
└─ 共享主機（無法控制系統）
   └─ 推薦：方案 B 或靜態保守配置
```

---

## 4. 方案 C 詳細設計（外部腳本動態調整）

若選擇實作外部腳本，以下是最佳實踐設計。

### 4.1 監控指標

| 指標 | 來源 | 用途 |
|------|------|------|
| `active processes` | php-fpm status | 當前處理請求的 worker 數 |
| `idle processes` | php-fpm status | 閒置 worker 數 |
| `listen queue` | php-fpm status | 等待處理的請求數 |
| `max listen queue` | php-fpm status | 歷史最大佇列長度 |
| `MemAvailable` | /proc/meminfo | 系統可用記憶體 |
| `worker RSS` | ps aux | 單一 worker 實際記憶體 |

### 4.2 啟用 PHP-FPM Status

```ini
; /etc/php-fpm.d/www.conf
pm.status_path = /fpm-status
pm.status_listen = 127.0.0.1:9001
```

```bash
# 取得狀態（JSON 格式）
curl -s "http://127.0.0.1:9001/fpm-status?json"
```

### 4.3 決策演算法

```
輸入：
  - current_active: 當前活躍 worker
  - current_max: 當前 max_children
  - listen_queue: 請求佇列長度
  - available_memory: 可用記憶體 (MB)
  - worker_memory: 單一 worker 記憶體 (MB)

計算：
  memory_headroom = available_memory × 0.8  // 保留 20% 安全邊際
  max_possible = floor(memory_headroom / worker_memory)
  utilization = current_active / current_max

決策：
  IF listen_queue > 0 AND utilization > 0.8:
      // 負載高，需要擴展
      new_max = min(current_max × 1.25, max_possible)

  ELIF utilization < 0.3 AND current_max > min_workers:
      // 負載低，可以縮減
      new_max = max(current_max × 0.8, min_workers)

  ELSE:
      // 維持現狀
      new_max = current_max

輸出：
  new_max_children（四捨五入為整數）
```

### 4.4 穩定性機制

#### 冷卻期（Cooldown）

```
規則：兩次調整之間至少間隔 N 分鐘

建議值：
  - 擴展冷卻：2 分鐘（快速響應高負載）
  - 縮減冷卻：10 分鐘（避免抖動）
```

#### 變更幅度限制

```
規則：單次調整不超過 ±25%

原因：
  - 避免劇烈變化導致不穩定
  - 給系統時間適應新配置
```

#### 硬性邊界

```
min_workers = max(2, CPU_cores)      // 最少 worker 數
max_workers = floor(total_memory × 0.7 / worker_memory)  // 絕對上限
```

### 4.5 故障安全機制

| 故障情境 | 處理方式 |
|----------|----------|
| php-fpm status 無回應 | 跳過本次調整，記錄警告 |
| 記憶體資訊無法讀取 | 使用上次已知值，不擴展 |
| reload 失敗 | 回滾配置，發送告警 |
| 腳本異常終止 | 不影響 php-fpm 運作（fail-safe） |

### 4.6 日誌與審計

```
[2024-01-15 14:30:00] INFO: 收集指標 active=45 idle=5 queue=3 mem=2048MB
[2024-01-15 14:30:00] INFO: 利用率 90%，佇列 3，決定擴展
[2024-01-15 14:30:00] INFO: max_children 50 → 62
[2024-01-15 14:30:01] INFO: 配置已更新，reload 成功
```

### 4.7 執行頻率

| 環境 | 建議頻率 | 原因 |
|------|----------|------|
| 高流量生產 | 每 1 分鐘 | 快速響應 |
| 一般生產 | 每 5 分鐘 | 平衡響應與穩定 |
| 開發/測試 | 每 15 分鐘 | 減少干擾 |

---

## 5. 實作建議

### 5.1 漸進式導入

```
階段 1：監控模式（1-2 週）
  - 只收集數據，不做調整
  - 分析流量模式
  - 確定基準線

階段 2：建議模式（1 週）
  - 計算建議值但不套用
  - 人工審核建議是否合理
  - 調整演算法參數

階段 3：自動模式（持續）
  - 啟用自動調整
  - 設置告警監控
  - 定期審查日誌
```

### 5.2 告警設置

| 告警 | 條件 | 嚴重性 |
|------|------|--------|
| 記憶體緊張 | available_memory < 500MB | 高 |
| 佇列堆積 | listen_queue > 100 持續 5 分鐘 | 高 |
| 頻繁調整 | 1 小時內調整 > 5 次 | 中 |
| 達到上限 | max_children = max_possible | 中 |

### 5.3 回滾計劃

```bash
# 保留最近 N 份配置備份
/etc/php-fpm.d/www.conf.bak.1
/etc/php-fpm.d/www.conf.bak.2
/etc/php-fpm.d/www.conf.bak.3

# 緊急回滾
cp /etc/php-fpm.d/www.conf.bak.1 /etc/php-fpm.d/www.conf
systemctl reload php-fpm
```

---

## 6. 不建議動態調整的情境

| 情境 | 原因 | 替代方案 |
|------|------|----------|
| 已使用容器編排 | K8s HPA 更適合 | 水平擴展 |
| 流量極穩定 | 複雜度不划算 | 靜態配置 |
| 無運維資源 | 維護成本高 | pm = dynamic |
| 共享主機 | 無系統權限 | 聯繫主機商 |

---

## 7. 結論

### 最佳實踐總結

1. **優先使用內建功能**：`pm = dynamic` 已能處理大多數場景
2. **監控先於自動化**：先了解流量模式，再決定是否需要動態調整
3. **保守優於激進**：寧可多保留資源，也不要 OOM
4. **可觀測性是關鍵**：啟用 status 頁面，整合監控系統
5. **漸進式導入**：從監控模式開始，逐步啟用自動化

### 推薦配置起點

對於 4GB 記憶體的典型 Web 伺服器：

```ini
pm = dynamic
pm.max_children = 30          ; 根據 tuner 計算
pm.start_servers = 8
pm.min_spare_servers = 4
pm.max_spare_servers = 16
pm.max_requests = 500         ; 防止記憶體洩漏
pm.process_idle_timeout = 10s
pm.status_path = /fpm-status
```

搭配監控，觀察 1-2 週後再決定是否需要動態調整。

---

## 8. 參考資料

- [PHP-FPM Process Management](https://www.php.net/manual/en/install.fpm.configuration.php)
- [Scaling PHP-FPM](https://tideways.com/profiler/blog/an-introduction-to-php-fpm-tuning)
- [Linux Memory Management](https://www.kernel.org/doc/Documentation/filesystems/proc.txt)
- [Kubernetes HPA](https://kubernetes.io/docs/tasks/run-application/horizontal-pod-autoscale/)
