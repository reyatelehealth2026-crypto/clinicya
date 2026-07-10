# Central Agent Context — Receipt Points Review

> **คอนเท็กกลาง (shared brief) สำหรับ spawn agent ทุกตัว (model: `claude-sonnet-5`).**
> ทุก worker ต้องอ่านไฟล์นี้ก่อนเริ่มงาน แล้วจึงอ่าน **task ของตัวเอง** ในแผนหลัก:
> `docs/superpowers/plans/2026-07-10-receipt-points-review.md`
> ไฟล์นี้ = "ความจริงร่วม + ตารางทำงานขนาน + สัญญาการรายงานผล" — โค้ดจริงทุกบรรทัดอยู่ในแผนหลัก (อ้างด้วยเลข Task/Step ไม่ก๊อปซ้ำ)

---

## 1. Mission (สรุปภารกิจ)

สร้างหน้า admin `receipt-points-review.php` ให้เภสัชกรตรวจใบเสร็จ (OCR loyalty-point claims) ที่ระบบให้แต้มอัตโนมัติไม่ได้ แล้วให้แต้มเอง แบ่งเป็น 4 งาน:

| Task | ขอบเขต | ไฟล์หลัก |
|------|--------|----------|
| **T1** Schema | เพิ่ม diagnostic columns + all-tenant runner | `database/migration_2026-07-10_receipt_points_review.sql`, `install/migrate_all_tenants_receipt_points_review.php`, `.gitignore` |
| **T2** Webhook | เก็บ OCR diagnostics + บันทึกรูปทุก claim | `webhook.php` (`~5173–5417`) |
| **T3** AwardAction | class ให้แต้ม pending claim | `classes/ReceiptPointsAdmin.php` |
| **T4** Admin page | หน้า list + approve + nav | `receipt-points-review.php`, `includes/header.php` |

Full spec: `docs/adr/0007-receipt-points-review.md`. **อย่า re-derive การตัดสินใจใน ADR** (no reject button, no cron, no OCR.Space fix, human review = fallback).

---

## 2. Spawn config

- **Model:** `claude-sonnet-5` สำหรับ worker ทุกตัว.
- **จำนวน worker:** 4 (Agent A/B/C/D = T1/T2/T3/T4).
- **หลักการ:** worker แต่ละตัว **author + stage-lint ได้ทันทีพร้อมกัน** (โค้ดครบในแผนแล้ว) แต่ **deploy/verify ต้องเรียงตาม DAG ในข้อ 6** และ **ทุก deploy รอ user ยืนยันรายไฟล์**.

---

## 3. Shared environment (ความจริงร่วม — ห้ามเดาเอง)

| สิ่ง | ค่า |
|------|-----|
| Prod host | `118.27.146.16` port `9922` user `zrismpsz` key `~/.ssh/id_ed25519_cny` |
| SSH flags | `-o StrictHostKeyChecking=no -o BatchMode=yes` |
| Web root | `/home/zrismpsz/public_html` |
| PHP บนเซิร์ฟเวอร์ | `/usr/local/bin/php` (**ไม่มี PHP/ไม่มี test framework บนเครื่อง dev นี้**) |
| Legacy DB | `zrismpsz_demo` |
| Tenant DBs | `zrismpsz_reya_t_NNNN` (เช่น `zrismpsz_reya_t_0001`) |
| Platform DB | `zrismpsz_reya_platform` (ใช้ enumerate tenants ผ่าน `information_schema.SCHEMATA LIKE 'zrismpsz_reya_t_%'`) |
| Multi-tenant | database-per-tenant (ADR-001); ทุก write scope ด้วย `line_account_id`; migration ต้องรัน **ทุก** DB |
| Live domain | `https://re-ya.com` (webhook), `https://tenant-0001.re-ya.com` (admin UI) |
| Browser login | `adminadmin` / `adminadmin` (ถ้า session หมดอายุ) |
| ภาษา | ทุก UI text + DB comment เป็นไทย/อังกฤษ, timezone `Asia/Bangkok` (+07:00) |

