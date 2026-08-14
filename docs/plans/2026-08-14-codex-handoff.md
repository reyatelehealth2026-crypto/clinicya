# Codex Handoff — Next.js Migration, Remaining Work

**Repo:** `reyatelehealth2026-crypto/clinicya`
**Base branch for all work:** `claude/nextjs-migration-plan-alspee` (currently at `b5c96b9`, PR #73 merged)
**Written:** 2026-08-14
**Audience:** an autonomous coding agent (Codex) with repo access and no prior conversation context.

This document is self-contained. You do not need any chat history to execute it. Every claim below was
verified against `HEAD` at the time of writing; where you must re-verify, the exact command is given.

---

## สรุปสำหรับเจ้าของระบบ (Thai summary — one page)

ตอนนี้โค้ด Next.js migrate ไปได้เยอะแล้ว (Phase 1–6 ส่วนใหญ่ merge เข้า branch แล้ว) แต่ **ยังไม่มีการ flip
traffic จริงแม้แต่ route เดียว** — ทุก route ใน `infra/nginx/routes.json` ยังชี้ไป PHP

สิ่งที่ค้างแบ่งเป็น 2 กอง:

- **Track A — งาน Phase 0 (คอขวดจริงของการขึ้น production)** — 4 งานเล็ก แต่ไม่ทำแล้วขึ้นไม่ได้:
  1. `classes/TenantProvisioning.php` สร้าง DB ผ่าน cPanel `uapi` อย่างเดียว → บน VPS/Docker จะสร้าง tenant ใหม่ไม่ได้เลย
  2. `composer.lock` ไม่มี `predis` → session-redis handler จะเงียบ ๆ ตกกลับไปใช้ file session (container ไม่ stateless → session bridge พัง)
  3. `config/config.php` ตั้ง `DB_HOST = 'localhost'` → บน Docker แปลว่า unix socket ที่ไม่มีอยู่
  4. ไม่มี CI เลย (`.github/workflows/` ไม่มีจริง) → ไม่มีอะไรกันของพังเข้า branch
- **Track B — หน้าที่ยังไม่ได้ port เลย** — Inventory (16,423 บรรทัด) และ Pharmacy (~3,600 บรรทัด ไม่รวมส่วน AI)
  ยังไม่มีโค้ด Next.js แม้แต่บรรทัดเดียว ถ้า flip tenant ไปแล้ว ผู้ใช้จะเจอทางตัน

**ทำ Track A ก่อนเสมอ** งานเล็กกว่ามากและปลดล็อกทุกอย่างที่เหลือ

---

## 0. How to use this document

Read in this order:

1. §1 Orientation — 5 minutes, gives you the map.
2. §2 **Hard guardrails** — read every line. Violating these is worse than not doing the task.
3. §3 Environment + verification loop — get a green build before you change anything.
4. §4 Track A tasks (do these first, in order A1 → A4).
5. §5 Track B tasks (only after Track A, or in parallel if you have separate worktrees).
6. §6 House style — copy it exactly; the codebase is stylistically uniform on purpose.
7. §7 Known pitfalls — these cost previous agents hours. Read before your first build.
8. §8 Git workflow, §9 Definition of Done, §10 Out of scope, §11 When to stop and ask.

**One task = one branch = one commit series = one draft PR.** Do not batch unrelated tasks.

---

## 1. Orientation

### 1.1 What this system is

PHP 8.0+ multi-tenant SaaS CRM for Thai pharmacies. LINE Official Account integration, telepharmacy,
e-commerce, Odoo ERP sync, AI consultation. Architecture is **database-per-tenant**: a master DB
(`zrismpsz_reya_platform`) holds the tenant registry; each tenant gets its own `zrismpsz_reya_t_NNNN`
schema. Subdomain routing (`tenant-XXXX.re-ya.com`). All user-facing text is bilingual Thai/English.
Timezone is always `Asia/Bangkok` (`+07:00`).

Read `CLAUDE.md` at the repo root — it is the authoritative architecture brief and it is accurate.

### 1.2 The migration model — strangler pattern

The plan is `docs/plans/2026-07-12-nextjs-full-migration-plan.md` (Thai). Phases 0–13.

PHP monolith and the Next.js monorepo **run side by side** behind an nginx edge. Which stack serves a
given path is decided by a committed route manifest:

- `infra/nginx/routes.json` — the manifest (source of truth, reviewable)
- `infra/nginx/routes.schema.json` — its JSON Schema
- `infra/nginx/generate-routes.mjs` — renders the manifest into `infra/nginx/generated/strangler-edge.conf`

Responses carry `X-Served-By: php` or `X-Served-By: next` so you can tell which stack answered.

**Current state: every application route points at `php_backend`.** Only 4 non-PHP entries exist and
they predate this migration (`/miniapp`, `/ws`, `/admin-preview`, `/api/miniapp`). Nothing has been
flipped. Verify with:

```bash
node infra/nginx/generate-routes.mjs --validate-only
python3 -c "import json;d=json.load(open('infra/nginx/routes.json'));print(len(d['routes']),'routes');print({r.get('upstream') for r in d['routes']})"
```

### 1.3 Monorepo layout

pnpm workspaces + Turborepo. `pnpm-workspace.yaml` globs `apps/*` and `packages/*`. The PHP monolith
stays at the repo root and is untouched by the workspace.

| Path | What it is |
|---|---|
| `apps/admin` | Next.js admin dashboard — the main port target. Jest for tests, `tsc --noEmit` for lint. |
| `apps/worker` | BullMQ jobs + Socket.io realtime relay. Vitest. |
| `packages/db` | Kysely + mysql2. `getMasterDb()`, `getTenantDb(tenantId)`, LRU `TenantPoolRegistry` keyed by `db_name`, `migrate-all` runner, `kysely-codegen` output in `src/generated/`. **Not Prisma.** |
| `packages/tenant` | Subdomain resolution, AsyncLocalStorage tenant context, `routeByLineAccount()`. |
| `packages/auth` | Two-realm cookie sessions (`reya_sid` tenant / `reya_platform_sid` platform) in the master `node_sessions` table + Redis. |
| `packages/core` | `genDocNumber` (Buddhist-era `{PREFIX}-{YYMM}-{seq4}`), `calcVAT`, `formatThaiDate`. |
| `packages/line` | `validateSignature`, reply-token-first `sendMessage`, `FlexTemplates`, multicast/broadcast. |
| `packages/contracts` | Shared zod schemas / response envelopes. |
| `packages/config` | Shared tsconfig/eslint bases. |
| `infra/` | `php/` (Dockerfile, vhost, php.ini, redis session handler), `compose/`, `nginx/`, `e2e/`. |

Already ported into `apps/admin` (do not re-port these): dashboard, users, user-detail, inbox (+ its
API routes), broadcast, templates, settings, analytics, articles, activity-logs, groups,
line-groups, line-group-detail, loyalty-members, pharmacists, shop/orders, shop/order-detail,
crm-dashboard-advanced, system-status, documents API, and ~15 `api/miniapp/*` endpoints.

### 1.4 The one new PHP file

`internal/session-bridge.php` — HMAC-signed endpoint that lets the Next.js side populate a PHP
`$_SESSION` so a user who logs in on Next can still open a PHP page. This is the **only** new PHP file
the migration adds. Do not add more.

---

## 2. Hard guardrails — non-negotiable

Read all of these. They encode expensive lessons.

1. **Do not flip any traffic.** Do not change any `upstream` value in `infra/nginx/routes.json` from
   `php_backend` to a Next upstream. Adding a *new* route entry that points at `php_backend` is fine;
   flipping an existing one is a production decision that is not yours to make.

2. **Do not modify PHP application files.** The single exception is Track A task **A1**
   (`classes/TenantProvisioning.php`), which the plan explicitly sanctions in §2. Everything else in
   `api/`, `classes/`, `modules/`, `includes/`, `cron/`, and the root `*.php` pages stays byte-identical.
   If a port reveals a PHP bug, fix it **forward in the Next.js code** and write a comment explaining
   the divergence — do not patch the PHP.

3. **Never modify `apps/admin/src/nav/manifest.ts`.** The nav manifest decides which links point at
   Next vs PHP. It is edited only at flip time, by a human.

4. **Do not delete `websocket-server.js` or `websocket-dashboard-server.js`.** They are decommissioned
   in Phase 13, gated on the traffic flip that hasn't happened.

5. **Secrets discipline.** GitGuardian scans this repo. Never commit a literal credential, password,
   API token, private key, or connection string with embedded auth. Note that `config/config.php` **is
   tracked in git** even though `.gitignore` lists it (it was committed before the ignore rule, and
   `.gitignore` does not untrack existing files). Verify before you edit it:
   ```bash
   git ls-files --error-unmatch config/config.php
   ```
   Any value you add there must come from `getenv()` with a safe non-secret default.

6. **Tests must never make real network calls.** No real LINE Messaging API calls, no real Gemini/OpenAI
   calls, no real Odoo JSON-RPC. Mock at a seam. Existing tests show the pattern — see
   `apps/admin/src/app/(tenant)/users/testHelpers/fakeTenantDb.ts`.

7. **Migration SQL whitelist.** `.gitignore` ignores `database/*.sql` and re-includes specific files
   with `!database/migration_*.sql` lines. If you add a migration, you **must** append a matching `!`
   line or the file will silently not be committed. Same rule applies to `docs/*.md` (`*.md` is ignored;
   individual docs are whitelisted). After `git add`, always confirm with `git status --short` that the
   file actually staged.

8. **Push immediately after you commit.** Work is done on ephemeral disk that can be reclaimed without
   warning. A commit that only exists locally has been lost twice before on this project.
   ```bash
   git push -u origin <branch>
   ```

9. **Do not run `bash force_deploy_testry.sh`.** It performs `git reset --hard HEAD` on the production
   server and permanently destroys uncommitted server-side work. Deployment is not part of this handoff.

10. **Phase 5 (dispense + documents/VAT) is high risk.** It is merged but must not be flipped without a
    human co-sign. Do not touch `genDocNumber`, `calcVAT`, or the dispense chain unless a task below
    explicitly names them.

---

## 3. Environment and the verification loop

### 3.1 First-run setup

```bash
cd /path/to/clinicya
corepack enable                       # pnpm 10.33.0 is pinned via packageManager
pnpm install
npx turbo run build --filter='@reya/*'   # build sibling packages BEFORE anything else — see §7.1
```

PHP tooling (for Track A only):

```bash
php -v          # expect 8.x
composer --version
```

### 3.2 The full check — run this before and after every task

```bash
npx turbo run build test lint
```

Individually, if you want a tighter loop:

```bash
pnpm --filter @reya/admin test      # Jest
pnpm --filter @reya/admin lint      # tsc --noEmit
pnpm --filter @reya/admin build     # next build  ← catches things tsc and jest do not (§7.2)
pnpm --filter @reya/worker test     # Vitest
```

PHP side:

```bash
composer test      # PHPUnit, property-based (100+ generated cases per property)
composer lint      # PSR-12 dry-run
composer lint:fix  # apply
composer analyse   # PHPStan level 0
```

Route manifest:

```bash
node infra/nginx/generate-routes.mjs --validate-only
python3 -c "import json; json.load(open('infra/nginx/routes.json')); print('json ok')"
```

**Run both.** They catch different failures — see §7.3.

### 3.3 What you cannot verify here

Docker is not available in the standard sandbox (`service docker start` fails with
`ulimit: error setting limit (Operation not permitted)`). That blocks:

- everything in `infra/e2e/` (`api-parity.mjs`, `parity.mjs`, `rollback-drill.mjs`, `worker-smoke.mjs`,
  `worker-realtime-relay-smoke.mjs`, `run.mjs`)
- `pnpm --filter @reya/core test:live` (the `genDocNumber` concurrency test)

**Report this honestly.** If a task's acceptance criteria require a live harness you cannot run, say so
explicitly in the PR body — do not substitute a mock and call the criterion met, and do not silently drop it.

---

## 4. Track A — Phase 0 unblockers

These are small, high-leverage, and fully executable inside the repo with no VPS access. They are the
real bottleneck to going live. **Do these first, in order.**

---

### A1. `TenantProvisioning` — add a `mysql` strategy alongside `uapi`

**Why.** `classes/TenantProvisioning.php` (714 lines) can only create tenant databases by shelling out
to cPanel's `uapi` binary. On a self-managed MySQL/MariaDB host (which is where Phase 0 moves
production) `/usr/bin/uapi` does not exist, so **provisioning a new tenant is impossible**. This is the
single change to PHP code that the migration plan sanctions (plan §2, item 4).

