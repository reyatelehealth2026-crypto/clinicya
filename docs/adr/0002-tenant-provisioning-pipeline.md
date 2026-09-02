# ADR-002: Tenant Provisioning Pipeline

## Status

**Reconstructed from code, needs confirmation.**

สร้างขึ้นใหม่จาก `classes/TenantProvisioning.php` ซึ่งอ้างถึง ADR นี้ที่บรรทัด 406 ว่า *"Full provision pipeline — runs every step in ADR-002 §\"Provisioning Pipeline\""* ไฟล์ต้นฉบับไม่เคยถูก commit

**สิ่งที่ต้องยืนยัน:** ลำดับขั้นตอนที่บันทึกไว้ตรงกับ ADR ต้นฉบับหรือไม่ และการที่ pipeline **ไม่ idempotent** เป็นการตัดสินใจโดยเจตนาหรือเป็นหนี้ทางเทคนิค

## Date

**ไม่ทราบวันที่ตัดสินใจ** — anchor ใกล้ที่สุด `2026-05-25` (migration ที่สร้าง `tenant_provisioning_log`)
เอกสารฉบับสร้างใหม่: 2026-09-02

## Context

[ADR-001](0001-database-per-tenant-isolation.md) กำหนดว่าผู้เช่าแต่ละรายต้องมีฐานข้อมูลจริงของตัวเอง คำถามที่ตามมาคือ **จะสร้างมันขึ้นมาอย่างไร** ในเมื่อ:

- cPanel shared hosting **บล็อก `CREATE DATABASE`** — MySQL user รันตรง ๆ ไม่ได้ (Access denied) ทางเดียวที่รองรับคือ shell out ไปที่ `uapi Mysql create_database`
- uapi เป็น **side effect ที่ไม่ใช่ transaction** — จะ wrap ด้วย `BEGIN`/`ROLLBACK` ของ master DB ไม่ได้
- schema ผู้เช่าใหญ่ (279 ตาราง) และต้องเหมือนกันทุกราย
- ต้องมี audit trail ว่าใครสร้าง/ระงับ/ปิดผู้เช่ารายไหนเมื่อไหร่

## Decision

Provisioning เป็น **pipeline แบบมีขั้นตอนชัดเจน + compensating rollback** ไม่ใช่ transaction — ทุกขั้นที่เปลี่ยนสถานะเขียน log ลง `tenant_provisioning_log` ในฐานข้อมูล master

สัญญาของทุก operation ที่เปลี่ยนสถานะ (`classes/TenantProvisioning.php:21-25`):

```
1. Records status=started in tenant_provisioning_log (master DB)
2. Executes the uapi / mysql shell call
3. Records status=succeeded OR status=failed (+ error_message)
4. Throws RuntimeException on failure so callers can run compensating actions
```

### Provisioning Pipeline

*(หัวข้อนี้ถูกอ้างโดยตรงจาก `classes/TenantProvisioning.php:406`)*

`TenantProvisioning::fullProvision()` (`:421-503`) ทำ 6 ขั้นตามลำดับ:

| # | ขั้นตอน | บรรทัด | ผู้ทำงาน |
|---|---|---|---|
| 1 | INSERT แถว `tenants` สถานะ `pending_setup` | :428 | inline INSERT, `db_name` จาก `tenantIdToDbName()` |
| 2 | สร้าง DB จริงผ่าน uapi | :455 | `self::create($tenantId)` → `uapi Mysql create_database` |
| 3 | GRANT สิทธิ์ให้ app user | :458 | `self::grant($dbName, DB_USER)` → `uapi Mysql set_privileges_on_database` |
| 4 | apply tenant template schema | :461 | `self::applySchema($dbName)` → mysql client |
| 4a | └ สร้าง `admin_users` (template ไม่มีให้) | :266 | `self::ensureAdminUsersTable($dbName)` |
| 4b | └ บันทึกลง `tenant_migrations` | :269 | `self::recordMigrationApplied()` |
| 5 | seed entitlements ตาม plan | :464 | inline INSERT |
| 6 | flip สถานะเป็น `active` | :481 | `UPDATE tenants SET status='active'` |

**คีย์ที่ `$tenantData` ต้องมี** (`:408-410`): `slug`, `display_name`, `plan_id` (int), `owner_name`, `owner_email`, `owner_phone`, `created_by` (nullable)
มีคีย์ที่โค้ดอ่านแต่ docblock ไม่ระบุ: `db_host` (:444, default `'localhost'`)