**"รันเทส" ในสภาพแวดล้อมนี้แปลว่า:** (1) `php -l` บน staged copy ที่ `/tmp/stgTN` บนเซิร์ฟเวอร์ แล้ว (2) รัน probe PHP script ชั่วคราวผ่าน SSH ยิงพฤติกรรมจริงกับแถว DB จริง (หรือที่ seed ขึ้นมา) — เหมือน workflow ที่พิสูจน์แล้วใน Flex Studio session.

`install/migrate_all_tenants_flex_studio.php` = **แพทเทิร์นต้นแบบที่พิสูจน์แล้ว** ให้ก๊อปโครงมา (T1 Step 3).

---

## 4. Safety protocol (บังคับทุก worker — ห้ามข้าม)

1. **ห้ามทับไฟล์ prod สดๆ** ก่อนจะ: (a) `php -l` ผ่านบน staged copy, (b) `cp -p <file> <file>.bak-<timestamp>` บนเซิร์ฟเวอร์, (c) **user ยืนยันไฟล์นั้นโดยเฉพาะ**. มี permission gate คุมอยู่ทุก deploy ใน session นี้ — คาดว่าจะเจออีก.
2. **Drift check ก่อนแตะไฟล์ที่ deploy ด้วยมือมาก่อน** (`webhook.php`, `includes/header.php`): pull ไฟล์ live มาก่อน แล้ว `diff` กับ repo. ถ้า drift → **patch บนสำเนา live ไม่ใช่ repo** (memory `clinicya-prod-deploy`: `header.php`/`checkout.php` เคย drift; `header.php` ปัจจุบันมี 2 บรรทัด nav ของ Landing-V2 ที่ repo ยังไม่มี).
3. **Migration = 0 failed เท่านั้นถึงผ่าน** (T1 Step 5). ถ้า DB ไหน fail → หยุด, diagnose ก่อนไป T2/T3/T4 (งานหลังสมมติว่าทุก tenant มี column ครบ).
4. **`.gitignore` มี blanket `*.md` (บรรทัด ~170)** อยู่ **หลัง** whitelist — ไฟล์ `.md`/`.sql` ใหม่ต้องมีบรรทัด `!path` ของตัวเอง ไม่งั้น `git add` เงียบ (no-op). Migration `.sql` ใหม่ → เพิ่ม `!database/migration_<name>.sql` ในบล็อกเดียวกับของเดิม.
5. **`webhook.php` = LINE webhook entry point สด** — deploy ด้วยความระวังสูงสุด; health check ต้อง 200 หลัง deploy (T2 Step 9).
6. **ห้าม require_once `webhook.php` ตรงๆ ใน probe** — มัน execute top-level dispatch code ตอน include. Probe ต้องยิง SQL เดียวกับที่ function รัน (ดู T2 Step 7) เพื่อพิสูจน์ column list ตรงกัน.
7. Probe ทุกตัว **seed + clean up ข้อมูลทดสอบของตัวเอง** เสมอ (มี cleanup block ในแผนแล้ว).

---

## 5. Coordination protocol (ไม่มี Orca ในเครื่องนี้)

> `orca` ไม่ได้ติดตั้งบน PATH ที่นี่ → **ใช้ Orca orchestration provenance ไม่ได้จริง** (no `task-create`/`dispatch --inject`/`worker_done`). อย่าอ้างว่าเป็น Orca-orchestrated. ใช้ native `Agent` tool spawn worker แทน. ถ้าต้องการ Orca provenance จริง ให้เปิด Orca runtime + orchestration feature ก่อน แล้ว coordinator ค่อย dispatch ใหม่.

**สัญญาการรายงานผล (worker → coordinator):** ข้อความสุดท้ายของ worker = ผลลัพธ์ (ไม่ใช่ข้อความคุยกับคน). ต้องมี:
```
TASK: <T1|T2|T3|T4>
STATUS: AUTHORED | LINT_OK | PROBE_PASS | AWAITING_CONFIRM | DEPLOYED | BLOCKED
FILES: <ไฟล์ที่เขียน/แก้>
EVIDENCE: <บรรทัด output จริง เช่น "LINT_OK", "PASS", "=== Done: N migrated, 0 failed ===">
BLOCKED_ON: <ถ้ามี: task/สิ่งที่รอ เช่น "รอ user ยืนยัน deploy webhook.php" หรือ "รอ T1 migrate tenant-0001">
NEXT: <ขั้นถัดไปที่ต้องทำ>
```
- ถ้าติด decision ที่ต้องให้คนตอบ → **หยุดแล้วรายงาน `BLOCKED` + คำถาม** ห้ามเดา แล้วทับ prod.
- worker **ห้าม** สั่ง deploy ทับ prod เอง ถ้ายังไม่มี user confirm ไฟล์นั้น.

