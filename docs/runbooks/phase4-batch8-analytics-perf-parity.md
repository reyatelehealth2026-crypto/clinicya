# Phase 4 batch 8 — `/inbox` consultation-analytics + performance-metrics actions

## 0. Scope note

Documentation-and-routing-note-only for infra: `/inbox` (`inbox-v2.php` /
`api/inbox-v2.php` / `apps/admin/src/app/(tenant)/inbox`) has never been
flipped off `php_backend` at the `infra/nginx/routes.json` layer, so this
batch carries no canary window and no rollback drill — it is a pure
code-parity landing, same as every prior `phase4-batch*` runbook's own §0.
`infra/nginx/routes.json` is explicitly outside this stream's allowed
paths (infra-agent-owned) and is untouched by this batch entirely — no
edit, no regeneration.

This batch (the `analyticsAndPerf` stream) ported exactly 4 actions:
`apps/admin/src/app/api/inbox/actions/{analytics,record-analytics,
log-performance-metric,get-performance-metrics}/**`. The sibling
`templatesCrud` stream landed `{create-template,update-template,
delete-template}/**` in the same worktree, same round, fully
file-disjoint by construction — its own runbook
(`docs/runbooks/phase4-batch8-templates-crud-parity.md`), not this one,
covers it.

None of the 4 ported PHP case blocks (`analytics`/`get_analytics`/
`consultation_analytics`, `record_analytics`/`record-analytics`,
`logPerformanceMetric`/`log_performance_metric`,
`getPerformanceMetrics`/`get_performance_metrics`) contain any Thai text —
confirmed by reading `api/inbox-v2.php` lines 1681-1786, 1792-1832,
2845-2904, 2910-2954, plus `classes/ConsultationAnalyzerService.php`'s
`recordAnalytics()` (1802-1832) and `classes/PerformanceMetricsService.php`
in full (all 410 lines), before writing any code — so "preserve all Thai
text" is vacuous for this batch specifically.

## 1. What landed — 4 ported actions

