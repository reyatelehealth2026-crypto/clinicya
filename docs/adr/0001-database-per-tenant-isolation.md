# ADR-001: Database-per-Tenant Isolation

## Status

**Reconstructed from code, needs confirmation.**

เอกสารฉบับนี้ถูก *สร้างขึ้นใหม่* จากโค้ดและ migration ที่อ้างถึงมัน ไม่ใช่ต้นฉบับที่เขียนตอนตัดสินใจ — ไฟล์ต้นฉบับที่ path นี้ไม่เคยถูก commit เข้ารีโป (ตรวจ `git log --all --diff-filter=D -- 'docs/adr/*'` แล้วไม่พบประวัติการลบ) แต่มีโค้ดอ้างอิงถึง **30+ จุด** รวมถึง 3 migration ที่ระบุชื่อไฟล์นี้ตรง ๆ

**สิ่งที่ต้องยืนยันโดยผู้ตัดสินใจเดิม:** วันที่ตัดสินใจจริง, ทางเลือกที่พิจารณาแล้วปฏิเสธ (ไม่มีร่องรอยเป็นลายลักษณ์อักษรหลงเหลือ), และความหมายของ "Option A" ใน `bootstrap/resolve_subdomain.php:5`

## Date

**ไม่ทราบวันที่ตัดสินใจ** — วันที่ใกล้เคียงที่สุดที่ยืนยันได้คือ `2026-05-25` (วันที่ของ migration ชุดแรกที่อ้าง ADR นี้)
เอกสารฉบับสร้างใหม่: 2026-09-02

## Context

ระบบเดิมเป็น PHP monolith แบบ **single-tenant** ใช้ฐานข้อมูลร่วมกันหนึ่งชุด (`zrismpsz_demo` — 322 base tables บน production, 7.81 MB) เมื่อจะขายเป็น SaaS ให้ร้านยาหลายราย จำเป็นต้องแยกข้อมูลระหว่างผู้เช่า (tenant)

ข้อจำกัดที่กำหนดกรอบการตัดสินใจ:

- ข้อมูลที่เก็บเป็น **ข้อมูลสุขภาพ** (ประวัติการจ่ายยา, health profile, AI consultation, consent) — การรั่วข้ามผู้เช่าคือความเสียหายระดับ PDPA ไม่ใช่แค่ bug
- โค้ดเดิมมี call site ที่เรียก `Database::getInstance()` แบบไม่รู้จัก tenant อยู่ **~700 จุด** — เปลี่ยนทั้งหมดพร้อมกันไม่ได้
- Production รันบน **cPanel shared hosting** ซึ่งจำกัดสิ่งที่ทำได้กับ MySQL อย่างมาก (ดู §"Hosting constraint")
- LINE webhook URL ตั้งค่าที่ LINE Developers Console ต่อ channel และ **เปลี่ยนตาม tenant ไม่ได้**

## Decision

ใช้ **database-per-tenant** — ผู้เช่าแต่ละรายมีฐานข้อมูลจริงของตัวเอง โดยขอบเขตการแยกข้อมูลคือ **ขอบเขตของฐานข้อมูล** ไม่ใช่คอลัมน์ discriminator

> "Cross-tenant isolation is enforced by the database boundary itself, not by this column."
> — `database/migration_2026-05-25_tenant_template.sql:26-30`

โครงสร้าง:

| ส่วน | รายละเอียด |
|---|---|
| Master/platform DB | `zrismpsz_reya_platform` — ทะเบียนผู้เช่า 7 ตาราง (`tenants`, `platform_users`, `plans`, `entitlements`, `super_admin_audit`, `tenant_provisioning_log`, `tenant_migrations`) |
| Tenant DB | `zrismpsz_reya_t_NNNN` — หนึ่งชุดต่อผู้เช่า สร้างจาก template 279 ตาราง |
| Instance | **instance เดียวกันทั้งหมด** — master และ tenant ทุกชุดใช้ MariaDB ตัวเดียวกันและ credential เดียวกัน (`DB_HOST`/`DB_USER`/`DB_PASS`) |
| Routing | subdomain `{slug}.re-ya.com` → `master.tenants.slug` → `db_name` |

