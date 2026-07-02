# Incident Runbook

## Scope

This runbook gives code-backed starting points for production incidents involving LINE webhooks, broadcasts, quota, jobs, integrations, security monitoring, and data issues.

## First 10 Minutes

1. Identify the affected tenant or LINE account.
2. Check whether the issue is inbound webhook, outbound send, scheduled job, LIFF/API, Odoo, database, or security.
3. Preserve evidence before retrying destructive operations.
4. Check `dev_logs`, PHP `error_log`, relevant cron output, and affected DB rows.
5. Prefer tenant-scoped queries using `line_account_id`.

## Key Evidence Sources

| Evidence | Where to check | Code evidence |
| --- | --- | --- |
| Webhook fatal errors | `dev_logs` source `webhook_fatal`, PHP `error_log` | `webhook.php` shutdown handler |
| Webhook processing errors | `dev_logs`, PHP `error_log` | `webhook.php::logWebhookException()`, `devLog()` |
| Webhook retry/DLQ | webhook monitoring API and cron retry processor | `api/webhook-monitoring.php`, `cron/webhook_retry_processor.php`, `classes/WebhookLoggingService.php` |
| System health | Unified health endpoint | `api/system-health.php` |
| Broadcast send state | `broadcast_queue`, `broadcasts`, `broadcast_campaigns` | `cron/process_broadcast_queue.php`, `api/process_scheduled_broadcasts.php` |
| Delivery reconciliation | Broadcast delivery reconcile job | `cron/broadcast_delivery_reconcile.php` |
| Security alerts | Security monitoring cron and `dev_logs` | `cron/security_monitoring.php` |
| Odoo DLQ | `odoo_webhook_dlq` cleanup/retry state | `cron/cleanup-dlq.php` |

## LINE Webhook Down or Delayed

Symptoms:

- LINE users send messages but admin inbox or bot replies do not update.
- `dev_logs` has `webhook_fatal` or webhook-related errors.
- LINE reports webhook delivery failures.

Checks:

- Validate account resolution and signature path in `webhook.php` and `classes/LineAPI.php::validateSignature()`.
- Check whether `webhook.php` is writing incoming messages to `messages`.
- Check whether `users.reply_token` and `reply_token_expires` are being updated.
- Inspect `error_log` for media download, WebSocket notification, BusinessBot, AI, or DB errors.
- Use `api/webhook-monitoring.php?action=health` if the monitoring API is deployed and authenticated.

Recovery:

- If failures are transient, use `api/webhook-monitoring.php` retry actions or allow `cron/webhook_retry_processor.php` to process retryable webhooks.
- If webhooks reach DLQ, use DLQ retry only after fixing the underlying error.
- If reply tokens expired, resend only when appropriate through push paths and account for LINE quota.

## Broadcast or Quota Incident

Symptoms:

- Campaign partially sends.
- Send counts diverge from LINE delivery counts.
- LINE returns quota/rate errors.

Checks:

- Inspect queue state in `broadcast_queue` and campaign state in `broadcasts` or `broadcast_campaigns`.
- Check `cron/process_broadcast_queue.php` output/logs for failed rows.
- Use `classes/LineAPI.php` quota helpers: `getMessageQuota()`, `getMessageQuotaConsumption()`, and sent-message counters.
- Run or inspect `cron/broadcast_delivery_reconcile.php` before reporting final delivery numbers.

Recovery:

- Stop new large sends until quota is understood.
- Retry failed queue rows only after confirming recipient targeting and quota.
- Prefer narrower targeting/narrowcast where possible; code uses `upToRemainingQuota` for narrowcast.

## Flex Message Render or Send Failure

Symptoms:

- LINE message send fails.
- User receives fallback text or no rich card.
- Broadcast/auto-reply Flex JSON is rejected.

Checks:

- Validate JSON shape before sending. LINE Flex payloads must be wrapped as `type = flex`.
- Check `classes/FlexTemplates.php::toMessage()` for canonical wrapping behavior.
- Check send-specific validators in `api/broadcast.php`, `cron/send_scheduled.php`, and admin Flex surfaces.
- For medicine-label issues, inspect `FlexTemplates::medicineLabel()` and dispense usage in `inbox-v2.php` / `messages.php`.

Recovery:

- Roll back the broken Flex JSON or switch to a known-good template.
- Keep URLs/actions unchanged unless the incident is specifically caused by those fields.
- Verify visually in a LINE client after JSON validation passes.

## Job or Scheduler Incident

Symptoms:

- Scheduled broadcasts do not send.
- Webhook retry/statistics cache does not update.
- DLQ cleanup not running.

Checks:

- Scheduled broadcast path: `api/process_scheduled_broadcasts.php`, `cron/process_broadcast_queue.php`, `cron/send_scheduled.php`.
- Webhook retry/statistics paths: `cron/webhook_retry_processor.php`, `cron/webhook_statistics_calculator.php`.
- Lock files are used by webhook retry/statistics crons under `tmp/*.lock`; stale locks can prevent execution until timeout.
- Error cleanup path: `cron/error_handling_maintenance.php`.
- Odoo DLQ cleanup path: `cron/cleanup-dlq.php`.

Recovery:

- Confirm no active process owns a lock before removing stale lock files.
- Re-run the smallest affected cron manually in a controlled shell and capture output.
- Do not rerun bulk-send jobs without confirming idempotency for the affected rows.

## Security Incident

Symptoms:

- Spike in failed login attempts.
- Unexpected admin/session behavior.
- Security events or audit logs show anomalous activity.

Checks:

- `cron/security_monitoring.php` calculates failed logins in 24h, security events in 7d, critical events in 24h, and active sessions.
- `api/audit-logs.php` exposes audit-log operations and dashboard metrics.
- `dev_logs` records security-monitoring cron notification attempts.

Recovery:

- Preserve logs before cleanup.
- Rotate exposed secrets outside this repository if credentials are suspected compromised.
- Disable or restrict affected admin accounts through the admin-user/session surfaces.
- Review recent audit logs and security events before restoring normal access.

## Odoo Integration Incident

Symptoms:

- Inventory/order/invoice sync stale.
- Webhook DLQ grows.
- Health endpoint reports Odoo degraded.

Checks:

- `api/system-health.php` checks Odoo last webhook, DLQ count, retry count, failed percentage, and notification success rate.
- `cron/cleanup-dlq.php` purges resolved/abandoned Odoo DLQ rows and marks 5+ retry rows abandoned.
- Existing docs state Odoo dashboard reads should use cache tables, not direct Odoo API reads.

Recovery:

- Fix credentials/network/API root cause before retrying DLQ rows.
- Reconcile cache tables before telling users that Odoo state is current.
- Respect `ODOO_INTEGRATION_ENABLED` when testing tenant UI.

## Communication Template

Use this format for internal incident notes:

```text
Incident:
Tenant/line_account_id:
Start time:
User impact:
Detected by:
Current status:
Evidence checked:
Root cause:
Mitigation:
Follow-up:
```

## Unknowns

- Production log aggregation and alerting tools are not defined in the repository.
- Exact cron scheduler configuration is not confirmed in code.
- On-call ownership and escalation contacts are not stored in the repository.

## Last Verified From Code

- Verified from `webhook.php`, `classes/LineAPI.php`, `classes/WebhookLoggingService.php`, `api/webhook-monitoring.php`, `api/system-health.php`, `api/audit-logs.php`, `cron/webhook_retry_processor.php`, `cron/webhook_statistics_calculator.php`, `cron/error_handling_maintenance.php`, `cron/security_monitoring.php`, `cron/process_broadcast_queue.php`, `cron/broadcast_delivery_reconcile.php`, and `cron/cleanup-dlq.php` on 2026-07-03.

