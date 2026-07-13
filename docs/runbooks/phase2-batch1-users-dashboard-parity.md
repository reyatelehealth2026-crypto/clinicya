# Phase 2 batch 1 — /users, /user-detail, /dashboard parity harness

> **Phase 2 batch 2 update**: `infra/e2e/parity.mjs` and `infra/e2e/lib/extract.mjs`
> now ALSO cover `/analytics` (tabs overview/advanced/crm/account),
> `/activity-logs`, `/loyalty-members` — same file, same single JSON-line
> output, 27 `pages` entries total (the 14 below + 13 new). See the
> **"Phase 2 batch 2"** section at the bottom of this file for what's new;
> everything above this notice still describes batch 1's original 14 entries
> accurately and is unchanged.
>
> **Phase 2 batch 3 update**: `infra/e2e/parity.mjs` and
> `infra/e2e/lib/extract.mjs` now ALSO cover `/templates`, `/groups`
> (baseline + `?view=N`), `/line-groups`, `/line-group-detail?id=N`,
> `/crm-dashboard-advanced`, `/system-status` — same file, same single
> JSON-line output, **42** `pages` entries total (the 27 above + 15 new). See
> the **"Phase 2 batch 3"** section at the bottom of this file for what's
> new, including two genuinely new PHP defects this batch's own harness run
> discovered (not previously flagged by any prior batch or brief) and how
> the harness proves them rather than silently mis-reporting them as Next
> bugs.
>
> **Phase 3 batch 1 (sibling harness, not an update to this one)**: the
> ported `/api/miniapp/**` JSON endpoints (resolve-line-account,
> points-history, shop-products, health-profile, member, rewards, wishlist)
> are proven by a SEPARATE script/file, `infra/e2e/api-parity.mjs` +
> `infra/e2e/lib/api-extract.mjs` — a different harness (JSON request/response
> diffing, not server-rendered HTML data-point extraction) with its own
> `{result, endpoints, steps, failedAt}` output shape. See
> `docs/runbooks/phase3-batch1-miniapp-api-parity.md`. It is a sibling to this
> file, not a batch appended to it, and — like `parity.mjs`/`run.mjs` — cannot
> run concurrently with this harness (same fixed container names/ports in
> `infra/e2e/docker-compose.yml`).

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

---

# Phase 2 batch 3 — /templates, /groups, /line-groups, /line-group-detail, /crm-dashboard-advanced, /system-status

Owner: mig-infra (this harness extension) / mig-ui (two page-porting agents,
"pagesA" = crm-dashboard-advanced + system-status, "pagesB" = templates +
groups + line-groups + line-group-detail — whose Next output this batch's
new entries verify) / mig-orchestrator (route-flip authorization, unchanged).

This section documents ONLY what's new. Batches 1-2's own scope note, JSON
output shape, and general run mechanics (§1-2 near the top of this file)
all still apply unchanged — read those first if you haven't already.

**Correction to this batch's own hand-off**: the brief that started this
batch said "5 new pages/routes" — that was wrong and corrected before
implementation. There are 6 distinct PHP source files (crm-dashboard-advanced,
system-status, templates, groups, line-groups, AND line-group-detail), so 6
distinct Next routes — `line-groups.php` and `line-group-detail.php` are two
separate PHP files/pages, not one, per pagesB's own grep-verified `?id=`
finding (confirmed independently here by reading both files in full).

## 11. What's new — the six new page-pairs (15 new `pages` entries)

`infra/e2e/parity.mjs` now fetches+extracts+diffs 15 additional page-pair
entries, appended to the SAME `pages` array batches 1-2's 27 already
populate (**42 total**). Same `runPagePair()`-per-entry, independent-
try/catch pattern for the four normal page-pairs — one broken/missing route
fails as its own entry, never the others — PLUS one new pattern,
`runSingleSideCheck()`, for the two pages whose PHP side does not return 200
at all (see §13below).

### `/templates` (PHP: `templates.php`, Next: `/templates`) — 1 entry