Cross-database foreign key **ไม่ถูกใช้เลย** เพราะ MariaDB ทำไม่ได้ข้าม DB — การอ้างถึงตารางฝั่ง tenant จาก master เป็น plain INT (soft reference)

### Connection routing

*(หัวข้อนี้ถูกอ้างโดยตรงจาก `database/migration_2026-05-25_platform_master.sql:84`)*

`master.tenants` คือ **ตารางกลางของการ routing** ทุก request วิ่งตามลำดับ:

```
session → platform_users → tenant_id → db_name → PDO connect
```

API ที่ implement ลำดับนี้อยู่ใน `modules/Core/Database.php`:

| Method | บรรทัด | หน้าที่ |
|---|---|---|
| `getInstance()` | :76 | ทางเข้าเดิม (backward-compat) — resolve ผ่าน `TenantContext` |
| `forTenant(int $tenantId)` | :117 | ระบุผู้เช่าตรง ๆ |
| `platform()` | :151 | ต่อเข้า master DB |
| `resolveTenantDbName(int $tenantId)` | :292 | `SELECT db_name FROM tenants WHERE id = ?` |
| `legacyFallback()` | :309 | ตาข่ายนิรภัยช่วงเปลี่ยนผ่าน |

**สัญญาเรื่อง pooling:** หนึ่ง PDO ต่อคู่ `(db_name, host)` ใช้ซ้ำภายใน request เดียว ไม่มี pooling ข้าม request เพราะ PHP-FPM ไม่เก็บ state (`modules/Core/Database.php:12-14`)

**ลำดับการ resolve tenant** (`classes/TenantContext.php:7-18`):

```
explicit setCurrentTenantId() > $_SESSION['active_tenant_id']
  > platform_users lookup > legacy current_bot_id > null
```

**Super-admin ไม่ได้ tenant โดยปริยาย** — ต้องเรียก `setCurrentTenantId()` หรือ `enterPlatformContext()` เอง เป็นการกันการอ่านข้ามผู้เช่าโดยไม่ตั้งใจ (`classes/TenantContext.php:20-23`) รายละเอียด session ดู [ADR-006](0006-two-realm-session-model.md) §"Session model"

**LINE routing เป็นเส้นทางที่สอง** — เพราะ LINE webhook URL และ LIFF base URL อยู่บน root domain ไม่มี subdomain จึงไม่มี TenantContext ต้อง route ด้วย `master.tenant_line_account_routes` แทน โดยจับคู่ `(line_account_id, tenant_id)` เป็น composite identity (id ไม่ unique ข้าม tenant DB เพราะแต่ละชุดมี auto-increment ของตัวเอง) — `classes/TenantContext.php:261, 295`

### Hosting constraint

*(หัวข้อนี้ถูกอ้างโดยตรงจาก `classes/TenantContext.php:36`)*

Production รันบน **cPanel shared hosting** ซึ่งบังคับสามข้อ:

**1. `CREATE DATABASE` ถูกบล็อก** — MySQL user รันคำสั่งนี้ตรง ๆ ไม่ได้ (Access denied) ต้องผ่าน cPanel uapi เท่านั้น:

```
uapi Mysql create_database name='zrismpsz_reya_platform'
uapi Mysql set_privileges_on_database user='zrismpsz_demo' \
     database='zrismpsz_reya_platform' privileges='ALL PRIVILEGES'
```
— `database/migration_2026-05-25_platform_master.sql:30-36`

**2. ชื่อ DB ต้องขึ้นต้นด้วย account prefix** — cPanel ปฏิเสธ `create_database` ที่ชื่อไม่ขึ้นต้นด้วย username ของ account จึงเป็นเหตุผลที่ `PLATFORM_DB_NAME` ถูก hardcode ไว้:

