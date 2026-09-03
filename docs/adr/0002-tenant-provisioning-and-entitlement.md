# ADR-002: Tenant Provisioning Flow + Entitlement Gating

**Status:** Accepted (2026-05-25)
**Deciders:** Platform Owner + Engineering
**Supersedes:** Implicit "insert row into `line_accounts` and hope" provisioning

> **Schema source of truth:** the canonical platform schema lives in
> `database/migration_2026-05-25_platform_master.sql` (Agent A output).
> Schema snippets in this ADR are **descriptive** — if they disagree with the
> migration file, the migration file wins.
>
> **Tenant DB user table:** the tenant-side admin/staff table is `admin_users`
> (existing table, kept as-is in the tenant template). The master DB's
> super-admin table is `platform_users`. They are **different tables in
> different databases** — do not confuse them.

---

## Context

ADR-001 established that each Tenant lives in its own MariaDB database
(`reya_tenant_NNNN`) and that `reya_platform` is the master registry. Two
operational questions are still open:

1. **How does a new Tenant come into existence?**
   Today: someone inserts a row into `line_accounts` and uploads SQL by hand.
   No reproducible script, no audit trail, no entitlement gating, no welcome
   email. Mistakes have already caused phantom tenants with no admin user.

2. **What can each Tenant actually do, and who decides?**
   Today: every Tenant gets every feature. There is no concept of "this
   Tenant paid for telepharmacy, that one didn't." Branch / channel limits
   are not enforced anywhere in code — multi-branch is technically possible
   but operationally unsupported.

The product constraints (from chat session 2026-05-25):

- **Target <50 Tenants in 3 years** → provisioning will run ~1/week at peak;
  human-in-the-loop is acceptable. No need for self-serve signup.
- **Pharmacy data ห้ามหลุดเด็ดขาด** → provisioning must be deterministic;
  partial provisioning that leaves a half-built Tenant is unacceptable.
- **Multi-branch & multi-channel = Platform Owner approves manually** → the
  entitlement system must default to *restrictive* (1 branch, 1 channel) and
  require an explicit Platform Owner action to expand.
- Tenants pay in tiers (Starter / Pro / Enterprise / Custom) → each tier maps
  to a default Entitlement bundle, which the Platform Owner can override
  per-Tenant.

## Decision

Introduce a **provisioning pipeline** (transactional, all-or-nothing) and an
**Entitlement table** (per-Tenant override of plan defaults).

### Provisioning Pipeline

Single PHP command exposed via CLI and the Platform Owner admin UI:

```bash
php scripts/provision_tenant.php \
  --name="คลินิคยา สาขาทดสอบ" \
  --owner-email="owner@example.com" \
  --owner-name="คุณภัทร" \
  --plan="starter" \
  --tenant-code="0042"
```

Steps executed inside a single platform-DB transaction wrapping non-trans
operations with compensating rollback:

```
[1] BEGIN platform tx
[2] INSERT INTO platform.tenants (code, name, plan, status='provisioning', db_name)
    → returns tenant_id, db_name = 'reya_tenant_0042'
[3] CREATE DATABASE reya_tenant_0042
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
[4] Apply template SQL:
    database/migration_2026-05-25_tenant_template.sql
    (the full 200+ table DDL, in order)
[5] Apply every incremental migration newer than template:
    SELECT * FROM platform.tenant_migrations
    WHERE applied_to_template = true AND NOT applied_to_tenant_0042
[6] Seed default Branch row (id=1, code='00000', name='สำนักงานใหญ่')
    [delegated to ADR-003]
[7] Seed default Entitlement rows from plan template:
    INSERT INTO reya_platform.entitlements (tenant_id, entitlement_key, value_int, value_text, value_bool, note)
    -- one row per key from the plan's defaults; typed columns (value_int / value_text / value_bool) used per entitlement
[8] Create initial admin user inside reya_tenant_0042.admin_users:
    role='admin', email=$ownerEmail, temp_password=random(16)
[9] Record provisioning event:
    INSERT INTO platform.tenant_provisioning_log
[10] UPDATE platform.tenants SET status='active'
[11] COMMIT
[12] Queue welcome email with credentials + reset-on-first-login flag
```

