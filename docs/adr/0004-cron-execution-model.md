# ADR-004: Cron Job Execution Model (Per-Tenant Loop)

**Status:** Accepted (2026-05-25)
**Deciders:** Platform Owner + Engineering
**Supersedes:** Implicit "single DB, single execution" cron model

---

## Context

The codebase contains ~30 scheduled scripts in `cron/*.php`. They all assume
a **single shared database** — they call `Database::getInstance()`,
iterate rows in tables like `messages`, `transactions`, `appointments`,
and write side effects (LINE pushes, email, status flips).

Examples of what's there today:

- `cron/check_low_stock.php` — every hour, scan `business_items` for
  on-hand below reorder point, send LINE notification
- `cron/process_pending_broadcasts.php` — every 5 min, find queued
  broadcasts and send via LINE Messaging API
- `cron/send_appointment_reminders.php` — every 10 min, scan
  `appointments` for ones happening in the next 60 min
- `cron/odoo_sync_orders.php` — every 5 min, pull from Odoo into cache
  tables
- `cron/reconcile_payment_slips.php` — every 15 min, OCR queued slips

After ADR-001 (Database-per-Tenant), each of these crons must run against
**every active Tenant's database**, not a single DB. There are three
plausible models:

| Model | Description | Failure isolation | Throughput |
|-------|-------------|-------------------|------------|
| **A. Per-tenant loop** | Single cron process iterates Tenants serially | Per-Tenant try/catch isolates failures | O(N) sequential |
| **B. Fan-out parallel** | Cron spawns N parallel processes (one per Tenant) | OS process isolation | O(1) wall time, but O(N) resources |
| **C. Per-tenant cron schedule** | Each Tenant has its own crontab line | Native isolation, native scheduling | O(N) configured manually |

At the target scale (<50 Tenants) plus the shared MariaDB connection cap,
we need to pick deliberately. We also need to honor ADR-002 entitlements
(skip suspended Tenants, skip feature-disabled Tenants).

## Decision

Adopt **Model A — per-tenant loop**, wrapped by a single helper
`withEachTenant($callback)` shared by every cron script. Schedules in the
system crontab do NOT change; only the inner logic iterates Tenants.

### The `withEachTenant` helper

```php
namespace App\Platform;

class TenantIterator {
    /**
     * Iterate every Tenant matching $filter and invoke $callback inside its
     * tenant context. Per-Tenant exceptions are caught and logged but the
     * loop continues.
     *
     * @param callable(int $tenantId, array $tenantRow): void $callback
     * @param array $filter ['status' => 'active', 'entitlement_key' => 'allow_x']
     * @return array  ['ok' => N, 'failed' => M, 'skipped' => K]
     */
    public static function withEachTenant(
        callable $callback,
        array $filter = ['status' => 'active']
    ): array {
        $cronName = self::detectCallerScript();   // basename of caller
        $runId    = self::startRunLog($cronName);

        $tenants = self::loadTenants($filter);   // SELECT * FROM platform.tenants
        $stats   = ['ok' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant['id'];

            // Per-tenant lock — prevents overlap if previous run hasn't finished
            $lockKey = "cron:{$cronName}:tenant:{$tenantId}";
            if (!self::tryAcquireLock($lockKey, ttlSec: 600)) {
                $stats['skipped']++;
                self::logSkip($runId, $tenantId, 'lock_held');
                continue;
            }

            try {
                TenantContext::setCurrentTenantId($tenantId);
                $started = microtime(true);

                $callback($tenantId, $tenant);

                $stats['ok']++;
                self::logSuccess($runId, $tenantId, microtime(true) - $started);
            } catch (\Throwable $e) {
                $stats['failed']++;
                self::logFailure($runId, $tenantId, $e);
                self::alertIfCritical($cronName, $tenantId, $e);
                // Continue loop — do NOT rethrow
            } finally {
                TenantContext::reset();
                self::releaseLock($lockKey);
            }
        }

        self::finishRunLog($runId, $stats);
        return $stats;
    }
}
```

### Cron script pattern (the new shape)

Every cron becomes a thin wrapper:

```php
// cron/check_low_stock.php
require_once __DIR__ . '/../bootstrap.php';

use App\Platform\TenantIterator;
use App\Platform\Entitlement;

TenantIterator::withEachTenant(function(int $tenantId, array $tenant) {
    // Skip if Tenant doesn't pay for inventory module (example)
    if (!Entitlement::can($tenantId, 'allow_inventory')) return;

    // Now the body looks just like the old single-tenant script,
    // because Database::forTenant($tenantId) is implicitly active.
    $db = Database::current()->getConnection();
    $low = $db->query("SELECT * FROM business_items WHERE stock <= reorder_point")
              ->fetchAll();

    foreach ($low as $item) {
        LowStockNotifier::send($tenantId, $item);
    }
});
```

The body is **identical to today's single-tenant logic** — the wrapper does
all the tenant routing.