---

## 6. Parallel schedule — "ทำพร้อมกันทุก step" อย่างปลอดภัย

โค้ดทุก task เขียนครบในแผนแล้ว → **การ author + lint (แตะแค่ `/tmp/stgTN`, ไม่แตะ live/DB) ทำขนานได้จริงทั้ง 4 ตัว**. สิ่งที่ **ต้องเรียง** คือ deploy/probe ที่แตะ prod DB หรือไฟล์ live.

```
WAVE 0 (parallel authoring — spawn ทั้ง 4 พร้อมกันได้เลย)
  A:T1  author SQL+runner+.gitignore  → lint (/tmp/stgT1)      [ไม่แตะ live]
  B:T2  author webhook patch          → lint (/tmp/stgT2)      [ไม่แตะ live]
  C:T3  author ReceiptPointsAdmin.php → lint (/tmp/stgT3)      [ไม่แตะ live]
  D:T4  author page + header nav      → lint (/tmp/stgT4)      [ไม่แตะ live]
        ↓ ทั้ง 4 รายงาน LINT_OK

WAVE 1 (critical path — ต้องเสร็จก่อนใครจะ probe/deploy)
  A:T1  ── user confirm ──► รัน all-tenant runner (T1 Step 5)  [แตะ prod DB]
        ต้องได้ "0 failed" + verify schema tenant-0001 (T1 Step 6)
        ↓ A รายงาน DEPLOYED (schema พร้อม)

WAVE 2 (parallel — B และ C ขนานกันได้ หลัง schema พร้อม)
  B:T2  probe pending path (T2 Step 7, seed+cleanup) → PROBE_PASS
        ── user confirm ──► deploy webhook.php (backup+lint) → health 200
  C:T3  probe award (T3 Step 3, seed+cleanup)       → PROBE_PASS
        ── user confirm ──► deploy ReceiptPointsAdmin.php (backup+lint)
        ↓ C รายงาน DEPLOYED (class พร้อม)

WAVE 3 (หลัง C deploy — page ต้อง require_once class จริง)
  D:T4  ── user confirm ──► deploy page + header.php (drift-check header!)
        browser verify (Chrome DevTools MCP) + end-to-end approve test
        ↓ D รายงาน DEPLOYED
```

**Dependency เข้ม (ห้ามฝ่า):**
- ทุก probe/deploy ของ B,C,D **block บน A:T1 migrate สำเร็จ** (column ต้องมีจริงบน DB).
- D:T4 deploy **block บน C:T3 deploy** (`receipt-points-review.php` `require_once classes/ReceiptPointsAdmin.php`).
- B:T2 ↔ C:T3 **อิสระต่อกัน** → ขนานได้เต็มที่ใน Wave 2.
- ทุกลูกศร `── user confirm ──►` = จุดหยุดรอมนุษย์ (deploy ทับ prod).

**สรุปสั้น:** authoring = ขนานได้ทั้งหมด; production mutation = serialize A → (B ∥ C) → D, คั่นด้วย user confirm ทุกครั้ง.

---

## 7. Per-agent briefs

### Agent A — Schema (Task 1) · critical path
- **Owns:** `database/migration_2026-07-10_receipt_points_review.sql`, `install/migrate_all_tenants_receipt_points_review.php`, `.gitignore` whitelist line.
- **ก๊อปแพทเทิร์นจาก:** `install/migrate_all_tenants_flex_studio.php`.
- **ทำทันที (Wave 0):** เขียนไฟล์ตาม T1 Step 1–3 → lint runner (T1 Step 4).
- **Block/รอ confirm:** T1 Step 5 (รัน runner แตะทุก DB) — **รอ user ยืนยัน**. Pass bar = `0 failed` + T1 Step 6 verify เห็น `ocr_amount, confidence, fail_reason, reviewed_by, reviewed_at`.
- **ปลดล็อกใคร:** B, C, D ทั้งหมด. รายงาน `DEPLOYED` ทันทีที่ migrate เสร็จเพื่อให้ Wave 2 เริ่ม.
- **Done:** T1 Step 7 commit.

