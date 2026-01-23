<!-- OPENSPEC:START -->
# OpenSpec Instructions

These instructions are for AI assistants working in this project.

Always open `@/openspec/AGENTS.md` when the request:
- Mentions planning or proposals (words like proposal, spec, change, plan)
- Introduces new capabilities, breaking changes, architecture shifts, or big performance/security work
- Sounds ambiguous and you need the authoritative spec before coding

Use `@/openspec/AGENTS.md` to learn:
- How to create and apply change proposals
- Spec format and conventions
- Project structure and guidelines

Keep this managed block so 'openspec update' can refresh the instructions.

<!-- OPENSPEC:END -->

# Claude Code 專案指南
# 由 Universal Dev Standards CLI 生成
# https://github.com/AsiaOstrich/universal-dev-standards

## 對話語言 / Conversation Language
所有回覆必須使用**繁體中文 (Traditional Chinese)**。
AI 助手應以繁體中文回覆使用者的問題與請求。

---

## 反幻覺協議
參考: .standards/anti-hallucination.md

### 實證分析
1. **讀取檔案**: 分析前必須先讀取檔案
2. **禁止猜測**: 不得猜測 API、類別名稱或函式庫版本
3. **明確不確定性**: 若未看過程式碼，需說明「我需要讀取 [檔案] 來確認」

### 來源標註
- 關於程式碼的每項事實陳述必須引用來源
- 格式: `[Source: Code] path/to/file:line`
- 外部文件: `[Source: External] http://url (存取日期: Date)`

### 確定性分類
使用標籤表示信心程度:
- `[確認]` - 已從程式碼/文件驗證
- `[推斷]` - 從證據邏輯推導
- `[假設]` - 合理猜測，需驗證
- `[未知]` - 無法確定

### 建議
提供選項時，必須明確標明「建議」選項並說明理由。

---

## 提交訊息標準
參考: .standards/commit-message-guide.md, .standards/options/traditional-chinese.ai.yaml

### 格式
```
<類型>(<範圍>): <主旨>

<本文>

<頁腳>
```

### 類型
| 類型 | 英文對照 | 說明 | 範例 |
|------|----------|------|------|
| `功能` | feat | 新功能 | 功能(認證): 新增 OAuth2 登入 |
| `修正` | fix | 錯誤修正 | 修正(api): 處理空值回應 |
| `文件` | docs | 文件更新 | 文件(readme): 更新安裝指南 |
| `樣式` | style | 格式調整 | 樣式(lint): 修正縮排 |
| `重構` | refactor | 程式碼重構 | 重構(user): 抽取驗證邏輯 |
| `測試` | test | 測試相關 | 測試(cart): 新增結帳測試 |
| `雜項` | chore | 維護任務 | 雜項(deps): 更新套件 |
| `效能` | perf | 效能改善 | 效能(query): 優化資料庫查詢 |
| `整合` | ci | 持續整合 | 整合(github): 新增部署流程 |

### 規則
- 主題行: 最多 72 字元
- 使用祈使語氣
- 本文: 說明做了什麼及為什麼，而非如何做

---

## 程式碼審查清單
參考: .standards/checkin-standards.md

### 每次提交前
1. **建置驗證**
   - [ ] 程式碼編譯成功
   - [ ] 所有相依套件已滿足

2. **測試驗證**
   - [ ] 所有現有測試通過
   - [ ] 新程式碼有對應測試
   - [ ] 測試覆蓋率未下降

3. **程式碼品質**
   - [ ] 遵循編碼標準
   - [ ] 無寫死的密鑰
   - [ ] 無安全漏洞

4. **文件**
   - [ ] API 文件已更新（如適用）
   - [ ] 使用者可見變更已更新 CHANGELOG

### 禁止提交情況
- 建置有錯誤
- 測試失敗
- 包含除錯程式碼（console.log 等）

---

<!-- UDS:STANDARDS:START -->
## Standards Compliance Instructions

**MUST follow** (每次都要遵守):
| Task | Standard | When |
|------|----------|------|
| Writing commits | [commit-message.ai.yaml](.standards/commit-message.ai.yaml) | Every commit |

**SHOULD follow** (相關任務時參考):
| Task | Standard | When |
|------|----------|------|
| Git workflow | [git-workflow.ai.yaml](.standards/git-workflow.ai.yaml) | Branch/merge decisions |
| Writing tests | [testing.ai.yaml](.standards/testing.ai.yaml) | When creating tests |


## Installed Standards Index

本專案採用 **Level 3** 標準。所有規範位於 `.standards/`：

### Core (30 standards)
- `anti-hallucination.ai.yaml` - anti-hallucination.ai.yaml
- `commit-message.ai.yaml` - 提交訊息格式
- `traditional-chinese.ai.yaml` - traditional-chinese.ai.yaml
- `checkin-standards.ai.yaml` - checkin-standards.ai.yaml
- `spec-driven-development.ai.yaml` - spec-driven-development.ai.yaml
- `code-review.ai.yaml` - code-review.ai.yaml
- `git-workflow.ai.yaml` - Git 工作流程
- `github-flow.ai.yaml` - github-flow.ai.yaml
- `squash-merge.ai.yaml` - squash-merge.ai.yaml
- `versioning.ai.yaml` - versioning.ai.yaml
- `changelog.ai.yaml` - changelog.ai.yaml
- `testing.ai.yaml` - 測試標準
- `unit-testing.ai.yaml` - unit-testing.ai.yaml
- `integration-testing.ai.yaml` - integration-testing.ai.yaml
- `documentation-structure.ai.yaml` - documentation-structure.ai.yaml
- `documentation-writing-standards.ai.yaml` - documentation-writing-standards.ai.yaml
- `ai-instruction-standards.ai.yaml` - ai-instruction-standards.ai.yaml
- `project-structure.ai.yaml` - project-structure.ai.yaml
- `error-codes.ai.yaml` - error-codes.ai.yaml
- `logging.ai.yaml` - logging.ai.yaml
- `test-completeness-dimensions.ai.yaml` - test-completeness-dimensions.ai.yaml
- `test-driven-development.ai.yaml` - test-driven-development.ai.yaml
- `behavior-driven-development.ai.yaml` - behavior-driven-development.ai.yaml
- `acceptance-test-driven-development.ai.yaml` - acceptance-test-driven-development.ai.yaml
- `reverse-engineering-standards.ai.yaml` - reverse-engineering-standards.ai.yaml
- `forward-derivation-standards.ai.yaml` - forward-derivation-standards.ai.yaml
- `refactoring-standards.ai.yaml` - refactoring-standards.ai.yaml
- `requirement-checklist.md` - requirement-checklist.md
- `requirement-template.md` - requirement-template.md
- `requirement-document-template.md` - requirement-document-template.md

<!-- UDS:STANDARDS:END -->

---