**ขั้นตอนภายนอก** ที่ `TenantOnboardingService::provisionFromOwner()` ทำเพิ่ม (`:63-215`): validate slug/email/password (ข้อความ error ภาษาไทย), เช็ค reserved subdomain, เช็ค slug ซ้ำ, resolve `plan_id`, **จอง tenant id ด้วย `max($maxId + 1, 100)`** (สงวน 1-5 ไว้ให้ dev), เรียก `fullProvision()`, สร้าง admin คนแรก, seed landing page (non-fatal), seed trial subscription (non-fatal), ส่งอีเมลต้อนรับ

### การสร้างฐานข้อมูล — cPanel uapi เท่านั้น

```
uapi --output=json Mysql create_database name=<db>
uapi --output=json Mysql set_privileges_on_database user=<u> database=<db> privileges='ALL PRIVILEGES'
uapi --output=json Mysql delete_database name=<db>
```

ทุก argument ผ่าน `escapeshellarg()` รันด้วย `proc_open` แยก stdout/stderr/exit code
ถือว่าสำเร็จเมื่อ **exit 0 และ** `json.result.status === 1` (`isUapiSuccess()` :566-576)

การ apply schema **ไม่ใช้ uapi** — ใช้ mysql client ปกติ โดยเขียน `--defaults-extra-file` ชั่วคราว (`tempnam`, `chmod 0600`, `@unlink` ใน `finally`) แทนการส่ง `-p<pass>` บน command line ที่มองเห็นได้จาก `ps` (`:211-214, 228-243`)

**Guard:** `assertDbNameAllowed()` (:599-613) ปฏิเสธชื่อที่ไม่ตรง `/^zrismpsz_reya_t_\d{4}$/` เรียกเป็นบรรทัดแรกของ `create`/`grant`/`applySchema`/`ensureAdminUsersTable`/`delete`

### Status lifecycle

```
pending_setup ──(step 6)──> active ──> suspended ──(resume)──> active
      │                        │            │
      └──────────────────────> terminated <─┘
```

นิยามที่ `database/migration_2026-05-25_platform_master.sql:100-101`:
`ENUM('active','suspended','pending_setup','terminated') DEFAULT 'pending_setup'`

การบังคับใช้ตอน runtime อยู่ที่ `bootstrap/resolve_subdomain.php:209-241`:

| สถานะ | พฤติกรรม |
|---|---|
| `pending_setup` | **ไม่ล็อกเอาต์** — เข้าใช้ได้เต็มในโหมด DEMO (`REYA_DEMO_MODE`) มี watermark "ข้อมูลตัวอย่าง / DEMO" |
| `suspended` | HTTP 503 — `'บัญชีของร้านนี้ถูกระงับชั่วคราว — กรุณาติดต่อทีมงาน REYA'` |
| `terminated` | HTTP 503 — `'บัญชีของร้านนี้ถูกปิดแล้ว'` |
| root domain | ยกเว้นเสมอ — *"Never take the master/root domain offline on a status glitch"* |

## Alternatives Considered

> ไม่มีเอกสารเปรียบเทียบทางเลือกหลงเหลือ หัวข้อนี้ประกอบจากร่องรอยในโค้ดและแผน migration

### Wrap ทั้ง pipeline ใน transaction

- **สถานะ:** ปฏิเสธ — เป็นไปไม่ได้ทางเทคนิค ระบุไว้ตรง ๆ ที่ `:417-419`:
  > *"this method does NOT begin a master-DB transaction wrapping the uapi shell-out — uapi side effects are not transactional. Instead it runs compensating actions on failure (DROP DATABASE + DELETE tenants row)."*

### `CREATE DATABASE` ตรงจาก MySQL user

- **สถานะ:** ปฏิเสธเพราะ hosting บล็อก (ดู [ADR-001](0001-database-per-tenant-isolation.md) §"Hosting constraint")
- **หมายเหตุ:** ทางเลือกนี้ถูก *รื้อฟื้น* ในแผน migration ไป VPS — `strategy=mysql` เป็น **การแก้โค้ด PHP ชิ้นเดียวที่อนุญาตใน Phase 0** เพราะ uapi ใช้บน VPS ไม่ได้