Verify the current state:

```bash
grep -c 'strategy' classes/TenantProvisioning.php     # expect 0
grep -n 'UAPI_BIN\|self::uapi(' classes/TenantProvisioning.php
```

The three `uapi` call sites are:

| Line (approx) | Method | uapi call |
|---|---|---|
| 164 | `create(int $tenantId)` | `Mysql::create_database` |
| 191 | `grant(string $dbName, string $mysqlUser)` | `Mysql::set_privileges_on_database` |
| 331 | `delete(string $dbName)` | `Mysql::delete_database` |

`applySchema()` already uses the plain `mysql` client (`self::MYSQL_BIN`) and works on both hosts —
leave it alone.

**What to build.**

1. Add a strategy selector resolved once, in this precedence order:
   - `getenv('REYA_PROVISIONING_STRATEGY')` — `'uapi'` or `'mysql'`
   - else a `REYA_PROVISIONING_STRATEGY` constant if defined in `config/config.php`
   - else default `'uapi'` (preserves today's behaviour exactly — this is important; the change must
     be a no-op on the current cPanel host)

2. Make the cPanel account prefix configurable. `private const CPANEL_ACCOUNT = 'zrismpsz'` and
   `private const DB_PREFIX = 'zrismpsz_reya_t_'` are cPanel artefacts — cPanel *requires* the account
   prefix, a self-managed host does not. Introduce a resolved prefix (env `REYA_DB_PREFIX`, defaulting
   to the current literal) and route `tenantIdToDbName()` / `dbNameToTenantId()` /
   `assertDbNameAllowed()` through it. **`dbNameToTenantId()` must stay the exact inverse of
   `tenantIdToDbName()` for whatever prefix is active** — there are existing tests/callers that
   round-trip these.

