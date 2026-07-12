# แผนและสโคปงาน: มิเกรทระบบทั้งหมดไป Next.js (PHP Monolith → Next.js Re-platform)

## Context

ระบบปัจจุบันเป็น PHP 8 multi-tenant SaaS (CRM/e-commerce ร้านยาไทย + LINE OA + Odoo + AI telepharmacy) ขนาด **~395.6k PHP LOC / 1,115 ไฟล์** (runtime จริง ~350k LOC หลังตัด install/, scripts/, dead stubs), DB **280 ตารางต่อ tenant** + master DB, สถาปัตยกรรม database-per-tenant + subdomain routing ต้องการมิเกรททั้งหมดไป Next.js เพื่อรวมเป็น stack เดียว, กำจัด technical debt (ไฟล์ยักษ์ `inbox-v2.php` 14.5k LOC / `webhook.php` 5.6k LOC, schema auto-create ตอน runtime), และต่อยอดจาก `line-mini-app/` (Next.js 15) ที่เป็น production อยู่แล้ว

ข้อเท็จจริงสำคัญจากการสำรวจ (ยืนยันกับโค้ดแล้ว):
- **Production ปัจจุบันรันบน shared hosting Apache (cPanel)** — Docker compose ในรีโปเป็น environment คู่ขนาน ไม่มี PHP service/Dockerfile (มีแค่ backend/frontend/websocket) → ต้อง replatform ก่อนจึงจะ strangle per-route ได้
- `frontend/` (Next 16) ส่วนใหญ่ยัง mock + ปิดใน prod; `backend/` (Fastify+Prisma) มีแค่ 13/223 ตาราง และ**ไม่รองรับ db-per-tenant** → ทั้งคู่จะถูก harvest แล้ว retire
- `line-mini-app/` เรียก PHP ตรงผ่าน `src/lib/php-bridge.ts` (~21 endpoints, base URL เดียว) → ต้อง refactor เป็น per-endpoint origin map ก่อน strangle API
- ไม่มี trigger/stored procedure ใน DB เลย — logic อยู่ใน PHP ทั้งหมด (ย้ายง่าย); JSON เก็บเป็น `longtext + json_valid()` (MariaDB idiom)
- WebSocket มี 3 implementation ซ้ำซ้อน; retail-api/ ซ้ำกับ api/retail-*.php

## การตัดสินใจหลัก (ผู้ใช้ยืนยันแล้ว 2026-07-12)

| หัวข้อ | ตัดสินใจ |
|---|---|
| สโคป | มิเกรท 100% — admin UI, API, LINE webhook, cron, websocket → เลิกใช้ PHP ทั้งหมด |
| สถาปัตยกรรม | Next.js full-stack (App Router: UI + Route Handlers/Server Actions) + Node worker แยกสำหรับ cron/queue/websocket |
| กลยุทธ์ | Strangler — เก่า/ใหม่รันคู่กัน ย้ายทีละโมดูล rollback ได้ทุกขั้น |
| Hosting | Docker บน VPS (ต่อยอด blue-green compose เดิม) |

---

## 1. สถาปัตยกรรมเป้าหมาย

### 1.1 Monorepo (pnpm workspaces + Turborepo) — PHP เดิมอยู่ที่ root จนถึง Phase 13

```
apps/
  admin/      # Next.js: admin UI + API ทั้งหมด — app/(tenant), app/(platform), app/(public), app/api, middleware.ts
  miniapp/    # ย้าย line-mini-app/ เข้ามา (align Next version กับ admin)
  worker/     # Node เดียว: BullMQ workers + cron scheduler + WebSocket รวม (inbox realtime + WebRTC signaling)
packages/
  db/         # Kysely types (codegen), tenant pool registry, migration runner, migrations/{master,tenant}/*.sql
  tenant/     # resolveTenant(host), TenantContext (AsyncLocalStorage), status gating, routeByLineAccount
  auth/       # sessions 2 realm, RBAC + admin_bot_access ACL, impersonation, PHP session bridge client
  line/       # LineAPI port (reply-token-first, rich menu, HMAC), FlexTemplates + golden JSON fixtures
  core/       # genDocNumber, calcVAT, Thai/Buddhist dates, TenantFileStorage port, money
  contracts/  # zod schemas + golden fixtures ทุก endpoint ที่ย้าย (หัวใจของ parity testing)
  ui/         # UI kit เก็บเกี่ยวจาก frontend/ (DataTable/Modal/layout, TanStack Query infra)
  config/     # env schema (zod), route-manifest/feature-flag types
infra/
  compose/    # ต่อยอด docker-compose.{prod,blue,green}.yml
  nginx/      # strangler edge templates + routes.json
  php/        # Dockerfile.php ใหม่ (ยังไม่มีในรีโป) + apache config
```

