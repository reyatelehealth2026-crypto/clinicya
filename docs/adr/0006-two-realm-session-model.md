# ADR-006: Two-Realm Session Model and Audited Impersonation

## Status

**Reconstructed from code, needs confirmation.**

สร้างขึ้นใหม่จาก 8 จุดในโค้ดที่อ้างถึง ADR นี้ โดย 2 จุดอ้างหัวข้อย่อยตรง ๆ ว่า §"Session model" (`admin/switch-tenant.php:5`, `includes/auth_check.php:60`) ไฟล์ต้นฉบับไม่เคยถูก commit

**สิ่งที่ต้องยืนยัน:** ADR ต้นฉบับสัญญาว่าจะ audit แค่ "การสลับ tenant" หรือ "ทุก write ในบริบท tenant" — ปัจจุบันโค้ดทำได้แค่อย่างแรก แต่ UI อ้างอย่างหลัง (ดู §Consequences ข้อ 4)

## Date

**ไม่ทราบวันที่ตัดสินใจ** — anchor: `2026-05-25` (migration ที่สร้าง `platform_users` + `super_admin_audit`), `2026-05-27` (bug fix ใน `includes/auth_check.php` ที่ปรับกฎ), `2026-07-12` (แผน migration ที่ต่อยอด)
เอกสารฉบับสร้างใหม่: 2026-09-02

## Context

[ADR-001](0001-database-per-tenant-isolation.md) แยกข้อมูลผู้เช่าด้วยขอบเขตฐานข้อมูล แต่ทีมงาน REYA ต้องเข้าไปดูข้อมูลในร้านของลูกค้าเพื่อ support ได้ จึงเกิดคำถาม 2 ข้อ:

1. **ผู้ใช้ระดับแพลตฟอร์มกับผู้ใช้ระดับร้าน เป็นระบบ auth เดียวกันหรือไม่**
2. **เมื่อทีมงานเข้าไปดูข้อมูลลูกค้า จะรู้ได้อย่างไรว่าใครดูอะไรเมื่อไหร่** — ข้อมูลที่ดูคือข้อมูลสุขภาพ ซึ่งอยู่ภายใต้ PDPA

ข้อจำกัดเพิ่มเติม:
- `admin_users` อยู่ใน **tenant DB** (คนละชุดต่อผู้เช่า) ส่วน `platform_users` อยู่ใน **master DB**
- รหัสผ่านเดิมถูก hash ด้วย PHP `password_hash()` (bcrypt `$2y$`) — ต้อง verify ต่อได้ **ห้าม re-hash**

## Decision

### Session model

*(หัวข้อนี้ถูกอ้างโดยตรงจาก `admin/switch-tenant.php:5` และ `includes/auth_check.php:60`)*

ใช้ **สอง realm แยกขาดจากกัน** ไม่ใช่ระบบสิทธิ์เดียวที่มี role สูงขึ้น

| | Realm A — tenant admin | Realm B — platform super-admin |
|---|---|---|
| ตาราง | `admin_users` (tenant DB) | `platform_users` (master DB) |
| PHP session key | `$_SESSION['admin_user']` (ทั้งแถว ตัด password ออก) | `$_SESSION['platform_user_id']` + `_email`/`_name`/`_role` |
| คอลัมน์รหัสผ่าน | `password` | `password_hash` |
| หน้า login | `/auth/login.php` | `/admin/platform-login.php` |
| ด่านตรวจ | `includes/auth_check.php:25` → redirect | `admin/switch-tenant.php:35` → HTTP 403 |
| Role | free-text `VARCHAR(20)` default `'admin'` | `ENUM('super_admin','support','readonly')` |
| Cookie (ฝั่ง Node) | **`reya_sid`** | **`reya_platform_sid`** |

> *"NOT the same auth as `$_SESSION['admin_user']` (which is tenant-scoped admin_users)"* — `admin/switch-tenant.php:6-8`

**สองบทบาทชื่อ `super_admin` ไม่ใช่สิ่งเดียวกัน** — `TenantRole::super_admin` ให้สิทธิ์ข้าม bot ภายในร้านเดียว ส่วน `PlatformRole::super_admin` ให้สิทธิ์ข้ามผู้เช่า `isSuperAdmin()` (`includes/auth_check.php:155-158`) ตรวจ **ตัวแรก** เสมอ ไม่เคยตรวจ `platform_user_role`

**คีย์ session ที่เป็นทางการ** (`internal/session-bridge.php:16-18`):