3. Implement the three operations for `strategy=mysql` using the master PDO connection
   (`self::platformPdo()`), with backtick-quoted identifiers built only from the already-validated
   `$dbName` (`assertDbNameAllowed()` runs first — keep that ordering):
   - create → `CREATE DATABASE ... CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`
   - grant → `GRANT ALL PRIVILEGES ON \`db\`.* TO ...` then `FLUSH PRIVILEGES`
   - delete → `DROP DATABASE`
   These are DDL and cannot use bound parameters; that is exactly why `assertDbNameAllowed()` exists.
   **Never** interpolate a `$dbName` that has not passed it.

4. Preserve the existing logging contract in every branch: `logStart()` → operation →
   `logComplete('succeeded'|'failed', $error)`, and throw `RuntimeException` on failure so callers can
   run their compensating actions. `fullProvision()` must behave identically under either strategy.

5. Update the class docblock. It currently says "for tenant databases on cPanel shared hosting" — that
   stops being true.

**Acceptance.**

- `grep -c 'strategy' classes/TenantProvisioning.php` > 0.
- With no env var and no config constant set, behaviour is byte-identical to today (uapi path).
- `composer test`, `composer lint`, `composer analyse` all pass.
- Add PHPUnit coverage for the pure helpers under both prefixes:
  `tenantIdToDbName()` / `dbNameToTenantId()` round-trip, and `assertDbNameAllowed()` rejecting
  names outside the active prefix. Follow the existing property-based style in `tests/`.
