# Phase 2 batch 1 — /users, /user-detail, /dashboard parity harness

> **Phase 2 batch 2 update**: `infra/e2e/parity.mjs` and `infra/e2e/lib/extract.mjs`
> now ALSO cover `/analytics` (tabs overview/advanced/crm/account),
> `/activity-logs`, `/loyalty-members` — same file, same single JSON-line
> output, 27 `pages` entries total (the 14 below + 13 new). See the
> **"Phase 2 batch 2"** section at the bottom of this file for what's new;
> everything above this notice still describes batch 1's original 14 entries
> accurately and is unchanged.

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

---

# Phase 2 batch 2 — /analytics, /activity-logs, /loyalty-members

Owner: mig-infra (this harness extension) / mig-ui (the two page-porting
agents whose Next output this batch's new entries verify — same division of
labor as batch 1) / mig-orchestrator (route-flip authorization, unchanged).

This section documents ONLY what's new. Batch 1's own scope note, JSON output
shape, and general run mechanics (§1-2 above) all still apply unchanged —
read those first if you haven't already.

## 6. What's new — the three new page-pairs

`infra/e2e/parity.mjs` now fetches+extracts+diffs 13 additional page-pair
entries, appended to the SAME `pages` array batch-1's 14 already populate (27
total). Same `runPagePair()`-per-entry, independent-try/catch pattern — one
broken/missing new route fails as its own entry, never the others.

### `/analytics?tab={overview|advanced|crm|account}` (PHP: `analytics.php?tab=...`, Next: `/analytics?tab=...`)

4 entries (`analytics:tab=overview|advanced|crm|account`), config-driven via
`ANALYTICS_TABS` in `parity.mjs`.

- **overview** (`includes/analytics/overview.php` / `OverviewTab.tsx`):
  followers, newFollowers, activeUsers, messages, broadcasts +
  broadcastRecipients, orders, revenue, topTagsCount (known-name counting,
  reuses batch-1's `FIXTURE_TAG_NAMES`), topKeywordsCount (new
  `FIXTURE_KEYWORD_NAMES`), segmentsCount.
- **advanced** (`includes/analytics/advanced.php` -> `AnalyticsController` ->
  `AnalyticsModel` / `AdvancedTab.tsx`): the realtime bar's 4 numbers
  (activeUsers/messagesPerHour/ordersToday/revenueToday — rendered
  server-side into a Client Component's `useState(initial)`, so still present
  in the plain-fetch SSR HTML this harness reads), plus users/messages/orders/
  revenue KPI totals. Does **not** assert the Customer Funnel numbers or the
  Broadcast-engagement list — `FunnelChart` is a client island that fetches
  its own data via a Server Action AFTER mount (confirmed by reading
  `FunnelChart.tsx`), so it is NOT present in the initial SSR HTML this
  harness fetches; asserting it would require driving a real browser, out of
  this batch's scope (see §8's Playwright note below for what WAS captured
  instead).
- **crm** (`includes/analytics/crm.php` + `classes/AdvancedCRM.php` /
  `CrmTab.tsx`): totalUsers, activeUsers (Nd — needs the new
  `user_behaviors` fixture rows), newUsers (Nd), segmentsCount,
  topTagsRowCount (known-name counting again).
- **account** (`includes/analytics/account.php` + `classes/
  LineAccountManager.php` / `AccountTab.tsx`): verifies the
  "กรุณาเลือกบอทเพื่อดูสถิติ" (please select a bot) prompt + the account
  `<select>`'s presence — the ONLY reachable state in this harness (see §7's
  "why no `line_accounts` rows" below). This is a real, valid tenant state
  (a fresh tenant with no LINE OA connected yet shows exactly this), not a
  skipped/faked check.