## Consequences

### 1. Pipeline นี้ผูกกับ cPanel — ย้าย host ไม่ได้ถ้าไม่แก้โค้ด

`grep -n "strategy" classes/TenantProvisioning.php` → **ศูนย์ผลลัพธ์** (ยืนยันแล้ว)

ไม่มี branch `strategy=mysql` การ provision จึงเป็นไปไม่ได้บนเครื่องที่ไม่มี `/usr/bin/uapi` มีสถานะเป็น "งานที่วางแผนไว้แต่ยังไม่ทำ" ใน 4 เอกสาร ได้แก่ `docs/plans/2026-08-14-golive-readiness-audit.md:39`, `docs/runbooks/phase0-cutover-rollback.md:217-236`, `docs/plans/2026-08-14-codex-handoff.md:245-306` และ `docs/plans/2026-07-12-nextjs-full-migration-plan.md:84`

### 2. `fullProvision()` **retry ไม่ได้** หลังล้มเหลวกลางคัน

| ขั้น | เหตุที่ retry ไม่ได้ |
|---|---|
| 1 | plain `INSERT` พร้อม explicit `id` → ชน PK + `uq_tenant_slug` + `uq_tenant_db_name` |
| 2 | `create()` throw ทันทีถ้า DB มีอยู่แล้ว: *"Refusing to create '{$dbName}': database already exists"* |
| 5 | plain `INSERT` entitlements ไม่มี `IGNORE`/`ON DUPLICATE` → ชน `uq_entitlement_tenant_key` |

ตรงข้ามกับส่วนที่ **idempotent จริง**: template SQL (279 ตาราง `CREATE TABLE IF NOT EXISTS` ทั้งหมด), `grant()`, `ensureAdminUsersTable()`, `suspend()`/`resume()`, `recordMigrationApplied()` (upsert)

### 3. Rollback ครอบคลุมไม่ครบ

`:487-502` ทำ compensating rollback แบบ best-effort (DROP DATABASE + DELETE แถว `tenants`) โดย catch แล้ว `error_log` อย่างเดียว แล้ว rethrow exception เดิม

**สิ่งที่ไม่ถูก rollback เลย:** seed ฝั่ง tenant, อีเมลต้อนรับ, แถว subscription — ทั้งหมดรันใน `TenantOnboardingService` *หลัง* `fullProvision()` คืนค่า จึงอยู่นอกขอบเขต rollback

**Bug ที่พบระหว่างตรวจสอบ:** `classes/SelfServeProvisioning.php:230` rollback ด้วยเงื่อนไข `db_name = "__provisioning__"` แต่ `:142` เขียนทับ `db_name` ด้วยชื่อจริงไปแล้ว → เงื่อนไขไม่เคย match แถวที่ล้มเหลวจึงค้างจอง slug ไว้ตลอด และ DB จริงไม่เคยถูกลบ

### 4. มี 4 ทางเข้าสู่ provisioning → เสี่ยง drift

| ทางเข้า | เรียกอะไร |
|---|---|
| `admin/beta-signups.php:137` | `TenantOnboardingService::provisionFromOwner()` |
| `admin/switch-tenant.php:216` | `fullProvision()` ตรง + copy แผนที่ entitlement มาแปะ inline |
| `scripts/provision_tenant.php:134` | `fullProvision()` ตรง + copy แผนที่ชุดที่สาม |
| `classes/SelfServeProvisioning.php:135-141` | **ข้าม** `fullProvision()` เรียก create/grant/applySchema เอง |

โค้ดยอมรับเองที่ `classes/TenantOnboardingService.php:16-18` ว่าสำเนาใน `admin/switch-tenant.php` ควรถูกชี้กลับมาที่ helper นี้เพื่อลบความซ้ำซ้อน

`admin_users` DDL ถูก copy ไว้ **3 ที่ด้วย schema 2 แบบ** — `scripts/provision_tenant.php:152-170` สร้างตารางแบบ 10 คอลัมน์ ขาด `phone`/`avatar_url`/`line_account_id`/`line_user_id`/`notification_enabled` เทียบกับ 17 คอลัมน์ของอีกสองที่

### 5. Template ไม่มี `admin_users` — เคยทำให้ผู้เช่าค้างครึ่งทาง