```php
/**
 * Hardcoded master DB name. cPanel shared-hosting requires the account prefix
 * (`zrismpsz_`) — see ADR-001 "Hosting constraint". Move to config later.
 */
public const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';
```
— `classes/TenantContext.php:34-38`

บังคับใน `classes/TenantProvisioning.php` ด้วย `CPANEL_ACCOUNT = 'zrismpsz'` (:36), `DB_PREFIX = 'zrismpsz_reya_t_'` (:39) และ `assertDbNameAllowed()` (:599) ที่ปฏิเสธชื่อที่ไม่ตรง `/^zrismpsz_reya_t_\d{4}$/`

**3. ไฟล์ static ถูกเสิร์ฟด้วย worker คนละสิทธิ์** — PHP รันผ่าน suexec เป็น account user แต่ static GET เสิร์ฟด้วย Apache worker สิทธิ์ต่ำกว่า (group `nobody` บนเครื่อง re-ya.net/re-ya.com) ไฟล์ที่เขียนด้วย 0640/0750 จึงอ่านไม่ได้และ 404 ทั้งที่มีอยู่จริง — เป็นที่มาของ `PUBLIC_BUCKETS` ที่ใช้ 0711/0644 ใน `classes/TenantFileStorage.php:72-106`

**เพดานจำนวนผู้เช่า:** tenant id ถูกจำกัด 1..9999 จากรูปแบบชื่อ 4 หลัก (`classes/TenantProvisioning.php:59-66`)

> **หมายเหตุความถูกต้อง:** ไม่พบหลักฐานในรีโปว่า cPanel จำกัด *จำนวน* ฐานข้อมูล เพดานเดียวที่ยืนยันได้คือรูปแบบชื่อ 4 หลักข้างต้น หากเอกสารใดอ้าง "cPanel จำกัด N ฐานข้อมูล" ถือว่ายังไม่มีหลักฐานรองรับ

## Alternatives Considered

> **คำเตือน:** ไม่มีเอกสารเปรียบเทียบทางเลือกหลงเหลือในรีโป หัวข้อนี้ประกอบขึ้นจาก *ร่องรอยทางอ้อม* ในโค้ดเท่านั้น เหตุผลการปฏิเสธที่แท้จริงยังต้องยืนยันจากผู้ตัดสินใจเดิม

### Shared schema + discriminator column

- **สถานะ:** ปฏิเสธ (คือสถาปัตยกรรมเดิมที่ถูกแทนที่)
- ระบบเดิมคือ DB ร่วม `zrismpsz_demo` ที่ถูกแยกออกเป็น per-tenant DB โดย `scripts/migrate_data_to_tenant_dbs.php:6-11`
- คอลัมน์ `line_account_id` ยังอยู่ในทุกตารางแต่ถูกระบุชัดว่า **ไม่ใช่** กลไกแยกข้อมูล (`database/migration_2026-05-25_tenant_template.sql:26-30`)

### Row-level filtering เป็นขอบเขตหลัก

- **สถานะ:** ปฏิเสธในฐานะขอบเขตหลัก แต่ **คงไว้เป็น defense-in-depth**
- `tests/Tenancy/CrossTenantIsolationPropertyTest.php:9-14` เรียกมันว่า "secondary, defense-in-depth boundary" และเป็น "static-analysis tripwire" กัน `SELECT * FROM <health_table>` ที่ไม่มี WHERE

### แยก MariaDB instance ต่อผู้เช่า

- **สถานะ:** เลื่อนออกไป ไม่ได้เลือกตอนนี้
- มีคอลัมน์ `tenants.db_host` เตรียมไว้แล้ว comment ว่า *"for future multi-host scaling; default = same instance as platform"* (`database/migration_2026-05-25_platform_master.sql:95-96`) แต่ปัจจุบัน "same instance" (`packages/config/src/env.ts:21`)

### Cross-database foreign keys

