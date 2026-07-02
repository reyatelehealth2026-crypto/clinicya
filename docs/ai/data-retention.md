# Data Retention

## Scope

This document summarizes what the repository confirms about data retention, deletion, expiry, and cleanup. It separates confirmed code behavior from unknown policy gaps.

## Confirmed Retention and Cleanup Behavior

| Data area | Confirmed behavior | Evidence |
| --- | --- | --- |
| Error logs | Old `error_logs` entries older than 30 days are deleted | `cron/error_handling_maintenance.php::cleanupOldErrorLogs()` |
| Generic dead-letter queue | Resolved `dead_letter_queue` messages older than 7 days are deleted | `cron/error_handling_maintenance.php::cleanupResolvedDLQMessages()` |
| Odoo webhook DLQ | Resolved `odoo_webhook_dlq` entries older than 30 days are purged | `cron/cleanup-dlq.php` |
| Odoo abandoned DLQ | Abandoned `odoo_webhook_dlq` entries older than 90 days are purged | `cron/cleanup-dlq.php` |
| Odoo retry exhaustion | `odoo_webhook_dlq` entries with 5+ retries can be marked abandoned | `cron/cleanup-dlq.php` |
| Points expiry | `points_settings.points_expiry_days` defaults to 365 and `0` means no expiry | `database/install_complete_latest.sql`, `database/schema_complete.sql` |
| User state expiry | `user_states.expires_at` exists and is written by webhook/business-bot state helpers | `webhook.php`, `classes/BusinessBot.php`, `database/install_complete_latest.sql` |
| Reply token expiry | `users.reply_token_expires` exists and webhook writes short-lived expiry | `webhook.php`, `database/install_complete_latest.sql` |
| Coupon/reward/request expiry | Several tables include `expires_at` and status values such as `expired` | `database/install_complete_latest.sql`, `database/schema_complete.sql` |

## Personal and Sensitive Data Stores

Confirmed sensitive or personal data categories:

- LINE identifiers and profile data: `users.line_user_id`, display name, picture URL, status message, and `line_account_followers`.
- Contact/profile fields: `users` and `user_profiles_extended` include real name, phone, email, birthday, gender, address, province, postal code, and related profile fields.
- Health/pharmacy data: `customer_health_profiles`, `medical_history`, `prescription_records`, `prescription_items`, `symptom_assessments`, and consultation/triage-related tables exist in `database/install_complete_latest.sql`.
- Conversations: `messages`, `line_group_messages`, and related webhook/account event tables store user communications and event metadata.
- Commerce/loyalty: `orders`, `transactions`, `cart_items`, `points_history`, `loyalty_points`, reward redemption tables, and receipt-claim fields.
- Operational/security logs: `dev_logs`, `audit_logs`, `security_events`, `error_logs`, webhook logs/statistics, and DLQ tables.

## What Is Not Confirmed

No code-backed global retention policy was found for:

- `users`
- `messages`
- `line_account_events`
- `line_account_followers`
- health profiles and medical history
- prescription records
- orders/transactions
- receipt images or uploaded medical media
- AI conversation context and health-profile snapshots
- audit logs beyond API-level query windows

This means the repository confirms targeted cleanup for selected operational logs/DLQs, but not a complete privacy/data-retention program for all customer data.

## Deletion Semantics

Confirmed facts:

- `webhook.php::handleUnfollow()` marks `users.is_blocked = 1`; it does not delete the user.
- Many schema foreign keys use `ON DELETE CASCADE`, so deleting a user can cascade into messages, orders, health records, and related records depending on table relationships.
- Some foreign keys use `ON DELETE SET NULL`, preserving records while removing direct object linkage.

Risk:

- Because cascade behavior differs by table, manual deletion without a documented workflow can either remove too much data or leave personally identifiable orphan records.

## Data Retention Matrix

| Category | Current code-backed retention | Risk |
| --- | --- | --- |
| Operational error logs | 30-day cleanup for `error_logs` | Medium, if cron is not scheduled |
| Generic DLQ | Resolved rows older than 7 days deleted | Low to medium |
| Odoo webhook DLQ | Resolved 30 days, abandoned 90 days | Medium, if Odoo integration disabled but rows accumulate |
| LINE messages | Unknown | High, contains user communications |
| Customer profile | Unknown | High, contains PII |
| Health/medical data | Unknown | High, sensitive medical data |
| Orders/payments | Unknown | Medium to high, legal/accounting constraints likely external |
| Loyalty points | Expiry configurable for points; user retention unknown | Medium |
| Audit/security events | Query windows exist, retention unknown | Medium |

## Recommended Policy Work

- Define a product/legal retention schedule per data category before implementing deletion.
- Add a documented customer data export/delete/anonymize workflow.
- Add table-level retention ownership for `users`, `messages`, health records, prescriptions, uploads, orders, AI context, and logs.
- Verify cron scheduling for cleanup jobs in production.
- Prefer anonymization over hard delete for records that must remain for pharmacy/accounting compliance.

## Last Verified From Code

- Verified from `cron/error_handling_maintenance.php`, `cron/cleanup-dlq.php`, `webhook.php`, `classes/BusinessBot.php`, `database/install_complete_latest.sql`, and `database/schema_complete.sql` on 2026-07-03.