```php
// The tenant template intentionally omits platform-level tables, but
// every tenant DB still needs admin_users for owner/staff login —
// its absence made provisionFromOwner fatal with 1146 after the DB
// was already created (orphaned half-provisioned tenants 0113/0115).
```
— `classes/TenantProvisioning.php:262-265`

### 6. รูบันทึก log ตอน bootstrap

`tenant_provisioning_log.tenant_id` มี FK ไปที่ `tenants.id` การ provision ผู้เช่ารายแรกสุดจึงไม่มีที่เขียน log — degrade ไปที่ `error_log()` เงียบ ๆ ยอมรับไว้แล้วที่ `:27-31`

### 7. ความไม่ตรงกันที่พบ (ควรแก้)

- **`event` enum ไม่มีค่า `grant`** — โค้ดเขียน `'seed'` แทนตอน grant (`:188`) ทำให้ log อ่านผิดความหมาย
- `db_backup`, `db_restore`, `migrate_apply`, `rolled_back` มีใน enum แต่**ไม่มีโค้ดใดเขียน**
- `triggered_by` ไม่เคยถูก populate (`logStart()` insert แค่ 5 คอลัมน์)
- **ชื่อ DB ในเอกสารกับโค้ดไม่ตรงกัน** — template header และ `database/migration_2026-05-25_platform_master.sql:94` เขียน `reya_tenant_NNNN` แต่โค้ดสร้างจริงเป็น `zrismpsz_reya_t_NNNN`
- **`$graceDays` เป็น parameter ที่ตายแล้ว** — `terminate(int $tenantId, bool $dropDb = false, int $graceDays = 30)` ไม่เคยใช้ `$graceDays` ในตัว method เลย docblock สัญญาว่า *"that happens after the grace period via cron"* แต่ **ไม่มี cron นั้นในรีโป**
- **`tenant_migrations` เป็น write-only ฝั่ง PHP** — เขียนที่ `:663-690` แต่ไม่มีโค้ด PHP ใดอ่าน ผู้อ่านมีแค่ `packages/db/src/migrateAll.ts` ซึ่ง docblock ระบุว่ายังไม่เคยรันกับ DB จริง
- **ไม่มี test ครอบคลุม pipeline เลย** — `grep -rn "fullProvision\|applySchema\|assertDbNameAllowed" tests/` ไม่พบผลลัพธ์
- **`TenantFileStorage` ไม่ถูกเรียกจาก provisioning เลย** — ไดเรกทอรีถูกสร้างแบบ lazy ตอน upload ครั้งแรก
- **`admin/beta-signups.php:166-169` แสดงรหัสผ่าน admin แบบ plaintext ลงใน HTML flash message** — ควรทบทวนด้านความปลอดภัย

## Evidence

**การอ้างถึง ADR นี้:**
- `classes/TenantProvisioning.php:406` — `Full provision pipeline — runs every step in ADR-002 §"Provisioning Pipeline".`

**Pipeline:**
- `classes/TenantProvisioning.php:421-503` (`fullProvision`), ขั้น 1-6 ที่ :428, :455, :458, :461, :464, :481
- `classes/TenantProvisioning.php:408-410` — คีย์ `$tenantData`; :444 — `db_host`
- `classes/TenantProvisioning.php:150-177` (`create`), :184-207 (`grant`), :216-276 (`applySchema`), :282-318 (`ensureAdminUsersTable`), :663-690 (`recordMigrationApplied`)
- `classes/TenantProvisioning.php:623-643` (`logStart`), :645-661 (`logComplete`)
- `classes/TenantOnboardingService.php:63-215` (`provisionFromOwner`), :23-50 (`PLAN_ENTITLEMENTS`), :125-127 (จอง id เริ่ม 100)

**cPanel uapi:**
- `classes/TenantProvisioning.php:8-19` (เหตุผล), :36, :39, :42, :45, :48 (constants)
- `classes/TenantProvisioning.php:164` (create), :191-195 (grant), :331 (delete)
- `classes/TenantProvisioning.php:513-532` (`uapi`), :538-560 (`runShell`), :566-576 (`isUapiSuccess`)
- `classes/TenantProvisioning.php:211-214, 228-243, 273-275` — defaults-extra-file
- `classes/TenantProvisioning.php:599-613` (`assertDbNameAllowed`), :58-79 (name helpers)
- `database/migration_2026-05-25_platform_master.sql:30-36`