### 1.2 Data access: **Kysely + mysql2 pool registry** (ไม่ใช้ Prisma)

เหตุผล: schema 280 ตาราง + db-per-tenant — Prisma ผูก 1 client/engine ต่อ 1 DB (หนักเมื่อ N tenants) และ `FOR UPDATE` ต้องหลบไป raw; Kysely เป็น layer บางบน `mysql2.Pool` ที่เราคุมเอง, `.forUpdate()` first-class (จำเป็นกับ `genDocNumber`/stock decrement), `sql\`\`` escape hatch เหมาะกับการ port SQL มือเขียนจาก PHP, `kysely-codegen` introspect 280 ตารางเป็น types ไฟล์เดียว

API หลักใน `packages/db` (แทน `modules/Core/Database.php`):
- `getMasterDb()` — pool เฉพาะ master (`zrismpsz_reya_platform`)
- `getTenantDb(tenantId)` — lookup `db_name` จาก `master.tenants` (cache 60s) → registry `Map<dbName, Kysely<TenantDB>>` แบบ **LRU (~50 pools, idle-evict 10 นาที, connectionLimit 3–5/pool)** — นี่คือสิ่งที่แทน per-request PDO ของ PHP
- ทุก connection init: `SET time_zone='+07:00'` + utf8mb4; งบ connection รวมต้อง < MySQL max_connections, alert ที่ 70%

### 1.3 Tenant resolution — port `bootstrap/resolve_subdomain.php` + `route_by_account.php`

- `apps/admin/middleware.ts`: Host → slug → master lookup (cache) → gating เดิมเป๊ะ: slug ไม่พบ → 404, suspended/terminated → หน้า 503 ไทย, demo → watermark, reserved subdomains ข้าม, root domain ไม่มี implicit tenant → set `x-tenant-id`/`x-tenant-db` headers
- Route Handler/RSC เข้า `AsyncLocalStorage` context `{tenantId, dbName, realm, user, botId, impersonatedTenantId}` — ลำดับ resolution เหมือน `TenantContext` (explicit > session > platform_users.tenant_id > legacy bot > null; super-admin ไม่มี implicit tenant)
- Root-domain APIs (webhook/checkout/member/ai-chat): `routeByLineAccount()` จาก `master.tenant_line_account_routes`
- Worker: job payload พก `{tenantId}` แล้ว wrap `withTenant()`

### 1.4 Auth: 2 realm, cookie session + PHP session bridge

- **Stateful session** ใน master DB (`node_sessions`) + Redis cache, httpOnly cookie แยก 2 realm (`reya_sid` tenant / `reya_platform_sid` platform) — เลือก session แทน JWT เพราะ impersonation/ACL ต้อง revoke ทันที (JWT อายุสั้นใช้เฉพาะ websocket handshake); port pattern จาก `backend/` auth service
- Session record จำลอง `$_SESSION` keys: `admin_user_id, tenant_id, current_bot_id, platform_user_id, platform_role, impersonated_tenant_id`; bcrypt เดิมต้อง verify ต่อได้ (**ห้าม re-hash**); Google OAuth + SSO ทำใหม่ใน Next; impersonation เขียน `super_admin_audit` ทุกครั้ง (ADR-006)
- **Bridge (ช่วง strangler): Next เป็นเจ้าของ login ตั้งแต่ Phase 1** — Next login สำเร็จ → เรียก `POST http://php/internal/session-bridge.php` (ไฟล์ PHP ใหม่ ~150 บรรทัด, HMAC-signed, internal network เท่านั้น) ให้ populate `$_SESSION` ผ่าน `AdminAuth` เดิม → PHP pages ใช้งานต่อได้โดยไม่แก้; ทิศกลับ: `auth/login.php` เพิ่ม redirect ผ่าน `/auth/adopt` บน Next; bot/tenant switch + logout วิ่งผ่าน Next → re-call bridge; ลบ bridge เมื่อหน้า PHP สุดท้าย retire

