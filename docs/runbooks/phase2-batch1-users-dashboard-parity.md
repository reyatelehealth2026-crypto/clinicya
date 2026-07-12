# Phase 2 batch 1 — /users, /user-detail, /dashboard parity harness

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 2
(page-by-page port), §1.5 (strangler edge), §7.3 (canary ramp: demo tenant →
1 real tenant → 10% → 50% → 100%). Owner: mig-infra (this harness) /
mig-ui (the two page-porting agents whose Next output this harness verifies) /
mig-orchestrator (route-flip authorization). Same checklist-not-prose style as
`docs/runbooks/phase0-cutover-rollback.md`.

## Scope note (read first — same documented-limits pattern as
`infra/e2e/run.mjs`'s own scope note)

`infra/e2e/parity.mjs` proves **data-point parity, on ONE seeded tenant and
ONE fixed fixture dataset**, between PHP's `/users`, `/user-detail.php?id=N`,
`/dashboard?tab=executive|crm` and Next's `/users`, `/user-detail?id=N`,
`/dashboard?tab=executive|crm` — on the REAL stack (a genuine MariaDB 10.11 +
Redis 7 + `php:8.2-apache` container set, and a genuine `next build` +
standalone-server Next process), never mocks.

**What this is NOT:**
- **Not a live-traffic shadow test.** It never sees real tenant data, real
  admin sessions, or real production load. A tenant with schema drift (extra
  columns, missing tables — see users.php's own `SHOW COLUMNS`/`SHOW TABLES`
  legacy-compat probes) is out of scope; see `apps/admin/src/app/(tenant)/
  users/queries.ts`'s module doc for why the Next port intentionally always
  takes the "happy path" branch.
- **Not a pixel/HTML diff.** It diffs a small, hand-picked EXTRACTION LIST of
  data points per page (counts, totals, name lists — see §3 below), never raw
  HTML or a screenshot. Two pages can look completely different and still
  pass, as long as the numbers/names this harness extracts agree. See
  `infra/e2e/lib/extract.mjs`'s module doc for the full "why label-anchored,
  not class-anchored" rationale, including two confirmed React-SSR quirks
  (HTML comments between adjacent JSX text expressions; Next's App Router
  title-hoisting swallowing an unrelated SVG `<title>` element's text) this
  harness had to specifically work around — read that file before touching
  either extractor or diagnosing a false-positive mismatch.
- **Not exhaustive.** `/users` exercises 9 filter combinations (baseline +
  one representative value per named filter: search, tag, tier, points,
  activity, purchase×2, status), not the full cross-product. `/user-detail`
  checks 3 of the fixture's 25 seeded users (a "rich", a "medium", and an
  "empty/new" case), not all 25.

If you need a broader/live check, that is a separate, larger verification
pass — this harness's JSON output says `"result":"PASS"` for THIS dataset,
never "Phase 2 batch 1: PASS" in some larger sense.

---

## 1. How to run it

```bash
node infra/e2e/parity.mjs
```

Single command, no flags required. It will (in order):

1. `docker compose -p reya-e2e-parity -f infra/e2e/docker-compose.yml up -d
   --build` (the SAME compose file `infra/e2e/run.mjs` uses — mariadb, redis,
   php — UNMODIFIED; see that file's own header comment for the port/socket
   gotchas this inherits).
2. Seed the master DB (6 committed migrations) + one fresh tenant DB (the
   ~280-table template) + this batch's own tenant/plan row
   (`infra/e2e/seed/15-plan-and-tenant.sql.tmpl` — a NEW file, deliberately
   not a reuse of `run.mjs`'s `10-plan-and-tenant.sql.tmpl`, which hardcodes
   a different tenant slug for a different harness) + the data-point fixture
   (`infra/e2e/seed/30-phase2-batch1-fixture.sql.tmpl`, ~25 users spanning
   every `users.php` filter branch — see that file's own extensive header/
   inline comments for the "why" behind every seeded row) + one admin user.
