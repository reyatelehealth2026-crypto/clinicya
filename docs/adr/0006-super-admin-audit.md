# ADR-006: Super Admin Cross-Tenant Access + Audit

**Status:** Accepted (2026-05-25)
**Deciders:** Platform Owner + Engineering
**Supersedes:** Implicit "super admin can do anything, nobody watches" model

---

## Context

ADR-001 specified that **only `role=super_admin` (Platform Owner) is
permitted to cross tenant boundaries**, and that every such access must
be audited. CONTEXT.md reinforces:

> **Platform Owner** = ทีม dev / เห็นข้าม Tenant ได้ แต่ทุกการเข้าถึง
> ข้าม Tenant ต้องถูกบันทึก audit log.

We need to spell out:

- **The mechanics of "switch into a Tenant"** — what's the UI, what
  happens to the session, how does the user exit
- **What constitutes "cross-tenant access"** that requires an audit row
  (just the switch, every query, every page load, every file read?)
- **The audit schema** — what columns, what indices, retention
- **The visual signal to the operator** — they must always know they are
  acting as Platform Owner inside a Tenant, not as themselves
- **Audit immutability** — super admin must not be able to delete their
  own audit trail
- **Reporting** — how do we make audit observable (monthly digest)

The threat model is two-headed:

1. **External compromise of a super admin account** — attacker uses the
   cross-tenant power to exfiltrate data. Audit gives forensics + alerts.
2. **Internal misuse** — a developer "just peeking" at a Tenant's data
   without business reason. Audit gives social pressure + compliance
   evidence under Thai PDPA.

We are also constrained by Thai data protection law guidance:
sensitive personal data access logs should be retained ≥2 years.

## Decision

