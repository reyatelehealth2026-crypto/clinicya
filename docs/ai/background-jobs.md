# Background Jobs

## Confirmed Job Surface

Jobs are PHP scripts under `cron/` and a worker under `worker/notification-worker.php`. Real scheduler entries were not inspected, so triggers are inferred from file names and code comments, not from crontab.

## Broadcast Jobs

- `cron/send_scheduled.php`: selects pending scheduled messages joined with `line_accounts`, builds LINE messages/product carousel, sends through `LineAPI::pushMessage`, and updates status to sent.
- `cron/process_broadcast_queue.php`: processes `broadcast_queue` rows with `status='pending'`, groups by broadcast/account metadata, sends LINE push messages, updates rows to sent/failed, and updates sent counts.
- `cron/broadcast_delivery_reconcile.php`: checks sent broadcasts/narrowcast delivery status and updates delivery counts/status.

Idempotency: status fields prevent reprocessing pending/sent rows. Delivery reconciliation uses sent status and pending/partial delivery status filters.

## Reminder Jobs

- `cron/appointment_reminder.php`: adds missing appointment reminder columns, selects confirmed appointments, and sends reminders/video links.
- `cron/medication_reminder.php`: sends medication reminders and logs to `medication_reminder_logs`.
- `cron/medication_refill_reminder.php`: creates/updates refill tracking and pushes refill reminders.
- `cron/reward_expiry_reminder.php`: sends LINE notifications for expiring rewards.
- `cron/wishlist_notification.php` and `cron/restock_notification.php`: notify users about wishlist/restock events.

## Webhook and Monitoring Jobs

- `cron/webhook_retry_processor.php`: lock file guarded; gets ready retries from `WebhookLoggingService`, marks processing/processed/failed, moves over max retries to DLQ.
- `cron/webhook_statistics_calculator.php`: lock file guarded; aggregates webhook counts/status/error rates and alerts.
- `cron/cleanup-dlq.php`: cleans or abandons DLQ records.
- `cron/error_handling_maintenance.php`: resets health status, archives/resolves old error data.
- `cron/security_monitoring.php`: creates/updates monitoring records and can trigger notifications.

## Reporting and Cache Jobs

- `cron/scheduled_reports.php`: generates daily/weekly/low-stock/pending-order reports and sends via LINE.
- `cron/send-daily-summary.php`: sends customer daily summary notifications.
- `cron/dashboard_realtime_updates.php`, `cron/dashboard_cache_warming.php`: dashboard/realtime cache work.
- `cron/ai_session_summarizer.php`: sweeps recent completed/escalated triage sessions and writes summaries.
- `cron/rebuild-customer-projections.php`: rebuilds customer projection metrics.

## Worker

`worker/notification-worker.php` loops and processes notification rows, sends LINE notifications using per-account channel token, reports processed/error counts, and stops after a defined runtime.

## Retry Behavior

`classes/WebhookLoggingService.php` calculates exponential backoff with jitter, stores `next_retry_at`, increments `retry_count`, and caps retries (`maxRetries` inspected as logic, exact default should be checked in class properties before tuning).

## Unknowns

Actual production schedule frequency, process supervisor, and overlap guarantees are unknown from inspected repo files. Some jobs use lock files; not all job scripts were inspected line by line.

## Last Verified From Code

Verified on 2026-07-03 from `cron/send_scheduled.php`, `cron/process_broadcast_queue.php`, `cron/broadcast_delivery_reconcile.php`, `cron/appointment_reminder.php`, `cron/medication_reminder.php`, `cron/medication_refill_reminder.php`, `cron/webhook_retry_processor.php`, `cron/webhook_statistics_calculator.php`, `cron/scheduled_reports.php`, `cron/ai_session_summarizer.php`, `worker/notification-worker.php`, `classes/WebhookLoggingService.php`.