### 1.5 Strangler edge (nginx)

- Upstreams: `php_backend` (**default**) / `next_admin` / `next_miniapp` / `ws`
- **Route manifest as code**: `infra/nginx/routes.json` — `{path, upstream, tenants: "all"|[slugs]}` → generator render เป็น nginx `map` (host×path สองชั้น = per-tenant canary), CI validate, deploy = render + reload; **rollback ทุก route = revert 1 บรรทัด + reload**
- พฤติกรรม `.htaccess` ที่สำคัญ (extensionless URLs, `liff-*.php` → `/miniapp/` redirects, block debug scripts, security headers) ย้ายมาไว้ที่ nginx; ทุก response ติด `X-Served-By: php|next`

---

## 2. Phase 0 — Replatform PHP prod → Docker VPS (prerequisite, ยกมาทั้งดุ้นไม่แก้โค้ด)

1. เขียน `infra/php/Dockerfile` ใหม่: `php:8.2-apache` (composer ต้องการ >=8.0 + predis), ext: pdo_mysql/gd/curl/mbstring/zip/opcache, `AllowOverride All` ให้ `.htaccess` เดิมทำงาน, **PHP session → Redis** (container stateless, จำเป็นต่อ session bridge)
2. DB: **MariaDB 10.11 LTS** (ไม่ใช่ mysql:8.0 ใน compose เดิม — dump มาจาก cPanel MariaDB ที่ใช้ `json_valid()` CHECK idiom) — import master + tenant DBs ทั้งหมด (`mysqldump --single-transaction` ต่อ DB, ซ้อมนำเข้า 2 รอบ), เปิด binlog ตั้งแต่วันแรก
3. Uploads: rsync `uploads/` + `tenant_NNNN/<bucket>/` ทั้งหมดเข้า volume path เดิม รักษา public/private permission split
4. Provisioning: cPanel `uapi` shell-out ใช้ไม่ได้บน VPS → เพิ่ม `strategy=mysql` (privileged user `CREATE DATABASE`/`GRANT` + apply template) ใน `TenantProvisioning` — **โค้ด PHP ที่แก้ชิ้นเดียวใน Phase 0** (config-gated)
5. Cron: sidecar container (ofelia/crond) รัน `php cron/<job>.php` 33 jobs ตาม schedule เดิม
6. Cutover: ลด DNS TTL ล่วงหน้า → write-freeze ช่วง traffic ต่ำ → final dump/rsync → สลับ wildcard A record — **hostname เดิมทุกตัว** จึงไม่ต้องแตะ LINE/FB/TikTok/Telegram console; TLS wildcard ผ่าน DNS-01
7. Rollback: shared hosting เก็บ read-only 30 วัน; rollback = สลับ DNS กลับ + replay writes จาก binlog (ซ้อม rollback ด้วย)

**Acceptance:** ทุก subdomain serve จาก VPS; อัตรา insert `webhook_events` เท่า baseline; provision tenant ทดสอบผ่าน strategy ใหม่; cron 33 ตัวรันครบ; mini-app checkout E2E ผ่าน 1 tenant จริง

---

## 3. เฟสมิเกรทรายโมดูล (เรียงตาม risk × value; ทุกเฟสใช้ route manifest + per-tenant canary + parity harness ตาม §7)