Introduce a hard separation between **Platform Mode** (super admin sees
only `reya_platform`) and **Tenant Context Mode** (super admin has
explicitly switched into one Tenant's DB). Every transition and every
write performed in Tenant Context Mode is recorded in
`platform.super_admin_audit`. The UI makes the current mode visually
obvious and offers a one-click exit.

### Roles & permissions recap

- `super_admin` (Platform Owner) — can see master DB, can switch into any
  Tenant. **Cannot** access any Tenant DB without going through the
  explicit switch action.
- `admin` (Tenant Owner) — only sees own Tenant. No switch UI.
- `pharmacist`, `marketing`, `tech`, `staff` — same; scoped further by
  Branch (ADR-003).

### Session model

`$_SESSION` carries TWO Tenant references:

```php
$_SESSION['platform_user_id']   // who you really are (super admin)
$_SESSION['acting_tenant_id']   // which Tenant you're inside (or null)
$_SESSION['switch_audit_id']    // FK to the audit row for this switch
```

`TenantContext::currentTenantId()` returns `$_SESSION['acting_tenant_id']`
if set, falling back to the user's home Tenant for non-super-admins.

A super admin with no `acting_tenant_id` is in Platform Mode: every page
that requires a Tenant context redirects them to `/platform/switch-tenant`.

### Switch UI

`/platform/switch-tenant` — list view:

```
┌─ เลือก Tenant เพื่อเข้าดู ──────────────────────────────┐
│  Code   ชื่อร้าน                Plan       สถานะ       │
│  0001   คลินิกยา สาขาดอนเมือง   Pro        ●  active   │
│  0002   ร้านยาทดสอบ              Starter    ●  active   │
│  0007   ร้านยา XYZ                Pro        ●  suspended │
│                                                          │
│  [Switch into 0001] [Switch into 0002] [Switch into 0007] │
└────────────────────────────────────────────────────────────┘
```

Clicking "Switch into 0042" triggers `POST /platform/switch-tenant/0042`
with a required form field `reason` (free text, ≥10 characters). The
controller:

```php
public function switchInto(int $tenantId, string $reason) {
    $superAdminId = currentUserId();
    requireRole('super_admin');

    if (strlen(trim($reason)) < 10) {
        throw new \InvalidArgumentException("Reason ≥10 chars required");
    }

    $auditId = SuperAdminAudit::log([
        'super_admin_id' => $superAdminId,
        'tenant_id'      => $tenantId,
        'action'         => 'switch_in',
        'reason'         => $reason,
        'ip'             => $_SERVER['REMOTE_ADDR'],
        'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    $_SESSION['acting_tenant_id'] = $tenantId;
    $_SESSION['switch_audit_id']  = $auditId;
    redirect("/dashboard.php");   // now sees Tenant's dashboard
}
```

A red banner is then injected by `includes/header.php`:

```
╔═══════════════════════════════════════════════════════════════╗
║ ⚠  Viewing as: Platform Owner → Tenant 0042 (คลินิกยา ดอนเมือง) ║
║    Reason: "Investigating customer complaint ticket #1234"     ║
║    All actions are being logged for compliance.                ║
║                                          [exit tenant context] ║
╚═══════════════════════════════════════════════════════════════╝
```

The banner:

- Is `<div role="alert">` red background, 100% width, sticky top
- Cannot be CSS-hidden by any Tenant-level stylesheet (rendered in
  shadow DOM-equivalent: inline `style="all: revert"` wrapper + `!important`
  declarations)
- "Exit tenant context" → `POST /platform/exit-tenant` →
  unsets `acting_tenant_id`, writes `switch_out` audit row referencing
  the original `switch_audit_id`, redirects to `/platform/dashboard`

### What gets logged

**Always logged (write to `super_admin_audit`):**

| Event | When |
|-------|------|
| `switch_in`             | Super admin enters Tenant context |
| `switch_out`            | Super admin leaves Tenant context |
| `sql_write`             | Any INSERT/UPDATE/DELETE issued while in context |
| `file_read`             | Reading any file via `/api/file.php` while in context |
| `file_write`            | Writing any file via `StorageWriter::put()` while in context |
| `entitlement_change`    | Editing any entitlement (always written, even from Platform Mode) |
| `provisioning_action`   | Create/suspend/terminate Tenant |
| `audit_export`          | Super admin downloads audit log itself |

**NOT logged (would be too noisy + low value):**

- SELECT reads of Tenant data (volume too high; instead we log the
  `switch_in` reason which gives intent)
- Page loads (covered by web-server access log)
- Auth events that happen in Platform Mode (covered by platform auth log)

### Audit schema

```sql
CREATE TABLE platform.super_admin_audit (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  super_admin_id  INT UNSIGNED NOT NULL,
  tenant_id       INT UNSIGNED NULL,  -- null for Platform-Mode actions
  action          VARCHAR(64) NOT NULL,
  -- One of: switch_in, switch_out, sql_write, file_read,
  --         file_write, entitlement_change, provisioning_action,
  --         audit_export
  reason          TEXT NULL,   -- required at switch_in, optional elsewhere
  target_table    VARCHAR(128) NULL,    -- for sql_write
  target_id       VARCHAR(64) NULL,     -- pk of affected row
  target_path     TEXT NULL,            -- for file_*  events
  sql_redacted    TEXT NULL,            -- query w/ PII fields masked
  metadata        JSON NULL,            -- structured per-action context
  ip              VARCHAR(45) NOT NULL,
  user_agent      VARCHAR(255) NOT NULL DEFAULT '',
  created_at      TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_admin_time (super_admin_id, created_at),
  KEY idx_tenant_time (tenant_id, created_at),
  KEY idx_action_time (action, created_at)
) ENGINE=InnoDB;
```

**Hash chain (tamper detection — optional but recommended):**

Each row also stores `prev_hash CHAR(64)` and
`row_hash CHAR(64) = sha256(prev_hash || JSON(row_without_hash))`.
The latest hash is mirrored to a write-once location (env var rotated
weekly, optionally a separate database). Mismatch = tampering detected.

### Implementation hook points

A single middleware sits in `TenantContext::setCurrentTenantId()`:

```php
public static function setCurrentTenantId(int $tenantId): void {
    self::$current = $tenantId;
    Database::switchToTenant($tenantId);

    // Super-admin-acting-as-tenant flag — checked once per request
    if (self::isActingAsSuperAdmin()) {
        AuditingPDO::enable();   // wraps PDO writes to log sql_write
        AuditingStorage::enable(); // wraps StorageWriter/Reader
    }
}
```

`AuditingPDO` is a thin decorator that intercepts `exec()`, `prepare()`+
`execute()`, and `query()`; on writes it inserts an audit row with the
SQL (PII-masked, see open question) and affected table/id when derivable.

### UI requirements (recap)

- **Banner** described above; present on every page render in Tenant
  Context Mode
- **Exit button** always visible in the banner
- **Switch action requires reason ≥10 chars** — enforces intent
- **Recent switches widget** on `/platform/dashboard` — shows last 10
  switches across all super admins (peer visibility)
- **Per-tenant access log** at `/platform/tenants/{id}/audit` —
  paginated view of all super_admin_audit rows for that Tenant
- **Super-admin self-audit** at `/platform/me/audit` — own activity

### Audit deletion

Super admins **cannot delete** their own audit rows. App enforcement:

- No UI control to delete
- API rejects `DELETE` on `platform.super_admin_audit` via super-admin role
- DB-level: a separate MariaDB user (`platform_audit_purger`) is the only
  account with `DELETE` privilege on this table; that user's password is
  held by a designated Platform Owner (not the dev team) and is rotated
  yearly. Purge tooling requires entering that password live.

**Legitimate purge:**

- Cron `cron/prune_audit.php` does NOT purge audit (different from other
  cron logs)
- Manual purge requires:
  1. Platform Owner login on a dedicated `/platform/audit-purge` endpoint
  2. Two-step confirmation (email link with second token to confirm)
  3. Date range only (`WHERE created_at < ?`); no targeted deletion
  4. Default minimum age 2 years from today
  5. Purge action itself is logged into a separate `audit_purge_log` table

### Retention

- **Minimum 2 years** (Thai PDPA guidance for sensitive data access logs)
- **Default 5 years** for failure / unusual-action rows
  (`switch_in_outside_hours`, `bulk_export`, etc.)
- Index `created_at` and partition by month if rows exceed 10M

### Monthly transparency report

Cron `cron/send_audit_digest.php` runs 1st of each month:

```
For each super_admin_id:
  Generate per-admin activity summary:
    - Total switches: N
    - Tenants accessed: [list with counts]
    - Most-touched tables: [top 5]
    - File reads: count by sensitivity bucket
    - Anomalies: switches outside 09:00-22:00, switches >50/day,
                 switches without reason text quality
Send digest to ALL super_admins (peer visibility)
Send aggregate to Platform Owner contact
```

This is the social-pressure mechanism: every super admin sees every
other super admin's activity summary. Not a punitive mechanism — a
"we are all watching" mechanism that matters more than tooling alone.

## Consequences

### Positive

- **Compliance story is simple** — "every cross-tenant action is logged,
  retained 2yr+, immutable by the actor."
- **Banner removes the most common mistake** — "I forgot I was in Tenant
  context and did something destructive in the wrong Tenant."
- **Monthly digest creates honesty equilibrium** — even without a
  manager, super admins moderate each other.
- **Hash chain detects after-the-fact tampering** — even if attacker gets
  DB admin, log integrity is verifiable.
- **Reason requirement at switch time** — forces a written-down intent;
  catches "just curious" peeks before they happen.

### Negative

- **Every write in Tenant Context = audit row insert** — at peak (super
  admin debugging) could be hundreds of inserts/min. Mitigation: batched
  insert with `commit` on request end.
- **Banner is visually intrusive** — that's the point, but it makes
  super-admin sessions feel heavier. Accepted tradeoff.
- **Audit table will grow** — at ~10k rows/super-admin/year × 5 admins
  = 50k rows/yr; trivial. But if any bulk action (export 1M rows) lands
  in audit verbatim, size could spike. Open question on what to log for
  bulk operations.
- **`AuditingPDO` decorator adds latency** — ~0.5ms/write at p50;
  acceptable for admin tooling, not for hot user paths (super admin not
  expected to be on user paths).
- **DB-level audit purger requires human ceremony** — friction is the
  point. Operationally slower.

### Neutral / Tradeoffs accepted

- We do NOT log SELECT reads in Tenant Context. Volume too high; we
  rely on the `switch_in` reason field as the proxy for intent.
- The "10 character reason" minimum is arbitrary; can be tuned without
  schema change. Aim is to defeat reflexive "asdf" inputs.
- Hash chain is described as "recommended"; if ops cost is too high in
  v1 we ship without it and add later via migration that rehashes from
  the beginning.

## Alternatives Considered

### Generic application audit log (every user action by every role)
Rejected for ADR-006 scope: too broad to ship in one go. Different ADR
later for Tenant-internal action logging. Super admin audit ships first
because it's the highest-leverage compliance evidence.

### "Break glass" mode (super admin must request access per session,
peer-approved before they can enter Tenant)
Rejected v1: at <50 Tenants and a small dev team, peer-approval flow
creates ops friction without proportional benefit. Reconsider if team
grows beyond 5 super admins.

