
# GitHub Copilot Instructions — LINE Telepharmacy CRM Platform

## Project Overview
PHP 8.0+ CRM platform for Thai pharmacies integrating LINE OA, Odoo ERP, AI (Gemini/OpenAI), and e-commerce. All UI text and DB comments are bilingual Thai/English. Timezone is always `Asia/Bangkok` (`+07:00`).

---

## Architecture

### Entry Points
| Path | Purpose |
|------|---------|
| `webhook.php?account={id}` | LINE Messaging API webhook (multi-account) |
| `line-mini-app/` | **Active LINE Mini App** (Next.js 15) — the deployed LIFF experience. Add shop features here. |
| `liff/index.php`, `liff-app/` | **Legacy LIFF** — reference only. Do NOT add features here. |
| `api/*.php` | REST API endpoints |
| Root `*.php` files | Admin panel pages |
| `cron/*.php` | Scheduled background tasks |

### Database Pattern — Always Use Singleton
```php
$db = Database::getInstance()->getConnection(); // returns PDO
```
`classes/Database.php` is a backward-compat wrapper around `modules/Core/Database.php`. Never instantiate PDO directly. DB charset is `utf8mb4_unicode_ci`; MySQL timezone is forced to `+07:00`.

### Multi-Tenant SaaS — read this before writing any query

This platform is **database-per-tenant** (see `docs/adr/0001-database-per-tenant-isolation.md`). Each pharmacy gets
its own MariaDB schema (`zrismpsz_reya_t_NNNN`); a master DB (`zrismpsz_reya_platform`) holds the tenant registry.
Requests are routed by subdomain (`tenant-XXXX.re-ya.com`) via `bootstrap/resolve_subdomain.php`.

What this means for generated code:

- **Isolation is the database boundary, not a column.** Inside a tenant DB, "all rows in this DB" already IS "all rows
  for this tenant" — do NOT add a `WHERE tenant_id = ?` filter to in-tenant queries. It is redundant and misleading.
- **Never instantiate PDO directly.** Use `Database::getInstance()`, or `Database::forTenant($id)` /
  `Database::platform()` when the scope is explicit.
- **CLI and cron must opt out of subdomain resolution** — `define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);` before
  `require_once 'config/database.php'`. Cron loops that iterate tenants call `TenantContext::setCurrentTenantId($id)`.
- **Super-admins get NO implicit tenant.** They must enter one explicitly; this guards against cross-tenant reads.
  See `docs/adr/0006-super-admin-audit.md` §"Session model".
- **Root-domain requests (LINE webhook, LIFF) have no subdomain** — resolve the tenant from `line_account_id` via
  `master.tenant_line_account_routes`, never assume tenant 1.

### Architecture decisions

Decisions cited from source as `ADR-NNN` live in [`docs/adr/`](../docs/adr/README.md) — ADR-001 (database-per-tenant),
ADR-002 (tenant provisioning), ADR-006 (two-realm sessions). When code you are editing carries a comment like
`see ADR-001 "Hosting constraint"`, read that section before changing the behaviour it describes.

### Multi-Account LINE OA
Every feature that belongs to a LINE bot is scoped to a `line_account_id` FK against the `line_accounts` table. Pass `$lineAccountId` to service constructors (e.g., `new BusinessBot($db, $line, $lineAccountId)`). The webhook identifies the account via `?account={id}` query param + signature validation.

### Service Class Pattern
`classes/` holds plain PHP classes (no namespace). `modules/` uses PSR-4 namespaces (`Modules\Core\`, `Modules\AIChat\`). Autoloading is declared in `composer.json`:
```
App\     → app/
Classes\ → classes/
Modules\ → modules/
```
Classes load settings from DB first, fall back to `config/config.php` constants, then hardcoded defaults (see `GeminiAI.php`, `LoyaltyPoints.php`).

---

## Standard Admin Page Template
Every admin page must follow this include order:
```php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/header.php'; // pulls in auth_check.php, database, session
// ... page logic ...
require_once 'includes/footer.php';
```
`includes/auth_check.php` redirects unauthenticated users and populates `$currentUser`. Role helpers: `isSuperAdmin()`, `isAdmin()`, `isStaff()`.

### Role Hierarchy
`super_admin` → `admin` → `pharmacist` / `marketing` / `tech` → `staff`  
DB roles map to menu roles in `includes/header.php::getCurrentUserRole()` (e.g., `super_admin` → `owner`).

---

## Key Integrations
- **LINE API** — `classes/LineAPI.php`. Multi-account; always pass token + secret from `line_accounts` row, not from constants.
- **Odoo ERP** — `classes/OdooAPIClient.php` (JSON-RPC 2.0). Error messages are Thai strings keyed by `ERROR_MESSAGES` constants. Sync flow: Odoo webhook → `api/odoo-webhook.php` → `OdooSyncService` → `odoo_orders` / `odoo_invoices` / `odoo_bdos` tables.
- **AI** — `classes/GeminiAI.php` (primary), `classes/OpenAI.php`. Settings stored in `ai_settings` table per `line_account_id`.
- **Notifications** — `classes/NotificationRouter.php` fans out to LINE, Telegram, email.

---

## Developer Workflows

### Running Tests
```bash
composer test                         # PHPUnit (all tests under tests/)
./vendor/bin/phpunit tests/LandingPage/ShopDataDisplayPropertyTest.php  # single file
```
Tests use property-based data providers generating 100+ random cases per property.

### Static Analysis & Linting
```bash
composer analyse   # PHPStan level 0 on classes/ and app/
composer lint      # php-cs-fixer dry-run
composer lint:fix  # apply fixes
```

### Deployment (SSH to server)
```bash
# Force deploy (discards local server changes)
bash force_deploy_testry.sh

# Stash local changes, deploy, restore
bash deploy_testry_branch.sh
```
Server path: `/home/zrismpsz/public_html/cny.re-ya.com`

---

## Conventions & Gotchas
- **`file_exists()` guards** — Optional classes (e.g., `BusinessBot`, `WebSocketNotifier`) are `require_once`-d only after a `file_exists()` check in `webhook.php`. Do the same for new optional integrations.
- **AI settings from DB** — Never hardcode AI model names. They are stored in `ai_settings.model` (default `gemini-2.0-flash`).
- **Cache buster** — LIFF SPA uses a `$v` version string (e.g., `202602142228`). Update it on any JS/CSS change in `liff/index.php`.
- **Odoo sync tables** — Query `odoo_orders`, `odoo_invoices`, `odoo_bdos` for fast reads; never hit Odoo API directly for dashboard queries.
- **`dev_logs` table** — Fatal errors in `webhook.php` are written here. Use `INSERT INTO dev_logs (log_type, source, message, data, created_at)` for debug logging in webhook context.
- **Clean URLs** — `.htaccess` strips `.php` extensions. Use `cleanUrl()` helper from `includes/header.php` when building admin nav links.
- **Cron** — Reminder/broadcast cron jobs live in `cron/`. Register new jobs as separate files; don't add to `scheduled.php` (admin-triggered only).

