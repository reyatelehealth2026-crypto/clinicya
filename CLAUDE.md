# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP 8.0+ multi-tenant SaaS CRM/e-commerce platform for Thai pharmacies integrating LINE Official Accounts, Odoo ERP, AI (Gemini/OpenAI), and telepharmacy. Wave-3 architecture is **database-per-tenant** (ADR-001) with **subdomain routing** (`tenant-XXXX.re-ya.com`). A master DB (`zrismpsz_reya_platform`) holds the tenant registry; each tenant has its own `reya_tenant_*` schema. All UI text and DB comments are bilingual Thai/English. Timezone is always `Asia/Bangkok` (`+07:00`).

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

# Modern backend API — Fastify + Prisma + TypeScript
cd backend && npm install && npm run dev   # dev server (tsx watch)
cd backend && npm test                     # Vitest
cd backend && npm run prisma:studio        # Prisma Studio UI

# Admin dashboard — Next.js 16
cd frontend && npm install && npm run dev
cd frontend && npm test                    # Jest
cd frontend && npm run test:coverage       # Jest with coverage

# LINE Mini App — Next.js 15 (active LIFF client)
cd line-mini-app && npm install && npm run dev

# Legacy LIFF app — React + Vite (read-only reference)
cd liff-app && npm install && npm run dev

# Docker — development
make dev-start    # start all containers (nginx, backend, frontend, mysql, redis)
make dev-stop
make dev-logs
make db-migrate   # run Prisma migrations inside backend container
make db-studio    # open Prisma Studio inside backend container

# Docker — production (blue-green)
make prod-deploy
make prod-logs

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
| `line-mini-app/` | **Active LINE Mini App** (Next.js 15) — shop (`/shop`), cart, App Router UI. This is the deployed LIFF experience. |
| `liff-app/` | **Legacy React+Vite LIFF** — read-only reference; do not add features here. |
| `liff/` | **Oldest legacy LIFF bundle** (`liff/index.php`, `liff/assets/js/liff-app.js`). Not used for routine production. |
| `api/*.php` | ~60 REST API endpoints |
| Root `*.php` files | Admin panel pages (104 files) |
| `inbox-v2.php` + `api/inbox-v2.php` | **Active admin inbox** — CRM HUD panel, dispense modal, cursor-paginated conversation list. Same-page POST AJAX (`X-Requested-With` header) co-exists with the cursor API in `api/inbox-v2.php`. |
| `messages.php` | Older parallel inbox UI; AJAX endpoint is `chat.php`. New inbox features should be added to `inbox-v2.php` and ported back only if needed. |
| `inventory/index.php` | **Consolidated product/inventory hub** — storefront, locations, drug-groups, generic-names, label-templates, drug-interactions tabs. `/products.php` is now just a redirect into this. |
| `documents.php` + `api/documents.php` | **VAT documents** — Thai receipts/invoices/quotations. Helpers in `includes/document-helpers.php` (doc numbering, VAT calc, Thai date). |
| `cron/*.php` | ~30 scheduled background tasks |
| `index.php` | Public landing page |
| `admin/platform-login.php`, `admin/switch-tenant.php`, `admin/beta-signups.php` | Platform super-admin entry — login against the master `reya_platform` DB, switch into a tenant scope, review beta signups. |
| `backend/src/server.ts` | Modern Fastify + Prisma API (dashboard modernisation layer) |
| `frontend/src/app/` | Next.js 16 admin dashboard UI (TanStack Query) |
| `websocket-server.js` | Real-time inbox updates — Socket.io + Redis |
| `retail-api/` | Separate retail API with own routing, endpoints, and sync logic |

**LINE in-app UI:** The deployed LIFF experience is **`line-mini-app/`**. The older `liff/` SPA and `liff-app/` remain for reference/compat only — **do not add new shop features there.**

### Database — Always Use Singleton

```php
$db = Database::getInstance()->getConnection(); // returns PDO
```

`classes/Database.php` is a backward-compat wrapper around `modules/Core/Database.php`. Never instantiate PDO directly. Charset is `utf8mb4_unicode_ci`; MySQL timezone forced to `+07:00`. The backend Prisma schema also connects to MySQL (not PostgreSQL).