- **สถานะ:** ปฏิเสธเพราะทำไม่ได้ทางเทคนิค — MariaDB ไม่รองรับ FK ข้าม DB (`database/migration_2026-05-25_platform_master.sql:19-23`)

## Consequences

### ผลดี

- การรั่วข้ามผู้เช่าต้องเกิดจาก *การต่อ connection ผิด* เท่านั้น ไม่ใช่ลืม `WHERE tenant_id = ?` — ลด attack surface ลงมาก
- query ภายใน tenant DB ไม่ต้อง filter อะไรเลย: "all rows in this DB" *คือ* "all rows for this tenant" (`apps/admin/src/app/(tenant)/settings/_lib/platform-queries.ts:29-31`)
- backup/restore/ลบข้อมูลรายผู้เช่าทำได้ที่ระดับ DB

### ผลเสียและภาระที่ตามมา

**1. ตาข่ายนิรภัยยังเปิดค้างอยู่ (ความเสี่ยงปัจจุบัน)**

สัญญาที่เขียนไว้คือ ถ้ามี master DB แล้วแต่ไม่มี tenant context → **ต้อง throw** แต่ในโค้ดจริง `throw` ยัง comment ไว้:

```php
// Transition safety net — fallback to legacy shared DB whenever no tenant
// context is set. This keeps ~700 unrefactored call sites working while we
// migrate them one-by-one. Once every entry point sets TenantContext,
// change this to throw (uncomment block below).
```
— `modules/Core/Database.php:89-98`

`platformDbExists()` (:260) ถูกนิยามและ cache ไว้แล้วแต่ **`getInstance()` ไม่เคยเรียก** — fallback จึงเกิดแบบไม่มีเงื่อนไข เปิด `REYA_LOG_COLD_FALLBACK=1` เพื่อดู breadcrumb (throttle 30 วิ)

**2. ทุก CLI/cron ต้องประกาศ opt-out**

```php
define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once 'config/database.php';
```
ปัจจุบันมี **18 ไฟล์** ที่ประกาศ cron ที่วน tenant ต้อง `TenantContext::setCurrentTenantId($id)` เอง และควรเรียก `Database::resetAll()` ระหว่างรอบเพื่อคืน connection (`modules/Core/Database.php:172-180`)

**3. งบ connection เป็นทรัพยากรจำกัด**

ฝั่ง PHP: หนึ่ง PDO ต่อ `(db_name, host)` ต่อ request
ฝั่ง Node (แผน migration): LRU ~50 pools, idle-evict 10 นาที, connectionLimit 3–5/pool, รวมต้อง < `max_connections`, alert ที่ 70% — ถูกขึ้นทะเบียนเป็น **ความเสี่ยงอันดับ 3** ของแผน migration

**4. Fail-open ทุกชั้น (ตั้งใจ)**

- subdomain resolve error → log + คืน null + ไปต่อด้วย legacy (`bootstrap/resolve_subdomain.php:242-245`)
- `TenantContext::getMasterPdo()` คืน null ไม่ throw เมื่อไม่มี master DB
- rollback ฉุกเฉิน: เปลี่ยน `config/database.php` ให้ require `config/database.legacy.php` แล้ว clear opcache

**5. Gating ที่ subdomain**

| กรณี | ผลลัพธ์ |
|---|---|
| slug ไม่พบ | HTTP 404 หน้าไทย (กันการ probe ว่ามีร้านนี้ไหม) |
| `suspended` / `terminated` | HTTP 503 หน้าไทย |
| `pending_setup` | **ไม่บล็อก** — ให้ใช้เต็มในโหมด DEMO (`REYA_DEMO_MODE`) |
| root domain | ยกเว้นจากทั้ง 404 และ 503 เสมอ |
| reserved subdomain (`www`, `api`, `admin`, `shop`, `odoo`, `stg`, …) | ข้ามการ resolve |

**6. ไฟล์อัปโหลดต้องแยกไดเรกทอรี**