```
admin_user, current_bot_id, active_tenant_id, platform_user_id,
platform_user_email, platform_user_name, platform_user_role,
admin_switched_to_tenant_id

Do not invent new keys — if a new field is ever needed, add it to BOTH
this file and packages/auth/src/types.ts's BridgePhpSessionKeys together.
```

### Super-admin ไม่ได้ tenant โดยปริยาย

```
IMPORTANT: super-admins do NOT get an implicit tenant via this resolver.
They MUST call setCurrentTenantId($id) explicitly to enter a tenant scope,
or enterPlatformContext() to query reya_platform. This is intentional — it
prevents accidental cross-tenant reads driven by whoever last logged in.
```
— `classes/TenantContext.php:20-23`

หลัง login สำเร็จ ระบบ **ล้าง** tenant ทิ้งทันทีและเข้าสู่ Platform Mode (`admin/platform-login.php:115-117`)

**กฎที่ใช้จริงมี 3 กิ่ง ไม่ใช่ "platform ชนะเสมอ"** — ปรับหลัง bug fix 2026-05-27 (`includes/auth_check.php:93-112`):

```
- ถ้ามี admin_switched_to_tenant_id → อันนั้นชนะ (super admin กำลังสวมบทเป็น tenant)
- ถ้ายังไม่มี tenant ใน scope เลย → fall back เป็น platform (หน้าเฉพาะ super-admin)
- ถ้ามี tenant ถูก pin จาก subdomain/session แล้ว → คง tenant ไว้ ไม่ override
```

เหตุผลที่ต้องแก้ (verbatim): การเข้า platform context อัตโนมัติเพียงเพราะ session มี `platform_user_id` *"nukes the tenant context set by the subdomain resolver … sending every admin query to the (mostly empty) platform DB → \"Table 'reya_platform.users' doesn't exist\" fatal errors across the whole admin UI"*

### Impersonation ต้องถูก audit ทุกครั้ง

**เข้า** (`admin/switch-tenant.php:104-132`): POST `action=enter` พร้อม `tenant_id` และ `reason` → ตรวจว่ามีจริงและไม่ใช่ `terminated` (`'Tenant นี้ถูกระงับถาวรแล้ว — ห้ามเข้า'`) → set `admin_switched_to_tenant_id` → **audit `switch_tenant_in`** พร้อม metadata `{tenant_slug, tenant_display_name, tenant_status, reason}` → redirect

**ออก** (`:255-266`): เก็บ tenant id เดิมไว้ก่อน unset → `enterPlatformContext()` → **audit `switch_tenant_out`** (ไม่มี metadata → เก็บเป็น SQL NULL)

**คอลัมน์ที่เขียนจริง 9 จาก 12** (`admin/switch-tenant.php:77-80`):
```sql
INSERT INTO super_admin_audit
    (platform_user_id, tenant_id, action, ip_address, user_agent,
     request_method, request_uri, metadata, created_at)
 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
```
`target_type` และ `target_id` มีในตารางแต่**ไม่มีโค้ดใดเขียน**

**action ที่ถูกเขียนจริงทั้งหมด:** `switch_tenant_in`, `switch_tenant_out`, `provision_tenant`, `platform_login`, `approve_tenant`, `suspend_tenant`, `resume_tenant`, `terminate_tenant`, `record_payment`, `save_subscription`

### เลือก stateful session ไม่ใช่ JWT

```
-- chosen over JWT specifically because impersonation
-- and admin_bot_access ACL changes must revoke access immediately
```
— `packages/db/migrations/master/migration_2026-07-12_node_sessions.sql:8-16`

กลไกคือ index สองตัวที่ทำให้ revoke ทุก session ของคนคนเดียวได้ใน query เดียว: `idx_node_sessions_realm_admin_user` และ `idx_node_sessions_realm_platform_user` เปิดใช้ผ่าน `SessionStore.deleteAllForIdentity()`

JWT ถูกใช้เฉพาะ **websocket handshake** ที่อายุสั้นเท่านั้น

### ห้าม re-hash bcrypt

```
passwords.ts — read-only password verification. Nothing in this file (or
anywhere else in this package's login path) ever calls bcrypt.hash()/
bcryptjs.hash() on a plaintext password — admin_users.password and
platform_users.password_hash were written by PHP's
password_hash($plain, PASSWORD_DEFAULT) (bcrypt, `$2y$` prefix) and this
package only ever verifies against those existing hashes.
```
— `packages/auth/src/passwords.ts:3-10`

ความเท่ากันของ prefix `$2a$`/`$2b$`/`$2y$` ถูก **ทดสอบกับ fixture ที่ PHP สร้างจริง** ไม่ได้เชื่อเอกสาร bcryptjs