### Multi-Tenant SaaS (Wave 3, ADR-001)

- **Master DB**: `zrismpsz_reya_platform` — single source of truth for tenants, platform users, beta signups, line-account routing (`master.tenants`, `platform_users`, `tenant_line_account_routes`). Name is in `TenantContext::PLATFORM_DB_NAME`; credentials reuse `DB_HOST/DB_USER/DB_PASS`.
- **Tenant DBs**: `reya_tenant_*` schemas — one per tenant; created from `database/migration_2026-05-25_tenant_template.sql`.
- **Resolution**: `bootstrap/resolve_subdomain.php` runs on every request via `config/database.php`, parses HTTP_HOST → subdomain → looks up `master.tenants.slug` → sets `TenantContext + $_SESSION['active_tenant_id']`. Reserved subdomains (`www`, `api`, `admin`, …) skip resolution. Suspended/terminated tenants get a 503.
- **`TenantContext`** (`classes/TenantContext.php`) resolves order: explicit `setCurrentTenantId()` → session → `platform_users.tenant_id` → legacy `current_bot_id` → null. Super-admins do **not** get an implicit tenant; they must call `setCurrentTenantId()` or `enterPlatformContext()` explicitly — guards against accidental cross-tenant reads.
- **CLI / cron**: `define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);` then `require_once 'config/config.php'` **then** `config/database.php` — that order matters, because `Database::getInstance()` throws *"Legacy fallback requested but DB_NAME constant is not defined"* without the config constants. Skipping resolution lands you on the **legacy fallback DB, not a tenant DB**, so every tenant table reads as empty; pick a tenant with `TenantContext::setCurrentTenantId($id)` (what the cron loops do) or `USE` the schema explicitly. Confirm with `SELECT DATABASE()` before concluding a feature "has no data".
- **Provisioning**: `classes/TenantProvisioning.php` (DB creation), `classes/TenantFileStorage.php` (per-tenant uploads).
- **Fail-safe**: any subdomain-resolution error logs + falls through to the legacy `DB_NAME` connection (`modules/Core/Database.php::legacyFallback()`); emergency rollback is `config/database.legacy.php`.

### Multi-Account LINE OA

Within a tenant, every LINE feature is scoped to a `line_account_id` FK against `line_accounts`. Pass `$lineAccountId` to service constructors (e.g., `new BusinessBot($db, $line, $lineAccountId)`). Webhook identifies account via `?account={id}` + HMAC-SHA256 signature validation. Cross-tenant LINE routing is handled by `master.tenant_line_account_routes` (which tenant DB owns a given LINE channel ID).

### Service Class Patterns