- No literal credentials introduced.

**Explicitly out of scope:** actually running provisioning against a live MySQL. You cannot, and you
should not try.

---

### A2. `composer.lock` — lock `predis/predis`

**Why.** `composer.json` requires `predis/predis: ^2.2`, but `composer.lock` has `"packages": []` —
predis is not locked, so `composer install` on a production host installs **zero** runtime packages.

Verify:

```bash
sed -n '1,12p' composer.json                     # predis/predis ^2.2 in require
grep -n '"packages": \[\]' composer.lock         # expect a hit at line ~8
grep -c predis composer.lock                     # expect 0
ls -d vendor/predis                              # expect: No such file or directory
```

The concrete consequence: `infra/php/session-redis-handler.php` is loaded via `auto_prepend_file` and
is written to **fail open** — if `Predis\Client` isn't autoloadable it silently returns and PHP keeps
its default **file-based** session handler. So a Phase 0 container looks healthy while quietly holding
session state on local disk. That breaks container statelessness, which the Next.js session bridge
(plan §1.4) depends on. It is a silent failure, which is why it survived this long.

`classes/RedisCache.php` and `classes/OdooRedisCache.php` are also predis-gated (both `class_exists`
-guarded, so they degrade rather than fatal).

**What to do.**

```bash
composer update predis/predis --lock   # if network allows; else `composer update --lock`
```

Then confirm the lock actually gained the package and that `content-hash` was refreshed:

```bash
grep -c predis composer.lock       # expect > 0
grep -n 'content-hash' composer.lock
composer validate --strict
```

If the sandbox has no network access to Packagist, **stop and report that** rather than hand-editing
`composer.lock`. A hand-written lock entry with a wrong `dist.shasum` is worse than no lock entry.

**Acceptance.**

- `composer validate --strict` passes.
- `composer install --dry-run` resolves predis.
- `composer.lock` is staged and committed (it **is** tracked despite the `.gitignore` line — confirm
  with `git ls-files --error-unmatch composer.lock`).
