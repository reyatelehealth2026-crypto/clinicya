# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP 8.0+ multi-tenant CRM/e-commerce platform for Thai pharmacies integrating LINE Official Accounts, Odoo ERP, AI (Gemini/OpenAI), and telepharmacy. All UI text and DB comments are bilingual Thai/English. Timezone is always `Asia/Bangkok` (`+07:00`).

## Commands

```bash
# PHP dependencies
composer install

# Run all tests (PHPUnit, property-based)
composer test

# Run a single test file
./vendor/bin/phpunit tests/LandingPage/ShopDataDisplayPropertyTest.php

# Static analysis (PHPStan level 0)
composer analyse

# Code style check (PSR-12, dry-run)
composer lint

# Apply code style fixes
composer lint:fix

# Node.js WebSocket server (dev)
npm install && npm run dev

# Deploy to production (force — discards server-side changes)
bash force_deploy_testry.sh

# Deploy preserving local changes in stash
bash deploy_testry_branch.sh
```

## Architecture

### Entry Points

| Path | Purpose |
|------|---------|
| `webhook.php?account={id}` | LINE Messaging API webhook (multi-account) |
| `liff/index.php` | LIFF SPA (client-side routing, single entry point) |
| `api/*.php` | 59 REST API endpoints |
| Root `*.php` files | Admin panel pages (104 files) |
| `cron/*.php` | 19 scheduled background tasks |
| `index.php` | Public landing page |

### Database — Always Use Singleton

```php
$db = Database::getInstance()->getConnection(); // returns PDO
```

`classes/Database.php` is a backward-compat wrapper around `modules/Core/Database.php`. Never instantiate PDO directly. Charset is `utf8mb4_unicode_ci`; MySQL timezone forced to `+07:00`.

### Multi-Account LINE OA

Every LINE feature is scoped to a `line_account_id` FK against `line_accounts`. Pass `$lineAccountId` to service constructors (e.g., `new BusinessBot($db, $line, $lineAccountId)`). Webhook identifies account via `?account={id}` + HMAC-SHA256 signature validation.

### Service Class Patterns