**FLAGGED FINDING** (extract.mjs's own doc comment on this): the advanced and
CRM tabs' stat cards render in OPPOSITE label/value order from each other
(advanced: value-then-label `<p>{value}</p><p>label</p>`; CRM:
label-then-value `<p>label</p><p>{value}</p>`) — confirmed by reading both
PHP partials AND both `.tsx` ports in full, not assumed. The extractors use
`beforeLabel()`/`advanceTo()+afterLabel('</p>')` respectively, matching each
tab's own real markup.

### `/activity-logs` (PHP: `activity-logs.php`, Next: `/activity-logs`)

7 entries (`activity-logs:baseline|type|action|search|date-range|combined|page2`),
config-driven via `ACTIVITY_LOGS_COMBOS`. Extraction: `totalLogs` (the "N
รายการ" count), `rangeStart`/`rangeEnd` (the "แสดงรายการที่ X-Y จาก Z" line,
only rendered when totalLogs > 0), `rowCount` (`<td>` tags inside `<tbody>`
÷ 6 fixed columns — a structural count, not a text-label one, chosen because
neither the row nor its cells carry a class name genuinely shared between
PHP's bespoke markup and Next's Tailwind-utility port), `emptyStateShown`.

- `type=pharmacy` and `action=login` each exercise a real, non-trivial
  filter branch.
- `search=BATCH2SEARCHMARK` exercises `queries.ts`'s search clause (LIKE
  across description/user_name/admin_name) against 3 marker-tagged fixture
  rows.
- `date-range` applies an independent, NARROWER date bound (6-12 days ago)
  than every other combo's WIDE bound, to actually exercise the date-filter
  code path (not just avoid the login-noise window — see below).
- `combined` (`type=consent&action=login`) exercises `type` and `action`
  filters simultaneously.