- Optionally add a non-fail-open diagnostic: `scripts/redis-cache-test.php` already reports predis
  availability. Do not change the handler's fail-open behaviour — that is deliberate.

---

### A3. `DB_HOST` — resolve socket-vs-TCP for containerised MySQL

**Why.** `config/config.php:21` is `define('DB_HOST', 'localhost');`. In MySQL client semantics
`'localhost'` means **connect over a unix socket**, not `127.0.0.1`. Inside a Docker container the
socket path does not exist and the DB is a separate service on the compose network — so this value
silently fails in the Phase 0 target environment.

Verify:

```bash
sed -n '15,30p' config/config.php
grep -rn "DB_HOST" config/ modules/Core/Database.php classes/Database.php | head -20
```

**What to do.**

1. Make it environment-overridable, defaulting to the current value so nothing changes on the existing
   host:
   ```php
   define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost');
   ```
   Apply the same treatment to `DB_USER` / `DB_PASS` / `DB_NAME` **only if they are not already
   env-driven** — check first, and do not print or commit any current value.
2. Mirror the change into `config/config.example.php` and `config/config.sample.php` (both tracked) so
   fresh installs get the env-aware form.
3. Add `DB_HOST` (and any siblings you touched) to `infra/compose/.env.example` with a Docker-correct
   value such as `mysql` (the compose service name) — that file is the documented place for this.
4. Confirm `modules/Core/Database.php` builds its DSN from `DB_HOST` and does not hardcode a socket
   path anywhere.

**Acceptance.**

- With no env vars set, `DB_HOST` still evaluates to `'localhost'` — zero behaviour change on the
  current host.
- `composer test` and `composer lint` pass.
- `git diff` shows **no** credential values added or changed.
- `infra/compose/.env.example` documents the container-correct value.

---

### A4. CI — there is none

**Why.** `.github/workflows/` does not exist. The only thing under `.github/` is
`copilot-instructions.md`. Nothing prevents a broken build from landing on the branch, and every
regression found on this project so far was found by hand.

Verify:

```bash
ls .github/workflows 2>&1        # expect: No such file or directory
git ls-files .github/
```

**What to build.** Create `.github/workflows/ci.yml`:

- Triggers: `pull_request`, and `push` to `main` and `claude/nextjs-migration-plan-alspee`.
- Job **node**:
  - `actions/checkout@v4`
  - `pnpm/action-setup@v4` (version comes from the root `package.json` `packageManager` field —
    `pnpm@10.33.0` — do not hardcode it separately)
  - `actions/setup-node@v4` with `cache: pnpm`
  - `pnpm install --frozen-lockfile`
  - `npx turbo run build test lint`
- Job **php**:
  - `shivammathur/setup-php@v2` with `php-version: '8.2'`
  - `composer install --prefer-dist --no-progress`
  - `composer lint` (dry-run), `composer analyse`, `composer test`
- Job **routes**:
  - `node infra/nginx/generate-routes.mjs --validate-only`
  - a JSON parse of `infra/nginx/routes.json`
  - **and a diff check** that the committed `infra/nginx/generated/strangler-edge.conf` matches what
    the generator produces — the generated conf is committed on purpose (see `.gitignore` lines
    ~249–252) so route flips are reviewable, and it must not drift.

Constraints:

- **No secrets.** The workflow must not reference any repository secret. Everything above runs on
  public tooling against source only.
- **No deploy step.** CI validates; it does not ship.
- Do not add live-Docker e2e jobs — they need a real MySQL/Redis and are out of scope here.

**Acceptance.**

- The workflow file is valid YAML and parses:
  ```bash
  python3 -c "import yaml;yaml.safe_load(open('.github/workflows/ci.yml'));print('yaml ok')"
  ```
- Every command it runs also passes locally, in the same order.
- `.gitignore` does not exclude `.github/workflows/` — confirm the file actually stages with
  `git status --short`.

---

## 5. Track B — remaining ports

Two surfaces have **zero** Next.js code. If a tenant were flipped today, a pharmacist clicking into
either would hit a dead end.

### B0. Survey first (read-only, do this before writing any code)

Re-measure before you plan — these numbers were taken at `b5c96b9`:

```bash
wc -l inventory/*.php | tail -1              # → 4832  (12 standalone pages)
wc -l includes/inventory/*.php | tail -1     # → 11591 (23 tab partials)
ls includes/inventory/*.php | grep -v '/wms' | xargs wc -l | tail -1   # → 10070 (WMS excluded)
wc -l includes/pharmacy/*.php | tail -1      # → 1969
wc -l pharmacist-dashboard.php pharmacist-video-calls.php api/pharmacist.php | tail -1
```

