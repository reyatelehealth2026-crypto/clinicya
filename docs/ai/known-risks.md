# Known Risks

## P0

## Secret Exposure In Config

Evidence: `config/config.php` contains literal database credentials and Redis password fallback. Impact is high if repository access expands or values are reused.

Remediation: rotate exposed values, move secrets to env/secret manager, add secret scanning.

## Client-Supplied Mini App Identity

Evidence: mini app API calls send `line_user_id` and `line_account_id`; server-side LIFF identity verification was not confirmed in `api/checkout.php` paths. Impact is high for orders, cart, points, profile, and health data.

Remediation: enforce token/profile verification server-side on sensitive endpoints.

## P1

## Database Routing Split Brain

Evidence: `classes/Database.php` warns that `config/database.php` can define the global `Database` first; `Modules\Core\Database::getInstance()` has legacy fallback if no tenant context exists.

Remediation: consolidate database bootstrap, require tenant context in entry points, add tests for subdomain/account routing.

## Large Coupled Controllers

Evidence: `webhook.php`, `inbox-v2.php`, and `api/checkout.php` combine routing, SQL, business logic, external calls, and response shaping.

Remediation: extract tested service boundaries around account resolution, checkout, uploads, dispense, and AI/webhook dispatch.

## Runtime Schema Mutation

Evidence: `inbox-v2.php` creates `dispensing_records`; cron scripts create or alter reminder/restock tables.

Remediation: move schema changes to versioned migrations and make jobs fail with actionable errors when schema is missing.

## Incomplete CSRF Evidence

Evidence: CSRF helper appears in `api/_products_lookup_crud.php`, but not uniformly in inspected mutating session endpoints.

Remediation: add standard CSRF middleware/helper for PHP admin AJAX mutations.

## P2

## Endpoint Contract Drift

Evidence: many `api/*.php` files use action switches and manually shaped JSON. Mini app TypeScript types document only some contracts.

Remediation: add contract tests/snapshots for core APIs and generate OpenAPI-like docs for PHP endpoints.

## Observability Gaps

Evidence: `dev_logs`, webhook logs, and monitoring jobs exist, but no single trace/correlation strategy was confirmed across PHP, cron, Fastify, and LINE calls.

Remediation: add request IDs to PHP API responses/logs, correlate LINE request IDs, and standardize job run IDs.

## Last Verified From Code

Verified on 2026-07-03 from `config/config.php`, `line-mini-app/src/lib/shop-api.ts`, `api/checkout.php`, `classes/Database.php`, `modules/Core/Database.php`, `webhook.php`, `inbox-v2.php`, `cron/*.php`, `api/_products_lookup_crud.php`, `database/schema_complete.sql`.
