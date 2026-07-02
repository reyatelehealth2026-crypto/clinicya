# Database Schema

## Database Access

Confirmed PHP DB access uses PDO. `modules/Core/Database.php` builds a MySQL DSN with `charset=utf8mb4`, sets `PDO::ATTR_ERRMODE`, disables emulated prepares, and executes `SET time_zone = '+07:00'`. `classes/Database.php` proxies to the tenant-aware database factory when loaded first, but warns that `config/database.php` can still define another global `Database` class depending on load order.

## Core Tenant Tables

From `database/schema_complete.sql`:

- `line_accounts`: LINE account credentials and metadata; unique `channel_secret`.
- `admin_users`: tenant admin users with unique `username` and `email`, role and `line_account_id` indexes.
- `users`: LINE/customer users; unique `line_account_id,line_user_id`; fields include profile/contact/points/tier style data.
- `messages`: conversation messages indexed by `line_account_id`, `user_id`, `created_at`.
- `business_items`, `item_categories`: product catalog/inventory.
- `cart`, `cart_items`: server-side cart; unique user/product combinations.
- `transactions`, `transaction_items`, `payment_slips`: orders, order lines, slip review.
- `shop_settings`: per-line-account shop settings.
- `wishlist` and runtime `user_wishlist` variants: wishlists and sale notifications.
- `user_tags`, `user_tag_assignments`, `user_notes`: CRM tagging and notes.
- `points_settings`, `points_transactions`, `points_history`, `rewards`, `reward_redemptions`, `points_tiers`, `point_rewards`: loyalty.
- `auto_replies`, `broadcast_messages`, `flex_templates`, `rich_menus`, `welcome_settings`: messaging automation.
- `pharmacists`, `pharmacist_schedules`, `pharmacist_holidays`, `appointments`, `video_calls`: telepharmacy booking.
- `symptom_assessments`, `triage_sessions`, `pharmacist_consultations`, `prescription_approvals`, `user_health_profiles`, `user_drug_allergies`, `user_current_medications`: clinical/triage data.
- `ai_settings`, `ai_conversations`, `user_states`, `emergency_alerts`, `pharmacist_notifications`: AI chat and workflow state.
- `settings`, `liff_apps`, `liff_message_logs`, `scheduled_reports`, `sync_queue`, `dev_logs`, `webhook_events`, `telegram_settings`: platform support.

## Platform Tables

From `database/migration_2026-05-25_platform_master.sql`:

- `plans`, `platform_users`, `tenants`, `entitlements`, `super_admin_audit`, `tenant_provisioning_log`, `tenant_migrations`.
- `tenants.db_name` is unique and used by tenant routing in `Modules\Core\Database::resolveTenantDbName`.

## Broadcast Tables

From `database/migration_2026-05-04_unified_broadcast.sql`:

- Extended `broadcast_campaigns`.
- `broadcast_links`, `broadcast_link_clicks`, `broadcast_drafts`.
- Adds tenant scoping indexes such as `idx_account_status_created`, `idx_account_clicked`.

## Read/Write Paths

- `api/checkout.php`: reads/writes `users`, `business_items`, `cart`, `cart_items`, `transactions`, `transaction_items`, `payment_slips`, `shop_settings`.
- `inbox-v2.php`: reads/writes `users`, `messages`, `user_tags`, `user_tag_assignments`, `user_notes`, `dispensing_records`, `cart`, `transactions`, `transaction_items`, `business_items`.
- `webhook.php` and `BusinessBot`: read/write users, messages, cart, transactions, user states, points/rewards, behavior/tag tables.
- `modules/AIChat/*`: reads/writes AI settings, conversation history/state, triage/assessment/profile/notification tables.
- `cron/*.php`: update reminders, broadcasts, reports, webhook logs/stats, projections, maintenance tables.

## Sensitive Fields

Sensitive by design: `line_accounts.channel_access_token`, `line_accounts.channel_secret`, `admin_users.password`, `platform_users.password_hash`, `ai_settings.gemini_api_key`, config DB credentials, Redis password, OAuth client secret, SSO secret.

## Schema Risks and Missing Constraints

Confirmed risks:

- Many tables in `schema_complete.sql` define indexes but not explicit foreign keys for core tenant tables such as `users`, `messages`, `transactions`, and `business_items`.
- Some runtime code creates or alters tables on execution (`inbox-v2.php` creates `dispensing_records`; `cron/medication_reminder.php` creates `medication_reminder_logs`; `cron/medication_refill_reminder.php` creates `medication_refill_tracking`; `cron/restock_notification.php` creates `restock_notifications`).
- There are parallel cart tables (`cart` and `cart_items`) and wishlist table variants (`wishlist`, `user_wishlist`) in inspected code/schema, which raises drift risk.

## Last Verified From Code

Verified on 2026-07-03 from `database/schema_complete.sql`, `database/install_complete_latest.sql`, `database/migration_2026-05-25_platform_master.sql`, `database/migration_2026-05-04_unified_broadcast.sql`, `modules/Core/Database.php`, `classes/Database.php`, `api/checkout.php`, `inbox-v2.php`, `cron/*.php`.