### Phase 1 — Foundation kernel (ไม่มี cutover ที่ผู้ใช้เห็น ยกเว้น login)
- สร้าง monorepo, `packages/db` (codegen จาก tenant template + pool registry + migration runner), `packages/tenant`, `packages/auth` + session bridge สองทิศ, `apps/admin` shell — หน้า login + layout + **nav ที่ port จาก `includes/header.php`** (147KB = ทั้ง IA ของ admin → แปลงเป็น typed nav manifest ผูกกับ routes.json ว่าเมนูไหน stack ไหน serve), observability baseline, โครง parity harness
- **Retire:** `auth/login.php` UI (เก็บเป็น fallback + adopt-redirect)
- **Acceptance:** login บน Next → เปิด 5 หน้า PHP หนัก (inbox-v2, users, inventory, settings, dashboard) ไม่มี session error; Google OAuth/SSO ผ่าน; platform login + switch-tenant มี audit rows

### Phase 2 — Read-mostly admin pages (batch แรกของ UI)
- **Retire:** `index.php`/`index-v2.php` (dashboard), `users.php`, `user-detail.php`, `analytics.php`+stubs, `crm-dashboard-advanced.php`, `activity-logs.php`, `system-status.php`, `articles.php`/`article.php`, read-only tabs ใน `includes/settings/`, `loyalty-members.php`, `groups.php`/`line-groups*`, `pharmacists.php`, `templates.php`
- Server Components + Kysely; mutation เล็ก (tag/note) เป็น Server Actions เลย — ไม่ทิ้งหน้า half-PHP
- **Acceptance:** golden-screenshot diff ต่อหน้า + aggregate/row-count parity บน frozen dataset

### Phase 3 — Customer-facing APIs ที่ mini-app ใช้ (~21 endpoints)
- **Prereq:** refactor `php-bridge.ts` เป็น per-endpoint origin map (default PHP) — ship ก่อน flip ใดๆ
- **Retire:** `api/checkout.php` (2.8k — port `UnifiedShop` + guarded stock decrement `WHERE stock >= qty` + pending-transaction seed), `member.php`, rewards/points-claim/points-history, shop/product, appointments, health-profile, consent/data-rights (PDPA), `resolve-line-account.php`, orders, addresses, reminders
- Route Handlers รักษา contract เป๊ะ (zod + golden fixtures จาก traffic จริง); ลำดับ flip: reads → member/rewards → points-claim → **checkout สุดท้าย**
- **Acceptance:** shadow-traffic parity ≥ 99.9% field-level 7 วัน/endpoint; Playwright LIFF checkout E2E; slip upload (port sharp matching จาก backend/)

### Phase 4 — Inbox v2 + realtime
- **Retire:** `inbox-v2.php` (14.5k: หน้า + 10 POST actions + ~19 AI copilot actions), `api/inbox-v2.php` (3.6k), `messages.php` UI (dispense action ยังอยู่ PHP จนถึง Phase 5); **รวม websocket 3 ตัว → 1** ใน `apps/worker`
- Port `InboxService` รักษา **keyset cursor contract** (`last_message_at DESC`, limit+1 hasMore, filters, batch enrichment); realtime consume Redis `inbox_updates` ช่องเดิม — PHP `WebSocketNotifier` publish ต่อได้ระหว่าง coexist
- **Decompose ไม่ transliterate:** 29 actions → Server Actions ติด flag รายตัว; ย้าย reads ก่อน แล้ว actions ทีละ ~5
- **Acceptance:** cursor pagination golden tests (dataset เดียว → หน้าเหมือนกันเป๊ะ); latency ≤ PHP baseline; soak 2 สัปดาห์บน canary