3. `pnpm --filter admin run build` (ALWAYS — never trusts a possibly-stale
   `.next/standalone` left over from an earlier checkout state), copies
   `.next/static` into the standalone bundle (Next's own documented
   requirement — `output: 'standalone'` does not do this for you), and
   starts `node server.js` as a plain host child process on port 3210, wired
   to the SAME MariaDB/Redis via `127.0.0.1` (same "Node runner runs on the
   host, not in the compose network" pattern `run.mjs` already documents).
4. Logs into BOTH stacks against the SAME seeded `admin_users` row: PHP via a
   real `POST /auth/login.php` (plain username/password form, no CSRF —
   verified by reading that file), Next via a real
   `POST /api/auth/login` (the `@reya/auth` interface contract's own Route
   Handler). Every request on both stacks (login AND every page fetch) sends
   `Host: e2e-parity-harness.re-ya.com` so both stacks resolve the tenant via
   real subdomain routing — stricter than `run.mjs`'s own bridge harness,
   which deliberately relies on PHP's legacy-DB fallback instead (see that
   script's comments); safe here because this fixture's `tenants.db_name`
   row points at the exact same physical database either resolution path
   would land on.
5. Fetches + extracts + diffs all 14 page-pair entries (§3).
6. Always tears down — `docker compose down -v` **and** kills the Next child
   process — in a `finally` block, even on a thrown error.
7. Prints exactly one JSON line, then exits 0 (PASS) or 1 (FAIL).

Debug aid (optional, off by default): set `PARITY_DUMP_HTML=1` to dump the
raw PHP/Next HTML for any page-pair that mismatches to
`/tmp/parity-debug-<page>-{php,next}.html` — this is how the two React-SSR
quirks mentioned above were actually diagnosed while building this harness.

## 2. How to read the JSON output

Extends `run.mjs`'s own `{result, steps, failedAt}` envelope with a `pages`
array:

```json
{
  "result": "PASS" | "FAIL",
  "pages": [
    { "page": "users:baseline", "ok": true, "mismatches": [] },
    { "page": "user-detail:id=1", "ok": false, "mismatches": ["totalSpent: php=555 next=null"] },
    ...
  ],
  "steps": { "compose_up": {"ok": true}, ... },
  "failedAt": null | "<step-or-page_parity>"
}
```

- `result:"PASS"` iff **every** entry in `pages` has `ok:true`. A single
  broken/missing page fails the whole run (`failedAt:"page_parity"`) but
  never aborts the OTHER page-pairs or skips teardown — each entry runs
  through its own try/catch (`runPagePair()` in `parity.mjs`), so e.g. a
  temporarily-missing Next route surfaces as
  `{"ok":false,"mismatches":["extraction/fetch error: ... expected 200, got 404"]}`
  for JUST that entry, never a crash.
- `steps` is infra-level bookkeeping (compose up, DB seed, Next build/start,
  login) — a failure here (e.g. `build_admin`) means the harness never even
  got to comparing pages; `failedAt` names the exact step.
- On a passing page, `mismatches` is always `[]`. On a failing page, each
  string is `"<field path>: php=<value> next=<value>"` — directly
  diagnosable without re-running anything.

## 3. The three page pairs + their extraction lists

All extraction logic lives in `infra/e2e/lib/extract.mjs` — read that file's
module doc before changing anything here; it explains the label-anchored
(not class-anchored) extraction strategy and the two React-SSR quirks it
works around.

### `/users` (PHP: `users.php?tab=line&...`, Next: `/users?...`)

Run once per filter combo (`users:<name>` in `pages`): `baseline`, `search`,
`tag`, `tier`, `points`, `activity`, `purchase-purchased`, `purchase-never`,
`status`.

Extraction list per combo:
- `totalUsers` — from the page-header subtitle ("ทั้งหมด N คน").
- `rows` — up to 20 `{displayName, tags}` entries, **in row order**
  (`created_at DESC`, same on both stacks). `tags` is SORTED before
  comparison (both stacks' `GROUP_CONCAT` have no `ORDER BY`, so tag order
  within a row is not a meaningful signal — see `queries.ts`'s own doc
  comment on this).
- `activeFilterCount` — the toolbar's "ตัวกรอง" filter-count badge.
- `computedTotalPages` — `ceil(totalUsers / 20)`, cross-checked against
  `paginationVisible` (the pagination nav only renders when `totalPages > 1`
  on both stacks).

### `/user-detail?id=N` (PHP: `user-detail.php?id=N`, Next: `/user-detail?id=N`)

Run for 3 seeded IDs (`user-detail:id=<N>` in `pages`): `1` (rich — tags,
`points_transactions`, `transactions`, a completed video call), `2` (medium —
1 tag, 1 transaction, earn-only points history), `11` (empty/new — no tags,
no `transactions`/`orders` rows in either table, no `points_transactions`,
no `loyalty_points` row).

Extraction list: `displayName`, `availablePoints`/`totalPoints`/`usedPoints`,
`orderCount` + `totalSpent` (both off `transactions`, NOT `orders` — see the
fixture file's own comment on that quirk), `messageCount`, `tierLabel`,
`tags` (sorted set), `recentTransactionCount` (counts `order-detail` links).

### `/dashboard?tab=executive` (PHP: `dashboard.php?tab=executive`, Next: `/dashboard?tab=executive`)

Extraction list: the 5 primary KPI numbers (messages today, customers
contacted, orders, revenue, video calls), the 3 attention-zone numbers (avg
response time, unread count, problem count), admin-performance row count,
hourly-activity total (sum of the 24 hourly buckets — see `extract.mjs` for
why Next's value is derived via a proven-equivalent fallback, not read
directly off its chart), problem-message count (the section badge, a
SEPARATE render path from the KPI tile), recent-conversation count (counts
avatar images in that section).

### `/dashboard?tab=crm` (PHP: `dashboard.php?tab=crm`, Next: `/dashboard?tab=crm`)

Extraction list: the 4 CRM KPI numbers (total customers, new today, total
tags, auto rules), plus tags/auto-rules/recent-customers row counts (tags
and auto-rules counted via known fixture names — `FIXTURE_TAG_NAMES` /
`FIXTURE_AUTO_RULE_NAMES` in `extract.mjs`, kept in sync with the fixture
file by hand since both are owned by this same batch; recent-customers
counted via avatar-image occurrences, same technique as the executive tab).

## 4. Acceptance evidence (rehearsed in this environment)

- `node infra/e2e/parity.mjs` → clean run from `docker compose down -v`:
  `{"result":"PASS", ...}` with all 14 `pages` entries `ok:true`,
  `docker ps -a` empty afterward.
- Deliberately broke the Next side (renamed
  `apps/admin/src/app/(tenant)/user-detail/page.tsx` away before running,
  restored it after): `{"result":"FAIL", ...}`, exactly the 3
  `user-detail:id=*` entries `ok:false` with
  `"extraction/fetch error: ... expected 200, got 404"`, the OTHER 11 page
  entries still `ok:true`, `docker ps -a` still empty afterward — no hang,
  no crash, no leftover containers.
- `node infra/nginx/generate-routes.mjs` runs clean against the updated
  `routes.json` (7 routes, schema-valid) and regenerates
  `infra/nginx/generated/strangler-edge.conf`.

**Known caveat on the "byte-for-byte" generated-conf check**: the generator
(`infra/nginx/generate-routes.mjs`, owned by Phase 0 — not modified by this
batch) embeds a `# Generated at : <ISO timestamp>` line that is, by
construction, different on every invocation. A true byte-for-byte diff
across two SEPARATE runs (e.g. the committed file vs. a fresh CI
regeneration) will always show that one line different even when nothing
else changed. This is a pre-existing generator characteristic, not
introduced here — flagged for mig-orchestrator/mig-verify to either
special-case that line or ask Phase 0's owner to make it deterministic.

**Known blocker on committing the generated conf**: `.gitignore` has a
blanket `generated/` rule (line ~240) that currently catches
`infra/nginx/generated/strangler-edge.conf` too — the file is NOT presently
tracked by git at all (verified: `git ls-files infra/nginx/generated/`
returns nothing, even though the file exists on disk and this batch's brief
asks for it to be "commit[ted]... alongside the routes.json edit"). `packages/
db/src/generated/` has an explicit `!`-negation exception to this same
blanket rule for exactly this reason (a generated file that must still be
tracked) — `infra/nginx/generated/strangler-edge.conf` needs the same
treatment before anyone can `git add` it without `-f`. `.gitignore` is
outside this agent's allowed paths (shared repo-wide config, not
`infra/e2e/**`/`infra/nginx/routes.json`/`infra/nginx/generated/**`/
`docs/runbooks/**`) — flagged here for whoever does the commit, not fixed
unilaterally.

## 5. The exact routes.json edit + generate-routes.mjs command for the future
   canary ramp (mig-orchestrator's job, not this agent's)

This batch added THREE schema-valid placeholder entries to
`infra/nginx/routes.json` — `/users`, `/user-detail`, `/dashboard` — each
`{"upstream": "php_backend", "tenants": "all"}`. Since `php_backend` is
already the strangler default (plan §1.5), these are functional no-ops
today; they exist only so the future flip has a named line to edit, same
pattern as the pre-existing `/admin-preview` placeholder.

**IMPORTANT — the `/dashboard` entry, not `/`:** the hand-off instructions
this batch started from said to add a route for `'/'`. That is WRONG and was
corrected before implementation: `/` is `index.php`, the unrelated public
storefront/landing page (already the Phase-0-committed `php_backend`
catch-all — Phase 13 scope, not this batch's). The real tenant KPI dashboard
is `dashboard.php` at path `/dashboard`, confirmed by
`apps/admin/src/nav/manifest.ts`'s already-ported nav (`'Dashboard'`'s href
is `/dashboard?tab=executive`, never `/`). Do not add a `/` entry for this
batch's dashboard work — one already exists and means something else
entirely.

When mig-orchestrator is ready to ramp ONE of these three routes (independently — they do not have to flip together) past the canary stages in plan §7.3 (demo tenant → 1 real tenant → 10% → 50% → 100%):

1. Edit that route's entry in `infra/nginx/routes.json`:
   - Demo-tenant-only canary: `"tenants": ["demo-tenant"]` (same shape as
     the existing `/admin-preview` example).
   - Wider ramp: `"tenants": ["demo-tenant", "<real-tenant-slug>", ...]`, or
     once ready for full cutover, `"upstream": "next_admin", "tenants": "all"`.
2. `node infra/nginx/generate-routes.mjs` — regenerates
   `infra/nginx/generated/strangler-edge.conf` from the edited `routes.json`
   (validates against `routes.schema.json` first; refuses to write on a
   schema violation).
3. `nginx -s reload` on the edge.
4. Rollback = revert that one line in `routes.json` + step 2 + step 3 again —
   no other file needs to change (this is the whole point of the route
   manifest, plan §1.5).

This agent does not perform step 1 for a real ramp — only mig-orchestrator
authorizes a route flip, per this agent's own "Do not" boundary.