`uploads/tenant_NNNN/<bucket>/` — ก่อนหน้านี้ slip ทุกผู้เช่าอยู่ในโฟลเดอร์เดียวกันด้วยชื่อที่เดาได้ (`slip_<order_number>_<unixtime>.<ext>`) ใครเดา URL ได้ก็ดึงสลิปของผู้เช่าอื่นได้ — ปิดช่องนี้ด้วย `scripts/migrate_uploads_to_tenant_dirs.php`

**7. หนี้ที่ยังค้าง**

- คำเตือน class collision ใน `classes/Database.php:10-26` **ล้าสมัยแล้ว** — `config/database.php:20` เปลี่ยนไป require shim แล้ว แต่ข้อความเตือนยังไม่ถูกลบ
- WebSocket auth ยัง port ไม่ได้ เพราะ `admin_users.session_token` lookup ระบุไม่ได้ว่าต้องถาม tenant DB ไหน (`docs/runbooks/websocket-consolidation.md:41` — สถานะ deferred)
- Prisma ถูกปฏิเสธในแผน migration เพราะผูก 1 client/engine ต่อ 1 DB ซึ่งหนักเมื่อมี N ผู้เช่า → เลือก Kysely + mysql2 pool registry แทน

## Evidence

**ชื่อและตำแหน่งไฟล์ ADR นี้ (อ้างตรงจากโค้ด):**
- `database/migration_2026-05-25_platform_master.sql:4` — `-- ADR: docs/adr/0001-database-per-tenant-isolation.md`
- `database/migration_2026-06-04_platform_billing.sql:4` — เดียวกัน `(master DB layer)`
- `database/migration_2026-05-25_tenant_template.sql:30`
- `CLAUDE.md:224` — `Single-context — one CONTEXT.md + docs/adr/ at the repo root.`

**หัวข้อย่อยที่ถูกอ้างชื่อตรง:**
- `classes/TenantContext.php:36` → §"Hosting constraint"
- `database/migration_2026-05-25_platform_master.sql:84` → §"Connection routing"

**การตัดสินใจ:**
- `config/database.php:7` — ระบุชื่อ decision ว่า "Database-per-Tenant Isolation Model"
- `database/migration_2026-05-25_tenant_template.sql:26-30` — ขอบเขต = DB ไม่ใช่คอลัมน์
- `tests/Tenancy/CrossTenantIsolationPropertyTest.php:5-9` — ขอบเขต = PDO connection ไหน
- `classes/TenantContext.php:25-30, 38` — master DB, credential ร่วม
- `modules/Core/Database.php:76, 117, 151, 292, 309` — `getInstance`/`forTenant`/`platform`/`resolveTenantDbName`/`legacyFallback`
- `bootstrap/resolve_subdomain.php:5, 7-25, 82-87, 138-144` — subdomain flow, regex, root tenant
- `infra/nginx/generate-routes.mjs:312-317`, `infra/nginx/generated/strangler-edge.conf:55` — scheme เดียวกันฝั่ง nginx
- `packages/config/src/env.ts:21, 28-33, 43, 70-76` — mirror ฝั่ง TypeScript

**Hosting constraint:**
- `database/migration_2026-05-25_platform_master.sql:30-36` — uapi, `CREATE DATABASE` ถูกบล็อก
- `classes/TenantProvisioning.php:7-20, 36, 39, 59-66, 599-613`
- `classes/TenantFileStorage.php:26, 72-80, 88-106`

**ทางเลือกที่ปฏิเสธ:**
- `scripts/migrate_data_to_tenant_dbs.php:6-11` — แยกจาก `zrismpsz_demo`
- `tests/Tenancy/CrossTenantIsolationPropertyTest.php:9-14` — row-level = defense-in-depth
- `database/migration_2026-05-25_platform_master.sql:19-23, 95-96` — cross-DB FK, `db_host`
- `docs/plans/2026-07-12-nextjs-full-migration-plan.md:47-49` — ปฏิเสธ Prisma เพราะ db-per-tenant