All four are literal ports of `api/inbox-v2.php`'s action switch, each
decomposed into its own self-contained Next.js Route Handler under
`apps/admin/src/app/api/inbox/actions/**` — following the
`customer-crm`/`detect-urgency`/`drug-info` house style exactly: a
`route.ts` + `_lib/<name>.ts` + `_lib/session.ts` +
`_lib/testHelpers/fakeTenantDb.ts` + `route.test.ts` per directory.
`session.ts` and `testHelpers/fakeTenantDb.ts` are byte-for-byte
DUPLICATED across all four directories (never imported cross-dir, never
imported from the sibling `templatesCrud` stream's directories either) —
the same "every consumer resolves its own session / builds its own fake
DB" precedent every prior batch in this family already established. Every
handler requires a valid tenant session (any of the six `TenantRole`
values) via `resolveInboxApiContext()`.

### 1.1 Alias table — every PHP case label maps to exactly one route

| PHP case labels (`api/inbox-v2.php`) | Lines | Route | Backing PHP source |
|---|---|---|---|
| `analytics`, `get_analytics`, `consultation_analytics` | 1681-1786 | `apps/admin/src/app/api/inbox/actions/analytics/route.ts` | Direct SQL against `consultation_analytics` — **no service class** |
| `record_analytics`, `record-analytics` | 1792-1832 | `apps/admin/src/app/api/inbox/actions/record-analytics/route.ts` | `ConsultationAnalyzerService::recordAnalytics()` (1802-1832) |
| `logPerformanceMetric`, `log_performance_metric` | 2845-2904 | `apps/admin/src/app/api/inbox/actions/log-performance-metric/route.ts` | `PerformanceMetricsService::logMetric()` (39-88) + `checkPerformanceThreshold()` (99-122) |
| `getPerformanceMetrics`, `get_performance_metrics` | 2910-2954 | `apps/admin/src/app/api/inbox/actions/get-performance-metrics/route.ts` | `PerformanceMetricsService::getAllMetricStats()` (378-387) + `getMetricStats()` (192-250) + `calculatePercentiles()` (260-312) + `getErrorRate()` (325-367) |

All 4 case-label groups are covered 1:1, no widening, no invented aliases.
Line ranges verified directly against the live PHP files (case-label line
to matching `break;`/closing-brace line) before writing any code, not
copied from the brief without re-checking.

### 1.2 A correction to this batch's own brief, resolved by reading the PHP source directly

The brief's own free-text scope note read ambiguously on which actions go
through `loadService()`'s 503-unavailable gate ("actions (1) ... and (2)
go through loadService()'s 503-unavailable gate, actions (3) and (4) do
NOT") while the SAME brief's own `analytics` deliverable description
separately, correctly, said "direct SQL against consultation_analytics, no
service class." Re-reading `api/inbox-v2.php` lines 1681-1786 directly
(not trusting either summary) resolves this decisively:
**`case 'analytics':` never calls `loadService()` at all — there is no
service instance anywhere in that case body.** Only `record_analytics`
(§2 below) has a real, textually-present `loadService('ConsultationAnalyzerService',
...)` 503 gate among this batch's 4 actions. `analytics/route.ts`'s own
module doc documents this correction explicitly (see its "NO 503 GATE"
section), and `analytics/route.test.ts` asserts the ABSENCE of a 503 code
path (same shape of assertion as `log-performance-metric`/
`get-performance-metrics` below), not its presence — per this agent's
standing instruction to read PHP source in full rather than guess from a
brief's own summary.

## 2. The 503-gate split — genuinely one of four, not two of four

| Action | `loadService()` call in PHP? | 503 branch in this port? | Test coverage |
|---|---|---|---|
| `analytics` | **No** — direct SQL, no service class (§1.2) | No — not fabricated | `analytics/route.test.ts`: `NO 503 CODE PATH EXISTS for this action (unlike record-analytics): a DB failure never produces a 503 — it always soft-degrades to 200, proving there is no loadService()-style service-availability branch to reach here` |
| `record-analytics` | **Yes** — `loadService('ConsultationAnalyzerService', $db, $lineAccountId)`, lines 1805-1809, `if (!$consultationAnalyzer) { sendError('...', 503); }` | **Yes** — `_lib/recordAnalytics.ts`'s `loadConsultationAnalyzerService()`, mocked in the test | `record-analytics/route.test.ts`: `503 {success:false, error:"Consultation analyzer service not available"} when loadConsultationAnalyzerService() returns null (explicit unit-test mock only, never reachable on real traffic)` |
| `log-performance-metric` | **No** — `require_once` + bare `new PerformanceMetricsService(...)`, no `file_exists()`/`class_exists()` guard, no `if (!$service)` branch anywhere | No — not fabricated | `log-performance-metric/route.test.ts`: `NO 503 CODE PATH EXISTS for this action: PerformanceMetricsService is instantiated directly in PHP, no loadService() guard — a DB failure never produces 503, only a per-metric failure count at 200` |
| `get-performance-metrics` | **No** — same bare `require_once` + `new`, no guard | No — not fabricated | `get-performance-metrics/route.test.ts`: `NO 503 CODE PATH EXISTS for this action: PerformanceMetricsService is instantiated directly in PHP, no loadService() guard — every internal query failure degrades silently, never producing a 503` |

`record-analytics` is the ONLY action in this batch (and the ONLY
`ConsultationAnalyzerService`-backed action across the ENTIRE
`api/inbox/actions/*` family — `detect-urgency`, `consultation-stage`,
`quick-actions`, `context-widgets` all decline to fabricate this identical
branch, documenting it as "structurally unreachable, a static import
always resolves") where a real, `jest.mock`-exercisable 503 branch was
added, per this batch's explicit brief instruction. This mirrors the
EXACT pattern the sibling `templatesCrud` stream independently established
this same round for `TemplateService`'s own identical `loadService()` gate
(`create-template/_lib/createTemplate.ts`'s `loadTemplateService()`) — both
streams arrived at the same "mockable factory function, `beforeEach` wires
the real implementation through `jest.requireActual`, only the dedicated
503 test overrides it to `null`" shape independently, for the same
brief-driven reason. This is a deliberate, batch-local choice for these
two specific actions, not a claim that the other four
`ConsultationAnalyzerService` actions' "never fabricate it" choice, or the
other two `PerformanceMetricsService` actions' complete absence of any gate
at all, is wrong — three genuinely different PHP realities, three
genuinely different ports.

## 3. Load-bearing PHP quirks — enumerated, with the exact test that covers each

### 3.1 `analytics` — soft-degrade on DB failure (broad catch, not narrow)

PHP's `catch (PDOException $e)` around both `SELECT`s does NOT produce an
error response — it soft-degrades to `{success:true, data:{period,
summary:{}, byType:[], message:'No analytics data available yet'}}`, still
HTTP 200 (PHP's `sendResponse()` default status, no explicit code passed).
This port's `catch` in `analytics/route.ts` is **broad** (catches anything
`getConsultationAnalytics()` throws), not narrowly typed to a PDO-style
exception class — JS has no exception-type hierarchy to narrow against.
This is the documented "broad-catch-with-same-degrade-behavior"
simplification called out explicitly in `_lib/consultationAnalytics.ts`'s
own module doc and `route.ts`'s own module doc, per this family's
established precedent of documenting such choices explicitly (see e.g.
`../poll/route.ts`'s own module doc for the general pattern) rather than
doing them silently.

- Test: `analytics/route.test.ts` → `LOAD-BEARING: DB query failure
  soft-degrades to {success:true, ...} at HTTP 200 — never 400/500/503
  (PHP's catch (PDOException) branch, broad-catch equivalent here)`.

### 3.2 `analytics` — `pharmacistId` resolution is `isset()`-based, not truthiness

`(int) ($_GET['pharmacist_id'] ?? $adminId ?? 0)` — the query key wins
whenever it is PRESENT (even `?pharmacist_id=0`), falling back to
`session.adminUserId` only when the key is entirely ABSENT.

- Tests: `analytics/route.test.ts` → `pharmacist_id ABSENT from query ->
  falls back to session.adminUserId, filters both queries`,
  `pharmacist_id=0 PRESENT in query -> wins over session.adminUserId
  (isset()-based ?? semantics, not truthiness) -> no pharmacist_id filter
  applied (0 is falsy)`, `pharmacist_id=5 PRESENT in query -> used
  directly, session.adminUserId ignored`.

### 3.3 `analytics` — start/end date default to Asia/Bangkok, not a bare `new Date()`

Per CLAUDE.md ("Timezone is always Asia/Bangkok"), the 30-days-ago/today
defaults are computed via `_lib/bangkokTime.ts`'s `Intl.DateTimeFormat`
with an explicit `timeZone: 'Asia/Bangkok'`, never a bare server-local
`new Date()`.

- Test: `analytics/route.test.ts` → `start_date/end_date absent -> default
  to 30-days-ago/today in Asia/Bangkok (not a bare server-local
  new Date())`.

### 3.4 `record-analytics` — `pharmacistId` is forced to an int, NEVER `null`

`(int) ($_POST['pharmacist_id'] ?? $body['pharmacist_id'] ?? $adminId ??
null)` wraps the WHOLE `??` chain, including its trailing `?? null`
fallback, in an `(int)` cast — `(int) null === 0` in PHP, so an absent
`pharmacist_id` (and an absent `adminId`) resolves to the literal int `0`,
never `null`/`undefined`. This is genuinely DIFFERENT from
`responseTimeAvg`/`messageCount`/`purchaseAmount`, which use PHP's
`isset(...) ? (cast) : null` ternary form and so CAN end up `null`.

- Test: `record-analytics/route.test.ts` → `LOAD-BEARING QUIRK:
  pharmacist_id ABSENT from body -> forced to session.adminUserId as an
  int, never null`.

### 3.5 `record-analytics` — always HTTP 200, even when `success:false`

`sendResponse(['success' => $success, 'message' => ...])` passes no
explicit status code — a `recordAnalytics()` failure (its own internal
`catch (PDOException $e) { return false; }`) still comes back as HTTP 200
with `{success:false, message:'Failed to record analytics'}`.

- Test: `record-analytics/route.test.ts` → `LOAD-BEARING:
  recordAnalytics() returning false (its own internal DB-error swallow)
  still yields HTTP 200 with success:false — never a 400/500`.

### 3.6 `record-analytics` — `resultedInPurchase`'s `FILTER_VALIDATE_BOOLEAN` truth table

Empirically verified against a real `php` 8.4 CLI (not assumed from
memory) across bool/string/number/null/array inputs:

```
$ php -r '
$cases = [true, false, null, "1", "0", "true", "false", "TRUE", "on", "off",
          "yes", "no", "", 1, 0, 2, -1, 1.5, 0.0, "yes ", " 1", "abc", []];
foreach ($cases as $c) {
  $r = filter_var($c ?? false, FILTER_VALIDATE_BOOLEAN);
  echo var_export($c, true) . " => " . var_export($r, true) . "\n";
}'
true => true      1 => true       'yes ' => true    ' 1' => true
'1' => true       0 => false      2 => false
'true' => true    'false' => false  -1 => false
'TRUE' => true    'off' => false    1.5 => false
'on' => true      'no' => false     0.0 => false
'yes' => true     '' => false       'abc' => false
false => false                      [] => false
NULL => false
```

PHP casts the value to a string first (`true`->`'1'`, `false`->`''`, a
number -> its decimal text), then matches case-insensitively/trimmed
against the fixed true-list `1|true|on|yes`; everything else (including
`'0'`/`'false'`/`'off'`/`'no'`/`''`, any unrecognized string, and any
number other than exactly `1`) is `false` — `phpFilterVarBoolean()` in
`record-analytics/route.ts` replicates this exactly.

- Test: `record-analytics/route.test.ts` → the 18-case
  `it.each` table `resultedInPurchase: filter_var(%p,
  FILTER_VALIDATE_BOOLEAN) -> %i`.

### 3.7 `record-analytics` — Thai text in the JSON-encoded array columns round-trips un-mangled

`json_encode(..., JSON_UNESCAPED_UNICODE)` <-> `JSON.stringify(...)` — JS's
`JSON.stringify` never escapes non-ASCII to `\uXXXX` by default, which is
already the behavioral equivalent of PHP's `JSON_UNESCAPED_UNICODE` flag,
not merely an approximation.

- Test: `record-analytics/route.test.ts` →
  `symptomCategories/drugsRecommended/successfulPatterns: JSON-stringified,
  Thai text round-trips un-mangled (unescaped unicode, not \uXXXX)`.

### 3.8 `log-performance-metric` — an empty JSON object `{}` is REJECTED (400), same as malformed input

`!$input` is PHP TRUTHINESS on the WHOLE decoded payload — `json_decode('{}',
true)` returns PHP's empty array `[]`, which is FALSY — so a syntactically
valid but key-less `{}` body hits the identical `sendError('Invalid JSON
input')` branch as genuinely malformed/empty input. This is the single
most easily-missed quirk in this batch: a naive null-check (`input ===
null`) would silently ACCEPT `{}` and then process zero metrics with no
error at all, a different (and wrong) observable behavior from PHP's real
400.

- Test: `log-performance-metric/route.test.ts` → `LOAD-BEARING PHP QUIRK:
  an empty JSON object {} -> 400 "Invalid JSON input" (PHP
  json_decode('{}') === [] which is falsy)` (plus the adjacent `malformed
  JSON body -> ...` and `null / empty array body -> ...` cases covering
  the rest of the falsy set).

### 3.9 `log-performance-metric` — always `success:true` at 200, even for an all-failed batch

`sendResponse(['success' => true, ...])` is unconditional once the
`!$input` gate passes — there is no branch that flips `success` to `false`
based on `$failCount`, even when every single metric in the batch failed
validation/logging.

- Test: `log-performance-metric/route.test.ts` → `LOAD-BEARING: an
  all-failed batch still returns success:true at HTTP 200, never an error
  status`.

### 3.10 `log-performance-metric` — `durationMs === null` is a STRICT check; `!metricType` is TRUTHINESS

The route-level skip-and-count-as-failed gate (`!$metricType ||
$durationMs === null`) mixes two different kinds of check: `metricType`
uses PHP truthiness (`0`/`''`/`false`/`null` all skip), but `durationMs`
uses a strict `=== null` check — a `duration_ms` of exactly `0` is NOT
skipped here (it still reaches `logMetric()`'s own, separate,
`is_numeric()`/`>= 0` validation).

- Tests: `log-performance-metric/route.test.ts` → `durationMs === 0 is NOT
  skipped by the route-level \`durationMs === null\` check (strict null
  check, not falsy)` and `metricType=0 (falsy but not null) IS skipped by
  the route-level \`!$metricType\` truthiness check`.

### 3.11 `log-performance-metric` — two DIFFERENT threshold maps, not one shared constant

`checkPerformanceThreshold()`'s own map (5 entries, INCLUDES
`scroll_performance: 17`) drives a `console.warn` when a single logged
metric exceeds its threshold — a COMPLETELY DIFFERENT map from
`get-performance-metrics`'s own `error_rate` threshold map (4 entries,
EXCLUDES `scroll_performance`). Both maps share identical numeric values
for their 4 common types, which is a PHP-source coincidence (two separate
hand-written arrays in two separate methods), not a shared constant — each
is ported as its own independent `const` (`LOG_THRESHOLDS_MS` vs.
`ERROR_RATE_THRESHOLDS_MS`).

- Test: `log-performance-metric/route.test.ts` → `threshold-exceeded
  warning (5-entry LOG_THRESHOLDS_MS map, includes scroll_performance:17)
  logs a console.warn but does not affect success`.

### 3.12 `get-performance-metrics` — `scroll_performance` has NO `error_rate` key at all

The case body's own `$thresholds` map (4 entries) deliberately excludes
`scroll_performance` — `isset($thresholds[$type])` is `false` for it, so
the augmentation loop's body never runs; `scroll_performance`'s stats
object has no `error_rate` key whatsoever (not even `null`), while all 4
other types always get one.

- Test: `get-performance-metrics/route.test.ts` → `LOAD-BEARING:
  scroll_performance has NO error_rate key at all (excluded from the
  4-entry error-rate threshold map), unlike the other 4 types which all
  have one`.

### 3.13 `get-performance-metrics` — two genuinely different "empty" stats shapes

Found by reading `PerformanceMetricsService::getMetricStats()` line by
line (not assumed from the brief, which only described the
exception-catch shape): when the stats query SUCCEEDS but matches ZERO
rows, PHP's `else` branch only forces `p50`/`p95`/`p99` to `0` — it never
touches `average`/`min`/`max`, which stay whatever `AVG()`/`MIN()`/`MAX()`
returned over zero rows, i.e. SQL `NULL` -> PHP `null`. Only the
`catch (PDOException $e)` branch (a genuine query FAILURE, not "zero
matching rows") forces literal `0`s for every field including
`average`/`min`/`max`. These are two different shapes with the same
`count: 0`, easy to conflate:

| Situation | `average`/`min`/`max` | `p50`/`p95`/`p99` |
|---|---|---|
| Query succeeds, 0 matching rows | `null` | `0` |
| Query throws | `0` | `0` |

- Tests: `get-performance-metrics/route.test.ts` → `count===0 (query
  succeeded, zero matching rows): average/min/max are null (NOT 0),
  p50/p95/p99 are 0` and `a stats-query FAILURE degrades to the
  literal-0s DEGRADED_STATS shape (average/min/max = 0, NOT null) — a
  DIFFERENT shape than the "0 matching rows" case — while that type's
  error_rate query still succeeds independently`.

### 3.14 `get-performance-metrics` — `start_date`/`end_date` stay `null` when absent, NO defaulting

Unlike `analytics` (§3.3), this action never defaults its date range to a
30-day Asia/Bangkok window — `$_GET['start_date'] ?? null` /
`$_GET['end_date'] ?? null` genuinely stay `null`, and the corresponding
`DATE(created_at) >=`/`<=` clauses are omitted from the SQL entirely.

- Test: `get-performance-metrics/route.test.ts` → `start_date/end_date
  ABSENT from query -> stay null, no defaulting (unlike ../analytics),
  date-range WHERE clauses omitted from the SQL text`.

## 4. Schema check (re-verified, no drift found)

Both tables are confirmed present in
`packages/db/src/generated/tenant-db.d.ts` with every column each ported
method touches correctly typed — re-verified independently against the
live generated file this round (not just trusting the orchestrator's
prior pass, per this batch's brief).

```ts
export interface ConsultationAnalytics {
  ai_suggestions_accepted: Generated<number | null>;
  ai_suggestions_shown: Generated<number | null>;
  communication_type: Generated<"A" | "B" | "C" | null>; // confirmed correct, NOT drift — see §4.1
  created_at: Generated<Date | null>;
  drugs_recommended: Generated<string | null>;
  id: Generated<number>;
  line_account_id: Generated<number>;
  message_count: Generated<number | null>;
  pharmacist_id: Generated<number | null>;
  purchase_amount: Generated<Decimal | null>;
  response_time_avg: Generated<number | null>;
  resulted_in_purchase: Generated<number | null>;
  stage_at_close: Generated<string | null>;
  successful_patterns: Generated<string | null>;
  symptom_categories: Generated<string | null>;
  user_id: number;
}

export interface PerformanceMetrics {
  created_at: Generated<Date | null>;
  duration_ms: number;
  id: Generated<number>;
  line_account_id: Generated<number | null>;
  metric_type: "api_call" | "cache_hit" | "cache_miss" | "conversation_switch" | "message_render" | "page_load" | "scroll_performance";
  operation_details: Generated<string | null>;
  user_agent: Generated<string | null>;
}
```

No fix-forward needed for either table — both are fully literal,
unmodified ports.

### 4.1 `communication_type`'s narrow union type — why the raw-SQL port, not the typed query builder

`ConsultationAnalytics.communication_type` is typed as the narrow union
`"A" | "B" | "C" | null`, but PHP's `recordAnalytics()` never validates
`$data['communicationType']` against that whitelist before binding it —
any string (or `null`) the caller supplies is written through unchecked.
`_lib/recordAnalytics.ts` therefore uses a raw `sql`-tagged-template
`INSERT` (not Kysely's typed `.insertInto()` query builder) specifically
so it can accept and bind whatever value the caller supplies, exactly
matching PHP's own unchecked bound parameter — using the typed builder
here would have silently ADDED a validation PHP never had.

## 5. Deferred scope

Out of scope for this batch (not called by any of the 4 ported case
blocks, so none were ported): `PerformanceMetricsService::getMetrics()`
(the un-aggregated raw-rows fetch, lines 135-180) and
`cleanupOldMetrics()` (lines 395-409); every other method on
`ConsultationAnalyzerService.php` (`detectStage`, `getContextWidgets`,
`getQuickActions`, `detectUrgency`, `searchDrugsFromMessage`,
`searchDrugsFromChatHistory`, `getSavedStage`, `clearStage`,
`extractSearchTerms`, etc.) — already ported piecemeal in sibling batches
(`detect-urgency`, `consultation-stage`, `quick-actions`,
`context-widgets`) and explicitly out of scope here per this batch's
brief; do not re-port. The sibling `templatesCrud` stream's 3 action
directories (`create-template/`, `update-template/`, `delete-template/`)
and its own runbook are owned by that stream, not this one.

## 6. How to run each route's own test suite locally

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/analytics
npx jest src/app/api/inbox/actions/record-analytics
npx jest src/app/api/inbox/actions/log-performance-metric
npx jest src/app/api/inbox/actions/get-performance-metrics
```

Or all four at once:

```bash
cd apps/admin
npx jest --testPathPattern="src/app/api/inbox/actions/(analytics|record-analytics|log-performance-metric|get-performance-metrics)/"
```

**A note on the exact acceptance-criteria command as literally written**
(`--testPathPattern="^src/app/api/inbox/actions/(...)/"`, with a leading
`^` anchor): under this repo's installed Jest 29, `--testPathPattern`
matches against each test file's full ABSOLUTE path, not a path relative
to `rootDir` — so an anchor requiring the match to start with the literal
text `src/...` never matches anything (`0 matches`, confirmed by running
it verbatim in this worktree). Dropping only the leading `^` (shown above)
is functionally equivalent and correctly selects exactly these 4
directories' `route.test.ts` files, nothing else — confirmed via
`--listTests`. This is the same command shape the sibling `templatesCrud`
runbook independently arrived at for its own 3 directories. Flagging this
explicitly for `mig-verify` rather than silently rewriting the brief's
literal command.

(Do not use `pnpm --filter admin test -- --testPathPattern=...` — the extra
`--` pnpm inserts silently drops one test file from the run; use `npx jest`
directly inside `apps/admin`, or `pnpm --filter admin exec jest <pattern>`
from the worktree root.)

`cd <worktree> && pnpm --filter admin lint` (`tsc --noEmit -p tsconfig.json`)
passes with zero errors across all new files. Building
`@reya/config`/`@reya/db`/`@reya/auth`/`@reya/tenant`/`@reya/contracts` via
`npx turbo run build --filter=admin^...` first is required for `tsc` to
resolve their `dist/*.d.ts` — `jest` resolves the same workspace packages
straight from source instead (via `apps/admin/jest.config.js`'s
`moduleNameMapper`), so `npx jest` never needs this build step.

## 7. Acceptance criteria (mig-verify executes these)

- [ ] `cd apps/admin && npx jest --testPathPattern="src/app/api/inbox/actions/(analytics|record-analytics|log-performance-metric|get-performance-metrics)/"`
      — all pass (4 suites / 71 tests as of this writing: 11 analytics +
      32 record-analytics + 17 log-performance-metric + 11
      get-performance-metrics). See §6 for the `^`-anchor caveat.
- [ ] Each `route.test.ts` covers: 401 on missing session (DB never
      touched) for its verb, 405 for the wrong verb, the 503 gate for
      `record-analytics` ONLY (with an explicit assertion of its ABSENCE
      for `analytics`/`log-performance-metric`/`get-performance-metrics`
      — §2), the always-200 assertion for `record-analytics`'s
      `success:false` branch and for `log-performance-metric`'s
      all-failed-batch branch, the empty-object-body -> 400 `'Invalid
      JSON input'` case for `log-performance-metric`, the PDOException
      soft-degrade (`success:true`, empty `summary`/`byType`, `'No
      analytics data available yet'`, still HTTP 200) for `analytics`,
      and — for `get-performance-metrics` — a test asserting
      `scroll_performance`'s stats object has no `error_rate` key while
      the other 4 types do.
- [ ] `cd <worktree> && pnpm --filter admin lint` — zero errors, exit 0.
- [ ] `git diff --name-only origin/main...HEAD` (or `git status --short`)
      touches only
      `apps/admin/src/app/api/inbox/actions/{analytics,record-analytics,log-performance-metric,get-performance-metrics}/**`
      and this runbook — no PHP file, no `infra/nginx/routes.json`, no
      `apps/admin/src/nav/manifest.ts`, and no pre-existing or
      sibling-stream directory under `apps/admin/src/app/api/inbox/actions/**`
      (in particular, `create-template/`, `update-template/`,
      `delete-template/` belong to `templatesCrud`, untouched by this
      stream).
- [ ] This document
      (`docs/runbooks/phase4-batch8-analytics-perf-parity.md`) exists,
      contains the alias table (§1.1) and the 503-gate split table (§2),
      and is a genuinely new file.