- `page2` exercises OFFSET pagination (`ACTIVITY_LOGS_PER_PAGE = 50`, and
  this batch's fixture seeds 55 rows specifically so page 2 is non-empty).

**FLAGGED FINDING** (build report, not fixed here — `classes/AdminAuth.php`,
`packages/auth/**` are out of this agent's allowed paths): `classes/
AdminAuth.php::login()` calls `ActivityLogger::logAuth(ACTION_LOGIN, ...)`
on every successful PHP login, which `phpLogin()` triggers — so PHP's
`activity_logs` table ends up with exactly ONE more row
(`log_type='auth', action='login'`, dated "today") than Next's after login,
for the rest of the run. `internal/session-bridge.php`'s `'login-sync'`
action (what `nextLogin()`'s bridge-sync hits) only assigns raw `$_SESSION`
keys — verified by reading the full file — it never touches
`activity_logs`. This is a genuine backend asymmetry in production too (a
real admin who logs in via the new Next UI today leaves no `activity_logs`
audit trail for that login, unlike the legacy PHP UI), independent of this
harness. **Workaround applied here** (not a fix): every fixture row in
`activity_logs` is dated 2-21 days in the past (never "today"), and every
`activity-logs` combo in `parity.mjs` applies a `date_to` bound of
"yesterday" (computed fresh each run via `bangkokDateString(-1)`, never
hardcoded) so the PHP-only login row is excluded from every comparison on
both stacks. Flagged for mig-orchestrator: a follow-up ticket may want
`@reya/auth`'s `login()` to also write an `activity_logs` row for real
production audit-trail parity — independent of and not blocking this
harness.

### `/loyalty-members` (PHP: `loyalty-members.php`, Next: `/loyalty-members`)

2 entries (`loyalty-members:baseline|search`). Extraction: the 3 stat-card
numbers (total/points/today) and which (if either) empty-state message is
shown (`no-members` vs `no-search-results`).

**Both entries assert the EMPTY state, not a populated member list** — see
§7 below for the full "why", but in short: `loyalty-members.php`'s (and its
`queries.ts` port's) entire query is gated behind `if ($lineAccountId > 0)`
/ `session.currentBotId ?? 0`, and this harness's shared PHP session NEVER
has a bot auto-selected (deliberately — see §7). This is still a real,
meaningful assertion: it verifies BOTH stacks read that exact same gate
condition identically (a regression where one side's gate check drifted from
the other's — e.g. PHP's `> 0` vs a hypothetical Next `>= 0` — would be
caught here as a mismatch), not a vacuous always-passing check. The `users`
rows seeded with `line_user_id LIKE 'offline:%'` (ids 101-105, exercising
`lmName()`'s 3-branch display-name fallback) are written per this batch's
brief for completeness and are ready for a future harness revision that DOES
wire up a real bot selection — they are simply unreachable in THIS run.

## 7. Why this batch's fixture seeds NO `line_accounts` rows

This is the single most important design decision in
`infra/e2e/seed/40-phase2-batch2-fixture.sql.tmpl` — read that file's own
(longer) header comment for the full mechanical trace through
`includes/header.php` / `classes/AdminAuth.php` / `packages/auth/src/
session.ts`. Summary:

- PHP's `includes/header.php` (required by EVERY protected admin page)
  auto-selects a bot into `$_SESSION['current_bot_id']` the FIRST time it
  runs for a session with none set yet, IF `line_accounts` has any
  `is_active=1` row (our seeded e2e admin is `super_admin`, so this always
  triggers the instant any such row exists). That selection is STICKY for
  the rest of that PHP session — every subsequent page fetch, not just the
  one that triggered it.
- Next's session has NO equivalent auto-select — `currentBotId` is `null`
  from `login()` onward, forever, unless something explicitly calls
  `switchBot()` (nothing in `apps/admin` does yet).
- Since `parity.mjs`'s `phpSid` is ONE shared PHP session across ALL 27
  page-pair fetches (batch-1's AND this batch's), seeding even one
  `line_accounts` row would have silently re-scoped several of batch-1's
  ALREADY-PASSING queries (`includes/dashboard/crm.php`'s
  `(line_account_id = ? OR ? IS NULL)` PARAM-nullness pattern, specifically)
  on the PHP side only, while Next stayed unscoped — corrupting entries this
  batch was never supposed to touch. This is a correctness hazard, not a
  style choice — this exact failure mode was reasoned through analytically
  before any `line_accounts` row was ever seeded (never actually triggered
  against a running stack, precisely because it was avoided from the start).
  The "acceptance criterion: all 27 entries pass, not just the new 13" check
  is what would catch a regression here if a future change ever
  re-introduces a `line_accounts` row into this fixture.
- Net effect: `/analytics`'s overview/advanced/crm tabs all use either the
  `(line_account_id = ? OR line_account_id IS NULL)` COLUMN-nullness pattern
  or a plain "truthy lineAccountId ? filter : no filter" check — BOTH
  evaluate to "match everything with `line_account_id IS NULL`" (or
  literally everything, for the truthy-check tables) when `lineAccountId` is
  null, which it always is here — so this batch's new fixture rows
  (`line_account_id = NULL` throughout) show up correctly. `/loyalty-members`
  and the `/analytics?tab=account` tab, by contrast, are ENTIRELY gated on a
  real bot being selected, so they render their (real, valid) empty/prompt
  states instead — see their own sections above.

## 8. Optional Playwright screenshots (non-gating) — SKIPPED this batch

Verified before deciding, per the brief's own instruction: a Chromium build
IS available at `/opt/pw-browsers` in this environment, but the `playwright`
npm package itself is not installed anywhere in this repo (no
`node_modules/playwright`, no `package.json` dependency, in either the root
workspace or `apps/admin`). Per this batch's own brief ("OPTIONAL,
non-gating... Skip entirely if it risks slipping the harder requirements
below"), this was intentionally skipped: adding a net-new `playwright`
dependency and writing/running a screenshot script would mean an additional
`npm install` plus at least one more full docker-compose-up +
`pnpm --filter admin run build` + standalone-server cycle (each already
costing several minutes in this environment, confirmed by the four full
harness runs actually executed for the real, gating deliverable above) for a
convenience artifact this brief explicitly does not gate on. `infra/e2e/
parity.mjs`'s data-point parity (§9 above) is the actual, complete evidence
for this batch's acceptance criteria. Not attempted; not partially done —
flagged here so mig-orchestrator doesn't assume screenshots exist under
`infra/e2e/screenshots/` when they don't.

## 9. Acceptance evidence (rehearsed in this environment, Phase 2 batch 2)

- `node infra/e2e/parity.mjs` → clean run from a fully torn-down state:
  `{"result":"PASS", ...}` with **all 27** `pages` entries `ok:true` (the
  original 14 from batch 1 + 4 `analytics:tab=*` + 7 `activity-logs:*` + 2
  `loyalty-members:*`), `docker ps -a` empty afterward.
- Deliberately broke the Next side (renamed `apps/admin/src/app/(tenant)/
  activity-logs/page.tsx` away before running, restored it after):
  `{"result":"FAIL", ...}`, exactly the 7 `activity-logs:*` entries
  `ok:false` with `"extraction/fetch error: ... expected 200, got 404"`, the
  OTHER 20 entries (batch-1's 14 AND this batch's `analytics:*`/
  `loyalty-members:*`) still `ok:true`, `docker ps -a` still empty
  afterward — no hang, no crash, no leftover containers. Re-ran clean after
  restoring: `{"result":"PASS", ...}`, all 27 `ok:true` again.
- `node infra/nginx/generate-routes.mjs` runs clean against the updated
  `routes.json` (now **10 routes**: the original 7 + `/users` +
  `/user-detail` + `/dashboard` from batch 1, + `/analytics` +
  `/activity-logs` + `/loyalty-members` from this batch) and regenerates
  `infra/nginx/generated/strangler-edge.conf` without a schema-validation
  error.
- Same "Generated at" nondeterministic-timestamp caveat as batch 1's own §4
  note still applies — not reintroduced or worsened by this batch.

**`.gitignore` blocker from batch 1's §4 — RESOLVED, not re-flagged**: batch
1's own write-up flagged that `.gitignore`'s blanket `generated/` rule
caught `infra/nginx/generated/strangler-edge.conf`, leaving it untracked
despite needing to be committed. Verified in this batch: `.gitignore` NOW
has `!infra/nginx/generated/` + `!infra/nginx/generated/*.conf` negation
lines (same pattern as `packages/db/src/generated/`'s existing exception),
and `git ls-files infra/nginx/generated/` DOES list the file — it is tracked
today. This was resolved somewhere between batch 1's write-up and this
batch's start (visible in `git log -- infra/nginx/generated/
strangler-edge.conf`, still a single commit — batch 1's own merged commit
already contains the fix, despite that batch's runbook text claiming
otherwise at write time). Nothing to do here; noted so mig-orchestrator
doesn't waste time re-investigating a already-closed item.

## 10. The exact routes.json edit + generate-routes.mjs command — updated for all SIX eligible routes

This batch added THREE more schema-valid placeholder entries to
`infra/nginx/routes.json` — `/analytics`, `/activity-logs`,
`/loyalty-members` — each `{"upstream": "php_backend", "tenants": "all"}`,
identical shape to batch 1's `/users`/`/user-detail`/`/dashboard` entries.
`routes.json` now has **10 routes total**: the original 7 (`/`, `/miniapp`,
`/ws`, `/admin-preview` + the 3 from batch 1) + these 3 new ones. All
functional no-ops today (`php_backend` is already the strangler default).

**All SIX routes now eligible for independent canary ramp** (plan §7.3:
demo tenant → 1 real tenant → 10% → 50% → 100%), each parity-proven by this
harness:

| Route | Parity-proven by | Owning brief |
|---|---|---|
| `/users` | `users:*` (9 entries) | Phase 2 batch 1 |
| `/user-detail` | `user-detail:id=*` (3 entries) | Phase 2 batch 1 |
| `/dashboard` | `dashboard:tab=*` (2 entries) | Phase 2 batch 1 |
| `/analytics` | `analytics:tab=*` (4 entries) | Phase 2 batch 2 |
| `/activity-logs` | `activity-logs:*` (7 entries) | Phase 2 batch 2 |
| `/loyalty-members` | `loyalty-members:*` (2 entries) | Phase 2 batch 2 |

When mig-orchestrator is ready to ramp ONE of these six routes
(independently — they do not have to flip together):

1. Edit that route's entry in `infra/nginx/routes.json`:
   - Demo-tenant-only canary: `"tenants": ["demo-tenant"]`.
   - Wider ramp: `"tenants": ["demo-tenant", "<real-tenant-slug>", ...]`, or
     once ready for full cutover, `"upstream": "next_admin", "tenants": "all"`.
2. `node infra/nginx/generate-routes.mjs` — regenerates
   `infra/nginx/generated/strangler-edge.conf` (validates against
   `routes.schema.json` first; refuses to write on a schema violation).
3. `nginx -s reload` on the edge.
4. Rollback = revert that one line in `routes.json` + step 2 + step 3
   again — no other file needs to change, for ANY of the six routes,
   independently of the others.

This agent does not perform step 1 for a real ramp for ANY of the six routes
— only mig-orchestrator authorizes a route flip, per this agent's own "Do
not" boundary (unchanged from batch 1).