### Crontab itself does NOT change

```cron
*/5  * * * *   php /var/www/cron/process_pending_broadcasts.php
*/10 * * * *   php /var/www/cron/send_appointment_reminders.php
0    * * * *   php /var/www/cron/check_low_stock.php
*/5  * * * *   php /var/www/cron/odoo_sync_orders.php
*/15 * * * *   php /var/www/cron/reconcile_payment_slips.php
```

Each line still fires once per interval; the script now spends its
budget walking Tenants.

### Tables for run tracking

```sql
-- platform.cron_run_log
CREATE TABLE platform.cron_run_log (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  cron_name     VARCHAR(128) NOT NULL,
  started_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  finished_at   TIMESTAMP NULL,
  tenants_total INT UNSIGNED NOT NULL DEFAULT 0,
  tenants_ok    INT UNSIGNED NOT NULL DEFAULT 0,
  tenants_failed INT UNSIGNED NOT NULL DEFAULT 0,
  tenants_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms   INT UNSIGNED NULL,
  KEY (cron_name, started_at)
) ENGINE=InnoDB;

-- platform.cron_tenant_log
CREATE TABLE platform.cron_tenant_log (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  run_id        BIGINT UNSIGNED NOT NULL,
  tenant_id     INT UNSIGNED NOT NULL,
  status        ENUM('ok','failed','skipped') NOT NULL,
  reason        VARCHAR(255) NULL,
  duration_ms   INT UNSIGNED NULL,
  error         TEXT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY (run_id),
  KEY (tenant_id, status, created_at)
) ENGINE=InnoDB;
```

Both tables prune via a cleanup cron: success rows kept 30 days, failures
kept 1 year.

### Per-Tenant locking

`tryAcquireLock` uses MariaDB `GET_LOCK(<key>, 0)` (non-blocking, fails
immediately if held). Lock key includes both `cron_name` and `tenant_id` so
two different crons can run for the same Tenant in parallel (different
crons don't conflict with each other).

TTL is enforced by the lock's session lifetime — if the cron process dies
mid-run, the connection drop releases the lock automatically. A safety net:
if a lock has been held >10 min by a dead session, the cleanup cron force-
releases via `RELEASE_LOCK`.

### Error handling + alerting

- **Tenant failure → keep going.** Loop catches `\Throwable`, logs to
  `cron_tenant_log`, continues to next Tenant. One Tenant's
  bad data must not block the rest.
- **Critical errors → Telegram alert.** `alertIfCritical()` checks error
  class against a whitelist (DB connection lost, disk full, unique
  constraint violation in entitlement enforcement, etc.) and sends to the
  Platform Owner's Telegram channel via existing `NotificationRouter`.
- **Run summary alert.** If `tenants_failed > 0` after a run, ALSO send a
  summary: `"check_low_stock: 47 ok, 3 failed — tenants [0007, 0012,
  0033]"`. Daily digest if continuous.
- **Per-Tenant suppression.** If the same Tenant fails the same cron 3
  times in 24h, suppress further alerts until manually cleared (avoids
  pager fatigue).

### Status filtering (from ADR-002)

`loadTenants(['status' => 'active'])` is the default — `suspended`,
`terminated`, `provisioning` are all skipped. A cron can opt in to other
statuses if it has a reason to (e.g. termination-cleanup cron loads
`terminated`).

Entitlement-based skip happens inside the callback (the cron knows which
entitlement key matters); the iterator doesn't try to be smart about it.

### Long-running cron handling

A cron whose total runtime exceeds its schedule interval would normally
overlap. With per-Tenant locks AND per-run locks, we guard both:

- **Per-run lock** (`cron:<name>:run`): non-blocking, held for the whole
  run. If a previous run still holds it, the new run logs "skipped (prior
  run still running)" and exits. Prevents two concurrent invocations of
  the same cron from racing.
- **Per-Tenant lock** (inside the loop): protects against same-cron
  reentrancy on the same Tenant.

If we see consistent overlap on a cron, the runbook says either:

- Bump the schedule interval (acceptable at <50 Tenants if cron is hourly)
- Profile per-Tenant body for slow query → fix
- Split the cron into two scripts on different schedules
- Reconsider parallel fan-out (Model B) for that one cron only

## Consequences

### Positive

- **Identity-of-shape** between old crons and new — porting is mechanical:
  wrap body in `withEachTenant(function(...) { ... })`. Low cognitive load
  for the team.
- **Failure isolation** — one Tenant's bad row doesn't break the rest.
- **Single source of truth for cron observability** — every run goes through
  `platform.cron_run_log`, easy to build a Platform Owner dashboard.
- **Connection count stays bounded** — at any moment one cron holds one
  connection (current Tenant). With ~5 crons firing, that's ≤5 active
  cron connections vs. ≤50 with fan-out.
