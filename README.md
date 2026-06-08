# 🏥 Reya — LINE Telepharmacy CRM & E-commerce Platform

ระบบจัดการร้านขายยาและ LINE Official Account แบบครบวงจร สำหรับร้านขายยาในประเทศไทย
รองรับหลายร้าน (multi-tenant SaaS) เชื่อมต่อ LINE OA, Odoo ERP, AI ปรึกษาเภสัชกร และ Telepharmacy

![Version](https://img.shields.io/badge/version-Wave--3-green)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-blue)
![MySQL](https://img.shields.io/badge/MySQL-%3E%3D5.7-orange)
![Architecture](https://img.shields.io/badge/architecture-database--per--tenant-blueviolet)
![License](https://img.shields.io/badge/license-MIT-purple)

> ภาษา / Language: UI และ DB comments ทั้งหมดเป็นสองภาษา ไทย/อังกฤษ — Timezone คงที่ที่ `Asia/Bangkok` (`+07:00`)

---

## 🧭 Overview

Reya เป็นแพลตฟอร์ม **PHP 8.0+ multi-tenant SaaS** สำหรับร้านขายยา โดยใช้สถาปัตยกรรม
**database-per-tenant** (ADR-001) ร่วมกับ **subdomain routing** (`tenant-XXXX.re-ya.com`)

- **Master DB** (`zrismpsz_reya_platform`) เก็บ tenant registry, platform users, beta signups และ LINE-account routing
- **Tenant DB** (`reya_tenant_*`) หนึ่งฐานข้อมูลต่อหนึ่งร้าน สร้างจาก template migration

PHP monolith ยังคงเป็น source of truth สำหรับ LINE events, คำสั่งซื้อ และทุกฟีเจอร์ที่ผูกกับ
`line_account_id` ส่วน Node.js apps (backend/frontend/mini-app) เป็นเลเยอร์ที่ทำให้ทันสมัยขึ้น

---

## ✨ Features

### 💬 CRM & Communication
- Multi-account LINE OA management (ทุกฟีเจอร์ scope ด้วย `line_account_id`)
- Real-time inbox (`inbox-v2.php`) — CRM HUD panel, dispense modal, cursor-paginated conversations
- Broadcast & scheduled messages, auto-reply rules, drip campaigns
- Rich Menu management และ deep links เข้าสู่ Mini App
- Real-time updates ผ่าน Node.js + Socket.io WebSocket server

### 🛒 E-commerce & Inventory
- **LINE Mini App** (`line-mini-app/`, Next.js 15) — storefront `/shop`, ค้นหา/กรองสินค้า, cart, checkout
- Consolidated product/inventory hub (`inventory/`) — storefront, locations, drug-groups,
  generic-names, label-templates, drug-interactions, master catalog + CSV import
- Order management, payment verification (โอน/ปลายทาง), inventory tracking
- VAT documents (`documents.php`) — ใบเสร็จ/ใบกำกับภาษี/ใบเสนอราคา ภาษาไทย พร้อม PDF

### 🤖 AI Pharmacist (AIChat Pipeline)
- SSE-streamed Gemini chat persona เภสัชกร (`modules/AIChat/`, `api/ai-chat*.php`)
- Pipeline: TriageRouter → ContextAnalyzer/SymptomMapper → KnowledgeRetriever/MIMS/RAG → PromptBuilder → Gemini
- Safety: RedFlagDetector + DrugInteractionChecker (structured SSE event สำหรับ UI การ์ดฉุกเฉิน)
- Vision (อัปโหลดรูป), auto chief-complaint summary, escalation → order, แจ้งเตือนเภสัชกร on-call

### 🏥 Telepharmacy & Loyalty
- Pharmacist profiles, consultation notes, prescription/dispense flow (ระบบจ่ายยา)
- Flex medicine label (`FlexTemplates::medicineLabel()`) — auto carousel เมื่อมีหลายรายการ
- Loyalty: points, tier membership, rewards, birthday rewards, medication reminders

### 📊 Analytics & Modern Dashboard
- Customer analytics, sales reports, campaign performance
- Modern admin dashboard (`frontend/`, Next.js 16 + TanStack Query) เชื่อม REST API (`backend/`, Fastify + Prisma)
- Odoo ERP integration (JSON-RPC, circuit breaker) — อ่าน dashboard จาก cache tables เสมอ

---

## 🏗️ Architecture

### Application Layers

| Layer | Location | Stack |
|-------|----------|-------|
| PHP monolith (source of truth) | root, `api/`, `classes/`, `modules/`, `app/` | PHP 8.0+, PDO/MySQL |
| Modern REST API | `backend/` | TypeScript + Fastify 5 + Prisma 5 (MySQL) |
| Admin dashboard | `frontend/` | Next.js 16 + React 18 + TanStack Query |
| **LINE Mini App (active LIFF)** | `line-mini-app/` | Next.js 15 + React 19 + TanStack Query |
| Real-time inbox | `websocket-server.js` | Node.js + Socket.io + Redis |
| Retail API | `retail-api/` | Separate routing + sync logic |

> **LINE in-app UI:** LIFF ที่ deploy จริงคือ `line-mini-app/` — โฟลเดอร์ `liff/` และ `liff-app/`
> เก็บไว้เป็น reference/compat เท่านั้น **อย่าเพิ่มฟีเจอร์ร้านค้าใหม่ในนั้น**

### Multi-Tenant SaaS (Wave 3, ADR-001)
- **Resolution:** `bootstrap/resolve_subdomain.php` แปลง HTTP_HOST → subdomain → `master.tenants.slug`
  → ตั้ง `TenantContext` + `$_SESSION['active_tenant_id']` ทุก request
- **TenantContext** (`classes/TenantContext.php`): explicit → session → platform user → legacy → null.
  Super-admin ต้องเรียก `setCurrentTenantId()` หรือ `enterPlatformContext()` เอง (กัน cross-tenant read)
- **Provisioning:** `classes/TenantProvisioning.php` (สร้าง DB), `classes/TenantFileStorage.php` (อัปโหลดแยกร้าน)
- **CLI/cron:** ต้อง `define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);` ก่อน `require config/database.php`
- **Fail-safe:** error ใด ๆ จะ log แล้ว fall through ไป legacy `DB_NAME` connection

### Database — Always Use the Singleton
```php
$db = Database::getInstance()->getConnection(); // returns PDO (utf8mb4, +07:00)
```
อย่าสร้าง PDO เอง — `classes/Database.php` เป็น wrapper ของ `modules/Core/Database.php`
สคีมามี **223 ตาราง** main migration: `database/install_complete_latest.sql`;
incremental ใน `database/migration_*.sql`

---

## 📋 Requirements

- **PHP** >= 8.0 (PDO, PDO_MySQL, cURL, JSON, mbstring, OpenSSL)
- **MySQL** >= 5.7 หรือ MariaDB >= 10.2
- **Node.js** >= 18 (สำหรับ backend / frontend / line-mini-app / websocket)
- **Composer** และ **HTTPS** (จำเป็นสำหรับ LINE Webhook)
- Optional: **Docker** + **Redis** + **Nginx** สำหรับ stack แบบ container

---

## 🚀 Quick Start

### PHP platform
```bash
# 1. ติดตั้ง dependencies
composer install

# 2. สร้าง master DB + tenant DB (ดู database/install_complete_latest.sql และ tenant template)
mysql -u root -p < database/install_complete_latest.sql

# 3. ตั้งค่า config
cp config/config.example.php config/config.php   # แก้ค่า DB / LINE / AI

# 4. รันทดสอบ + static analysis
composer test       # PHPUnit (property-based)
composer analyse    # PHPStan level 0
composer lint       # PSR-12 (dry-run); composer lint:fix เพื่อแก้
```

### Node.js services
```bash
# Modern backend API (Fastify + Prisma)
cd backend && npm install && npm run dev      # npm test = Vitest

# Admin dashboard (Next.js 16)
cd frontend && npm install && npm run dev     # npm test = Jest

# LINE Mini App (Next.js 15) — active LIFF client
cd line-mini-app && npm install && npm run dev

# WebSocket server (real-time inbox)
npm install && npm run dev
```

### Docker
```bash
make dev-start      # nginx, backend, frontend, mysql, redis
make db-migrate     # Prisma migrations ภายใน backend container
make db-studio      # Prisma Studio
make dev-logs
make prod-deploy    # production blue-green
```

Health checks: `:8080/health` (nginx) · `:4000/health` (backend) · `:3001/health` (websocket)

---

## ⚙️ Configuration

### LINE Messaging API
1. สร้าง Messaging API channel ที่ [LINE Developers Console](https://developers.line.biz/console/)
2. ใส่ **Channel Secret** และ **Channel Access Token** ลงใน DB row ของ `line_accounts`
   (อย่า hardcode — service ต่าง ๆ อ่าน token จาก row ของ account นั้น)
3. ตั้ง **Webhook URL:** `https://<tenant>.re-ya.com/webhook.php?account={id}`
   — webhook ระบุ account จาก `?account={id}` + ตรวจ HMAC-SHA256 signature
4. Enable **Use webhook**, disable **Auto-reply**
5. Cross-tenant routing จัดการที่ `master.tenant_line_account_routes`

### LIFF / Mini App
- ตั้ง LIFF endpoint ให้ชี้มาที่ `line-mini-app/` (Next.js 15) — ดู `NEXT_PUBLIC_*` env ต่อ environment
- เมื่อแก้ assets ของ mini-app ให้ bump build/version (cache buster)

### AI (Optional)
- ตั้งค่าใน Admin > AI Settings — เก็บต่อ `line_account_id` ในตาราง `ai_settings`
- อย่า hardcode ชื่อโมเดล (default `gemini-2.0-flash`); keys: Gemini (Google AI Studio) / OpenAI

---

## 📁 Directory Structure

```
├── api/              # ~120 REST API endpoints (PHP)
├── classes/          # ~118 service classes (legacy, no namespace)
├── modules/          # PSR-4 (Modules\Core, Modules\AIChat, Modules\Onboarding)
├── app/              # App\ namespace (Controllers, Models, Services, Views)
├── bootstrap/        # resolve_subdomain.php (tenant resolution)
├── config/           # config + database bootstrap (+ legacy fallback)
├── cron/             # ~31 scheduled background tasks
├── database/         # SQL: install_complete_latest.sql + migration_*.sql (223 tables)
├── includes/         # shared includes (header/footer/auth, document-helpers)
├── inventory/        # consolidated product/inventory hub
├── admin/            # platform super-admin (login, switch-tenant, beta-signups)
├── line-mini-app/    # ★ ACTIVE LIFF — Next.js 15 shop/cart/checkout
├── liff-app/         # legacy React+Vite LIFF (reference only)
├── liff/             # oldest legacy LIFF bundle (reference only)
├── backend/          # modern REST API (Fastify + Prisma + TypeScript)
├── frontend/         # admin dashboard (Next.js 16)
├── retail-api/       # separate retail API
├── docker/           # nginx configs + deploy scripts
├── docs/             # documentation (Thai/English)
├── tests/            # PHPUnit property-based tests
├── webhook.php       # LINE webhook (multi-account)
├── inbox-v2.php      # active admin inbox
├── documents.php     # VAT documents (Thai receipts/invoices/quotations)
├── websocket-server.js
└── index.php         # public landing page
```

---

## 📱 User Roles

Role hierarchy: `super_admin` → `admin` → `pharmacist` / `marketing` / `tech` → `staff`

| Role | Access |
|------|--------|
| **Super Admin** | Full system + platform context (เข้าได้ทุก tenant แบบ explicit) |
| **Admin** | ทุกฟีเจอร์ในร้าน ยกเว้น system settings |
| **Pharmacist** | Consultations, prescriptions, dispense |
| **Marketing / Tech** | Campaigns / integrations ตามสิทธิ์ |
| **Staff** | Chat, orders |

Helpers หลัง `includes/header.php`: `isSuperAdmin()`, `isAdmin()`, `isStaff()`

---

## 🔌 Key Integrations

| Integration | Entry point |
|-------------|-------------|
| LINE Messaging API | `classes/LineAPI.php` (token/secret จาก `line_accounts` row) |
| Flex medicine label | `classes/FlexTemplates.php` |
| Odoo ERP | `classes/OdooAPIClient.php` → `api/odoo-webhook.php` → cache tables |
| AI consultation | `modules/AIChat/`, `api/ai-chat*.php` (Gemini SSE) |
| VAT documents | `api/documents.php` + `includes/document-helpers.php` |
| Notifications | `classes/NotificationRouter.php` (LINE / Telegram / email) |
| Real-time | `websocket-server.js` (Socket.io + Redis) |

> **Odoo kill-switch:** UI ของ Odoo ต่อ tenant ถูก gate ด้วยแฟล็ก `ODOO_INTEGRATION_ENABLED`
> (เช็ค `$isOdooMode` ก่อน render) — tenant ที่ไม่ใช้ Odoo จะไม่เห็นวิดเจ็ตที่พัง

---

## 🔧 Cron Jobs

CLI/cron ต้อง `define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);` และวน tenant ด้วย
`TenantContext::setCurrentTenantId($id)` งานใหม่ให้สร้างไฟล์แยกใน `cron/` (อย่าเพิ่มใน `scheduled.php`)

```bash
*/15 * * * * php /path/to/cron/medication_reminder.php
*/30 * * * * php /path/to/cron/appointment_reminder.php
*/5  * * * * php /path/to/cron/process_broadcast_queue.php
```

---

## 🧩 Commit Convention

Conventional Commits: `type(scope): description` — types: `feat`, `fix`, `refactor`,
`docs`, `test`, `chore`, `perf`, `ci` (เช่น `feat(line-mini-app): …`, `fix(checkout): …`)

---

## 🛠️ Troubleshooting

**Webhook ไม่ทำงาน** — ตรวจ URL เป็น HTTPS, Channel Secret ถูกต้อง, signature ผ่าน, สิทธิ์ไฟล์
ของ `webhook.php` (fatal errors บันทึกที่ตาราง `dev_logs`)

**ส่งข้อความไม่ได้** — ตรวจ Channel Access Token ใน `line_accounts` row และ token ยังไม่หมดอายุ

**Tenant ผิด / cross-tenant** — ตรวจ subdomain → `master.tenants.slug` และ `active_tenant_id`
ใน session; super-admin ต้องตั้ง tenant context เอง

**อัปโหลดไฟล์** — ตรวจสิทธิ์ `uploads/` (755) และ `upload_max_filesize` ใน php.ini

---

## 📖 Documentation

- [Architecture](ARCHITECTURE.md) · [Project Flow](PROJECT_FLOW_DOCUMENTATION.md) · [CRM Workflow](CRM_WORKFLOW_COMPLETE.md)
- [User Manual](USER_MANUAL.md) · [Setup Guide](SETUP_GUIDE_COMPLETE.md)
- **Deployment (Thai):** [Quick Deploy](QUICK_DEPLOY_GUIDE.md) · [Production Guide](/docs/DEPLOYMENT_GUIDE_TH.md) · [Docker](DEPLOYMENT_GUIDE.md)
- **API:** [Customer Management](/docs/API_CUSTOMER_MANAGEMENT.md) · [Webhook Management](/docs/WEBHOOK_MANAGEMENT_SYSTEM.md) · [Audit Logging](/docs/AUDIT_LOGGING.md)
- **Knowledge graphs:** อ่าน `graphify-out/GRAPH_REPORT.md` ก่อนสำหรับคำถามข้ามโมดูล

> รายละเอียดเชิงลึกของสถาปัตยกรรม, conventions และ gotchas ทั้งหมดอยู่ใน [`CLAUDE.md`](CLAUDE.md)

---

## 📄 License

MIT License — Free for personal and commercial use.

---

## 🤝 Support

หากพบปัญหาหรือมีคำขอฟีเจอร์ กรุณาเปิด Issue ในรีโพ

---

Made with ❤️ for Thai pharmacies · LINE Telepharmacy CRM & E-commerce