`templates:baseline`. `templates.php` has no query-param filters at all —
its category filter is 100% client-side JS on both stacks (`filterCategory()`
/ `TemplatesClient.tsx`'s `activeCategory` state), so one baseline fetch
covers the whole page. `templates.line_account_id` is nullable and the page
never filters on it (global CRUD — confirmed by reading both `templates.php`
and `templates/queries.ts` in full) — a straightforward fixture, no
`$currentBotId` concerns at all for this page.

Extraction (`extractTemplatesPage` in `infra/e2e/lib/extract.mjs`): the
category-filter-bar's button labels IN RENDER ORDER (`['ทั้งหมด', 'FAQ',
'ทักทาย', 'โปรโมชั่น']` — exercises `templates/_lib/categories.ts`'s
`array_unique`-equivalent first-seen-order dedupe, NOT alphabetical
`.sort()`), plus one `{name, categoryDisplay, messageType}` tuple per
rendered template card, in `ORDER BY category, name` row order (5 fixture
rows, both `message_type` values present). Anchored on the `data-category="…"`
attribute (the ONE thing PHP's bespoke `.template-card` markup and Next's
Tailwind-utility `TemplateCard.tsx` genuinely share byte-for-byte) as a
per-card delimiter — the category VALUE itself is still read from each
card's own visible text, not trusted from the attribute, so a future
attribute/text drift would still be caught.

### `/groups` (PHP: `groups.php?view=N`, Next: `/groups?view=N`) — 3 entries

`groups:baseline|view-empty|view-members`. `view-empty` (fixture group id 1)
and `view-members` (fixture group id 2, >=2 members) are two DIFFERENT real
detail-panel states, not the same state fetched twice.

Extraction (`extractGroupsPage`): the left-hand groups list (name +
memberCount per row, `ORDER BY g.name` order — group rows delimited by their
own `href` containing `view=<id>"`, a literal substring both stacks share by
construction since it's the same URL query param) and, when `?view=` resolves
to a real group, the right-hand detail panel (group name + description +
member rows). Same function handles both the baseline and `?view=` variants
per the brief — the detail-panel fields are simply `null`/`[]` when no
`viewGroup` resolved.

### `/line-groups` (PHP: `line-groups.php`, Next: `/line-groups`) — 1 entry

`line-groups:baseline`. No query-param filters (its actions — leave group,
send message — are all POST-only mutations, out of this read-only harness's
scope).

Extraction (`extractLineGroupsPage`): the 4 stats-card numbers (total/
active/totalMembers/totalMessages — genuinely class-identical on both
stacks, `text-3xl font-bold text-{blue,green,purple,orange}-500`), the "N
กลุ่ม" list-header count, and one row per group ({groupName, botName,
memberCount, totalMessages, isActive}, `ORDER BY is_active DESC, joined_at
DESC` order) — `botName` is `-` on every row (LEFT JOIN to the deliberately-
empty `line_accounts` table, per §12 below), a deterministic, fully-
comparable state.

### `/line-group-detail?id=N` (PHP: `line-group-detail.php?id=N`, Next: `/line-group-detail?id=N`) — 2 + 4 entries

`line-group-detail:id=1|2` (members/messages diff) PLUS
`line-group-detail:php-header-defect id=1|2` +
`line-group-detail:next-header id=1|2` (the header-defect exception — see
§13.1 below). id=1 is the ACTIVE group with seeded members/messages (mixed
`is_active`, one `sticker`-type message to exercise the `[message_type]`
prefix branch, one 158-Unicode-code-point message to exercise the
`mb_substr(…, 0, 100)` truncation + `'...'` suffix branch); id=2 is the
INACTIVE/left group with NO members/messages rows, exercising both empty-
state branches. `line-group-detail.php?id=N` is confirmed (by reading the
full PHP source) to be a SEPARATE top-level route from `line-groups.php`,
matching `/user-detail?id=N`'s established precedent — not a nested
`/line-groups/[id]`.

