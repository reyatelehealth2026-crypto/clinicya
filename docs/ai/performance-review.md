# Performance Review

## Confirmed Slow or Heavy Paths

## Large Procedural Request Handlers

`webhook.php`, `inbox-v2.php`, and `api/checkout.php` are large files with many inline SQL calls and branch-heavy flows. They are high-risk for latency spikes because they combine routing, DB, external HTTP, rendering/API response, and side effects.

## Conversation Loading

`classes/InboxService.php` provides pagination/cursor methods such as `getConversationsDelta`, `getMessagesCursor`, `pollUpdates`, and search. `inbox-v2.php` initially renders conversations and later uses AJAX/cursor loading. Risk is high if indexes on `messages.user_id`, `messages.created_at`, and `users.line_account_id` drift from query patterns.

## Product Catalog and Checkout

`api/checkout.php` product/cart/order handlers query product/category/cart/order tables and can include product enrichments, units, favorites, catalog filters, and image/slip upload paths. `line-mini-app/src/lib/shop-api.ts` calls `products`, `product_detail`, `cart`, `create_order`, `get_order`, and upload actions frequently.

## Background Aggregations

`cron/webhook_statistics_calculator.php`, `cron/scheduled_reports.php`, `cron/rebuild-customer-projections.php`, and dashboard/cache jobs aggregate many rows. Some scripts use `ON DUPLICATE KEY UPDATE`, which is useful for idempotency but can be expensive without supporting indexes.

## Repeated External Calls

- LINE push/reply calls occur in webhook, cron reminders, broadcast jobs, scheduled reports, and worker.
- Gemini calls occur in AI chat adapters and product recommendation reranking.
- Fastify WebSocket dashboard updates run every 30 seconds in `backend/src/server.ts`.

## Existing Index Evidence

`database/schema_complete.sql` defines indexes for many hot tables: `messages(line_account_id,user_id,created_at)`, `users(line_account_id,line_user_id)`, `business_items(line_account_id,category_id,sku,is_active)`, `transactions(line_account_id,user_id,status,created_at)`, `payment_slips(status,user_id,transaction_id)`, appointments/reminders/AI notification indexes. Broadcast migration adds tenant/status/time indexes.

## Missing or Risky Areas

- Runtime-created tables may lack migrations/index review (`restock_notifications`, `medication_reminder_logs`, `dispensing_records` when created from page load).
- Some SQL is dynamically assembled in large handlers and should be profiled with real query plans before scaling.
- `LineAPI` has many cURL operations; not every method visibly sets short timeouts in the excerpted evidence.
- Fastify health route comments still check Odoo env variables, but Odoo is excluded here; verify non-Odoo health dependencies separately before relying on health status.

## Cache Opportunities

- Product catalog/category lists by `line_account_id`.
- Shop settings, transfer info, LIFF app mapping.
- AI knowledge/RAG candidates.
- Inbox conversation counts and unread counts.
- Dashboard stats already have cache-oriented jobs; verify freshness and invalidation.

## Last Verified From Code

Verified on 2026-07-03 from `webhook.php`, `inbox-v2.php`, `api/checkout.php`, `classes/InboxService.php`, `line-mini-app/src/lib/shop-api.ts`, `database/schema_complete.sql`, `database/migration_2026-05-04_unified_broadcast.sql`, `cron/webhook_statistics_calculator.php`, `cron/scheduled_reports.php`, `cron/rebuild-customer-projections.php`, `backend/src/server.ts`, `classes/LineAPI.php`.
