# Coverage Report

## Scope

Included: PHP monolith, PHP APIs, LINE Mini App, Fastify backend route/service surface, database schema/migrations, cron/worker jobs, auth, integrations, tests, and graphify report.

Excluded by request: Odoo-specific files/routes/jobs/services and `frontend/` Next.js admin app.

## Evidence Sources Inspected

- Graph: `graphify-out/GRAPH_REPORT.md`.
- Core PHP: `config/config.php`, `includes/auth_check.php`, `webhook.php`, `inbox-v2.php`, `classes/Database.php`, `modules/Core/Database.php`, key `classes/*`, key `modules/AIChat/*`.
- PHP APIs: `api/checkout.php`, `api/member.php`, `api/rewards.php`, `api/points-history.php`, `api/points-claim.php`, `api/appointments.php`, `api/medication-reminders.php`, `api/video-call.php`, `api/ai-chat.php`, `api/ai-chat-history.php`, and route inventory under `api/`.
- LINE/broadcast/Flex deep dives: `classes/LineAPI.php`, `classes/LineAccountManager.php`, `classes/BusinessBot.php`, `classes/FlexTemplates.php`, `api/broadcast.php`, `api/process_scheduled_broadcasts.php`, `api/ai-studio-flex.php`, `api/liff-bridge.php`, `auto-reply.php`, `includes/settings/welcome.php`, broadcast migrations, and selected reminder/broadcast cron files.
- Mini app: `line-mini-app/package.json`, `line-mini-app/src/app/*`, `line-mini-app/src/lib/*.ts`, `line-mini-app/src/components/miniapp/*`.
- Backend: `backend/src/server.ts`, `backend/src/config/config.ts`, `backend/src/routes/*.ts`, `backend/src/services/*`, `backend/src/websocket/server.ts`, tests inventory.
- Database: `database/schema_complete.sql`, `database/install_complete_latest.sql`, selected migrations.
- Jobs: `cron/*.php`, `worker/notification-worker.php`.
- Tests: `tests/**`, `line-mini-app/src/lib/__tests__`, `backend/src/test/**`.

## Approximate Coverage

Inventory command counted 1,837 relevant files after excluding `frontend/`, `vendor`, `node_modules`, `graphify-out`, `archive`, and build outputs. This KB directly inspected or inventoried the main architectural surfaces and high-risk files, but it did not line-read every relevant file.

Approximate documented coverage:

- Architectural surface coverage: 75%.
- Critical entry point coverage: 90%.
- LINE webhook/broadcast/Flex operational coverage: 80%.
- API endpoint detail coverage: 40%.
- Database table coverage from schema files: 70%.
- Cron/worker coverage: 65%.
- Security/performance finding coverage: evidence-based but not exhaustive.
- Overall repo documentation coverage estimate: 60% of relevant non-excluded source/docs by architectural importance, not file count.

## Excluded Folders and Reasons

- `frontend/`: excluded by user request.
- Odoo-specific files such as `api/odoo-*`, `api/webhook/odoo.php`, `cron/sync_odoo_dashboard_cache.php`, `classes/Odoo*`, `backend/src/services/OdooService.ts`: excluded by user request.
- `vendor/`, `node_modules/`, `.next/`, `dist/`: generated/dependency/build output.
- `archive/`: historical material, excluded from primary current-system coverage.

## Tooling Notes

`codebase-memory-mcp` was installed, but `codebase-memory-mcp cli index_repository` repeatedly returned `repo_path is required` despite multiple PowerShell/cmd quoting forms. This KB therefore used existing `graphify-out/GRAPH_REPORT.md` plus direct source inspection. This is a tooling gap, not a claim that the repository lacks graph data.

## Gaps For Next Pass

- Exhaustive endpoint catalog for every non-Odoo `api/*.php`.
- Line-by-line security review of all mutating PHP endpoints.
- Query plan review against a real database.
- Runtime verification of cron schedule, deployment config, and live secrets handling.
- Formal customer-data retention matrix confirmed by product/legal owners.
- Full ADR expansion after confirming product and architectural decisions with maintainers.

## Last Verified From Code

Verified on 2026-07-03 from repo inventory commands, `graphify-out/GRAPH_REPORT.md`, `config/`, `classes/`, `modules/`, `api/`, `line-mini-app/`, `backend/src/`, `database/`, `cron/`, `worker/`, `tests/`, and the LINE/broadcast/Flex/user-lifecycle evidence listed above.