Extraction (`extractLineGroupDetailPage`, members/messages ONLY — see §13.1
for why the header is excluded): members-panel heading count + emptyShown +
per-member {displayName, totalMessages} pairs + left-count; messages-panel
emptyShown + row count + per-message {displayName} + non-'text'-type-prefix
count + truncated-message count. Two harness bugs, caught by this batch's
own unit tests (`/tmp/.../scratchpad/test/test-extract.mjs`, not shipped —
see §14) BEFORE ever touching the real docker stack, then confirmed fixed
against real dumped HTML:
  - React SSR inserts a `<!-- -->` hydration-boundary comment between
    `{formatNumber(member.totalMessages)}` and the adjacent conditional
    `{member.lastMessageAt ? … : ''}` — a raw `/ข้อความ:\s*(\d+)/` regex
    silently failed to match across it. Fixed by stripping HTML comments
    from the members/messages slices before any regex runs against them.
  - `sliceMainContent()`'s `<main>`-onward slice is, by construction,
    UNBOUNDED at the end — every other extractor in this file only ever
    reads BOUNDED sub-slices (`sliceUntil([...])` to a known next heading),
    but this was the first one to read "the rest of the document" for the
    messages panel, which on Next's real output sweeps in a trailing
    `self.__next_f.push(...)` RSC hydration payload `<script>` containing a
    JSON-re-serialization of the SAME page content with different escaping
    — inflating both `messageRowCount` and `messageTruncatedCount` with
    spurious matches. Fixed by bounding the messages slice at the first
    `<script` tag after the messages heading.

## 12. The `$currentBotId`/no-`line_accounts` decision (joint with mig-ui)

Per this batch's brief, the default is NO — reuse batch 2's documented
invariant (§7 above) — unless jointly verified otherwise. **Outcome: NO,
confirmed correct, no override needed.**