### Phase 5 — Dispense + Documents/VAT (flip พร้อมกันเป็น atomic ต่อ tenant)
- **Retire:** dispense action (`messages.php:271` + inbox-v2), `dispense-drugs.php`, `dispense-tracking.php`, `documents.php` + `includes/documents/`, sales-tax-register
- `packages/core`: `genDocNumber` (Buddhist `{PREFIX}-{YYMM}-{seq4}`, `INSERT IGNORE` + `SELECT…FOR UPDATE` — ต้องออกเลขเหมือนเดิมทุก byte), `calcVAT` (7% inclusive back-calc), `formatThaiDate`; dispense Server Action ทำ chain ครบ: `dispensing_records` → `transactions`/`transaction_items` → seed cart (transfer/later) → guarded stock decrement → RefillTracking → **Flex ฉลากยา** (LIFF-or-OA URL fallback) → `messages` `sent_by='system:dispense'`; เอกสารยังเป็น printable A4 HTML (ไม่เพิ่ม PDF lib — ตรงกับปัจจุบัน)
- **Acceptance:** Flex JSON byte-diff = 0 เทียบ golden จาก `FlexTemplates`; property test 50 concurrent dispenses → เลขเอกสารไม่ซ้ำ/ไม่ข้าม; VAT ตรงถึงสตางค์บน corpus 1,000 รายการย้อนหลัง

### Phase 6 — LINE webhook + bot brain + auto-reply (เฟสเสี่ยงสูงสุด)
- **Retire:** `webhook.php` (5.6k), `BusinessBot.php` (3.3k), auto-reply matcher (exact/contains/starts_with/regex), slip handling, media download, Telegram mirror, `LineAPI.php` → `packages/line`
- **แก้ design เก็บ behavior:** Route Handler validate HMAC per-account (+ by-signature scan fallback) → check idempotency (`webhook_events.event_id` — **ตารางแชร์สองstack**) → enqueue BullMQ → **ACK 200 ทันที** (แทนงาน inline หนัก + background-curl hack); worker port event switch ทั้งหมด, AI hand-off ยังเรียก PHP `api/ai-chat.php` จนถึง Phase 7, media เขียน layout เดิม, publish `inbox_updates`
- **Cutover ละเอียดกว่า tenant:** ต่อ LINE account (`?account={id}`) — nginx route รายบัญชี
- **Acceptance:** **shadow mode 2 สัปดาห์** (nginx `mirror` → Next ประมวลผลแบบไม่ส่งจริง เขียน `webhook_events_shadow`, diff การตัดสินใจ) ≥ 99.5% parity ก่อน flip บัญชีแรก; ทดสอบ reply-token-first/push-fallback + single-use token

### Phase 7 — AI SSE pipeline
- **Retire:** `api/ai-chat.php` + siblings, `modules/AIChat/*` (โค้ด port ง่ายสุด), `api/pharmacy-ai.php` (2.6k), `ai-admin.php`
- Route Handler คืน `ReadableStream` (`text/event-stream`, `X-Accel-Buffering: no` — nginx ห้าม buffer location นี้); **event contract ศักดิ์สิทธิ์** (`data:{token}` / `data:{structured:{…}}` / `[DONE]` — mini-app ใช้อยู่); pipeline fail-open ครบชั้น: triage state machine → RedFlagDetector (CRITICAL → escalate ข้าม LLM) → Gemini SSE relay + key rotation → OpenAI fallback → Thai degrade + PharmacistNotifier → persist → drug-interaction post-check → per-tenant metering
- **Acceptance:** golden SSE transcript replay (stub upstream LLM) → event sequence เหมือนเดิม; red-flag corpus จาก `triage_sessions` จริง classify ตรงกัน; ทดสอบ flip กลางบทสนทนา (state อยู่ DB จึง resume ได้)

### Phase 8 — Odoo stack
- **Retire:** `classes/Odoo*` ทั้งชุด (`OdooWebhookHandler` 2.8k), `api/odoo-dashboard-api.php` (5k), `odoo-webhooks-dashboard.php` (3.9k), `api/odoo-webhook.php`, `odoo-dashboard.php` + JS 300KB
- JSON-RPC client + rate limit ใน worker; **circuit breaker ย้าย state จากไฟล์ /tmp → Redis** (จำเป็น — หลาย container); batching แทน curl_multi; dashboard อ่าน cache tables เดิม (`odoo_orders/invoices/bdos`); คง kill-switch ทั้งสอง (`ODOO_INTEGRATION_ENABLED` 410 guard + per-tenant `order_data_source`)
- **Cutover:** sync ต่อ tenant ผ่าน `sync_owner` flag (stack เดียว sync ต่อ tenant กันเขียนชนกัน); **Acceptance:** dry-run sync คู่ขนานเขียน `_shadow` tables แล้ว diff = 0

