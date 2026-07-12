# Phase 0 — PHP prod → Docker VPS replatform: cutover + rollback runbook

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` §2 (numbered
items 1–7 below map 1:1 onto that section) and §1.5 (strangler edge). Owner:
mig-infra (build) / mig-orchestrator (go/no-go on each checkbox). This document
is a **checklist**, not prose — every leaf item is something a person or CI job
can literally check off.

## Scope note (read first)

This runbook was authored inside a container with **no VPS, DNS, or live
production/tenant-DB access**. Every artifact it references (`infra/php/Dockerfile`,
`infra/compose/docker-compose.strangler.yml`, `infra/nginx/*`) was built and
verified **locally** (`docker build`, `docker compose config`, `docker compose up`
against throwaway containers, `nginx -t`) — see the "Local verification evidence"
callouts under each item. Nothing here has touched production. Items marked
**[REAL-INFRA]** cannot be executed or rehearsed until mig-orchestrator hands this
runbook to an agent/operator with actual VPS + DNS + production-DB-read access —
they are written as literal step-by-step instructions for that person, not as
something already done.

---

## 0. Pre-flight

- [ ] Confirm target VPS sizing (CPU/RAM/disk) covers: PHP+Apache container,
      MariaDB with the full master + all tenant DBs, Redis, cron sidecar,
      nginx edge, uploads volume. **[REAL-INFRA]**
- [ ] Confirm `docker` + `docker compose` (v2 plugin) installed on the VPS.
      **[REAL-INFRA]**
- [ ] Pull this branch onto the VPS; `docker build -f infra/php/Dockerfile .`
      succeeds there too (same Dockerfile verified locally in this repo — see
      item 1).
- [ ] Freeze schema changes to `database/*.sql` for the duration of the
      rehearsal window (avoid rehearsing against a schema that's stale by the
      time of the real cutover).

---

## 1. PHP Dockerfile + session strategy (plan §2 item 1)

Artifacts: `infra/php/Dockerfile`, `infra/php/apache-vhost.conf`,
`infra/php/php.ini`, `infra/php/session-redis.ini`,
`infra/php/session-redis-handler.php`.

- [x] Base image `php:8.2-apache` (composer.json requires `php: >=8.0` +
      `predis/predis: ^2.2`, satisfied).
- [x] Extensions installed: `pdo_mysql`, `gd` (`--with-freetype --with-jpeg`),
      `curl`, `mbstring`, `zip`, `opcache`. All compiled from the PHP source
      tarball already baked into the base image — **no PECL/packagist network
      access required at build time** (important: this env's egress proxy
      TLS-intercepts and breaks `pecl install` / `composer install` run
      *inside* a `docker build` — confirmed empirically; see item 1's
      verification note below).
  - Local verification: `docker build -f infra/php/Dockerfile . && docker run
    --rm <image> php -m` lists `pdo_mysql gd curl mbstring zip` +
    `Zend OPcache`. Ran clean in this container.
- [x] `AllowOverride All` set (`infra/php/apache-vhost.conf`, `<Directory
      /var/www/html>`) so the existing repo-root `.htaccess` — clean URLs,
      `liff-*.php` redirects, debug-script blocking, security headers,
      mod_php ini overrides — works **completely unmodified**.
  - Local verification: bind-mounted a docroot with a bare `.htaccess`
    rewrite rule, `curl`'d the clean URL, got the rewritten `hello.php`
    response (200, correct body) — confirms mod_rewrite + AllowOverride are
    live, not just configtest-clean.
- [x] PHP sessions → Redis, container stays stateless. Implemented via a
      **Predis-backed** `SessionHandlerInterface`
      (`infra/php/session-redis-handler.php`), wired with `auto_prepend_file`
      (`infra/php/session-redis.ini`) — **not** the PECL `redis` extension
      (that needs a second compiled extension + PECL network access this
      environment can't reach; predis is already a composer dependency per
      plan §2.1 and needs neither).
  - Fails open: if `vendor/autoload.php` isn't mounted yet or Redis is
    unreachable, the handler no-ops and PHP quietly falls back to file
    sessions instead of fataling every request — verified by running without
    a mounted `vendor/` (falls back cleanly, `session.save_handler=files`)
    and separately with a real `predis/predis` install + a live `redis:7-alpine`
    container (two sequential HTTP requests through the same cookie showed
    `hits=1` → `hits=2`, and `redis-cli -n 1 KEYS '*'` showed the session key
    living in Redis, not on local disk).
- [ ] **[REAL-INFRA]** Update the real cPanel `crontab -l` export gathered
      for item 5 to also confirm no cron job assumes local PHP session files
      exist on disk (none should, per CLAUDE.md's "CLI/cron" contract, but
      verify against the real job list, not just the 33 filenames known
      here).
- [ ] **[DECISION NEEDED from mig-orchestrator]** `config/config.php` hardcodes
      `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` as literal `define()`s — **not**
      `getenv()`-driven. `DB_HOST='localhost'` makes PDO's mysql driver open a
      **unix socket**, not TCP, regardless of any `DB_HOST`-shaped env var a
      container sets. Two ways to reach the new `mariadb` container without
      touching this file (out of scope for mig-infra — "do not change PHP
      application code beyond the config-gated `strategy=mysql` switch"):
      1. **(used by `infra/compose/docker-compose.strangler.yml`, verified
         locally)** Share a `/run/mysqld` volume between the `php`/`cron`
         services and `mariadb`, and point `pdo_mysql.default_socket` /
         `mysqli.default_socket` (`infra/php/php.ini`) at the socket file
         MariaDB creates there. Zero PHP changes. Only works when PHP and
         MariaDB share a Docker volume (i.e., MariaDB must run on the same
         VPS/host as the PHP container, which Phase 0 already assumes).
      2. Give `config/config.php` an env-var override (e.g.
         `define('DB_HOST', getenv('DB_HOST') ?: 'localhost');`) — a real,
         if small, PHP source change; needs explicit mig-orchestrator sign-off
         since it's outside the one allowed `strategy=mysql` edit.
      Runbook assumes path 1 (already wired in the compose overlay) unless
      mig-orchestrator says otherwise.
  - Local verification: with path 1 wired, a MariaDB user/DB seeded to match
    `config.php`'s hardcoded DB_NAME/DB_USER/DB_PASS (values live only in the
    untracked `config/config.php` + `infra/compose/.env` — never write them
    into tracked files), and the **real repo bind-mounted** into the `php`
    container (`docker compose -f docker-compose.dev.yml -f
    infra/compose/docker-compose.strangler.yml up`), `curl`ing the container
    rendered the actual production landing page (`<title>LINE
    Telepharmacy</title>`, 200 OK, ~50KB HTML) — end-to-end PHP → Apache →
    MariaDB → (degraded-to-file) session, zero PHP source changes. The one
    logged warning was a missing table (`landing_featured_products`) because
    no schema was imported in this test — expected, not a connectivity bug.
- [ ] **[GAP FOUND — needs a decision, not a fix from mig-infra]**
      `composer.lock` in this repo is **stale relative to `composer.json`**:
      it predates `predis/predis`, `phpstan/phpstan`, and
      `friendsofphp/php-cs-fixer` being added to `require`/`require-dev`.
      `composer install` today does **not** install `predis/predis`, so the
      Redis session handler silently falls back to file sessions on a fresh
      checkout until someone runs `composer update predis/predis` (or a full
      `composer update`) to refresh the lock file. `composer.lock` is outside
      `infra/**`/`docker/**`/`docs/runbooks/**` (mig-infra's allowed paths) —
      flagging for mig-orchestrator / whichever agent owns `composer.lock`.

---

## 2. MariaDB 10.11 import (plan §2 item 2)

Artifact: `infra/compose/docker-compose.strangler.yml` service `mariadb`
(`mariadb:10.11`, distinct from the dev-compose `mysql:8.0` service).

- [x] `mariadb:10.11` (not `mysql:8.0`) — the cPanel source dump uses
      MariaDB's `json_valid()` CHECK-constraint idiom, which `mysql:8.0`
      doesn't accept identically.
- [x] Binlog on **from first boot**: `--log-bin=mysql-bin --binlog-format=ROW
      --server-id=1 --binlog-expire-logs-seconds=604800` (7-day retention —
      widen if the rollback window needs to be longer than that).
  - Local verification: `SHOW VARIABLES LIKE 'log_bin'` → `ON`,
    `SHOW VARIABLES LIKE 'server_id'` → `1`, `SELECT @@version` →
    `10.11.18-MariaDB`, on a freshly `docker compose up`'d container.
- [x] `utf8mb4`/`utf8mb4_unicode_ci`, `default-time-zone=+07:00`,
      `sql-mode=STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO`
      — matches the existing `mysql` dev service's flags so behavior parity
      holds across both.
- [ ] **[REAL-INFRA] Rehearsal #1** (required — plan says "ซ้อมนำเข้า 2 รอบ"):
  - [ ] `mysqldump --single-transaction --routines --triggers --events
        --hex-blob <master_db> > master.sql` on the cPanel source (there are
        no triggers/stored procedures per the plan's survey, but keep the
        flags for safety/future-proofing).
  - [ ] `mysqldump --single-transaction --hex-blob <tenant_db>` per tenant DB
        (loop every `reya_tenant_*` schema — get the list from
        `master.tenants`, not a filesystem glob).
  - [ ] Import all dumps into the fresh `mariadb` container:
        `docker compose exec -T mariadb mysql -u root -p"$MARIADB_ROOT_PASSWORD"
        < master.sql` (repeat per tenant dump).
  - [ ] Row-count / checksum diff each imported DB against the source
        (`CHECKSUM TABLE` per table, or a row-count sweep over
        `information_schema.tables`) — zero diffs is the pass bar.
  - [ ] Record: total dump size, total import wall-clock time, any warnings
        emitted by `mysqldump`/`mysql` (character set coercions, etc.).
  - [ ] Time-box: this determines the write-freeze window length in item 6 —
        write the measured number into item 6's checklist before the real
        cutover.
- [ ] **[REAL-INFRA] Rehearsal #2** — repeat rehearsal #1 in full, on a
      **freshly wiped** `mariadb` volume, using a **second, more recent**
      source dump (catches drift the first rehearsal's dump missed, and
      confirms the import procedure itself — not just the first dump — is
      reproducible). Record the same metrics as rehearsal #1 and diff them
      against rehearsal #1's numbers (import time should be stable; any
      large delta needs an explanation before the real cutover).
- [ ] **[REAL-INFRA]** After both rehearsals pass, confirm `tenant_migrations`
      ledger rows (per CLAUDE.md: "Incremental changes go in
      `database/migration_*.sql`") in the imported master DB match what's
      expected for the `HEAD` of this branch — the imported DB shouldn't be
      missing a migration that's already committed to git, nor contain one
      that isn't.

---

## 3. Uploads rsync (plan §2 item 3)

- [ ] **[REAL-INFRA]** `rsync -avz --progress
      zrismpsz@<cpanel-host>:/home/zrismpsz/public_html/uploads/
      <vps>:/path/to/php_uploads_phase0-volume/` (or directly into the named
      Docker volume's mount point) — **preserve owner/group/perm bits**
      (`-a` already implies `-p`/`-o`/`-g`; add `--numeric-ids` if
      cPanel-side UID/GID won't exist on the VPS and you'd rather remap them
      explicitly afterward instead of rsync failing to chown).
- [ ] **[REAL-INFRA]** Confirm the public/private bucket permission split
      survived the rsync, per `classes/TenantFileStorage.php`:
      - Public buckets (`logos`, `shop_photos`): dirs `0711`, files `0644`.
      - Every other bucket (`slips`, `products`, `exports`, `rx_uploads`,
        `profile_pics`): dirs `0750`, files `0640`.
      - Tenant root dirs: `0711`.
      `find <uploads-root> -type d \( -name logos -o -name shop_photos \)
      ! -perm 0711` and the file-mode equivalent should both return nothing;
      same check inverted for the private buckets.
- [ ] **[REAL-INFRA]** Confirm the Apache worker user inside the `php`
      container (`www-data`) can actually read/write these paths post-rsync
      — this is the exact failure mode `TenantFileStorage.php`'s own comment
      warns about ("files written at the default 0640/0750 are unreadable by
      that worker and 404 even though they exist on disk" — that comment
      describes a *different* host's suexec quirk, but re-verify the new
      container's UID mapping doesn't reintroduce an equivalent problem).
  - Local note: `infra/compose/docker-compose.strangler.yml` mounts
    `php_uploads_phase0` at `/var/www/html/uploads` as a **separate** named
    volume (not part of the `.:/var/www/html` code bind mount) specifically
    so this rsync target can be swapped for a real persistent
    volume/NFS mount at cutover time without touching the compose file's
    shape — only the volume driver/mount source changes.

---

## 4. Provisioning: `strategy=mysql` (plan §2 item 4 — the one PHP code change)

- [ ] **[OUT OF SCOPE for mig-infra, flagged for the owning agent]**
      `classes/TenantProvisioning.php` currently only supports cPanel's
      `uapi Mysql create_database` / `set_privileges_on_database` shell-out
      (`UAPI_BIN = '/usr/bin/uapi'`) — confirmed by reading the file; there is
      **no `strategy=mysql` branch yet**. Per plan §2 item 4, this is *the*
      PHP code change allowed in Phase 0 (config-gated), needed because the
      VPS user won't have `uapi` and instead gets a privileged MySQL user
      that can run `CREATE DATABASE`/`GRANT` directly. mig-infra's mandate is
      explicitly config/infra only ("Do not: change PHP application code
      beyond the config-gated provisioning `strategy=mysql` switch" — i.e.
      that switch belongs to whoever *does* touch PHP for Phase 0, not
      automatically to mig-infra). This checklist item exists so the gap is
      visible; implementing it is a separate, explicitly-scoped task.
- [ ] **[REAL-INFRA]** Once implemented: provision a throwaway tenant through
      `strategy=mysql` end-to-end (`CREATE DATABASE` + template applied +
      `master.tenants` row inserted), confirm the new tenant subdomain
      resolves (`bootstrap/resolve_subdomain.php`) and a login works, then
      deprovision it the same way.

---

## 5. Cron sidecar — 33 jobs (plan §2 item 5)

Artifacts: `infra/php/crontab` (baked into the image at
`/etc/cron.d/clinicya-cron`), `infra/compose/docker-compose.strangler.yml`
service `cron` (same image as `php`, command overridden to `cron -f`).

- [x] All 33 files under `cron/*.php` have an entry
      (`grep -c 'php /var/www/html/cron/' infra/php/crontab` → 33; matches
      `find cron -maxdepth 1 -name '*.php' | wc -l` → 33 in this repo).
- [x] Sidecar mechanism verified end-to-end locally: started the `cron`
      command from the built image, waited for the `* * * * *` jobs to fire,
      confirmed (a) cron read `/etc/cron.d/clinicya-cron` without a parse
      error, (b) jobs ran **as `www-data`** (log file ownership), (c) output
      was redirected to the correct per-job log file
      (`/var/log/clinicya-cron/<job>.log`), (d) PHP itself invoked correctly
      (the only failure in this isolated test was "could not open input
      file" because that specific smoke test didn't bind-mount the repo —
      expected; the compose service does mount it).
- [ ] **[CRITICAL — REAL-INFRA, rehearsal blocker]** Every schedule in
      `infra/php/crontab` is **inferred from job semantics/filenames, not the
      real cPanel crontab** — this container has no access to that crontab,
      and `docs/ai/background-jobs.md` itself says "Real scheduler entries
      were not inspected." **Before the real cutover**, run `crontab -l` on
      the cPanel account that owns these jobs and replace
      `infra/php/crontab` wholesale with that output (translated from
      "single crontab line" to `/etc/cron.d` format — remember `/etc/cron.d`
      entries need the extra `www-data` user column cPanel's own crontab
      won't have).
- [ ] **[REAL-INFRA]** Re-run the "33 jobs ran on schedule" check against the
      **real** schedule for at least one full cycle of the least-frequent job
      (if any job is weekly/monthly, either wait a cycle or manually
      `docker compose exec cron php cron/<job>.php` it once and confirm no
      fatal error).
- [ ] **[MINOR — worth reconciling, not blocking]** Only 2 of the 33
      `cron/*.php` files (`adherence_reminder.php`, `reorder_reminder.php`)
      actually `define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true)` before
      requiring `config/database.php`, even though CLAUDE.md's "CLI / cron"
      contract says every cron/CLI script must. In practice this looks
      harmless — `bootstrap/resolve_subdomain.php` reads
      `$_SERVER['HTTP_HOST'] ?? ''`, which is empty under CLI, and the
      resolver's empty-host path doesn't crash — but it's an unverified
      assumption from reading the code, not something exercised against a
      real multi-tenant DB in this container. Worth a real-schema smoke run
      before the cutover, not a mig-infra fix (touches 29 files outside
      `infra/**`).
- [ ] **[REAL-INFRA]** Confirm the cron sidecar and the `php` web service
      never double-run the same job during the coexistence window with the
      **old** cPanel cron (which is presumably still enabled until DNS
      cutover in item 6) — either disable the cPanel crontab the moment the
      `cron` sidecar goes live, or accept brief double-execution for
      idempotent jobs only (check each job's idempotency before deciding;
      several — `webhook_retry_processor.php`, e.g. — are explicitly
      lock-file guarded per `docs/ai/background-jobs.md`, most others are
      not verified either way here).

---

## 6. Cutover (plan §2 item 6)

**[REAL-INFRA — every item below]**

- [ ] Lower DNS TTL for every `*.re-ya.com` hostname well ahead of the
      cutover window (24–48h, so the shortened TTL itself has propagated
      before cutover starts).
- [ ] Announce + start the write-freeze at a measured low-traffic window
      (check `webhook_events` insert-rate history to pick the quietest
      recurring window, not a guess).
- [ ] During the freeze: application-level read-only mode (or literal
      `REVOKE INSERT, UPDATE, DELETE` on the cPanel DB user, if a read-only
      app mode doesn't exist) — confirm with a live write attempt that it's
      actually blocked before proceeding.
- [ ] Final `mysqldump` (same flags as item 2's rehearsals) of master + every
      tenant DB, taken *after* the freeze is confirmed active.
- [ ] Final `rsync` of `uploads/` (item 3's command, delta-only against the
      copy already rsynced during rehearsal — should be fast).
- [ ] Import the final dump into the VPS `mariadb` container; re-run the
      row-count/checksum diff from item 2's rehearsal steps.
- [ ] Smoke-test the VPS stack fully **before** flipping DNS: hit every
      `tenant-XXXX.re-ya.com` hostname via `curl --resolve
      tenant-XXXX.re-ya.com:443:<vps-ip> https://tenant-XXXX.re-ya.com/` (or
      an equivalent hosts-file override) and confirm 200s + a login works
      for at least one real tenant.
- [ ] Flip the wildcard `*.re-ya.com` A record to the VPS IP. **Hostnames
      are unchanged** — this is the whole point of keeping subdomain
      routing identical, so **no LINE/FB/TikTok/Telegram console change is
      needed** (per plan; do not touch those consoles as part of this
      cutover).
- [ ] TLS: issue the wildcard cert via DNS-01 (works before the A record
      flip propagates everywhere, since DNS-01 only needs the TXT record,
      not the A record, to be resolvable by the ACME validator).
- [ ] End freeze once DNS has propagated and the VPS is confirmed serving
      real traffic (check nginx/Apache access logs for real client IPs, not
      just synthetic checks).
- [ ] Re-enable the (real, reconciled — see item 5) cron sidecar; disable the
      old cPanel crontab in the same change.
- [ ] Watch `webhook_events` insert rate for at least one full day-cycle;
      compare against the pre-cutover baseline (plan's Phase 0 acceptance
      line: "อัตรา insert webhook_events เท่า baseline").

---

## 7. Rollback (plan §2 item 7)

- [x] **Mechanism decided and available from day one**: binlog is on from
      the MariaDB container's first boot (item 2), so any writes that land
      on the VPS after cutover can be replayed onto the old cPanel DB if
      rollback is needed (`mysqlbinlog --start-datetime=... | mysql`, scoped
      per schema).
- [ ] **[REAL-INFRA]** Keep the cPanel host **read-only, not decommissioned,
      for 30 days** post-cutover (per plan) — this is what makes rollback
      possible at all; do not let anyone delete/deprovision the cPanel
      account inside that window.
- [ ] **[REAL-INFRA] Rollback rehearsal (explicit, required — not optional)**:
      before the real cutover, on a throwaway copy of both environments:
      1. Cut over as in item 6 (throwaway DNS or hosts-file override is
         fine for the rehearsal — don't touch real DNS for this drill).
      2. Let a small amount of synthetic write traffic land on the VPS
         MariaDB post-"cutover" (a few inserts into a low-risk table is
         enough to prove the mechanism, not a full traffic replay).
      3. Extract the binlog segment covering that window
         (`mysqlbinlog --start-datetime`/`--stop-datetime` or
         `--start-position`/`--stop-position` off `SHOW BINLOG EVENTS`).
      4. Flip DNS back (or hosts-file back, for the rehearsal).
      5. Replay the extracted binlog segment onto the old (cPanel-side, or a
         throwaway copy of it) DB.
      6. Row-for-row diff the replayed writes against what actually landed
         on the VPS — zero diff is the pass bar.
      7. Record wall-clock time for steps 3–6 — this is the real rollback
         RTO estimate mig-orchestrator needs for the go/no-go call on the
         real cutover.
- [ ] **[REAL-INFRA]** Document the rehearsal's measured RTO + any manual
      steps that couldn't be scripted, in this file, before the real cutover
      is scheduled.

---

## Local artifact index (everything checkable without VPS/DNS/prod access)

| Artifact | Purpose | Local check that passed |
|---|---|---|
| `infra/php/Dockerfile` | PHP 8.2 + Apache replatform image | `docker build -f infra/php/Dockerfile .` + `php -m` shows all 6 required extensions |
| `infra/php/apache-vhost.conf` | `AllowOverride All` | Live `.htaccess` clean-URL rewrite through the built image |
| `infra/php/php.ini` | mod_php parity + opcache + PDO socket | `php -i` shows correct `memory_limit`/`date.timezone`/`pdo_mysql.default_socket` |
| `infra/php/session-redis.ini` + `session-redis-handler.php` | Stateless Redis sessions | Two-request cookie test against a real `redis:7-alpine` container showed session persisted in Redis DB 1, not on disk |
| `infra/php/crontab` | 33-job cron sidecar | Per-minute jobs fired on schedule as `www-data`, correct log redirection |
| `infra/compose/docker-compose.strangler.yml` | Additive overlay: `php`, `cron`, `mariadb`, `nginx-edge` | `docker compose -f docker-compose.dev.yml -f infra/compose/docker-compose.strangler.yml config` validates; base compose files byte-identical (`git diff` empty); full stack brought up locally and served the real landing page end-to-end |
| `infra/nginx/routes.json` + `routes.schema.json` + `generate-routes.mjs` | Strangler route manifest | Generated config passes `nginx -t`; functional test against dummy upstreams confirmed `X-Served-By: php`/`next`, per-tenant canary routing, debug-script 403, liff redirect 302; add/remove-one-route round-trip diff touched only the expected lines |

None of the above required DNS, VPS, or a live production/tenant database —
every check ran against throwaway local containers and was torn down after.

---

## E2E bridge harness (Phase 1)

Phase 1 batch 3 (mig-infra). Source: `infra/e2e/`. Proves — on the REAL
stack, not mocks — the exact mechanics Phase 1's login-bridge acceptance
depends on: `@reya/auth`'s `login()` creates a session AND bridges it into a
real PHP `$_SESSION` via `internal/session-bridge.php`, such that a browser
presenting the resulting `PHPSESSID` cookie loads a real
`auth_check`-gated PHP admin page without bouncing to `/auth/login.php`,
and that `logout()` reverses this.

### What this proves, and what it does NOT prove

This exercises the bridge **mechanism** end-to-end on **one** representative
page (`system-status.php` — see `infra/e2e/probe-page.md` for why) and
**one** representative tenant DB (a scratch DB seeded from the committed
`database/migration_2026-05-25_tenant_template.sql`). It does **not**
attempt the full plan Phase-1-acceptance line ("5 heavy PHP pages, Google
OAuth/SSO, platform-login+switch-tenant audit rows") — that is a broader,
separate verification pass. Read this harness's PASS/FAIL output as
**"bridge mechanics: PASS/FAIL"**, never as "Phase 1: PASS/FAIL".

### How to run it locally

```bash
node infra/e2e/run.mjs
```

This is the single documented command. It is fully self-contained:

1. Checks `packages/auth/dist/index.js` exists; if not, runs
   `pnpm --filter @reya/auth run build` itself first (which transitively
   builds `@reya/config`/`@reya/db` via TypeScript project references).
2. Parses `config/config.php` (the local, gitignored copy — see this repo's
   CLAUDE.md) at runtime for `DB_NAME`/`DB_USER`/`DB_PASS`. These values are
   **never** written into any tracked file — see the secrets-discipline
   note below.
3. Generates fresh, random, throwaway secrets (MariaDB root password,
   `SESSION_BRIDGE_HMAC_SECRET`, the seeded admin's password) — held only in
   the Node process's memory/env for the duration of the run.
4. `docker compose -f infra/e2e/docker-compose.yml up -d --build` — brings
   up `mariadb:10.11` (host port **3306 directly** — see the "port gotcha"
   note below), `redis:7-alpine`, and a `php` service built from the
   **existing, unmodified** `infra/php/Dockerfile`, all on a dedicated
   bridge network with a **fixed subnet** (`172.30.99.0/24`).
5. Seeds, strictly in order (see `infra/e2e/seed/` + `run.mjs`'s
   `MASTER_MIGRATIONS` list): the master DB (`zrismpsz_reya_platform`) from
   the six committed master migrations, the app/tenant DB (named whatever
   `config/config.php` says — in practice `zrismpsz_clinicya`) from the
   ~280-table tenant template, one `plans` row, one `tenants` row. Then
   fires **one throwaway unauthenticated GET** at the probe page (this is
   what makes `classes/AdminAuth.php`'s constructor auto-create
   `admin_users`/`admin_bot_access`/`admin_activity_log` — see
   `infra/e2e/seed/20-admin-user.sql.tmpl`'s header comment for the full
   "sequencing trap" explanation), generates a **real PHP bcrypt hash**
   by invoking `password_hash()` inside the harness's own `php` container,
   and seeds one `admin_users` row with it.
6. Calls the **built dist** of `@reya/auth`'s `login()` (wrapped in
   `runWithTenantDb`, per that package's README), asserts `ok===true` and
   `bridgeSynced===true` (printing the bridge's actual HTTP status/body on
   failure, not just a bare boolean).
7. Fetches the probe page through the harness's `php` service with
   `Cookie: PHPSESSID=<sid>` — asserts 200, no `Location` to
   `auth/login.php`. Separately fetches the same page with a garbage
   `PHPSESSID` — asserts a 3xx redirect **to** `auth/login.php` (the
   "does it actually gate on auth at all" control).
8. Calls `logout()`, then re-fetches the probe page with the **same**
   `PHPSESSID` — asserts it now redirects like the logged-out control.
9. Prints one JSON line to stdout — see "Reading the output" below — and
   **always** tears the stack down (`docker compose down -v
   --remove-orphans`) in a `finally` block, even on a thrown error.

Re-running the same command immediately again is safe and produces the same
result — every volume/network/container is destroyed at the end of the
previous run, so there is no leftover state to interfere with a fresh run.

### Reading the output

The last stdout line is a single JSON object:

```json
{"result":"PASS"|"FAIL","steps":{"<step_name>":{"ok":true|false,...},...},"failedAt":"<step_name>"|null}
```

`steps` is populated in execution order — a `FAIL` result's `failedAt`
names the first step that didn't succeed; every step recorded before it
succeeded. Exit code is 0 for PASS, 1 for FAIL — the whole harness is
scriptable/CI-friendly by design (this is what mig-verify runs verbatim).

### The `internal/.htaccess` CIDR caveat

`internal/.htaccess` previously denied ALL requests unconditionally (its own
header comment explicitly named mig-infra as the owner of replacing that
with a scoped Allow/Require-ip rule once a Docker network CIDR was known).
This batch replaces the blanket deny with:

```
Require ip 172.30.99.0/24
```

— scoped to **exactly** this harness's own dedicated compose network
(`infra/e2e/docker-compose.yml`'s `e2e-net`, fixed subnet). This was
verified empirically (not assumed): a request from the Docker **host**
(where `infra/e2e/run.mjs`'s Node process itself runs — it is not
containerized) to a container's published port on a custom bridge network
is SNAT'd to that network's **gateway** IP (`172.30.99.1` for this subnet),
which is why the whole `/24` is allow-listed rather than a single address.

**This is a narrow, harness-specific carve-out — it is NOT the production
Phase 0 cutover's CIDR decision.** The real production network `internal/`
needs to be scoped to (whatever Docker network Phase 0 actually runs the
`php` + Next.js kernel containers on) is a **separate** decision, tracked in
`docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 0/13 — do not
assume `172.30.99.0/24` means anything there, and do not widen this rule to
cover it. When that production CIDR is decided, add a second scoped rule
alongside this one (or replace it if this harness is retired) rather than
loosening it.

### The DB_HOST/port gotcha

`config/config.php` hardcodes `DB_HOST` to the literal string `'localhost'`,
which PDO's mysql driver (used by every PHP page) treats as "connect via
unix socket", not TCP — `infra/e2e/docker-compose.yml` replicates the exact
shared-socket-volume trick `infra/compose/docker-compose.strangler.yml`
already uses for this (`e2e_mariadb_socket` mounted at `/run/mysqld` in both
the `mariadb` and `php` services; `infra/php/php.ini`, unmodified, already
points `pdo_mysql.default_socket` there).

Separately: `packages/db`'s `masterPool.ts`/`tenantPoolRegistry.ts` build
their `mysql2` `PoolOptions` from `env.DB_HOST` **alone** — there is no
`DB_PORT` anywhere in `packages/config`'s env schema, and `mysql2`'s `host`
option does not parse a `"host:port"` string. Since `infra/e2e/run.mjs`'s
Node process runs on the host (not inside the compose network), the only
way for it to reach MariaDB on `mysql2`'s hardcoded default port is a
**direct host-port mapping**, `"3306:3306"` — not a remapped high port. If a
genuine port conflict is ever hit here, that is a real `packages/db` gap
(no way to configure a non-default MySQL port for the Node side) — flag it
back rather than working around it in this harness.

### Confirmed findings from running this harness against the real stack

Building and running this harness surfaced two real, blocking defects
**outside** this batch's allowed paths (`packages/**`, and any PHP file
other than the `internal/.htaccess` CIDR carve-out, are out of scope for
mig-infra to fix — see this batch's brief). Both were diagnosed with
temporary, fully-reverted local edits (verified via `git diff` /
`git status` showing a clean tree afterward) and are reported here, not
patched:

1. **`packages/auth/src/sessionStore.ts` — `toRow()` and `touch()` write a
   JS `Date#toISOString()` string (`"...T...Z"`) directly into a raw `sql`
   tag for `node_sessions`' `created_at`/`last_seen_at`/`expires_at`
   `TIMESTAMP` columns.** MariaDB rejects that literal syntax outright
   (`Incorrect datetime value: '2026-07-12T15:03:08.546Z' ...`) — this
   fails on **every** `login()` call against a real MySQL/MariaDB pool (not
   just this harness). None of `packages/auth`'s own tests catch it because
   they mock `mysql2` entirely (see that package's README: "No test in this
   package opens a real DB"). Fix direction: strip the `T`/trailing
   `.sssZ` (`iso.replace('T',' ').replace(/\.\d+Z$/, '')`) before handing
   these to the `sql` tag, or pass real `Date` objects instead of
   pre-stringified ISO values (mysql2 auto-formats actual `Date` objects
   correctly).
2. **`internal/session-bridge.php`'s `'login-sync'` action calls
   `session_regenerate_id(true)`, which changes which PHP session id
   actually holds the bridged `$_SESSION` data — but nothing communicates
   that new id back to Node or the browser.** `session_regenerate_id`'s
   `Set-Cookie` is sent on the POST response `@reya/auth`'s `bridgeClient.ts`
   consumes (server-to-server), never on a response a browser ever sees;
   the browser's `PHPSESSID` cookie is set independently by `apps/admin`'s
   login route to Node's own `sid` (`SessionCookieDescriptor.value`), which
   is never told about the regeneration. Verified directly: after a
   `login-sync` bridge call, the ORIGINAL `sid`'s PHP session file/key is
   empty, and the admin_user payload is filed under a freshly-regenerated,
   different id that no client ever holds — so a browser presenting the
   `PHPSESSID` Next.js actually sets it always bounces back to
   `auth/login.php`. With `session_regenerate_id(true)` temporarily disabled
   for verification (immediately reverted), every remaining step of this
   harness passed cleanly (authed 200, unauthed-control 302, `logout()`
   reverses it) — confirming the rest of the bridge/probe mechanism is
   otherwise correct. This blocks the bridge mechanism itself in its
   current form and needs to be fixed by whoever owns
   `internal/session-bridge.php` (routed via mig-orchestrator) — most
   likely by not rotating the PHP-side session id at all during a
   Node-driven bridge sync (Node already rotates its own `sid` for
   privilege elevation; the PHP side doesn't need a second, uncommunicated
   rotation on top of that) before this harness can report a real `PASS`.

A third, lower-severity observation: this harness's own manual debugging
(`docker compose exec php php -r 'var_dump(class_exists("Predis\\Client"));'`)
found `predis/predis` is declared in `composer.json`'s `require` but is
**not actually present in `vendor/`** in this checkout (no entry in
`composer.lock` either) — so `infra/php/session-redis-handler.php` silently
falls back to file-based PHP sessions (its documented fail-open behavior;
confirmed via `session.save_handler => files` and populated `sess_*` files
under `/tmp` inside the container, with **zero** keys in Redis DB 1). This
did not block this harness (both the bridge-write request and the
probe-page-read request land on the *same* single `php` container, so
file-based sessions are still shared correctly for THIS harness's purposes)
— but it silently defeats the stateless-PHP-session design Phase 0/§2.1
calls for, which matters once there is more than one PHP replica. Worth a
`composer install` + `composer.lock` audit outside this batch's scope.

The FAIL path itself was verified as real (mig-verify acceptance criterion):
with `SESSION_BRIDGE_HMAC_SECRET` deliberately mismatched between the `php`
service and the Node runner (both `internal/session-bridge.php`'s HMAC
signature check and this repo's `internal/.htaccess` fix from this batch
were otherwise unchanged), the harness printed `{"result":"FAIL",...,
"failedAt":"bridge_synced"}` — correctly isolating the injected fault to
exactly the right step — and `docker ps`/`docker volume ls`/`docker network
ls` showed zero leftover harness resources afterward. Both temporary changes
used for this verification (the HMAC mismatch, and a diagnostic-only rebuild
of `packages/auth/dist/sessionStore.js`, which is gitignored) were fully
reverted; `git status`/`git diff` show no trace of them.

### Secrets discipline

`config/config.php`'s `DB_NAME`/`DB_USER`/`DB_PASS` values are parsed at
runtime by `infra/e2e/run.mjs` and never written into `infra/e2e/`'s tracked
files (`docker-compose.yml`, the `infra/e2e/seed/*.sql*` files) — only the
non-secret schema/table identifiers they represent (e.g. the literal string
`zrismpsz_reya_platform`, which is `TenantContext::PLATFORM_DB_NAME` /
`packages/config`'s `PLATFORM_DB_NAME`, hardcoded identically in both
runtimes already) appear literally in those files. Every other credential
(`MARIADB_ROOT_PASSWORD`, `SESSION_BRIDGE_HMAC_SECRET`, the seeded test
admin's password) is generated fresh per run via `crypto.randomBytes(...)`
and lives only in the Node process's environment for the run's duration.