- `groups.php` (`require_once 'includes/header.php'` on line 11, BEFORE any
  of its own queries) inherits header.php's sticky auto-selected
  `$currentBotId`. Seeding even one `is_active=1` `line_accounts` row would
  re-trigger that auto-select on parity.mjs's ONE shared `phpSid` session —
  silently re-scoping every EARLIER batch's already-passing
  `line_account_id`-sensitive query for the rest of the run, per the exact
  hazard batch 2's fixture file already reasoned through in full. Not worth
  it: `getAllUsersForGroups()`'s `(line_account_id = ? OR line_account_id IS
  NULL)` single-bind pattern already resolves to "every `line_account_id IS
  NULL` row" when `$currentBotId` is null, which is exactly what every
  batch 1/2/3 `users` row is.
- `line-groups.php` (`$currentBotId = $_SESSION['current_bot_id'] ?? null;`,
  its OWN local read, on line 15 — BEFORE `require_once
  'includes/header.php'` on line 130) never even reaches header.php's
  auto-select before running its own queries, and no page in this harness's
  fixture ever sets `$_SESSION['current_bot_id']` (no `?switch_bot=` fetch,
  no active `line_accounts` row for header.php to auto-select from on ANY
  page fetch, in this batch or any earlier one) — so `$currentBotId` is null
  here regardless. Seeding a `line_accounts` row would ALSO risk
  retroactively feeding header.php's auto-select on some EARLIER page in the
  shared session.

Net: this batch's fixture (`infra/e2e/seed/60-phase2-batch3-fixture.sql.tmpl`)
seeds ZERO `line_accounts` rows, same as batch 2. `line_groups.line_account_id`
(NOT NULL, no FK) uses synthetic ids (9001/9002 — match no real
`line_accounts` row); `line_group_members`/`line_group_messages.line_account_id`
(NOT NULL DEFAULT 1) use the owning group's synthetic id, purely for fixture
readability (neither table is ever filtered by `line_account_id` in either
stack — grepped both). The **acceptance criterion "all 42 entries pass, not
just the new 15" is what would catch a regression here** if a future change
ever re-introduces a `line_accounts` row into this fixture — exact same
mechanism batch 2's own §7 already established.

## 13. Two genuinely new findings — discovered by THIS batch's own harness run

Both of the following were found empirically, by actually fetching real
authenticated PHP pages this harness is the FIRST to exercise — neither is
visible from reading the relevant PHP file in isolation, and neither was
flagged by pagesA/pagesB's own (thorough) module-doc write-ups. Both are
handled the same way crm-dashboard-advanced's `crm_deals`/`crm_tickets`
finding was handled by pagesA: POSITIVELY asserted, not silently absorbed
into a false "Next is broken" diff mismatch.

### 13.1 `line-group-detail.php`'s header is permanently broken in production

**Root cause** (confirmed by reading `includes/header.php` in full):
`foreach ($menuGroups as $group) { … }` at header.php line 449 (no
`unset($group)` afterward) reuses the exact same variable name `$group` as
`line-group-detail.php`'s own fetched DB row. `require_once
'includes/header.php'` (line-group-detail.php line 58) is a plain top-level
include, not a function call, so both files share the SAME global scope —
header.php's loop OVERWRITES `line-group-detail.php`'s `$group` with
header.php's own LAST menu-group array entry, BEFORE the HTML body ever
reads it. `$pageTitle` (line 29, computed BEFORE header.php runs) is
unaffected and correctly shows the real group name in `<title>`/the sidebar
breadcrumb — only the page's own H1/badges/status/type text see the
clobbered value. Verified against BOTH id=1 (real group, active, 3 members,
42 messages) AND id=2 (real group, inactive, 1 member, 5 messages) — both
render the IDENTICAL broken output ("Unknown Group", 0, 0, "Left" badge,
"Group" not "Room", regardless of the real data) — confirming the defect is
structural, not data-dependent.

**Handling**: `extractLineGroupDetailPage()` no longer reads the header
fields AT ALL (diffing Next's genuinely-correct header against PHP's
genuinely-broken one would just look like "Next has a bug" and bury the
real finding). Two new single-stack functions positively assert each
stack's own reality:
  - `extractLineGroupDetailHeaderPhpDefect(html)` — throws unless PHP shows
    EXACTLY the known-broken pattern (groupName="Unknown Group",
    memberCountBadge=0, totalMessagesBadge=0, isActive=false,
    groupType="group"). Catches a future fix to header.php's variable
    collision.
  - `extractLineGroupDetailHeaderNext(html, expected)` — throws unless
    Next shows the REAL fixture data (`LINE_GROUP_DETAIL_EXPECTED_HEADER` in
    `parity.mjs`, one lookup table per id, same "kept in one place"
    precedent as `FIXTURE_TAG_NAMES`).

Wired up as 4 additional `runSingleSideCheck()` entries (one PHP + one Next,
per id), mirroring `runCrmDashboardAdvancedChecks()`'s own precedent.
**This is a real, reproducible PRODUCTION defect** — every request to
`line-group-detail.php`, for every group, on the live site, shows the wrong
header today. Flagged here for mig-orchestrator; not fixable by this agent
(`line-group-detail.php`/`includes/header.php` are off-limits).

### 13.2 `system-status.php`'s "Current Bot ID" footer is ALSO clobbered (same bug class, third instance)

Same root cause as §13.1, this time for `$currentBotId`: header.php line
~172 (`$currentBotId = $currentBot['id'] ?? null;`) runs via `require_once
'includes/header.php'` on system-status.php line 176 — AFTER the 19-check
section (lines 22-173) already computed its OWN `$currentBotId =
$_SESSION['current_bot_id'] ?? 1` at line 16 and correctly used it for the
`message_stats`/`user_stats` queries, but BEFORE the "System Info" footer's
`<?php echo $currentBotId; ?>` renders. Confirmed via a real page dump:
`<span class="font-medium"></span>` — empty, not "1". **The 19 health
checks themselves are UNAFFECTED** (all run and render before header.php's
clobbering assignment) — only this one decorative footer field is tainted.

**Handling**: given two prior instances of this exact bug class already
have dedicated exception mechanisms in this batch, a THIRD parallel
mechanism for one low-value decorative field was judged not worth the
harness complexity — `extractSystemStatusPage()` simply does not read
`currentBotId` at all anymore. Flagged here (not silently absorbed) as a
real, reproducible finding for mig-orchestrator — a plausible root-cause
pattern worth a dedicated audit across every PHP page that both (a)
`require_once`s `includes/header.php` and (b) itself uses a bare `$group`
or `$currentBotId` variable name after that include point.

### 13.3 `crm-dashboard-advanced`'s default tab ALSO 500s (a second gap beyond pagesA's `crm_deals`/`crm_tickets` finding) — FIXED (mig-verify parity-miss)

**Status: fixed.** mig-verify caught this exact 500 live via
`node infra/e2e/parity.mjs` (`crm-dashboard-advanced:next-overview-500-expected`
asserting-and-getting a 500 was itself the gate failure — the check below was
originally written to positively assert a known-bad state, which is a
regression detector, not a passing gate). The fix: `getRevenueAnalytics()` in
`apps/admin/src/app/(tenant)/crm-dashboard-advanced/queries.ts` now wraps its
`odoo_webhooks_log` query in the same try/catch-with-documented-empty-default
shape as every crm_deals/crm_tickets sibling query in that file (empty
`daily` series on failure; `summary` is untouched — already an unconditional
hardcoded placeholder in both PHP and this port). `queries.test.ts` and
`page.test.tsx` gained matching regression coverage (simulating the
`odoo_webhooks_log.created_at` `ER_BAD_FIELD_ERROR`, previously invisible to
`page.test.tsx`'s fake-DB harness since it only simulated missing
crm_deals/crm_tickets/crm_ticket_interactions tables). `parity.mjs`'s
`crm-dashboard-advanced:next-overview-500-expected` entry has been re-wired
to `next-overview-200-defensive-empty`, asserting a normal 200 +
`extractCrmDashboardAdvancedDefensiveEmpty()` instead, per the "Handling"
re-wire plan originally documented below (kept for history).

The original finding, as first discovered, follows unchanged:

Per this batch's brief (decision 2, pagesA's own CRITICAL FINDING):
`crm_deals`/`crm_tickets` are absent from the committed tenant template, so
PHP's own `crm-dashboard-advanced.php` throws an uncaught `PDOException` and
returns 500 — a pre-existing PHP defect, and queries.ts's "AUTHORIZED
RESOLUTION" defensively wraps every `crm_deals`/`crm_tickets`-touching query
in Next's port so the page returns 200 with documented empty defaults
instead.

**This batch's own harness run discovered that resolution is INCOMPLETE**:
`getRevenueAnalytics()` in queries.ts queries `odoo_webhooks_log.created_at`
with NO try/catch (unlike every sibling `crm_deals`/`crm_tickets`-touching
query in the same file) — and `odoo_webhooks_log` genuinely has no
`created_at` column in the committed tenant template (it has `received_at`/
`processed_at` instead; confirmed via a real `ER_BAD_FIELD_ERROR` in Next's
own server log). This is a FAITHFUL 1:1 port of PHP's own
`CRMDashboardService::getRevenueAnalytics()` (identical query — confirmed by
reading `classes/CRMDashboardService.php` lines 701-724) — real PHP would
throw the exact same class of error here too, had it ever gotten past its
OWN earlier, unguarded `crm_deals` query first. **Net effect: on this
fixture schema, Next's own DEFAULT/landing tab (`?tab=overview`, what a bare
`/crm-dashboard-advanced` URL flip would actually serve) is not reachable at
200 today either.**

**Handling** — `runCrmDashboardAdvancedChecks()` in `parity.mjs` now returns
THREE entries (not two):
  1. `crm-dashboard-advanced:php-500-expected` — unchanged from the
     original design; positively asserts PHP still 500s.
  2. `crm-dashboard-advanced:next-overview-500-expected` — NEW; positively
     asserts Next's default tab ALSO currently 500s, for the specific
     `odoo_webhooks_log.created_at` reason above (symmetric with #1 — throws
     if Next stops 500ing, catching a future fix).
  3. `crm-dashboard-advanced:next-pipeline-200-defensive-empty` — NEW;
     proves the AUTHORIZED RESOLUTION pattern genuinely DOES work where it
     IS applied: `?tab=pipeline` (`SalesPipelineTab`) never calls
     `getRevenueAnalytics()` at all (only the properly-defended
     `getPipelineData()`/`getCustomers()`), so it reaches 200 with the
     documented defensive-empty shape today (`extractCrmDashboardAdvancedPipelineDefensiveEmpty()`
     — Total Pipeline ฿0, 0 deals, Win Rate 35.0% hardcoded placeholder).

`extractCrmDashboardAdvancedDefensiveEmpty()` (the original overview-tab
extractor per the brief's literal design) is KEPT, fully implemented, and
covered by this batch's own unit tests — it is simply not wired into an
active `parity.mjs` check right now, because doing so would ship a check
that can never pass on this schema. It activates the MOMENT queries.ts's
`getRevenueAnalytics()` gains the same try/catch its siblings already have —
re-wiring it back into the `?tab=overview` entry is then a one-function-call
change, no new extraction logic needed.

## 14. Unit tests (not shipped, used to de-risk before spending docker cycles)

Every extractor above was verified against representative HTML fixtures
(hand-written PHP-shaped and Next-shaped fragments, including deliberately
whitespace-padded/comment-straddling/trailing-`<script>`-payload variants
matching REAL dumped output) in a throwaway test script BEFORE the first
real docker run, and iterated against the real thing after. This caught
(in order): a `data-category`-slice off-by-one in `extractTemplatesPage`, a
`beforeLabel()`/`>${label}</h3>` window-boundary bug in the original
`extractSystemStatusPage` design (superseded by §16 below), and both
`extractLineGroupDetailPage` bugs in §11's `/line-group-detail` write-up
above. Not part of this batch's shipped deliverables (a scratch file, not
committed) — mentioned here only so a future reader understands where the
"verified empirically" claims throughout this section came from.

## 15. `PARITY_DUMP_HTML` now also dumps on an EXTRACTION error, not just a mismatch

`runPagePair()`'s HTML-dump-on-`PARITY_DUMP_HTML=1` behavior (batch 1's own
§1 debug aid) previously only fired when `diff()` found a mismatch — i.e.
AFTER both sides successfully extracted. This batch extended it to ALSO
dump whatever was fetched when EXTRACTION ITSELF throws (the
`system-status.php` whitespace-padding bug in §16 was diagnosed this way) —
a strict superset of the old behavior, no change to any existing batch's
pass/fail outcome. `runSingleSideCheck()` got the same treatment for its
own single-response case. Also bumped the Next server log tail
`getLogs()` keeps for a FAIL print from 4000 to 20000 chars — the
`odoo_webhooks_log` finding (§13.3) was hiding behind a real uncaught stack
trace that the old, shorter tail had already scrolled past by the time the
harness's `finally` block printed it.

## 16. `/system-status` — the 11-portable/8-placeholder split, and a real extraction bug this batch had to fix

Per pagesA's own brief/queries.ts module doc, system-status.php's 19 named
checks split into:
  - **11 "portable" checks**: pure SQL probes (`database`, the 5 `table_*`
    checks, the 3 `v2_table_*` checks, `message_stats`, `user_stats`) that
    run identically against the same physical MySQL database regardless of
    stack — a real, diffable `status` (ok/warning/error) plus, for
    `message_stats`/`user_stats`, the embedded count(s).
  - **8 "placeholder" checks**: PHP-class-instantiation probes
    (`VibeSellingHelper`, `InboxService`, the 4 V2 `*Service` classes,
    `LineAccountManager`/`LineAPI`, AIChat's `GeminiChatAdapter`) with no
    Next-side equivalent yet (Phase 4/6/7 per the migration plan) — Next
    renders these as a fixed `not_ported`/🚧 row. PRESENCE-ONLY: this
    harness proves the check ROW exists in the right DOM-order slot on both
    stacks, never diffs its status/message (expected to differ).

`overallStatus` (the green/yellow/red banner) is DELIBERATELY NOT extracted
at all — Next's `computeOverallStatus()` folds ONLY the 11 portable checks,
while PHP folds all 19 (including the 8 placeholder ones) into its cascade —
an intentional, documented divergence (see `system-status/queries.ts`'s own
module doc), not a bug on either side. Diffing it would produce a false
mismatch the instant any placeholder check's real PHP behavior isn't a
clean 'ok'.

**Extraction bug found and fixed**: the original design anchored each
check's status via a literal `${label}</h3>` substring search (the same
label-anchored technique every other extractor in this file uses
successfully). This NEVER matched on real PHP output — `system-status.php`'s
actual (un-minified) template pads every `<h3>…LABEL                    </h3>`
with substantial trailing whitespace/newlines before the closing tag; Next's
compact SSR output has none. `extractSystemStatusPage()` was rewritten
around `CHECK_CARD_RE`, a single regex that matches each check card's whole
STRUCTURE (`<span class="text-2xl">EMOJI</span>…<h3 class="font-medium
text-gray-800 truncate">LABEL</h3><p class="text-sm text-gray-500
mt-1">MESSAGE</p>`) and zips the 19 matches against `SYSTEM_STATUS_CHECKS`
BY POSITION (both stacks push all 19 checks in the identical order —
verified by reading both sources in full), not by re-searching for each
label's own text — sidesteps the whitespace difference entirely and is
MORE robust than the label-search convention for this one page (documented
as a deliberate departure in the function's own module doc).

`currentBotId` is not read at all — see §13.2.

## 17. Acceptance evidence (rehearsed in this environment, Phase 2 batch 3)

- `node infra/e2e/parity.mjs` → clean run from a fully torn-down state:
  `{"result":"PASS", ...}` with **all 42** `pages` entries `ok:true` (the
  original 27 from batches 1-2 + 15 new: 1 `templates:*` + 3 `groups:*` +
  1 `line-groups:*` + 2 `line-group-detail:id=*` + 4
  `line-group-detail:*-header*` + 3 `crm-dashboard-advanced:*` + 1
  `system-status:*`), `docker ps -a` empty afterward.
- Deliberate-break rehearsal: renamed
  `apps/admin/src/app/(tenant)/templates/page.tsx` away, ran from a clean
  teardown: `{"result":"FAIL", ...}`, exactly ONE entry (`templates:baseline`)
  `ok:false` with `"extraction/fetch error: ... expected 200, got 404"`, the
  OTHER 41 entries (batches 1-2's 27 AND this batch's other 14) still
  `ok:true`, `docker ps -a` still empty afterward — no hang, no crash, no
  leftover containers. Restored the file, re-ran clean:
  `{"result":"PASS", ...}`, all 42 `ok:true` again.
- crm-dashboard-advanced PHP-500-expected regression check, rehearsed per
  this batch's own acceptance criteria: temporarily pointed
  `runCrmDashboardAdvancedChecks()`'s PHP fetch at `/dashboard.php?tab=executive`
  (a real, unrelated, always-200 PHP page) instead of
  `/crm-dashboard-advanced.php`, ran from a clean teardown:
  `{"result":"FAIL", ...}`, exactly ONE entry
  (`crm-dashboard-advanced:php-500-expected`) `ok:false` with
  `"...expected PHP crm-dashboard-advanced.php to return 500 ... but got 200. ..."`,
  the other 41 entries still `ok:true`, `docker ps -a` empty afterward.
  Reverted the fetch URL immediately, re-ran clean:
  `{"result":"PASS", ...}`, all 42 `ok:true` again. This confirms the check
  is a REAL regression detector, not a permanently-vacuous pass.
- `node infra/nginx/generate-routes.mjs` runs clean against the updated
  `routes.json` (see §18 below for the exact route count — 17, not 16) and
  regenerates `infra/nginx/generated/strangler-edge.conf` without a
  schema-validation error. Same "Generated at" nondeterministic-timestamp
  caveat as batches 1-2's own notes still applies — not reintroduced or
  worsened by this batch.

## 18. `routes.json` — 11 → 17, not "10 → 16" (hand-off arithmetic correction)

This batch's brief said `routes.json` "goes from 10 to 16 entries." That
arithmetic was written assuming batch 2's end state (10 routes) as the
starting point, but THIS worktree's `main` already includes Phase 3 batch 1's
merged work (`/api/miniapp` — see this file's own top note and
`docs/runbooks/phase3-batch1-miniapp-api-parity.md`), which had already
brought the committed `routes.json` to **11** entries before this batch
started (verified: `python3 -c "import json;print(len(json.load(open('infra/nginx/routes.json'))))"`
→ 11, prior to any edit in this session). This batch added the 6 new
entries documented in §11 above (`/crm-dashboard-advanced`, `/system-status`,
`/templates`, `/groups`, `/line-groups`, `/line-group-detail`), each
`{"upstream": "php_backend", "tenants": "all"}`, identical shape to every
prior batch's placeholder entries — bringing the total to **17**. Flagged
here so mig-orchestrator doesn't treat "16" as the expected post-merge count.

**All SIX of this batch's routes are now eligible for independent canary
ramp** (plan §7.3: demo tenant → 1 real tenant → 10% → 50% → 100%), each
parity-proven by this harness — same flip/rollback mechanic as every prior
batch's routes (§5/§10 above; unchanged, not repeated here):

| Route | Parity-proven by | Caveat |
|---|---|---|
| `/templates` | `templates:baseline` (1 entry) | none |
| `/groups` | `groups:*` (3 entries) | none |
| `/line-groups` | `line-groups:baseline` (1 entry) | none |
| `/line-group-detail` | `line-group-detail:*` (6 entries) | header fields are a KNOWN, PRE-EXISTING PHP defect (§13.1) — a route flip here would be a genuine, visible BUG FIX for end users (PHP currently shows "Unknown Group"/0/0 for every group; Next shows the real data), not byte-parity in the usual sense. Flag for mig-orchestrator to make an explicit call on before ramping, not an automatic block. |
| `/crm-dashboard-advanced` | `crm-dashboard-advanced:*` (3 entries) | PHP 500s outright today (pre-existing defect, out of scope — database/** is off-limits). Next's default tab previously ALSO 500'd for a second, narrower reason (§13.3) — **now fixed**: `getRevenueAnalytics()` has the same try/catch its siblings have, so all 9 `?tab=` values reach 200 with the documented defensive-empty shape. Since PHP never renders this page at all on this schema, this remains parity-with-a-working-Next-page (not parity-with-PHP) — a route flip here is a genuine improvement over PHP's hard 500, not byte-parity in the usual sense. |
| `/system-status` | `system-status:baseline` (1 entry) | 8 of 19 checks are Next-side placeholders (Phase 4/6/7 features, `not_ported`/🚧) — a route flip here changes what admins SEE for those 8 rows, independent of the 11-portable-check parity this harness proves. |

This agent does not perform a real ramp for ANY of these six routes — only
mig-orchestrator authorizes a route flip, per this agent's own "Do not"
boundary (unchanged from every prior batch).

## 19. Gaps intentionally not fixed (per this batch's allowed-paths boundary)

- `crm_deals`/`crm_tickets`/`crm_ticket_interactions` remain absent from
  `database/migration_2026-05-25_tenant_template.sql` — `database/**` is
  outside this agent's allowed paths (per the brief's explicit "do not add a
  migration to work around it" instruction). §13.3's `getRevenueAnalytics()`
  gap (the `apps/admin/**`-side try/catch) has since been fixed — see §13.3's
  "FIXED" note.
- `includes/header.php`'s `$group`/`$currentBotId` variable-collision defect
  (§13.1, §13.2) is a PHP-side fix — `includes/header.php`/
  `line-group-detail.php`/`system-status.php` are all outside this agent's
  allowed paths ("No existing PHP file may be modified").
- No attempt was made to audit every OTHER PHP admin page for the same
  `include`-scope variable-collision pattern — flagged in §13.2 as worth a
  dedicated audit, not performed here (out of this batch's scope).