**Failure handling:** any step ≥3 failing triggers compensating actions:
`DROP DATABASE reya_tenant_NNNN`, `DELETE FROM platform.tenants WHERE id=?`,
write failure cause to `tenant_provisioning_log.error`. Email NOT sent. The
Platform Owner sees a red "Provisioning failed" row in the admin UI.

### Master DB schema additions

> **Canonical schema:** `database/migration_2026-05-25_platform_master.sql`.
> What follows is a descriptive summary — column names and types may differ
> slightly from the implementation; the migration file is authoritative.

Key tables in `reya_platform` used by provisioning:

- **`tenants`** — `id`, `slug`, `display_name`, `legal_name`, `tax_id`,
  `db_name`, `db_host`, `plan_id` (FK→plans), `status`, `default_branch_id`
  (soft ref), `default_channel_id` (soft ref), owner contact fields,
  `created_by`, audit timestamps. ID is INT AUTO_INCREMENT (not CHAR(4) as
  earlier draft suggested) — the 4-digit zero-padded form (`0042`) is only
  used in derived names like `reya_tenant_0042` and `tenant_0042/` directories.

- **`plans`** — `id`, `slug`, `display_name`, `description`,
  `price_monthly_thb`, `is_active`, `is_visible_public`. Seeded with
  `starter` / `pro` / `enterprise`. Plan defaults are **NOT stored in a
  `defaults_json` column** in v1 — instead the provisioning script reads
  hard-coded plan-default entitlement maps from PHP (`PlanDefaults::for($slug)`)
  and writes individual rows into `entitlements`. This trades schema
  flexibility for explicitness; revisit if defaults need to change without a
  deploy.