**ไม่มี `strategy=mysql`:**
- `grep -n "strategy" classes/TenantProvisioning.php` → exit 1, ศูนย์ผลลัพธ์
- `docs/plans/2026-08-14-golive-readiness-audit.md:39`; `docs/runbooks/phase0-cutover-rollback.md:217-236`; `docs/plans/2026-08-14-codex-handoff.md:245-306`; `docs/plans/2026-07-12-nextjs-full-migration-plan.md:84`

**Status lifecycle:**
- `database/migration_2026-05-25_platform_master.sql:100-101`
- `classes/TenantProvisioning.php:354` (suspend), :366 (resume), :382 (terminate), :437, :482
- `classes/SelfServeProvisioning.php:130, 281`; `admin/tenant-approvals.php:66`
- `bootstrap/resolve_subdomain.php:209-241`
- ป้ายภาษาไทย: `admin/customers.php:107`, `admin/platform-dashboard.php:123`, `admin/tenant-detail.php:196`

**Idempotency / rollback:**
- `classes/TenantProvisioning.php:417-419` (doc), :487-502 (impl), :155-160, :465-479, :181-183, :280, :349, :361
- `database/migration_2026-05-25_tenant_template.sql:8-11`
- `classes/SelfServeProvisioning.php:230` เทียบ :142 — bug guard ที่ไม่เคย match
- `classes/TenantProvisioning.php:378-399` — `$graceDays` ไม่ถูกใช้

**Schema:**
- `database/migration_2026-05-25_platform_master.sql:86-130` (`tenants`), :197-226 (`tenant_provisioning_log`), :234-254 (`tenant_migrations`), :19-23 (cross-DB FK)
- `database/migration_2026-05-25_tenant_template.sql:8-11, 15-16, 18-22, 26-30` และ trailer `Emitted: 279 tables | Skipped: 43 tables`

**ช่องว่างที่โค้ดยอมรับเอง:**
- `classes/TenantProvisioning.php:27-31` (bootstrap log), :262-265 (tenants 0113/0115), :188 (`seed` แทน `grant`)
- `classes/TenantOnboardingService.php:16-18` (ความซ้ำซ้อน)
- `classes/TenantFileStorage.php:47-51` (ขอบเขตที่ไม่รับผิดชอบ)
- `packages/db/src/migrateAll.ts:8-19` (runner ยังไม่เคยรันกับ DB จริง)
- `admin/beta-signups.php:166-169` (plaintext password ใน flash)

## Known Gaps

1. **วันที่และสถานะจริงของ ADR ต้นฉบับ** — กู้คืนไม่ได้
2. **การไม่ idempotent เป็นเจตนาหรือหนี้** — โค้ดไม่ได้บอก ต้องถามผู้ตัดสินใจเดิม
3. **ADR ต้นฉบับครอบคลุม `TenantFileStorage` ด้วยหรือไม่** — ปัจจุบันไฟล์นั้นอ้าง ADR-001 ไม่ใช่ ADR-002 แต่ตามหน้าที่แล้วเป็นส่วนหนึ่งของ provisioning

## Last Verified From Code

Verified on 2026-09-02 from `classes/TenantProvisioning.php`, `classes/TenantOnboardingService.php`, `classes/SelfServeProvisioning.php`, `classes/TenantFileStorage.php`, `database/migration_2026-05-25_platform_master.sql`, `database/migration_2026-05-25_tenant_template.sql`, `bootstrap/resolve_subdomain.php`, `admin/beta-signups.php`, `admin/tenant-detail.php`, `admin/tenant-approvals.php`, `scripts/provision_tenant.php`, `install/wizard.php`, `packages/db/src/migrateAll.ts`, and `docs/plans/2026-08-14-golive-readiness-audit.md` at commit `61ebb4c`.

## Related

- [ADR-001: Database-per-Tenant Isolation](0001-database-per-tenant-isolation.md) — สถาปัตยกรรมที่ pipeline นี้รับใช้ และ §"Hosting constraint" ที่บังคับให้ใช้ uapi
- [ADR-006: Two-Realm Session Model](0006-two-realm-session-model.md) — `provision_tenant` เป็นหนึ่งใน action ที่เขียน `super_admin_audit`