**ผลที่ตามมา:**
- `modules/Core/Database.php:12-14, 21-27, 89-110, 172-180, 260-287`
- `classes/TenantContext.php:7-23, 56-59, 113-124, 130-139, 261, 295`
- `classes/Database.php:10-26` เทียบกับ `config/database.php:14-18, 20`
- `config/database.legacy.php:6-43` — rollback ฉุกเฉิน
- `bootstrap/resolve_subdomain.php:19-21, 44-64, 113-136, 186-245, 252`
- `database/migration_2026-05-27_tenant_line_account_routes.sql:1-19` — LINE ใช้ subdomain ไม่ได้
- `classes/TenantFileStorage.php:4-52, 59, 82, 269`
- `scripts/migrate_uploads_to_tenant_dirs.php:5-14, 43-51`
- `packages/auth/src/tenantDbContext.ts:5-18` — AsyncLocalStorage seam ฝั่ง Node
- `docs/runbooks/websocket-consolidation.md:41` — ข้อจำกัดที่ทำให้ websocket auth ถูก defer
- `apps/admin/src/app/(tenant)/articles/_lib/seo.ts:10-13` — BASE_URL แบบ hardcode ผิดทุกผู้เช่า

**สรุปเชิงบรรยาย (สองภาษา):** `CLAUDE.md:7, 101-110`; `README.md:18-22, 79-82`

## Known Gaps

สิ่งที่ **กู้คืนจากโค้ดไม่ได้** และต้องการการยืนยันจากผู้ตัดสินใจเดิม:

1. **วันที่และสถานะจริงของ ADR ต้นฉบับ** — anchor ที่ใกล้ที่สุดคือ migration ลงวันที่ 2026-05-25
2. **เหตุผลการปฏิเสธทางเลือกแบบเป็นลายลักษณ์อักษร** — ไม่มีตารางเปรียบเทียบหรือ prose หลงเหลือเลย
3. **"Option A" หมายถึงอะไร** — `bootstrap/resolve_subdomain.php:5` และ `config/database.php:22` อ้าง "Option A subdomain decision" แต่ไม่มี Option B/C อยู่ที่ใดในรีโป
4. **เนื้อหาเดิมของ §"Connection routing"** — สร้างใหม่จาก `database/migration_2026-05-25_platform_master.sql:82-85` + `modules/Core/Database.php` เท่านั้น

## Last Verified From Code

Verified on 2026-09-02 from `classes/TenantContext.php`, `modules/Core/Database.php`, `classes/Database.php`, `config/database.php`, `config/database.legacy.php`, `bootstrap/resolve_subdomain.php`, `classes/TenantFileStorage.php`, `classes/TenantProvisioning.php`, `database/migration_2026-05-25_platform_master.sql`, `database/migration_2026-05-25_tenant_template.sql`, `database/migration_2026-05-27_tenant_line_account_routes.sql`, `database/migration_2026-06-04_platform_billing.sql`, `tests/Tenancy/CrossTenantIsolationPropertyTest.php`, `scripts/migrate_data_to_tenant_dbs.php`, `scripts/migrate_uploads_to_tenant_dirs.php`, `packages/config/src/env.ts`, `packages/auth/src/tenantDbContext.ts`, `infra/nginx/generate-routes.mjs`, `CLAUDE.md`, and `README.md` at commit `61ebb4c`.

## Related

- [ADR-002: Tenant Provisioning Pipeline](0002-tenant-provisioning-pipeline.md) — วิธีสร้าง tenant DB ที่ ADR นี้กำหนดโครงไว้
- [ADR-006: Two-Realm Session Model](0006-two-realm-session-model.md) — auth ที่บังคับกฎ "super-admin ไม่มี tenant โดยปริยาย"
- [docs/ai/adrs/0001-tenant-aware-database-routing.md](../ai/adrs/0001-tenant-aware-database-routing.md) — เอกสาร AI-inferred หัวข้อทับซ้อน ดู [README](README.md) §"ความสัมพันธ์กับ docs/ai/adrs/"
