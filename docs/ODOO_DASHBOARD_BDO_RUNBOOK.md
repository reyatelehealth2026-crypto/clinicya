# Odoo Dashboard and BDO Matching Runbook

Version: 1.0 (March 2026)
Audience: Re-Ya engineers operating dashboard, webhook, and BDO matching flows

---

## 1. Scope

This runbook documents the current behavior implemented in these codepaths:

- `api/odoo-dashboard-api.php`
- `api/odoo-dashboard-local.php`
- `api/bdo-inbox-api.php`
- `classes/OdooAPIClient.php`
- `classes/OdooAPIPool.php`
- `classes/OdooCircuitBreaker.php`
- `classes/BdoSlipContract.php`
- `classes/BdoContextManager.php`
- `classes/OdooWebhookHandler.php`
- `cron/sync_odoo_dashboard_cache.php`
- `verify_odoo_local_cache.php`
- `database/migration_odoo_api_performance.sql`

---

## 2. Architecture Snapshot

```text
Odoo Webhooks
  -> odoo_webhooks_log
  -> sync job (cron/sync_odoo_dashboard_cache.php)
  -> local cache tables (odoo_*_cache / odoo_orders_summary / odoo_order_events)
  -> dashboard APIs
       - api/odoo-dashboard-api.php    (mixed live + cache + write proxies)
       - api/odoo-dashboard-local.php  (local-only reads)
       - api/bdo-inbox-api.php         (normalized BDO/slip contract)
  -> UI
       - odoo-dashboard.js / odoo-dashboard.php
```

Key intent:

- Keep dashboards fast via local cache tables.
- Keep match/unmatch authoritative via Odoo write APIs.
- Keep BDO context multi-BDO safe via `(line_user_id, bdo_id)`.

---

## 3. Interfaces You Should Use

### 3.1 `api/bdo-inbox-api.php` (recommended normalized facade)

Auth:

- Header `X-Internal-Secret` must match `INTERNAL_API_SECRET` (or request is `401`).

Read actions:

- `bdo_list`
- `bdo_detail`
- `slip_list`
- `bdo_context`
- `matching_workspace`
- `statement_pdf_url`
- `health`

Write actions:

- `slip_match_bdo`
- `slip_unmatch`
- `slip_upload`

Contract rules (enforced in code):

- Odoo is the source of truth for matching state.
- `slip_inbox_id` is canonical for slip mutations.
- Local rows are updated only after Odoo confirms success.
- Unmatch is blocked for slip status `posted` and `done`.

### 3.2 `api/odoo-dashboard-api.php` (dashboard + proxy actions)

Frequently used actions:

- `health`
- `overview_fast`
- `overview_today`
- `customer_full_detail`
- `odoo_bdo_list_api`
- `odoo_bdo_detail_api`
- `odoo_slip_match_api`
- `odoo_slip_unmatch_api`
- `odoo_bdo_statement_pdf`
- `circuit_breaker_status`
- `circuit_breaker_reset`

Notes:

- Responses include `_meta.duration_ms` and cache metadata.
- Some actions are cached in APCu/file cache with action-specific TTL.
- `statement_pdf` and `odoo_bdo_statement_pdf` stream PDF directly.

### 3.3 `api/odoo-dashboard-local.php` (local-only reads)

Use this when you need cache-only reads with no live Odoo call:

- `overview_kpi`
- `orders_list`, `orders_today`
- `customers_list`, `customer_detail`
- `invoices_list`, `invoices_overdue`
- `slips_list`, `slips_pending`
- `order_timeline`
- `search_global`
- `cache_status`
- `health`

---

## 4. Usage Examples

### 4.1 Health checks

```bash
curl -s "https://<host>/api/odoo-dashboard-api.php?action=health"
curl -s "https://<host>/api/odoo-dashboard-local.php?action=health"
curl -s "https://<host>/api/bdo-inbox-api.php?action=health" \
  -H "X-Internal-Secret: <INTERNAL_API_SECRET>"
```

### 4.2 List BDOs from normalized inbox API

```bash
curl -s "https://<host>/api/bdo-inbox-api.php" \
  -H "Content-Type: application/json" \
  -H "X-Internal-Secret: <INTERNAL_API_SECRET>" \
  -d '{
    "action": "bdo_list",
    "line_user_id": "Uxxxxxxxx",
    "state": "waiting",
    "limit": 50,
    "offset": 0
  }'
```