## Alternatives Considered

> ไม่มีเอกสารเปรียบเทียบหลงเหลือ หัวข้อนี้ประกอบจากเหตุผลที่เขียนกำกับไว้ในโค้ดและแผน

### JWT / stateless token

- **สถานะ:** ปฏิเสธ — ระบุเหตุผลชัดที่ `docs/plans/2026-07-12-nextjs-full-migration-plan.md:67`: *"เลือก session แทน JWT เพราะ impersonation/ACL ต้อง revoke ทันที"*
- คงไว้เฉพาะ websocket handshake ที่อายุสั้น

### Realm เดียวที่มี role สูงกว่า (super_admin เป็นแค่ role หนึ่งใน `admin_users`)

- **สถานะ:** ปฏิเสธ — จะทำให้ platform user ต้องมีตัวตนในทุก tenant DB ซึ่งขัดกับ ADR-001 และทำให้ revoke สิทธิ์ทั่วระบบเป็นไปไม่ได้
- ร่องรอย: ตารางคนละฐาน คนละคอลัมน์รหัสผ่าน คนละหน้า login คนละด่านตรวจ

### Role hierarchy (role สูงกว่าครอบสิทธิ์ role ต่ำกว่าอัตโนมัติ)

- **สถานะ:** ปฏิเสธ — `packages/auth/src/rbac.ts:9-16` ระบุว่า `requireRole()` ทำแค่ exact allow-list membership test และ *"no PHP code in this repo does hierarchical role comparison either — every check is an explicit allow-list"*

## Consequences

### 1. ต้องมี session bridge ชั่วคราวระหว่าง strangler

ระหว่าง migration Next.js เป็นเจ้าของ auth ตั้งแต่ Phase 1 แต่หน้า PHP เดิมยังต้องใช้งานได้ จึงมี `internal/session-bridge.php`:

- **ทิศทางที่ทำจริง:** Node → PHP เท่านั้น (`syncToPhpBridge()`) บวก action `introspect` ที่อ่านอย่างเดียว
- **ทิศทางกลับ (`/auth/adopt` บน Next) ยังไม่ได้สร้าง** — `grep "auth/adopt"` ไม่พบผลลัพธ์ที่ใดเลย คำว่า "bidirectional" ใน header จึงเป็นเจตนา ไม่ใช่สถานะปัจจุบัน
- **HMAC-SHA256** บน raw body, header `X-Reya-Signature`, เทียบด้วย `hash_equals()` **ก่อนแตะ `$_SESSION`** และ fail-closed เมื่อไม่ได้ตั้ง secret (503 `bridge_not_configured`) — *"an unconfigured secret must never be treated as 'no signature required'"*
- **replay window 300 วินาที** ผ่านฟิลด์ `issuedAt` ที่อยู่ใน body ที่ถูกเซ็น
- **ต้องอยู่ใน internal network เท่านั้น** — `internal/.htaccess` ปัจจุบันอนุญาตแค่ `172.30.99.0/24` ซึ่ง comment ระบุว่าเป็น carve-out เฉพาะ harness ไม่ใช่การตัดสินใจสำหรับ production
- **จะถูกลบเมื่อหน้า PHP สุดท้าย retire** — *"ลบ bridge เมื่อหน้า PHP สุดท้าย retire"*
- `syncToPhpBridge()` **ไม่เคย throw** — ทุก field มี `bridgeSynced: boolean` และ UI ควรแสดงเป็น notice ที่ไม่บล็อก

**ยังไม่เสร็จ:** เพื่อให้ browser เห็น `$_SESSION` ที่ bridge เขียน `apps/admin` ต้องตั้ง cookie `PHPSESSID` ให้เท่ากับ sid เดียวกันด้วย — ระบุชัดว่ายังไม่ได้ทำ

### 2. Session fixation ถูกจัดการที่จุดยกระดับสิทธิ์

`session_regenerate_id(true)` ตอน platform login (`admin/platform-login.php:105-108`) ฝั่ง TS ออก sid ใหม่ทั้งตอน login และทั้งสองกิ่งของ `switchTenant()` — เข้มกว่า PHP เดิมเพราะบังคับ single-active-session ต่อ identity

### 3. `admin_bot_access` ACL แยกจาก role

`canAccessBot($lineAccountId, $permission)` — ไม่มี user → false; tenant role `super_admin` → true ทันทีไม่ query; นอกนั้นอ่านแถว `admin_bot_access` แล้วดูคอลัมน์สิทธิ์ 6 ตัว (`can_view`, `can_edit`, `can_broadcast`, `can_manage_users`, `can_manage_shop`, `can_view_analytics`)