- `classes/` — plain PHP classes, no namespace (legacy). Settings loaded from DB first, fall back to `config/config.php`, then hardcoded defaults.
- `modules/` — PSR-4 namespaced (`Modules\Core\`, `Modules\AIChat\`, `Modules\Onboarding\`).
- `app/` — `App\` namespace for Controllers, Models, Services, Views.

Autoloading declared in `composer.json`: `App\` → `app/`, `Classes\` → `classes/`, `Modules\` → `modules/`.

### Standard Admin Page Template

```php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/header.php'; // pulls in auth_check.php, session, $currentUser
// ... page logic ...
require_once 'includes/footer.php';
```

Role helpers available after header: `isSuperAdmin()`, `isAdmin()`, `isStaff()`.
Role hierarchy: `super_admin` → `admin` → `pharmacist` / `marketing` / `tech` → `staff`

### Key Integrations

- **LINE API** — `classes/LineAPI.php`. Always pass token + secret from the `line_accounts` DB row, not from constants.
- **Odoo ERP** — `classes/OdooAPIClient.php` (JSON-RPC 2.0, circuit breaker + exponential backoff). Sync flow: Odoo webhook → `api/odoo-webhook.php` → `OdooSyncService` → cache tables (`odoo_orders`, `odoo_invoices`, `odoo_bdos`). Use `OdooAPIPool.php` for parallel fan-out queries.
- **AI** — `classes/GeminiAI.php` (primary), `classes/OpenAI.php`. Settings per `line_account_id` in `ai_settings` table.
- **Notifications** — `classes/NotificationRouter.php` fans out to LINE, Telegram, email.
- **Real-time** — Node.js + Socket.io WebSocket server (`package.json`).

## Conventions & Gotchas

- **`file_exists()` guards** — Optional classes (`BusinessBot`, `WebSocketNotifier`) are `require_once`-d only after a `file_exists()` check in `webhook.php`. Do the same for new optional integrations.
- **AI settings from DB** — Never hardcode AI model names. Stored in `ai_settings.model` (default `gemini-2.0-flash`).
- **Cache buster** — LIFF SPA uses `$v` version string (e.g., `202602142228`). Update it on any JS/CSS change in `liff/index.php`.
- **Odoo dashboard queries** — Always query cache tables (`odoo_orders`, `odoo_invoices`, `odoo_bdos`); never hit the Odoo API directly for dashboard reads.
- **`dev_logs` table** — Fatal errors in `webhook.php` are written here. For debug logging in webhook context: `INSERT INTO dev_logs (log_type, source, message, data, created_at)`.
- **Clean URLs** — `.htaccess` strips `.php` extensions. Use `cleanUrl()` from `includes/header.php` when building admin nav links.
- **Cron jobs** — New reminder/broadcast jobs go in `cron/` as separate files. Do not add to `scheduled.php` (admin-triggered only).
- **Tests** — Property-based; each test generates 100+ random cases per property. Bootstrap: `tests/bootstrap.php`.
- **Database schema** — 223 tables. Main migration: `database/install_complete_latest.sql`. Incremental changes go in `database/migration_*.sql`.
- **Server path** — `/home/zrismpsz/public_html/cny.re-ya.com` on production.

## Odoo Dashboard (`odoo-dashboard.php`)

### Frontend Architecture — Three-Tier Caching

```
sessionStorage (JS) → fast endpoint (<500ms) → heavy endpoint (cached, 30–60s TTL)
```

| Tier | File | TTL | หมายเหตุ |
|------|------|-----|---------|
| Client cache | `sessionStorage` via `_cacheGet/_cacheSet` | 5 min | instant, ไม่มี network |
| Fast endpoint | `api/odoo-dashboard-fast.php` | — | query เฉพาะ cache tables ที่มี index |
| Heavy endpoint | `api/odoo-dashboard-api.php` | 20–300s ต่อ action | PHP in-memory cache, fallback เต็ม |

### Fast Endpoint Actions (`WH_FAST_ACTIONS` ใน `odoo-dashboard.js`)

Actions ใน Set นี้จะถูก route ไป lightweight endpoint ก่อนเสมอ:
```javascript
const WH_FAST_ACTIONS = new Set([
    'health', 'overview_fast', 'orders_today_fast', 'customers_fast',
    'circuit_breaker_status', 'circuit_breaker_reset'
]);
```
**กฎ:** ถ้า `api/odoo-dashboard-fast.php` รองรับ action ใหม่ → ต้องเพิ่มใน `WH_FAST_ACTIONS` ด้วยเสมอ

### Performance Rules

- **CDN preconnect** — `odoo-dashboard.php` ต้องมี `<link rel="preconnect">` สำหรับ `fonts.googleapis.com`, `fonts.gstatic.com`, `cdn.jsdelivr.net` ก่อน `<link rel="stylesheet">` ทุกครั้ง
- **ห้าม query Odoo API โดยตรงใน dashboard** — ใช้ cache tables (`odoo_orders`, `odoo_invoices`, `odoo_bdos`, `odoo_customer_projection`) เท่านั้น
- **ห้าม query `odoo_webhooks_log` แบบ full scan** — ใช้ aggregate query ที่มี WHERE บน indexed column เท่านั้น
- **polling guard** — `setInterval` ที่เรียก API ต้องตรวจ `document.hidden` ก่อนทุกครั้ง

### JS Files ใน Dashboard

| ไฟล์ | สถานะ | หมายเหตุ |
|------|--------|---------|
| `odoo-dashboard.js` | active (~267KB) | ไฟล์หลัก โหลดโดย PHP |
| `odoo-dashboard-fast.php` | active | lightweight API endpoint |
| `odoo-dashboard-api.php` | active (~182KB) | heavy endpoint, PHP parse ~1.3s ถ้าไม่มี OPcache |
| `odoo-dashboard-optimized.js` | stub | สำรองสำหรับ optimization patches อนาคต |
| `odoo-dashboard-fixes.js` | stub | สำรองสำหรับ bug fix patches อนาคต |

### `whApiCall(data)` — ลำดับการ fallback

1. ถ้า action อยู่ใน `WH_FAST_ACTIONS` → ลอง `odoo-dashboard-fast.php` (timeout 5s)
2. ถ้า fast endpoint ตอบ `fallback: true` หรือ timeout → ต่อไปที่ heavy endpoint
3. Heavy endpoint: ลอง `WH_API_ACTIVE` ก่อน → fallback ไปทุก URL ใน `WH_API_CANDIDATES`