**Inventory surface = 16,423 lines total** (4,832 in `inventory/` + 11,591 in `includes/inventory/`).
Excluding the six `wms-*.php` partials (Phase 9, out of scope — see §10) it is **14,902 lines**.

**Pharmacy surface** — note `pharmacists.php` is already ported (`apps/admin/src/app/(tenant)/pharmacists`
exists), so the remaining gap is:

| File | Lines | Notes |
|---|---:|---|
| `pharmacist-dashboard.php` | 1,084 | |
| `pharmacist-video-calls.php` | 1,622 | telepharmacy video call scheduling |
| `includes/pharmacy/dashboard.php`, `dispense.php`, `interactions.php` | ~1,969 total (incl. `pharmacists.php` partial, already covered) | `dispense.php` overlaps Phase 5 — **treat as high risk, see guardrail 10** |
| `api/pharmacist.php` | 929 | |
| `api/pharmacy-ai.php` | 2,560 | **out of scope** — Phase 7 AI pipeline |

Confirm nothing is already ported before you start:

```bash
ls apps/admin/src/app/\(tenant\)/ | sort
grep -rn "inventory" apps/admin/src/nav/manifest.ts
```

`/products.php` is **only a redirect** into `/inventory/`. Do not port `products.php` as a page.

Deliverable for B0: a short survey note (in the PR body, not a new file) listing each PHP page/tab,
its line count, which DB tables it reads and writes, and whether it mutates stock. That determines
batch boundaries.

### B1..Bn. Port tab by tab

Split by tab partial, not by file size.

In-scope partials under `includes/inventory/` (23 files; the six `wms-*.php` are **excluded**):
`storefront`, `locations`, `drug-groups`, `generic-names`, `label-templates`, `drug-interactions`,
`products`, `stock`, `batches`, `movements`, `adjustment`, `low-stock`, `reports`, `put-away`,
`planogram`, `catalog-sync`, plus the shared `_lookup_helpers.php`.

Standalone pages under `inventory/`: `goods-receive`, `low-stock`, `po-detail`, `product-detail`
(the largest single file — 1,436 lines / 80 KB), `product-units`, `purchase-orders`, `reports`,
`stock-adjustment`, `stock-forecast`, `stock-movements`, `suppliers`, `index`.

Rules for each batch:

- **One tab per branch/PR.** These are large; a mega-PR is unreviewable.
- Server Components for reads (direct Kysely via `getTenantDb`), Server Actions for mutations.
  No half-PHP pages — if a tab has a small write, port the write too.
- **Any tab that mutates stock needs a property test** asserting the stock invariant (stock never goes
  negative; a decrement and its compensating action balance). The repo's test style is property-based
  with 100+ generated cases per property — match it.
- Every column you read must exist. This codebase has known schema drift where PHP queries reference
  columns that aren't in the schema (previously found: `users.birth_date`, `users.chronic_diseases`,
  `customer_notes.note_type`, `business_items.is_prescription`, `member_tiers.name` / `badge_color`).
  Check `packages/db/src/generated/tenant-db.d.ts` before trusting a PHP query. When PHP is wrong, fix
  forward in TypeScript and leave a comment naming the drift.
- Respect the **Odoo kill-switch**: per-tenant Odoo UI is gated on `ODOO_INTEGRATION_ENABLED`
  (`$isOdooMode` in the PHP). Any Odoo-touching UI you port must stay behind the equivalent gate so
  non-Odoo tenants don't see broken integrations.
- Odoo dashboard reads always come from the cache tables (`odoo_orders`, `odoo_invoices`, `odoo_bdos`),
  never from the Odoo API directly.
- Do **not** add nav entries (guardrail 3). The route exists but is unreachable from the UI until a
  human flips it. That is intended.

---

## 6. House style — copy it exactly

### 6.1 Route Handler (API)

```
apps/admin/src/app/api/<area>/<name>/
  route.ts                    # HTTP layer only: parse, delegate, respond
  route.test.ts               # Jest, colocated
  _lib/<action>.ts            # one file per action — the actual logic
  _lib/session.ts             # session/tenant resolution for this folder
  _lib/testHelpers/fakeTenantDb.ts
```

Reference implementation: `apps/admin/src/app/api/miniapp/wishlist/route.ts`. Read it before you write
your first handler. Note in particular:

- A long header docblock naming **the PHP file being ported, its line count, and what is explicitly out
  of scope, with the evidence** ("zero callers, confirmed via grep of …"). Write these. They are how
  the next agent avoids re-litigating your decisions.
- **Envelope parity is exact.** The Next response must match what the PHP `echo json_encode([...])`
  actually emitted — including whether the key is `error` or `message`, and including PHP's
  implicit HTTP 200 when the source file never called `http_response_code()`.
- **DATETIME handling.** `packages/db`'s mysql2 pool does not set `dateStrings: true`, so
  DATETIME/TIMESTAMP columns hydrate as JS `Date` and serialize to a `Z`-suffixed ISO string — which is
  *not* what PHP PDO returned. Format back to `YYYY-MM-DD HH:MM:SS`. Existing helpers to copy:
  `asDateTimeString()` in the wishlist route, `formatPhpDate()` in points-history.
- Each miniapp route folder is deliberately **self-contained** — helpers are mirrored, not imported
  across folders. Follow that rather than "improving" it.

### 6.2 Page (admin UI)

```
apps/admin/src/app/(tenant)/<page>/
  page.tsx / page.test.tsx
  queries.ts / queries.test.ts        # reads
  actions.ts / actions.test.ts        # Server Actions (mutations)
  _components/                        # client components
  _lib/session.ts, _lib/format.ts
  testHelpers/fakeTenantDb.ts
```

Reference: `apps/admin/src/app/(tenant)/users/`. Its `_lib/session.ts` is the canonical
`requireTenantPageContext()` — read its docblock; it explains why the session gate is duplicated per
leaf page rather than inherited from the layout, and how a `super_admin` with no active tenant is
handled.

### 6.3 `"use server"` files export **only** async functions

`next build` fails with *"A 'use server' file can only export async functions, found object"*.
**`tsc --noEmit` and Jest both miss this** — it only surfaces in a real `next build`. If you need to
export a constant alongside Server Actions, put it in a sibling non-`"use server"` module. Precedent:
`apps/admin/src/app/(tenant)/broadcast/_lib/products-errors.ts` exists solely for this reason.

### 6.4 Conventions

- Commits: Conventional Commits — `type(scope): description`. Types in use: `feat`, `fix`, `refactor`,
  `docs`, `test`, `chore`, `perf`, `ci`.
- Bilingual Thai/English for user-facing strings and DB comments.
- Timezone `Asia/Bangkok` / `+07:00` everywhere. Never rely on the host TZ.
- Charset `utf8mb4_unicode_ci`.
- LINE outgoing messages prefer reply-token-first `sendMessage()` (push-message quota is finite);
  fall back to `pushMessage()` only when a reply token is unavailable.

---

## 7. Known pitfalls — read before your first build

### 7.1 Build sibling packages first, or tsc lies to you

A fresh checkout or worktree produces spurious `Cannot find module '@reya/line'` (and friends) errors
because the workspace packages haven't been built. Always:

```bash
npx turbo run build --filter='@reya/*'
```

before running `lint` or `test`.

### 7.2 Stale `.next/types/validator.ts` produces false lint failures

After merging branches, `apps/admin/.next` can hold generated route types for routes that no longer
exist, which then fail typecheck. Fix:

```bash
rm -rf apps/admin/.next
pnpm --filter @reya/admin build
```

Also: **always run a real `next build`** before declaring a task done. `tsc` + Jest passing is not
sufficient — see §6.3.

### 7.3 `routes.json` — validate two ways

A previous merge-conflict resolution produced structurally-invalid JSON (two route objects
concatenated without a separator) that one validator accepted. After **any** edit or conflict
resolution touching `infra/nginx/routes.json`, run **both**:

```bash
node infra/nginx/generate-routes.mjs --validate-only
python3 -c "import json;d=json.load(open('infra/nginx/routes.json'));print(len(d['routes']),'routes')"
```

and eyeball the route count against what you expected.

### 7.4 `.gitignore` swallows files silently

`*.md`, `database/*.sql`, and `generated/` are blanket-ignored with per-file `!` whitelists. If you add
a doc, a migration, or generated types, add the matching `!` line **and then verify**:

```bash
git add <file> && git status --short | grep <file>
```

No output means the file was ignored and your work will not be committed.

### 7.5 Hand-patched generated types

`packages/db/src/generated/tenant-db.d.ts` contains at least one hand-patched block (`PaymentSlips`)
that should be replaced by a real `kysely-codegen` run against a live schema. If you touch that file,
note it in the PR. The regeneration recipe is in `packages/db/README.md`.

---

## 8. Git workflow