### 4. ⚠️ คำโฆษณาเรื่อง audit เกินความจริง

`admin/switch-tenant.php:547` อ้างว่า *"Every tenant switch and every write inside a tenant context is audited"* และแบนเนอร์แดงตอน impersonate บอกผู้ใช้ว่า *"all actions are being audited"*

**ความจริงคือครึ่งเดียว:** การสลับ tenant ถูก audit ครบ (ทั้งเข้าและออก) แต่ **ไม่มี write interceptor แบบทั่วไป** — มีผู้เขียน `super_admin_audit` แค่ 3 ไฟล์ PHP (`admin/switch-tenant.php`, `admin/platform-login.php`, `admin/tenant-detail.php`) บวก `packages/auth/src/impersonation.ts` super-admin ที่อยู่ในบริบท tenant แล้วแก้ข้อมูลผ่านหน้า admin ปกติ **ไม่สร้างแถว audit ใด ๆ**

นี่เป็นช่องว่างเชิง compliance ที่ควรตัดสินใจว่าจะปิดด้วยการทำ interceptor จริง หรือแก้ข้อความให้ตรงความจริง

### 5. การเขียน audit ฝั่ง PHP กลืน error

`admin/switch-tenant.php:92-94` catch แล้ว `error_log` อย่างเดียว — audit trail ที่พังจึงเงียบ
ฝั่ง TS แก้ไว้แล้วโดยเจตนา (`packages/auth/src/impersonation.ts:11-15`): *"here a failed INSERT propagates as a thrown error so a broken audit trail is never silently invisible to the caller"*

### 6. Audit เป็น append-only แค่โดยธรรมเนียม

UI บอกผู้ใช้เป็นภาษาไทยว่า *"ไม่สามารถลบโดย super admin ได้"* แต่ migration เขียนแค่ *"Append-only by convention"* — **ไม่มี `REVOKE DELETE`, trigger หรือ grant ใด ๆ บังคับ** และรีโปนี้มีนโยบายไม่ใช้ trigger/stored procedure เลย

FK `fk_saa_platform_user … ON DELETE RESTRICT` ทำให้ลบ platform user ที่มีแถว audit ไม่ได้ (แถว audit ตรึงผู้กระทำไว้)

### 7. ความไม่ตรงกันเล็กน้อยที่ควรรู้

- `admin/tenant-detail.php:48-58` มี audit writer ชุดที่สอง (copy pattern มา) ที่ตัด `user_agent` ที่ **255** ไม่ใช่ 500 → ข้อมูลหายเมื่อ UA ยาว
- `platform_login` ยัง **ไม่ถูก audit ฝั่ง TS** — ระบุไว้ว่าจงใจ *"to avoid inventing an undocumented side effect"*
- ชื่อคีย์ใน `docs/plans/2026-07-12-nextjs-full-migration-plan.md:68` (`admin_user_id`, `tenant_id`, `platform_role`, `impersonated_tenant_id`) **ไม่ใช่ชื่อจริง** เป็นภาพร่าง ชื่อจริงคือ `admin_user['id']`, `active_tenant_id`, `platform_user_role`, `admin_switched_to_tenant_id`

## Evidence

**8 จุดที่อ้าง ADR นี้:**
- `admin/switch-tenant.php:5` — `Auth model (ADR-006 §"Session model"):`
- `admin/switch-tenant.php:547` — `ADR-001 • ADR-006 — Every tenant switch and every write inside a tenant context is audited.`
- `admin/platform-login.php:213` — `All platform sessions are audited per ADR-006.`
- `includes/auth_check.php:60` — `to enter one. See ADR-006 §"Session model".`
- `internal/session-bridge.php:4`
- `packages/auth/src/impersonation.ts:6`
- `docs/plans/2026-07-12-nextjs-full-migration-plan.md:68`
- `.claude/agents/mig-kernel.md:36`

**สอง realm:**
- `classes/AdminAuth.php:12, 163, 212-213` — `$sessionKey='admin_user'`
- `admin/platform-login.php:5-6, 33-44, 110-113`
- `admin/switch-tenant.php:6-8, 35-44`
- `includes/auth_check.php:25, 155-158`
- `packages/auth/src/types.ts:15-23, 32-33, 38-40, 52-56, 143-152`
- `packages/auth/src/session.ts:366-375` — realm-scoped session rows
- `database/migration_2026-05-25_platform_master.sql:60-79` — `platform_users`