- **`entitlements`** — `id`, `tenant_id` (FK→tenants), `entitlement_key`
  (VARCHAR — name is `entitlement_key` because `key` is a SQL reserved word),
  `value_int` / `value_text` / `value_bool` (typed columns; pick the one that
  matches the key's type), `note` (Platform Owner's free-form "why I changed
  this"), audit timestamps. UNIQUE`(tenant_id, entitlement_key)`. **Not yet
  modelled in v1:** `source` ENUM, `granted_by`, `expires_at` — these are
  ADR-002 v2 enhancements; add via non-breaking ALTER when the use case
  appears (trial expiry, audit drill-down).

- **`tenant_provisioning_log`** — `id`, `tenant_id`, `event` ENUM (`create`,
  `schema_apply`, `seed`, `suspend`, `resume`, `terminate`, `db_backup`,
  `db_restore`, `migrate_apply`), `migration_file`, `status` ENUM (`started`,
  `succeeded`, `failed`, `rolled_back`), `error_message`, `triggered_by` (FK
  platform_users), `started_at`, `completed_at`.

- **`tenant_migrations`** — tracks which `migration_*.sql` has been applied
  to each tenant DB, with sha256 checksum + execution time. See ADR-001
  §Consequences for purpose.

### Entitlement Keys (v1)

Standard keys checked by app code. Values stored as string; app casts.

| Key | Type | Default (Starter) | Default (Pro) | Default (Enterprise) | Description |
|-----|------|-------------------|---------------|----------------------|-------------|
| `max_branches`           | int    | 1     | 3      | 10        | UI hides "Add Branch" at limit (see ADR-003) |
| `max_channels`           | int    | 1     | 3      | 10        | UI hides "Add Channel" at limit |
| `max_admin_users`        | int    | 2     | 5      | 20        | Block adding `role=admin` users at limit |
| `max_staff_users`        | int    | 5     | 20     | 100       | Block adding `role IN (pharmacist,marketing,tech,staff)` |
| `allow_documents`        | bool   | false | true   | true      | Hide entire Documents module from nav |
| `allow_ai_chat`          | bool   | true  | true   | true      | Hide AI Chat module |
| `allow_telepharmacy`     | bool   | false | false  | true      | Hide Telepharmacy module |
| `allow_odoo`             | bool   | false | true   | true      | Honors existing `ODOO_INTEGRATION_ENABLED` gate |
| `allow_broadcast`        | bool   | true  | true   | true      | Hide LINE broadcast UI |
| `storage_quota_mb`       | int    | 500   | 5000   | 50000     | Enforced by daily cron (see ADR-005) |
| `db_size_quota_mb`       | int    | 1000  | 10000  | 100000    | Soft limit; alert via Telegram at 80% |

### Entitlement check API

Single static call inside any controller/service:

```php
namespace App\Platform;

class Entitlement {
    public static function can(int $tenantId, string $key): bool { /* bool */ }
    public static function getInt(int $tenantId, string $key): int { /* int */ }
    public static function getString(int $tenantId, string $key): string { /* string */ }

    /** Throws \App\Platform\EntitlementDeniedException — to be caught by
     *  error handler and rendered as "ฟีเจอร์นี้ไม่ได้เปิดให้กับแพ็กเกจของคุณ"
     */
    public static function requireCan(int $tenantId, string $key): void;
}
```

Internally backed by an in-request cache: one SELECT per request loads all
entitlements for the current Tenant into `App\Platform\EntitlementCache`.

Usage sites:

- **Navigation rendering** (`includes/header.php`) — hide module links
- **Controller entry points** — `Entitlement::requireCan(...)` at top
- **Form submit handlers** — block creating Nth resource if `max_*` hit
- **API endpoints** — return 402 Payment Required + JSON error
- **Cron jobs** (ADR-004) — skip Tenants that don't have the feature

### Platform Owner UI (grant / revoke)

Located at `/platform/tenants/{id}/entitlements`. Renders the matrix of all
known keys × current value × source. Inline edit, with required fields:

- New value
- Reason (free text, required, written to `entitlements.notes`)
- Optional `expires_at` (for trials)

Submitting an edit writes BOTH the entitlement row AND a
`super_admin_audit` entry (ADR-006). Edits emit a `entitlement.changed` event
that invalidates the per-Tenant cache.

### Suspension Flow (non-payment, ToS violation, etc.)

Triggered manually by Platform Owner from `/platform/tenants/{id}`:

1. `UPDATE platform.tenants SET status='suspended', suspended_at=NOW() WHERE id=?`
2. Add notes (reason, expected resume date)
3. On next login attempt for any user of that Tenant: redirect to
   `/account-suspended.php` with message: "บัญชีของท่านถูกระงับชั่วคราว
   กรุณาติดต่อทีมงาน REYA"
4. Webhook handlers (`webhook.php?account=X`) reply HTTP 200 + log only —
   do NOT process inbound LINE events (preserves message at LINE platform
   for 7 days, but we don't act on it)
5. Cron jobs skip suspended Tenants (see ADR-004)
6. **Data is preserved as-is** — no deletion

Resume = `UPDATE status='active'`. Idempotent. Auditable.

### Termination Flow

Two-step, intentionally slow.

**Step 1 — Mark for termination (Platform Owner action):**

1. `UPDATE platform.tenants SET status='terminated', terminated_at=NOW()`
2. Generate full backup: `mysqldump reya_tenant_NNNN > backups/terminations/tenant_NNNN_YYYY-MM-DD.sql`
3. Tar the file storage: `tar -czf backups/terminations/tenant_NNNN_files.tar.gz /var/reya/storage/tenant_NNNN/`
4. Send confirmation email to Tenant Owner: "ข้อมูลของท่านจะถูกลบใน 30 วัน
   หากต้องการคืนสภาพ กรุณาติดต่อภายในวันที่ YYYY-MM-DD"
5. Block all user access (same as suspension)

**Step 2 — Hard delete (cron job after 30 days):**

`cron/cleanup_terminated_tenants.php` runs daily. For each Tenant where
`terminated_at < NOW() - INTERVAL 30 DAY`:

1. Verify backup file exists and checksum is valid
2. `DROP DATABASE reya_tenant_NNNN`
3. `rm -rf /var/reya/storage/tenant_NNNN/`
4. `DELETE FROM platform.entitlements WHERE tenant_id=?`
5. Keep `platform.tenants` row but flip status to `'deleted'` + null out PII
6. Write final entry to `tenant_provisioning_log`

Backups retained for 1 year then offlined to cold storage.

## Consequences

### Positive

- **Reproducible provisioning** — no more half-built Tenants from manual
  inserts; failure = clean rollback.
- **Entitlement is the single chokepoint** for feature gating — easy to
  audit "who has what" with one SQL query.
- **Plan upgrades = bulk entitlement diff** — no migration needed when a
  Tenant moves Starter → Pro; just rewrite entitlement rows.
- **Suspension reversible without data loss** — payment problems don't
  destroy data; key for SaaS goodwill.
- **30-day termination grace** — defensible against accidental deletion and
  Thai PDPA "right to be forgotten" requests (we can prove deletion).
- **Platform Owner UI is the only path to expand limits** — matches the
  business decision to keep multi-branch / multi-channel manual.

### Negative

- **Entitlement check is now on every request hot path** — needs in-request
  cache and `entitlements.tenant_id` index (already in DDL above).
- **Plan changes are not automatic** — moving a Tenant from Starter → Pro
  requires Platform Owner to either rerun seed or edit entitlements. We
  accept this manual step at <50 Tenants.
- **`tenant_provisioning_log` will grow** — but only on provisioning events
  (~1/week max); cron prunes >2yr old success rows.
- **Welcome email contains temp credentials** — must use TLS SMTP and force
  password reset on first login. Implementation risk if a developer forgets
  the reset flag.

### Neutral / Tradeoffs accepted

- Provisioning is intentionally synchronous (CLI blocks 30s) — no async
  queue. At <50 Tenants this is fine. Reconsider if signup rate increases.
- Entitlement values stored as `VARCHAR(255)` not typed columns — app does
  casting. Tradeoff: schema flexibility vs. type safety. Chose flexibility
  because new keys land often.
- We do NOT store historical entitlement values — only current. Changes
  audit via `super_admin_audit` (ADR-006). Acceptable for now.

## Alternatives Considered

### Self-serve signup form on public site
Rejected: pharmacy SaaS sales cycle involves trust-building, demo, license
verification. Self-serve invites fraudulent signups. Stays Platform-Owner
gated.

### Stripe-driven entitlement (subscription webhook decides features)
Rejected for v1: REYA bills via bank transfer + manual invoice in Thailand;
no Stripe-equivalent integration in scope. Entitlement table is the
single source of truth; if billing is added later it writes here.

### Per-feature license keys (like Atlassian)
Rejected: overkill for <50 Tenants; license key management is its own
subsystem.

### "Soft delete" (set deleted_at, keep all data forever)
Rejected for terminated Tenants because Thai PDPA + storage cost; we DO
soft-delete (suspended) for non-payment, but final termination is a real
delete.

## Open questions

> Surfaced for the next grilling round — do NOT silently decide.

1. **Temp-password email — what channel?** SMTP to owner email is the
   plan, but Thai pharmacy owners often prefer LINE OA. Should the
   welcome message ALSO be sent to a designated LINE userId (collected
   during the sales call)?
2. **Plan downgrade behavior.** If a Pro Tenant has 3 Branches then
   downgrades to Starter (`max_branches=1`), what happens to Branches 2
   and 3? Soft-disable (read-only)? Force the owner to pick one to keep?
   Block the downgrade until they consolidate?
3. **`entitlements.expires_at` triggers what?** Cron flips back to plan
   default? Sends warning 7 days before? Silently expires (bad UX)?
4. **Tenant code reuse.** If Tenant 0042 is hard-deleted, can a new
   Tenant later be assigned code 0042? Audit trail wants NO (preserves
   meaning), DBA wants YES (short codes scarce). Default: NO, monotonic
   counter.
5. **Trial period mechanics.** Starter free for 30 days then auto-suspend?
   Or all paid from day 1 (sales-led)? Implications for cron job in
   ADR-004 (which Tenants to skip).
6. **Multi-tenant ownership.** Can one human be Tenant Owner of two
   separate Tenants (e.g. owns two pharmacies as separate legal entities)?
   Today the `platform_users.email` would conflict. Yes/no decision
   affects login flow.

## Related decisions

- **ADR-001:** Database-per-Tenant Isolation Model (foundation)
- **ADR-003:** Branch model — provisioning seeds Branch 1
- **ADR-004:** Cron jobs — must skip suspended/terminated Tenants
- **ADR-005:** File storage — provisioning creates `/uploads/tenant_NNNN/`
  directory tree; quota enforced by entitlement
- **ADR-006:** Super admin audit — every entitlement change writes audit row