### 4.3 Match a slip to BDO(s)

```bash
curl -s "https://<host>/api/bdo-inbox-api.php" \
  -H "Content-Type: application/json" \
  -H "X-Internal-Secret: <INTERNAL_API_SECRET>" \
  -d '{
    "action": "slip_match_bdo",
    "line_user_id": "Uxxxxxxxx",
    "slip_inbox_id": 113,
    "matches": [{"bdo_id": 437, "amount": 15950}],
    "note": "manual match from dashboard"
  }'
```

### 4.4 Unmatch a slip

```bash
curl -s "https://<host>/api/bdo-inbox-api.php" \
  -H "Content-Type: application/json" \
  -H "X-Internal-Secret: <INTERNAL_API_SECRET>" \
  -d '{
    "action": "slip_unmatch",
    "line_user_id": "Uxxxxxxxx",
    "slip_inbox_id": 113,
    "reason": "operator correction"
  }'
```

---

## 5. Operations Runbook

### 5.1 Sync local dashboard cache

CLI:

```bash
php cron/sync_odoo_dashboard_cache.php incremental
php cron/sync_odoo_dashboard_cache.php full
php cron/sync_odoo_dashboard_cache.php orders
php cron/sync_odoo_dashboard_cache.php customers
php cron/sync_odoo_dashboard_cache.php invoices
```

Optional second arg for bot scope:

```bash
php cron/sync_odoo_dashboard_cache.php incremental <line_account_id>
```

Recommended cron:

```bash
*/5 * * * * php /path/to/cron/sync_odoo_dashboard_cache.php incremental
```

### 5.2 Verify cache health

```bash
php verify_odoo_local_cache.php
```

Web:

```text
/verify_odoo_local_cache.php
```

### 5.3 Apply performance indexes

Run:

```bash
mysql -u <user> -p <db_name> < database/migration_odoo_api_performance.sql
```

This migration adds indexes for:

- `odoo_webhooks_log`
- `odoo_api_logs`
- `odoo_orders`
- `odoo_invoices`
- `odoo_bdos`
- `odoo_customer_projection`
- `odoo_order_projection`

### 5.4 Circuit breaker operations

Inspect:

```bash
curl -s "https://<host>/api/odoo-dashboard-api.php?action=circuit_breaker_status"
```

Reset one breaker:

```bash
curl -s "https://<host>/api/odoo-dashboard-api.php?action=circuit_breaker_reset&service=odoo_reya"
```

---

## 6. Constraints and Pitfalls

### 6.1 Canonical IDs

- Use `slip_inbox_id` for Odoo match/unmatch operations.
- Do not treat local `odoo_slip_uploads.id` as the authoritative Odoo slip ID.

### 6.2 Multi-BDO context behavior

When upload arrives without `bdo_id`:

- 1 open context: auto-attach that `bdo_id`.
- 0 contexts: allow Odoo partner/amount auto-match.
- >1 contexts: API returns `ambiguous_bdos`; caller must select target BDO.

### 6.3 Strict mutation rule

- If Odoo rejects match/unmatch, local state must not be updated to success.

### 6.4 Common error patterns

- `Unauthorized` from `bdo-inbox-api`: missing/invalid `X-Internal-Secret`.
- `Missing line_user_id` or `Missing slip_inbox_id`: required parameters absent.
- `CIRCUIT_OPEN`: Odoo service marked unavailable by circuit breaker.
- Table-missing sync failures: local cache schema not deployed or incomplete.

### 6.5 Maintenance note

`cron/sync_odoo_dashboard_cache.php` and `verify_odoo_local_cache.php` reference a migration file named `database/migration_odoo_dashboard_cache.sql`. If your environment does not include that file, deploy required cache tables from your standard schema bundle before running the sync job.

---

## 7. Quick Decision Guide

- Need normalized BDO/slip API with strict Odoo-first writes: use `api/bdo-inbox-api.php`.
- Need dashboard aggregate data with mixed live/cache and existing UI compatibility: use `api/odoo-dashboard-api.php`.
- Need fastest cache-only reads: use `api/odoo-dashboard-local.php`.