**คีย์ session:**
- `internal/session-bridge.php:16-18, 68-77, 240-247`
- `classes/AdminAuth.php:293, 302, 315` — `current_bot_id`
- `includes/auth_check.php:67-70, 81`

**Impersonation + audit:**
- `admin/switch-tenant.php:68-95` (`$writeAudit`), :104-132 (enter), :255-266 (exit), :239 (`provision_tenant`), :308-312, :340-342
- `admin/platform-login.php:86-103` (`platform_login`)
- `admin/tenant-detail.php:48-58, 77, 82, 87, 92, 120, 134`
- `database/migration_2026-05-25_platform_master.sql:160-189` — `super_admin_audit` 12 คอลัมน์, index, FK, comment ไทย
- `packages/auth/src/impersonation.ts:4-16, 29-42`; `packages/auth/src/session.ts:390-449`
- `packages/auth/tests/impersonation.test.ts:19, 39-48`

**ไม่มี implicit tenant:**
- `classes/TenantContext.php:7-23, 56-59`
- `includes/auth_check.php:45-60, 93-112` (bug fix 2026-05-27)
- `admin/platform-login.php:115-117`

**Session ไม่ใช่ JWT:**
- `docs/plans/2026-07-12-nextjs-full-migration-plan.md:67`
- `packages/db/migrations/master/migration_2026-07-12_node_sessions.sql:8-16, 85-88`
- `packages/auth/src/sessionStore.ts:29-30`

**ห้าม re-hash:**
- `packages/auth/src/passwords.ts:3-26`; `packages/auth/src/session.ts:117`
- `database/migration_2026-05-25_platform_master.sql:67`
- `admin/switch-tenant.php:232` — `password_hash()` ที่นี่คือสร้าง admin ใหม่ ไม่ใช่ re-hash

**Session fixation / ACL:**
- `admin/platform-login.php:105-108`; `packages/auth/src/session.ts:403-405, 432-434`; `packages/auth/README.md:15, 92-102`
- `classes/AdminAuth.php:83-88, 271-286`; `packages/auth/src/rbac.ts:9-16, 62-68, 80-112`

**Session bridge:**
- `internal/session-bridge.php:3-8, 26-42, 111-146, 152, 157-205, 231-252`
- `internal/.htaccess` (ทั้งไฟล์)
- `packages/auth/src/bridgeClient.ts:5-22`; `packages/auth/src/types.ts:117, 158-173, 184-186`; `packages/auth/README.md:16, 53-62, 103-107`

## Known Gaps

1. **วันที่และสถานะจริงของ ADR ต้นฉบับ** — กู้คืนไม่ได้
2. **ขอบเขต audit ที่ ADR ต้นฉบับสัญญาไว้** — โค้ดกับ UI ไม่ตรงกัน (ดู Consequences ข้อ 4) ต้องให้ผู้ตัดสินใจเดิมชี้ขาดว่าจะปิดช่องว่างหรือแก้ข้อความ
3. **`target_type` / `target_id`** — ออกแบบไว้ให้ใช้ทำอะไร ไม่มีโค้ดใดเขียน
4. **CIDR ของ production สำหรับ session bridge** — ยังไม่ถูกตัดสิน

## Last Verified From Code

Verified on 2026-09-02 from `admin/switch-tenant.php`, `admin/platform-login.php`, `admin/tenant-detail.php`, `includes/auth_check.php`, `classes/AdminAuth.php`, `classes/TenantContext.php`, `internal/session-bridge.php`, `internal/.htaccess`, `database/migration_2026-05-25_platform_master.sql`, `packages/db/migrations/master/migration_2026-07-12_node_sessions.sql`, `packages/auth/src/types.ts`, `packages/auth/src/session.ts`, `packages/auth/src/sessionStore.ts`, `packages/auth/src/passwords.ts`, `packages/auth/src/rbac.ts`, `packages/auth/src/impersonation.ts`, `packages/auth/src/bridgeClient.ts`, `packages/auth/tests/impersonation.test.ts`, `packages/auth/README.md`, and `docs/plans/2026-07-12-nextjs-full-migration-plan.md` at commit `61ebb4c`.

## Related

- [ADR-001: Database-per-Tenant Isolation](0001-database-per-tenant-isolation.md) — §"Connection routing" กำหนดว่า super-admin ไม่ได้ tenant โดยปริยาย
- [ADR-002: Tenant Provisioning Pipeline](0002-tenant-provisioning-pipeline.md) — `provision_tenant` เป็น action หนึ่งใน `super_admin_audit`