### Hide super admin existence from Tenant Owner entirely
Rejected: Thai PDPA gives data subjects the right to know who has accessed
their data. A Tenant Owner who asks "did REYA staff see my customers'
data?" must get a truthful answer. The `/platform/tenants/{id}/audit`
view should be optionally surfaced to the Tenant Owner on request.

### Use SaaS audit-logging vendor (Datadog, Auth0 Logs, etc.)
Rejected: PII export to external vendor itself becomes a PDPA question.
Self-hosted in master DB is simpler and audit-of-the-auditor stays in
our control.

### Mask the Tenant context entirely from super admins (proxy through
read-only views)
Rejected: investigating live customer issues requires writes (apply a
fix, reset a password). A read-only audit-superadmin role could be added
as a later refinement, but the primary super admin needs writes.

## Open questions

> Surfaced for the next grilling round.

1. **PII redaction in `sql_redacted`.** A SQL like
   `UPDATE customers SET phone='0812345678' WHERE name='สมชาย'` would
   land verbatim in audit and itself become PII. Need a mask
   transformation: replace string literals in SQL with `'***'` before
   logging. But "phone='***'" loses the forensic detail of "what was
   changed to what." Tradeoff to resolve: full SQL (PII risk) vs. masked
   SQL (lossy audit). Possibly: full SQL encrypted with a key only the
   Platform Owner has, separate from the super admin's own access.