### Phase 9 — WMS / POS / Accounting / Inventory (เฟส port ใหญ่สุด เสี่ยงต่ำ แบ่งคนง่าย)
- **Retire:** `WMSService` (2.7k), `POSService` (1.4k) + POS* ทั้งชุด, `pos.php`, `inventory/` (12 ไฟล์) + `includes/inventory/` (23 ไฟล์ 11.6k), `includes/accounting/`, `procurement.php`
- **Acceptance:** property tests — stock ไม่ติดลบ, movements รวม = level; รายงานบัญชี parity บน frozen data

### Phase 10 — Cron 33 jobs → BullMQ worker
- Job registry per-job: schedule, tenant-fanout, timeout, retry/backoff, DLQ; **กัน double-execution:** `cron-manifest.json` แหล่งเดียว render ทั้ง crond และ BullMQ (job เป็นของฝั่งเดียวเสมอ) + Redis lock `cron:{job}:{window}` สำหรับ jobs อันตราย (broadcast/reminder/drip); webhook background-curl → enqueue ตรง
- **Acceptance:** side-effect parity ต่อ job (รันสองเวอร์ชันบน snapshot DB, diff rows)

### Phase 11 — Platform admin + Provisioning + Billing
- **Retire:** `admin/` 15 ไฟล์, `TenantProvisioning`, `signup.php`, `beta.php`
- `(platform)` realm ใน apps/admin; provisioning เป็น worker job: `CREATE DATABASE` + apply **committed schema จาก §4.1** (เลิกใช้ template dump ดิบ) + seed + routes + compensating rollback + log
- **Acceptance:** provision → onboard → ส่งข้อความ LINE → deprovision E2E อัตโนมัติ

### Phase 12 — Public site + webhooks อื่น + retail consolidation
- **Retire:** landing `index.php` + `includes/landing/`, articles CMS, privacy/terms/data-deletion, sitemap/robots, PWA; `facebook/tiktok/telegram_webhook.php` → thin Route Handlers enqueue เข้า pipeline Phase 6 (channel adapters); **retail-api/ + api/retail-*.php รวมเป็น shop API เดียว** (ทำ alias routes); `onboarding/` → Next
- **Acceptance:** SEO parity (sitemap/meta golden diff), Lighthouse ≥ baseline, echo test ทุก channel, retail mini-app E2E

### Phase 13 — Decommission PHP
- Route manifest = 100% next 30 วันติด (รวม month-end + provisioning event) → default upstream เป็น next, PHP เหลือ ops-only host → 30 วันถัดมา: ถอด PHP+crond containers, ลบ session bridge, archive PHP ไป branch `legacy-php`, ลบ vendor/composer/phpstan/phpunit, drop shadow/parity tables

---

## 4. Cross-cutting workstreams (รันคู่ทุกเฟส)