- **MariaDB connection cap of 500** (per ADR-001) easily accommodates this.
- **Locking is free** (MariaDB built-in, no Redis dependency for cron alone).

### Negative

- **Wall-time scales linearly with Tenant count.** At 50 Tenants × 2 sec
  per Tenant, a "5 min cron" becomes 100 sec. Below the 5-min window but
  the headroom shrinks. Mitigation thresholds:
  - <30 Tenants: comfortable
  - 30-50 Tenants: monitor closely, consider Model B for the slowest cron
  - >50 Tenants: ADR-004 supersession trigger
- **Cron logs grow** — `cron_tenant_log` could be ~1M rows/yr at 50
  Tenants × 30 crons × hourly. Prune policy handles it but disk impact
  exists.
- **A single hung Tenant can starve later Tenants in same run** — per-
  Tenant `set_time_limit(60)` inside iterator caps individual body time.
- **No native cross-Tenant transaction** — by design (ADR-001), but means
  a cron that needs to "reconcile across Tenants" has to be a Platform-
  level cron operating on `reya_platform` only.

### Neutral / Tradeoffs accepted

- We accept that cron observability lives in the master DB (cross-DB by
  design). The cron's *business effects* live in the Tenant DB.
- Lock contention is not anticipated at this scale — `GET_LOCK` is fast
  enough.
- Cron clock drift between Tenants is OK — within the same run, one
  Tenant's processing happens slightly later than another's. For hourly
  jobs that doesn't matter; for sub-minute jobs we'd revisit.

## Alternatives Considered

### Model B — fan-out parallel processes
Rejected for now:

- Process management cost (PHP-FPM-style worker pool not present)
- 50 Tenants × 30 crons could spike to 1500 active DB connections; that
  exceeds the 500 cap from ADR-001
- Failure aggregation becomes non-trivial (which child failed how)
- Returns to the table when we hit Tenant scaling pain (>50) OR when a
  single cron body exceeds its interval

### Model C — per-Tenant cron schedule
Rejected:

- Each Tenant having 30 crontab lines = 1500 lines at 50 Tenants
- Cron syntax is not transactional — adding a Tenant means editing crontab
- Operationally fragile (one syntax error breaks the whole file)
- No central observability without piping into a log aggregator

### Queue-based (Beanstalkd / Redis / SQS) work distribution
Rejected v1:

- Introduces a new infra dependency
- For <50 Tenants and <30 cron types, the queue overhead pays back nothing
- Worth reconsidering if we hit Model A's wall-time limits

### Move cron logic into per-Tenant systemd timers
Rejected: same drawbacks as Model C plus systemd config management.

### "Just fire one HTTP request per Tenant per cron, let nginx parallelise"
Rejected: HTTP timeouts, nginx worker exhaustion, hidden retries from
nginx-side errors. Cron should not flow through the web server.

## Open questions

> Surfaced for the next grilling round.

1. **Long-running cron split.** Today `cron/reconcile_payment_slips.php`
   can take >2 min per Tenant if OCR queue is large. At 50 Tenants that
   blows the 5-min window. Do we (a) split OCR into a dedicated cron with
   shorter Tenant timeout, (b) move OCR to a separate worker process,
   (c) fan-out only this one cron? Decide before Tenant 30.
2. **Cron alert routing.** Failures alert "the Platform Owner Telegram
   channel" — but is that the right granularity? Should a Tenant-specific
   failure also notify the Tenant Owner (e.g. "your appointment reminder
   cron failed")? Or only Platform Owner?
3. **What "critical" means.** `alertIfCritical()` whitelist is not yet
   defined. Need a curated list to avoid alert spam from expected errors
   (e.g. LINE API rate limit during peak hours).
4. **Provisioning-state Tenants.** A Tenant in `status='provisioning'`
   for more than 5 min is probably stuck. Should a cron detect & alert?
5. **Time-zone sensitivity.** All Tenants assumed `Asia/Bangkok`. If we
   ever serve a Tenant in another TZ, business-hours crons (e.g.
   broadcast send window) need to honor `tenants.timezone`. Defer until
   first non-Thai Tenant.
6. **Cron suspension per Tenant.** Today: suspended Tenants are skipped
   entirely. But what if Platform Owner suspends payment-related crons
   only for one Tenant (debugging)? Need a per-Tenant cron disable list?
   Or out of scope?
7. **Migration runner is also a "cron-like" iterator.** Should
   `database/migration_*.sql` runner reuse `TenantIterator`? Lean yes for
   consistency. Confirm.

## Related decisions

- **ADR-001:** Database-per-Tenant (defines the per-Tenant connection model)
- **ADR-002:** Entitlement filtering inside cron callbacks; suspended
  Tenant skip behavior
- **ADR-003:** Some crons may need per-Branch fan-out inside per-Tenant loop
  (e.g. low-stock alerts per Branch)
- **ADR-006:** Cron processes do not generate `super_admin_audit` rows —
  they run as system, not as a person