2. **Search query in audit metadata.** If super admin types "ค้นหา
   customers ที่ชื่อสมชาย" the search query string includes "สมชาย".
   Logging it is helpful for "why did you look this up" but the search
   string is itself customer PII. Mask? Hash?
3. **`switch_out` timeout.** If a super admin closes the tab without
   clicking "exit", `acting_tenant_id` lives until session timeout (30
   min default). Should auto-expire after 15 min of inactivity? Force
   re-justify? Tradeoff: friction vs. forgotten-session leak.
4. **Banner visibility on print / PDF export.** If super admin "Print
   page", banner must be in the print stylesheet too — otherwise printed
   evidence shows no warning. Confirm requirement.
5. **Hash chain in v1 or v2.** Cost/benefit: cheap to implement (one
   trigger), but rotating the head-hash storage and rebuilding chain
   after fix-ups is ops overhead. Defer or ship?
6. **Tenant Owner visibility into super admin audit.** Should we give
   the Tenant Owner a self-serve report "who from REYA touched my data
   last month"? Aligns with PDPA but increases support burden. Yes/no.
7. **Audit for `agentic-flow` automated agents.** If a swarm agent
   running under Platform Owner credentials touches Tenant data, it
   should appear in audit. But which `super_admin_id` does it attribute
   to? A synthetic `agent_id`? Needs design.
8. **Multi-Tenant super admin actions.** A platform-wide migration
   touches every Tenant. Do we write N audit rows (one per Tenant) or
   one row with a `tenant_id_set` JSON list? N rows = easier per-Tenant
   query; one row = less noise. Lean toward N.
9. **Outside-hours alerting.** Should a `switch_in` at 03:00 trigger an
   immediate Telegram alert to peer super admins? Useful for compromise
   detection; annoying if a dev is just working late.
10. **Audit log of the Platform Owner who runs the purge.** The
    `audit_purge_log` is separate, but who watches the watchers? Likely
    "it lives forever and only the founder can delete" — confirm.

## Related decisions

- **ADR-001:** Platform-vs-Tenant DB split makes audit storage easy
- **ADR-002:** Entitlement edits are recorded here; provisioning actions
  are recorded here
- **ADR-003:** Branch-level operations are audited inside the
  `metadata.branch_id` field
- **ADR-004:** Cron jobs run as system, NOT as super admin; do not
  generate audit rows
- **ADR-005:** File reads via `/api/file.php` generate `file_read` audit
  rows when caller is super admin acting in Tenant context