### Agent B — Webhook (Task 2)
- **Owns:** `webhook.php` (`~5173–5417`) — helper `saveReceiptClaimImage()`, patch `recordPendingReceiptPointClaim()`, 3 failure call sites, save image บน auto-approve path.
- **ทำทันที (Wave 0):** T2 Step 1 (**drift check webhook.php ก่อน!**) → Step 2–5 author → Step 6 lint.
- **Block:** T2 Step 7 probe **รอ A:T1 migrate** (probe INSERT ใช้ column ใหม่). Step 8 deploy `webhook.php` = **entry point สด, รอ user confirm** + backup. Step 9 health check ต้อง 200.
- **อิสระจาก C** → Wave 2 ขนานกับ C ได้.
- **Done:** T2 Step 10 commit.

### Agent C — AwardAction (Task 3)
- **Owns:** `classes/ReceiptPointsAdmin.php` (function เดียว `awardPendingReceiptClaim(PDO,int,int,int,string,int): array`).
- **ทำทันที (Wave 0):** T3 Step 1 author → Step 2 lint (**ก๊อป sibling classes `LoyaltyPoints.php`/`LineAccountManager.php`/`LineAPI.php` เข้า staging** ให้ `require_once` resolve — บทเรียนจาก flex-studio probe).
- **Block:** T3 Step 3 probe **รอ A:T1 migrate**. Step 4 deploy **รอ user confirm** + backup.
- **อิสระจาก B** → Wave 2 ขนานกับ B ได้. **ปลดล็อก D** เมื่อ deploy เสร็จ.
- **Done:** T3 Step 5 commit.

### Agent D — Admin page (Task 4)
- **Owns:** `receipt-points-review.php`, nav entry ใน `includes/header.php`.
- **ทำทันที (Wave 0):** T4 Step 1–2 author → Step 3 lint ทั้ง 2 ไฟล์.
- **Block:** deploy (T4 Step 4) **รอ C:T3 deploy** (page require class จริง) + **drift-check `header.php`** (ต้องมั่นใจ diff เดียวคือบรรทัด nav ใหม่) + **user confirm** (`header.php` shared ทุกหน้า admin). Step 5–6 browser verify + end-to-end approve ผ่าน Chrome DevTools MCP.
- **Done:** T4 Step 7 commit.

---

## 8. Definition of done (integration checkpoints)

1. **T1:** ทุก DB `0 failed`; tenant-0001 มี 5 column ใหม่.
2. **T2:** probe `PASS`; `webhook.php` deploy แล้ว health 200; auto-approve claim บันทึกรูป, pending claim เก็บ `ocr_amount/confidence/fail_reason`.
3. **T3:** probe `PASS` + `LEDGER DELTA: 3`; class deploy แล้ว.
4. **T4:** หน้า render ไม่มี PHP error; nav "ตรวจสลิปรับแต้ม" ขึ้น; กด "อนุมัติ" แล้วแถว update เป็น "ให้แล้ว +N แต้ม" โดยไม่ reload + `points_transactions` มีแถวจริง.
5. **Cross-task consistency:** column names + signatures ตรงกันทั้ง migration/runner/webhook INSERT/page SELECT (ดู Self-Review Notes ท้ายแผน).

---

## 9. Caveat

- **Orca ไม่พร้อมในเครื่องนี้** → coordination ใช้ native `Agent` tool (final-message contract ข้อ 5) ไม่ใช่ Orca task/dispatch. ถ้าอยากได้ Orca provenance จริง แจ้ง coordinator ให้เปิด Orca runtime + orchestration feature ก่อน.
- **ไม่มี local PHP** → lint/probe/deploy ทุกอย่างวิ่งผ่าน SSH บนเซิร์ฟเวอร์เท่านั้น.
- **Production mutation ทุกอย่าง gated ด้วย user confirm** — proactive mode ก็ยังต้องหยุดที่ deploy ทับ prod เสมอ.
