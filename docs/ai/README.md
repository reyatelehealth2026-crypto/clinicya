# Clinicya Technical Knowledge Base

## Purpose

Clinicya/Re-Ya is a multi-tenant LINE CRM, pharmacy commerce, loyalty, telepharmacy, and operations platform. The primary runtime is a PHP monolith with LINE Messaging API webhook handling, admin pages, PHP JSON/SSE APIs, cron jobs, and a shared LINE Mini App built with Next.js 15. A separate Fastify/Prisma backend exists for modern API and realtime dashboard services, but this documentation excludes the `frontend/` Next.js admin app and excludes Odoo-specific behavior by request.

Confirmed evidence: `webhook.php` handles multi-account LINE events; `api/checkout.php` exposes shop/cart/order actions; `line-mini-app/src/lib/config.ts` resolves tenant/LIFF context at runtime; `backend/src/routes/index.ts` registers Fastify route groups under `API_PREFIX`; `database/schema_complete.sql` defines core tenant tables.

## Main capabilities

- LINE OA webhook processing, signature validation, auto replies, AI replies, menu/shop flows, loyalty commands, receipt flow, and scheduled broadcast triggering: `webhook.php`, `classes/LineAPI.php`, `classes/LineAccountManager.php`, `classes/BusinessBot.php`.
- Admin CRM inbox, message send, customer tags/notes, medical profile edits, image/PDF sends, and dispense flow: `inbox-v2.php`, `classes/InboxService.php`, `classes/FlexTemplates.php`.
- Customer mini app for shop, cart, checkout, orders, member card, points, rewards, appointments, health, AI chat, reminders, wishlist, and video calls: `line-mini-app/src/app/*`, `line-mini-app/src/lib/*.ts`, PHP APIs under `api/`.
- AI pharmacy consultation and triage backed by Gemini settings, conversation state/history, product/RAG search, MIMS-style knowledge, red flag detection, and pharmacist notifications: `api/ai-chat.php`, `modules/AIChat/*`, `api/ai-chat-history.php`, `api/mims-pharmacist.php`.
- Background processing for broadcasts, reminders, webhook retry/statistics, reports, cache warming, notification worker, and maintenance: `cron/*.php`, `worker/notification-worker.php`.

## Architecture Summary

The system is layered around PHP request entry points, shared service classes, MySQL tenant data, LINE APIs, and a statically deployed shared mini app that calls PHP APIs. Modern Fastify services provide JWT-backed API groups and WebSocket dashboard updates using Prisma and Redis. Database access in PHP is transitioning from legacy `config/database.php` behavior to tenant-aware `Modules\Core\Database`; the shim warning in `classes/Database.php` makes load order an explicit risk.

## Navigate This Documentation

- Start with [system-context.md](system-context.md) for users, boundaries, and external systems.
- Read [architecture.md](architecture.md) and [codebase-map.md](codebase-map.md) before modifying code.
- Use [api-contracts.md](api-contracts.md), [database-schema.md](database-schema.md), and [background-jobs.md](background-jobs.md) for implementation work.
- Use [line-webhook-flow.md](line-webhook-flow.md), [broadcast-and-quota.md](broadcast-and-quota.md), and [flex-template-system.md](flex-template-system.md) for LINE Messaging, broadcast, and Flex-message changes.
- Use [user-lifecycle.md](user-lifecycle.md), [data-retention.md](data-retention.md), and [incident-runbook.md](incident-runbook.md) for customer data, lifecycle, operations, and incident response work.
- Use [security-review.md](security-review.md), [performance-review.md](performance-review.md), and [known-risks.md](known-risks.md) for risk planning.

## Unknowns

Runtime production config, real cron schedule, current DB contents, and active deployment topology were not verified live. `codebase-memory-mcp` was installed but its CLI index command did not accept `repo_path` in this Windows session, so the indexed graph evidence used here is `graphify-out/GRAPH_REPORT.md` plus direct source inspection.

## Last Verified From Code

Verified on 2026-07-03 from `graphify-out/GRAPH_REPORT.md`, `webhook.php`, `config/config.php`, `classes/Database.php`, `modules/Core/Database.php`, `classes/LineAPI.php`, `classes/BusinessBot.php`, `classes/FlexTemplates.php`, `line-mini-app/src/lib/config.ts`, `line-mini-app/src/lib/shop-api.ts`, `backend/src/routes/index.ts`, `database/schema_complete.sql`, `database/install_complete_latest.sql`, `cron/`, and `tests/`.
