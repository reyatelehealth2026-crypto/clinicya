# Codebase Map

## Important Directories

- `api/`: PHP JSON/SSE/multipart endpoints. Key files include `checkout.php`, `ai-chat.php`, `member.php`, `rewards.php`, `appointments.php`, `medication-reminders.php`, `resolve-line-account.php`, `miniapp-bootstrap.php`, `inbox-v2.php`.
- `classes/`: legacy PHP service classes without namespaces. Key files: `LineAPI.php`, `LineAccountManager.php`, `BusinessBot.php`, `InboxService.php`, `FlexTemplates.php`, `AdminAuth.php`, `TenantContext.php`, `Database.php`.
- `modules/`: namespaced PHP modules. `modules/Core/Database.php` is the tenant-aware DB factory; `modules/AIChat/*` owns AI chat, triage, product/RAG, MIMS, and pharmacist notification logic.
- `line-mini-app/`: active LIFF/LINE Mini App client. Key files: `src/lib/config.ts`, `src/lib/shop-api.ts`, `src/lib/ai-chat-api.ts`, `src/components/miniapp/*`, `src/app/*`.
- `backend/`: Fastify + Prisma service. Key files: `src/server.ts`, `src/routes/index.ts`, route modules, auth middleware, WebSocket server, Prisma schema.
- `cron/`: scheduled background tasks for reminders, broadcasts, webhook retry/statistics, reports, maintenance, cache warming.
- `database/`: SQL schema and migrations. Key files: `schema_complete.sql`, `install_complete_latest.sql`, `migration_2026-05-25_platform_master.sql`, `migration_2026-05-04_unified_broadcast.sql`.
- `tests/`: PHPUnit property/smoke tests for PHP and Node tests under `backend/src/test` and `line-mini-app/src/lib/__tests__`.

Excluded by request: `frontend/` and Odoo-specific files/routes/jobs.

## Key Files To Read First

1. `graphify-out/GRAPH_REPORT.md`: current repository graph report.
2. `config/config.php`: global constants, timezone, DB, Redis, AI, integration flags.
3. `webhook.php`: LINE event routing and high-volume behavior.
4. `classes/LineAPI.php` and `classes/LineAccountManager.php`: LINE integration contract.
5. `api/checkout.php`: main mini app commerce API.
6. `line-mini-app/src/lib/config.ts`: runtime tenant and LIFF resolution.
7. `includes/auth_check.php`, `classes/AdminAuth.php`, `admin/platform-login.php`: auth and permissions.
8. `modules/Core/Database.php` and `classes/Database.php`: DB connection behavior.
9. `database/schema_complete.sql`: canonical visible schema snapshot.
10. `cron/send_scheduled.php`, `cron/process_broadcast_queue.php`, `cron/webhook_retry_processor.php`: async behavior.

## Bootstrapping Flow

PHP admin pages commonly load config, database, header/auth, then page logic. Evidence: project AGENTS.md describes standard admin template; `includes/auth_check.php` checks session and tenant context; `config/config.php` starts session and sets timezone.

LINE webhook bootstraps by loading config/database, account routing, LINE classes, optional bot/CRM/tag/LIFF/WebSocket classes, reading raw body/signature, resolving a line account, then processing events. Evidence: `webhook.php`.

Mini app bootstraps from Next pages/components, resolves LIFF ID through `api/miniapp-bootstrap.php`, maps LIFF to `line_account_id` through `api/resolve-line-account.php`, then calls PHP APIs through `apiUrl()`.

## Last Verified From Code

Verified on 2026-07-03 from `graphify-out/GRAPH_REPORT.md`, `config/config.php`, `webhook.php`, `classes/*.php`, `modules/Core/Database.php`, `modules/AIChat/*`, `api/*.php`, `line-mini-app/src/lib/*.ts`, `backend/src/server.ts`, `backend/src/routes/index.ts`, `database/*.sql`, `cron/*.php`, `tests/`.
