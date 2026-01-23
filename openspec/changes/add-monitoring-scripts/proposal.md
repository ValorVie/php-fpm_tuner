# Change: 重構專案架構並新增監控分析功能

## Why

目前 `php-fpm-tuner.php` 是單一檔案，配置與邏輯混合，無法被其他工具引用。
使用者需要完整的調優流程：

1. **計算**：根據系統資源計算建議參數（現有功能）
2. **監控**：收集實際運行指標
3. **分析**：評估配置效果並提供調整建議

## What Changes

### 專案架構重構

```
php-fpm_tuner/
├── bin/                        # 可執行入口（CLI 介面）
│   ├── tuner                   # 參數計算
│   ├── collect                 # 監控收集
│   └── analyze                 # 配置分析
├── lib/                        # 共用程式庫（核心邏輯）
│   ├── bootstrap.php           # 初始化（polyfills、autoload）
│   ├── Config.php              # 配置管理類別
│   ├── SystemInfo.php          # 系統資訊收集
│   ├── Calculator.php          # 參數計算邏輯
│   ├── Collector.php           # 指標收集邏輯
│   └── Analyzer.php            # 分析評估邏輯
├── config/
│   └── default.php             # 預設配置值
├── docs/                       # 文件
└── php-fpm-tuner.php           # 向後相容入口（呼叫 bin/tuner）
```

### 各模組職責與解耦

| 模組 | 職責 | 輸入 | 輸出 |
|------|------|------|------|
| **SystemInfo** | 收集系統資源 | - | CPU、記憶體、worker 資訊 |
| **Config** | 管理配置參數 | 設定檔/CLI 參數 | 配置物件 |
| **Calculator** | 計算建議參數 | 系統資訊 + 配置 | PM 參數 |
| **Collector** | 收集 FPM 指標 | Status URL | CSV 數據 |
| **Analyzer** | 分析並評估 | CSV + 當前配置 | PES 分數 + 建議 |

### 解耦原則

1. **資料驅動**：各模組透過資料結構（陣列/物件）通訊，非直接呼叫
2. **單一職責**：每個類別只做一件事
3. **可獨立執行**：每個 bin 腳本可單獨使用
4. **可組合使用**：分析腳本可呼叫 Calculator 產生新建議

### 為何選擇 PHP

| 考量 | 說明 |
|------|------|
| 環境保證 | PHP-FPM 環境必有 PHP CLI |
| 專案一致性 | 與現有 `php-fpm-tuner.php` 語言一致 |
| 零外部相依 | JSON、CSV、HTTP 請求皆內建支援 |

## Impact

- Affected specs: `monitoring-scripts`（新建立）
- Affected code: 重構現有 `php-fpm-tuner.php`，新增 `bin/`、`lib/`、`config/` 目錄
- **向後相容**：保留 `php-fpm-tuner.php` 作為入口，內部呼叫新架構