- `classes/` — plain PHP classes, no namespace (legacy). Settings loaded from DB first, fall back to `config/config.php`, then hardcoded defaults.
- `modules/` — PSR-4 namespaced (`Modules\Core\`, `Modules\AIChat\`, `Modules\Onboarding\`).
- `app/` — `App\` namespace for Controllers, Models, Services, Views.

Autoloading declared in `composer.json`: `App\` → `app/`, `Classes\` → `classes/`, `Modules\` → `modules/`.

### Modern Services (added during dashboard modernisation)

| Service | Location | Stack |
|---------|----------|-------|
| REST API | `backend/` | TypeScript + Fastify + Prisma (MySQL) |
| Admin UI | `frontend/` | Next.js 16 + React 18 + TanStack Query |
| LINE Mini App | `line-mini-app/` | Next.js 15 + React 19 + TanStack Query |

These are independent Node.js apps containerised in `docker-compose.dev.yml` / `docker-compose.prod.yml`. The PHP monolith remains the source of truth for LINE events, shop orders, and all `line_account_id`-scoped features.

### Backend Route Structure

Routes in `backend/src/routes/` are: `audit.ts`, `auth.ts`, `customers.ts`, `dashboard.ts`, `health.ts`, `orders.ts`, `payments.ts`, `performance.ts`, `security.ts`. Middleware in `backend/src/middleware/`. Prisma schema at `backend/prisma/schema.prisma`.

### Docker — Blue-Green Deployment

- `docker-compose.blue.yml` / `docker-compose.green.yml` — blue-green production configs
- `docker-compose.dev.yml` — development environment
- `docker-compose.prod.yml` — production compose
- `docker/scripts/` — shell scripts for deploy, start, stop
- `docker/nginx/` — nginx configs
- Health check endpoints: `:8080/health` (nginx), `:4000/health` (backend), `:3001/health` (websocket)

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

- **LINE API** — `classes/LineAPI.php`. Always pass token + secret from the `line_accounts` DB row, not from constants. For pharmacist-side outgoing messages, prefer `LineAPI::sendMessage()` (checks `reply_token` first to avoid push-message quota) and fall back to `pushMessage()` only when `sendMessage` is unavailable.
- **Flex medicine label** — `classes/FlexTemplates.php` exposes `medicineLabel()`, `medicineLabelsCarousel()`, `toMessage()`. Used by the dispense flow in `inbox-v2.php` and `messages.php`. Carousel is automatically used when `count($items) > 1`.
- **Odoo ERP** — `classes/OdooAPIClient.php` (JSON-RPC 2.0, circuit breaker + exponential backoff). Sync flow: Odoo webhook → `api/odoo-webhook.php` → `OdooSyncService` → cache tables (`odoo_orders`, `odoo_invoices`, `odoo_bdos`). Use `OdooAPIPool.php` for parallel fan-out queries.
- **AI** — `classes/GeminiAI.php` (primary), `classes/OpenAI.php`. Settings per `line_account_id` in `ai_settings` table.
- **AIChat consultation pipeline** (`modules/AIChat/`, `api/ai-chat*.php`) — PSR-4 `Modules\AIChat\…`. SSE-streamed Gemini chat for the mini-app's pharmacist persona. Pipeline: `TriageRouter` → `TriageQuestionEngine` / `TriageSessionManager` → `ContextAnalyzer` + `SymptomMapper` → `KnowledgeRetriever` / `MIMSKnowledgeBase` / `PharmacyRAG` → `PromptBuilder` → `GeminiAPI`. Safety: `RedFlagDetector` + `DrugInteractionChecker` emit structured SSE events (`{structured: {…}}`) for emergency / drug-interaction UI cards. Escalation: `PharmacistNotifier` pushes urgent LINE text messages to on-call pharmacists. Default persona is `consult` (customer-facing); `admin`/`b2b` requires explicit `mode` param or admin-page referer. Sibling endpoints: `api/ai-chat-vision.php` (Gemini Vision image upload), `api/ai-chat-summary.php` (auto chief-complaint summary), `api/ai-chat-history.php` (persistence), `api/ai-chat-approve-order.php` (escalate → order). Persistence + rate-limits in `ai_chat_*` + `ai_rate_limits` tables.
- **Documents (VAT)** — `api/documents.php` + `documents.php` + `includes/document-helpers.php`. Generates Thai receipts/invoices/quotations with shop tax info from `shop_settings`, PDF render, optional revenue-department register. Doc numbering, VAT calc, and Thai-date formatting all live in the helper file — reuse it, don't re-implement.
- **Notifications** — `classes/NotificationRouter.php` fans out to LINE, Telegram, email.
- **Real-time** — Node.js + Socket.io WebSocket server (`websocket-server.js`).

### Conversation List Pagination (inbox-v2)

`InboxService::getConversationsDelta($lineAccountId, $since, $cursor, $limit, $search, $filters)` returns `['conversations' => [], 'next_cursor' => string|null, 'has_more' => bool]`. Cursor is the last conversation's `last_message_at` (sorted DESC). The page initially server-renders `$conversationLimit = 200` rows then a `ConversationLoader` IIFE auto-loads more in batches via `GET api/inbox-v2.php?action=getConversations&cursor=...&limit=200`. The API caps `limit` at 500 and defaults to 200. Do not lower these without checking that customer-visibility complaints have been resolved.

### Dispense System (ระบบจ่ายยา)

Cross-cutting flow shared by `messages.php` and `inbox-v2.php`. Action handler key: `action=dispense` (POST). Writes `dispensing_records` (auto-created if missing), then either decrements `business_items.stock` (cash/credit) or seeds `cart` + creates a pending `transactions` row referencing `dispense_id` via `delivery_info` JSON (transfer/later). Always sends a Flex medicine-label message via `FlexTemplates` and persists it to `messages` with `sent_by='system:dispense'`.

### Receipt Points (สะสมแต้มจากใบเสร็จ)

Customer photographs a shop receipt in LINE and earns loyalty points. Lives almost entirely in `webhook.php`:

1. `triggerReceiptFlow()` puts the user in the **`waiting_receipt`** state and sends the camera/album quick reply. Entered by the keywords `ส่งใบเสร็จ` / `ใบเสร็จ` / `เพิ่มแต้ม` / `สลิปรับแต้ม`, or the `send_receipt` postback on the member card.
2. An inbound image reaches `handleReceiptPointsClaim()` **only while that state is live** — a photo sent unprompted never touches OCR. Gated per account by `line_accounts.receipt_points_enabled`.
3. `GeminiAI::analyzeReceiptImage()` returns `total_amount` plus a `confidence` verdict. **Points are auto-awarded only on `high`**, which requires arithmetic proof — cash − change, exact cash, or line items summing to the total (`assessReceiptConfidence()`). Anything else is deliberately routed to manual review rather than trusted.
4. Failures and unproven reads go to `recordPendingReceiptPointClaim()`, which writes `receipt_point_claims` (with `fail_reason`: `no_ocr_result`, `not_recognized_as_receipt`, `zero_amount`, `low_confidence`, `ocr_exception`), replies "we'll review it", and drops an admin card via `persistReceiptConversationCard()`. Pharmacists clear the queue in `receipt-points-review.php` / `ReceiptPointsAdmin`.
5. Success sends the `FlexTemplates::pointsReceipt()` card. Images are stored under `uploads/receipt-claims/YYYY/MM/<sha256>.jpg`; `claim_key` + `image_hash` dedupe re-submissions.

`analyzeReceiptImage()` tries Gemini vision models, then falls back to OCR.Space text OCR + `parseReceiptOcrText()`. Both loops keep escalating until a read is *arithmetically proven* — do not "optimise" either one back into returning the first non-zero total, which is how a slip whose true total was 615 was once recorded as 25. See ADR-007 (`docs/adr/0007-receipt-points-review.md`).

## Commit Convention

Conventional Commits format: `type(scope): description`

Common types: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`

Examples from this repo: `fix(checkout): …`, `feat(line-mini-app): …`, `feat(storefront): …`

## Conventions & Gotchas

- **`file_exists()` guards** — Optional classes (`BusinessBot`, `WebSocketNotifier`) are `require_once`-d only after a `file_exists()` check in `webhook.php`. Do the same for new optional integrations.
- **AI settings from DB** — Never hardcode AI model names. Stored in `ai_settings.model` (default `gemini-2.0-flash`).
- **Cache buster** — Bump build/version in `line-mini-app` when changing its assets. Legacy: `liff/index.php` has `$v` for the old SPA.
- **Odoo dashboard queries** — Always query cache tables (`odoo_orders`, `odoo_invoices`, `odoo_bdos`); never hit the Odoo API directly for dashboard reads.
- **`dev_logs` table** — Fatal errors in `webhook.php` are written here. For debug logging in webhook context: `INSERT INTO dev_logs (log_type, source, message, data, created_at)`.
- **Clean URLs** — `.htaccess` strips `.php` extensions. Use `cleanUrl()` from `includes/header.php` when building admin nav links.
- **Cron jobs** — New reminder/broadcast jobs go in `cron/` as separate files. Do not add to `scheduled.php` (admin-triggered only).
- **Tests** — Property-based; each test generates 100+ random cases per property. Bootstrap: `tests/bootstrap.php`.
- **Database schema** — 223 tables. Main migration: `database/install_complete_latest.sql`. Incremental changes go in `database/migration_*.sql`.
- **Migration whitelist** — `.gitignore` ignores `database/*.sql` by default and re-includes specific migrations with `!database/migration_*.sql` lines. When adding a new incremental migration, append a matching `!` line so the file is actually committed.
- **Odoo kill-switch** — Per-tenant Odoo UI is gated by the `ODOO_INTEGRATION_ENABLED` flag (config + DB). Pages check `$isOdooMode` (set in `config/config.php`) before rendering Odoo widgets, dashboard tiles, inventory sync UI, etc. New Odoo-touching UI must respect this gate so non-Odoo tenants don't see broken integrations.
- **Same-page admin AJAX** — Many admin pages (`inbox-v2.php`, `messages.php`, `chat.php`) handle their own POST AJAX at the top of the file, gated on `$_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])`. Add new actions as a `case` inside the existing `switch ($action)` block; do not split into a separate API file unless the endpoint is shared across pages.
- **Never bulk-edit source with byte-wise tools** — `sed`/`perl` without a UTF-8 layer read these files as latin1 and write them back as UTF-8, double-encoding every Thai literal they touch. A bulk emoji-strip did exactly that to `classes/FlexTemplates.php` and shipped `à¹\x81à¸\x95…` where customers expected `แต้ม`. Use PHP `preg_*` with the `/u` flag, or an editor that is encoding-aware. The guard test is `tests/PatientSelfService/ClinicalFlexPropertyTest.php::testSourceHasNoDoubleEncodedThai`.
- **Auto-create tables** — Some legacy admin pages auto-create their feature tables on page load via `SHOW TABLES LIKE` + `CREATE TABLE IF NOT EXISTS` (e.g. `dispensing_records`, `user_notes`, `user_tag_assignments`). For new features, prefer a versioned `database/migration_*.sql` file plus a whitelist entry, not page-load auto-create.
- **Server path** — `/home/zrismpsz/public_html` on production (the `re-ya.com` site sits at the root of `public_html`; there is no `cny.re-ya.com/` or `clinicya.re-ya.com/` subdirectory). SSH: `ssh -i ~/.ssh/id_ed25519_cny -p 9922 zrismpsz@118.27.146.16`.
- **`/products.php` is a redirect** — the real consolidated product/inventory UI is `/inventory/` (tabs: storefront, locations, drug-groups, generic-names, label-templates, drug-interactions). Don't add new product UI to `products.php`.

## Knowledge Graphs

Three graph snapshots coexist; pick the one matching the task:

- **`graphify-out/`** — full god-node / community report. Read `graphify-out/GRAPH_REPORT.md` first for cross-module / "how does X relate to Y" questions. Use `graphify query "…"`, `graphify path "A" "B"`, `graphify explain "concept"` over grep when traversing relationships. Run `graphify update .` after non-trivial code changes (AST-only, no API cost).
- **`.understand-anything/knowledge-graph.json`** — JSON graph powering the `/understand-anything:understand-dashboard` Vite dashboard. Launch with that slash command; the dashboard prints a tokenised URL (`http://127.0.0.1:PORT/?token=…`) — share the full URL or the token gate blocks access.
- **`.codegraph/codegraph.db`** — SQLite graph used by codegraph CLI; ad-hoc.

## Agent skills

### Issue tracker

Issues are tracked as GitHub issues on `reyatelehealth2026-crypto/clinicya` (via the `gh` CLI). See `docs/agents/issue-tracker.md`.

### Triage labels

Canonical five-state vocabulary (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context — one `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.

**ADR register: [`docs/adr/README.md`](docs/adr/README.md).** Decisions cited from source as `ADR-NNN` (and `ADR-NNN §"Section"`) live there. 0001 database-per-tenant · 0002 provisioning/entitlement · 0003 branch model · 0004 cron execution · 0005 file storage · 0006 super-admin audit · 0007 receipt-points review · 0008 single point balance. Read the README before citing one: it records which files are original versus reconstructed-from-code (0001 is still a reconstruction), and **0007 is used twice** — `0007-receipt-points-review.md` and `0007-two-realm-session-implementation.md`, the latter documenting where the session code drifted from ADR-006. Do not renumber existing ADRs or rename a section that source code references by name. `docs/ai/adrs/` is a separate AI-inferred set that no code cites — not canonical.