1. **Schema governance** (เริ่ม Phase 1): introspect template 280 ตาราง + master → committed SQL ใน `packages/db/migrations` (สอง stream: master/tenant); **`migrate-all` runner** ไล่ทุก tenant DB (--tenant/--dry-run/--continue-on-error) ใช้ตาราง `tenant_migrations` เดิมเป็น ledger; **drift audit ครั้งเดียวบังคับ** (ปีของ ensureColumn ทำให้ tenants เพี้ยนจาก template — สร้าง reconciliation migrations); โค้ดใหม่ห้าม auto-create schema; JSON คงเป็น longtext + `parseJson<T>()` zod helpers (ไม่แปลง column type ระหว่างมิเกรท)
2. **File storage:** port `TenantFileStorage` layout/bucket whitelist เดิมเป๊ะ — **สอง stack แชร์ volume เดียว = กลไก coexistence**; GD → `sharp`; private bucket serve ผ่าน Route Handler + nginx `X-Accel-Redirect`; ไม่ย้าย S3 ระหว่างมิเกรท
3. **Observability:** pino structured logs (tenant_id, request_id, served_by) + Sentry; เก็บ `dev_logs`-compat writer ระหว่าง coexist; metrics หลัก: latency/error แยกตาม `X-Served-By`, pool gauges, queue depth, webhook ACK latency, SSE duration
4. **i18n/dates:** `packages/core/dates` — Buddhist era +543, `Asia/Bangkok` ผ่าน explicit Intl timeZone (ห้ามพึ่ง server-local); `next-intl` default `th`; extract strings ตามหน้า ไม่ big-bang
5. **Testing:** contract/parity (recorded replay + live shadow) เป็น safety net หลัก; property tests (เลขเอกสาร monotonic, stock ≥ 0, points conservation, cursor completeness); Playwright E2E + LIFF mock ใน CI + on-device checklist ต่อ canary flip; golden-JSON snapshot ทุก FlexTemplates output
6. **CI/CD blue-green:** สีใช้กับ **Node set เท่านั้น** (admin/miniapp/worker) — PHP/MariaDB/Redis เป็น singleton นอกสี; pipeline: build → tests → deploy สี inactive → smoke → nginx flip → drain (worker หยุดรับ job รอ in-flight จบ); migration ต้อง backward-compatible 1 เวอร์ชันเสมอ (PHP กับ Node สีเก่าอ่าน DB เดียวกัน)

---

## 5. ประมาณการ effort

| Phase | งาน | person-weeks | ขนานกับ |
|---|---|---|---|
| 0 | VPS replatform | 3–5 | — (block ทุกอย่าง) |
| 1 | Foundation kernel | 8–12 | — (block 2+) |
| 2 | Read-mostly admin | 10–14 | 3 |
| 3 | Mini-app APIs | 12–16 | 2 |
| 4 | Inbox + realtime | 10–14 | 5 บางส่วน |
| 5 | Dispense + documents | 6–8 | 4 |
| 6 | LINE webhook + bot | 12–16 | 7 prep, 9 |
| 7 | AI SSE pipeline | 8–12 | 8 |
| 8 | Odoo stack | 10–14 | 7, 9 |
| 9 | WMS/POS/accounting | 14–18 | 6–8 (แบ่งคนง่ายสุด) |
| 10 | Cron → BullMQ | 6–8 | 11 |
| 11 | Platform + provisioning + billing | 6–9 | 10, 12 |
| 12 | Public + webhooks + retail | 6–9 | 11 |
| 13 | Decommission | 2–4 | — |
| **รวม** | + contingency 20% | **≈ 136–190 pw** | |

Timeline: **2 devs ≈ 20–28 เดือน** / **4 devs ≈ 10–14 เดือน** (หลัง Phase 1 แยกสองสาย: A = 2→3→4→5→6→7 (product), B = 8→9→10→11→12 (platform) + 1 คน float งาน cross-cutting)

## 6. Risk register (top 10)

| # | ความเสี่ยง | Mitigation |
|---|---|---|
| 1 | Webhook cutover ทำ LINE events หาย/ซ้ำ | `webhook_events` idempotency แชร์สอง stack; cutover ต่อบัญชี; shadow ≥99.5% ก่อน flip; fast-ACK+queue |
| 2 | Session bridge พัง (login stack หนึ่ง 401 อีก stack) | bridge สองทิศ HMAC internal-only; PHP session ใน Redis ตรวจได้; synthetic probe ทุก 5 นาที |
| 3 | Tenant pool exhaustion (N tenants × pools > max_connections) | LRU + idle evict; connectionLimit 3–5; alert 70%; load test ก่อน Phase 3 |
| 4 | Flex JSON เพี้ยน → ฉลากยา dispense พัง | golden byte-diff ใน CI; dispense+เลขเอกสาร flip atomic ต่อ tenant; เช็คบนเครื่องจริง |
| 5 | SSE contract เพี้ยน → mini-app AIChat พัง | golden transcripts ใน CI; mini-app contract tests ใน pipeline เดียวกัน |
| 6 | Cron รันซ้ำสองฝั่งช่วง coexist (broadcast/reminder ซ้ำ) | cron-manifest แหล่งเดียว + Redis window lock |
| 7 | MariaDB longtext-JSON + schema drift ทำ Kysely typing พัง | drift audit ก่อน; zod parse helpers; `sql\`\`` escape hatch |
| 8 | Provisioning ใหม่ทำ tenant ใหม่พัง | PHP provisioning (strategy=mysql) ใช้จนถึง Phase 11; E2E provision-to-message |
| 9 | เลขเอกสารชน/ข้ามถ้าสอง stack ออกพร้อมกัน | sequence ใน DB แชร์ + FOR UPDATE ทั้งสองฝั่ง; flip atomic |
| 10 | Phase 0 ทำ prod ล่มก่อนเริ่มมิเกรท | ซ้อมเต็ม 2 รอบ; hostname เดิม (ไม่แตะ LINE console); fallback 30 วัน + binlog replay |