```bash
# start from the shared base
git fetch origin claude/nextjs-migration-plan-alspee
git checkout -b codex/<short-task-slug> origin/claude/nextjs-migration-plan-alspee

# ... work ...

npx turbo run build test lint          # must be green
git add -A
git status --short                     # CONFIRM every intended file staged (§7.4)
git commit -m "feat(provisioning): add mysql strategy alongside cPanel uapi"
git push -u origin codex/<short-task-slug>   # push immediately (guardrail 8)
```

Then open a **draft** PR targeting `claude/nextjs-migration-plan-alspee` (not `main`).

Retry a failed push up to 4 times with exponential backoff (2s, 4s, 8s, 16s) — network flakiness is
common here — but only for network errors, never for rejected-non-fast-forward.

**PR body must contain:**

1. Which task from this document (e.g. "Track A / A1").
2. What changed, file by file, and why.
3. The exact verification commands you ran and their outcome — paste real output, not a summary.
4. **What you could not verify and why** (e.g. "Docker unavailable — `infra/e2e/api-parity.mjs` not
   run"). This section is mandatory and must not be empty if anything was skipped.
5. For Track B: the survey table from B0.

---

## 9. Definition of Done

A task is done when **all** of these hold:

- [ ] `npx turbo run build test lint` is green from a clean state.
- [ ] `pnpm --filter @reya/admin build` (real `next build`) succeeds — not just tsc.
- [ ] For PHP changes: `composer test`, `composer lint`, `composer analyse` are green.
- [ ] New behaviour has tests. Mutating logic has property tests.
- [ ] No PHP file changed except the one sanctioned in A1.
- [ ] `infra/nginx/routes.json` has no `upstream` value changed from `php_backend`.
- [ ] `apps/admin/src/nav/manifest.ts` is untouched.
- [ ] No credentials, tokens, or keys in the diff.
- [ ] Every intended file is actually staged (§7.4).
- [ ] Committed **and pushed**, draft PR opened against `claude/nextjs-migration-plan-alspee`.
- [ ] PR body lists what you could not verify.

---

## 10. Explicitly out of scope

- Any traffic flip, canary, or nginx upstream change.
- Deployment of any kind. Do not run `force_deploy_testry.sh` or `deploy_testry_branch.sh`.
- Phase 7 (AI SSE pipeline) — depends on `modules/AIChat`, deferred.
- Phase 8 (Odoo), Phase 9 (WMS/POS/accounting beyond inventory), Phase 10 (cron→BullMQ),
  Phase 11 (platform admin/billing), Phase 12 (public site), Phase 13 (decommission).
- Retiring `messages.php` or shutting down the legacy websocket servers.
- Live parity harness runs (`infra/e2e/*`) and `@reya/core test:live` — these need real Docker.
- Regenerating `packages/db/src/generated/*` — needs a live schema.

---

## 11. When to stop and ask

Stop and report rather than guessing if:

- A task requires a credential, a live database, a live Redis, or network access you don't have.
- `composer update` cannot reach Packagist (A2) — do **not** hand-edit `composer.lock`.
- You find a PHP bug whose correct fix would require editing PHP outside A1.
- A port reveals schema drift you cannot resolve from `packages/db/src/generated/tenant-db.d.ts`.
- Acceptance criteria conflict with a guardrail in §2. The guardrail wins; report the conflict.
- A merge conflict lands in `infra/nginx/routes.json` and the correct resolution is not obvious.

Report what you found, what you did, and what you deliberately did not do. An honest partial result is
worth more than a confident wrong one.

---

## Appendix — verification commands used to write this document

```bash
git log --oneline -3
grep -c 'strategy' classes/TenantProvisioning.php                 # → 0
grep -n 'UAPI_BIN\|self::uapi(' classes/TenantProvisioning.php    # → 3 call sites (create/grant/delete)
wc -l classes/TenantProvisioning.php                              # → 714
sed -n '1,12p' composer.json                                      # → predis/predis ^2.2
grep -n '"packages": \[\]' composer.lock                          # → line 8
grep -c predis composer.lock                                      # → 0
ls -d vendor/predis                                               # → No such file or directory
sed -n '21p' config/config.php                                    # → define('DB_HOST', 'localhost');
ls .github/workflows                                              # → No such file or directory
git ls-files --error-unmatch composer.lock config/config.php      # → both tracked
node infra/nginx/generate-routes.mjs --validate-only
wc -l inventory/*.php includes/inventory/*.php                    # → 4,832 + 11,591 = 16,423
```