## 7. Verification (gate ก่อน flip traffic ทุกเฟส)

1. **Parity harness:** replay production corpus (scrubbed) ใส่ทั้งสอง stack ใน staging → field-level diff; gate 99.9% (API) / visual-diff approve (pages)
2. **Shadow traffic** (webhook, checkout): nginx `mirror` → Next โหมด side-effect-suppressed; gate 7–14 วันสะอาด
3. **Per-tenant canary:** tenant = subdomain = หน่วย canary ธรรมชาติ; ramp: demo tenant → tenant จริง 1 ราย → 10% → 50% → 100% (ขั้นละ ≥3 วัน; ฟีเจอร์บัญชี/billing ต้องผ่าน month-end 1 รอบ)
4. **Dual-run jobs** (Phase 8/10): Node เขียน `_shadow`/snapshot → row diff = 0
5. **Synthetic monitors** ตั้งแต่ Phase 1: login-bridge, checkout, webhook echo, SSE probe — alert แยกตาม `X-Served-By`
6. **Rollback drill:** ทุกเฟสต้องซ้อม flip กลับจริงบน canary ก่อน ramp

## 8. Non-goals / ของที่ไม่ port (ลบทิ้ง)

- `install/` (27k LOC), `scripts/`, redirect stubs ~20 ไฟล์, debug UTIL ~25 ไฟล์ (`describe_*`, `dump_bdo`, `bulk_sync_*`, `add_col` ฯลฯ), `user/` legacy shell, `liff/` + `liff-app/` (redirect ไป `/miniapp/` คงไว้ที่ nginx), inbox-intelligence v3–v10 JS, `ajax_handler.php`, `index-v2.php`, odoo-dashboard JS variants
- `frontend/` ลบหลัง harvest UI kit; `backend/` Fastify ลบหลัง port auth/slip/audit; websocket 3 → 1; retail-api + api/retail-* → shop API เดียว; deploy scripts ของ shared hosting retire ที่ Phase 0
- **ไม่ทำระหว่างมิเกรท:** ย้าย S3, redesign/rename schema, อัพเกรด MySQL 8 (คง MariaDB), ฟีเจอร์ใหม่บนโมดูลที่กำลัง cutover (freeze เฉพาะช่วง soak ของโมดูลนั้น)

## ไฟล์วิกฤตอ้างอิง

- `bootstrap/resolve_subdomain.php` + `bootstrap/route_by_account.php` — semantics ที่ `packages/tenant` ต้อง replicate เป๊ะ
- `modules/Core/Database.php` — per-request PDO factory ที่ pool registry แทน (legacy fallback มี ~700 call sites)
- `webhook.php` — surface เสี่ยงสุด (Phase 6)
- `line-mini-app/src/lib/php-bridge.ts` — ต้องเพิ่ม per-endpoint origin map ก่อน Phase 3
- `includes/header.php` — แหล่ง nav/IA ทั้งหมดของ admin (Phase 1)
- `includes/document-helpers.php` — เลขเอกสาร/VAT/วันที่ไทย (Phase 5)
- `docker-compose.prod.yml` + `docker/nginx/*` — ฐานของ strangler topology
