# Phase 4 batch 8 — `/inbox` quick-reply template CRUD actions

## 0. Scope note

Documentation-and-routing-note-only for infra: `/inbox` (`inbox-v2.php` /
`apps/admin/src/app/(tenant)/inbox`) has never been flipped off
`php_backend` at the `infra/nginx/routes.json` layer, so this batch carries
no canary window and no rollback drill — it is a pure code-parity landing,
same as every prior `phase4-batch*` runbook's own §0. This batch (the
`templatesCrud` stream) ported exactly 3 write actions:
`apps/admin/src/app/api/inbox/actions/{create-template,update-template,
delete-template}/**` — one directory per PHP case label (not a single
`templates/**` directory with method-based dispatch; see §1 for why). The
sibling `analyticsAndPerf` stream landed
`{analytics,record-analytics,log-performance-metric,
get-performance-metrics}/**` in the same worktree, same round, fully
file-disjoint by construction — its own runbook, not this one, covers it.

None of the 3 ported PHP case blocks (`create_template`, `update_template`,
`delete_template`) contain any Thai text — confirmed by reading
`api/inbox-v2.php` lines 2237-2359 and `classes/TemplateService.php` in
full before writing any code — so "preserve all Thai text" is vacuous for
this batch specifically. (Test fixtures below use Thai strings purely as
representative real-world template content, not because the PHP source
itself carries any bilingual UI strings in this code path.)

## 1. What landed — 3 ported actions, 1 directory each

All three are literal ports of `api/inbox-v2.php`'s action switch, each
decomposed into its own self-contained Next.js Route Handler under
`apps/admin/src/app/api/inbox/actions/**` — following the
`customer-crm`/`detect-urgency`/`drug-info` house style exactly: a
`route.ts` + `_lib/<verb>Template.ts` + `_lib/session.ts` +
`_lib/testHelpers/fakeTenantDb.ts` + `route.test.ts` per directory.
`session.ts` and `testHelpers/fakeTenantDb.ts` are byte-for-byte
DUPLICATED across all three directories (never imported cross-dir, never
imported from the sibling `analyticsAndPerf` stream's directories either)
— the same "every consumer resolves its own session / builds its own fake
DB" precedent every prior batch in this family already established.

**Ownership choice**: 3 separate directories
(`create-template/`, `update-template/`, `delete-template/`), NOT a single
`templates/**` directory with method-based dispatch. This mirrors the PHP
source directly — `create_template`/`update_template`/`delete_template`
are three independent, unaliased `case` labels (no shared alias, no
fallthrough between them), and every other multi-action batch in this
family (e.g. batch 5's `add-customer-tag`/`remove-customer-tag`) already
splits one-case-label-per-directory rather than bundling. This batch's
brief explicitly offered both options ("pick one and state it in the
runbook") — this is that statement.

Every handler requires a valid tenant session (any of the six `TenantRole`
values) via `resolveInboxApiContext()` — the same per-route auth gate every
prior batch established.

### 1.1 Alias table — every PHP case label maps to exactly one route

| PHP case label (`api/inbox-v2.php`) | Route |
|---|---|
| `create_template` (line 2237) | `apps/admin/src/app/api/inbox/actions/create-template/route.ts` |
| `update_template` (line 2277) | `apps/admin/src/app/api/inbox/actions/update-template/route.ts` |
| `delete_template` (line 2329) | `apps/admin/src/app/api/inbox/actions/delete-template/route.ts` |

All 3 case labels are unaliased 1:1 — no widening, no extra aliases
invented, same as batch 5's `poll`/`save_pending_order`/
`update_chat_status` alias table. **Not part of this table, and
deliberately not ported at all this round**: `get_templates`/`templates`
(the read-side `GET` action, lines 2207-2229 — out of scope, this batch is
write-actions only per its brief) and the already-merged, separate Phase-2
`/templates` PAGE (`apps/admin/src/app/(tenant)/templates/**`) — a
different UI surface backing the same table, not touched, not imported
from, not opened.

### 1.2 Request/response summary

| # | Route | Verb(s) | Required input | Response shape | Status codes |
|---|---|---|---|---|---|
| 1 | `create-template` | `POST` only | `{name, content}` required (PHP `empty()` string semantics — `''`/`'0'` both reject); `category` defaults `''`, `quick_reply` defaults `null` | `{success: true, message: 'Template created successfully', id}` | 200 / 400 `Name and content are required` / 503 `Template service not available` / 400 `Failed to create template: ...` / 405 on `GET` |
| 2 | `update-template` | `POST` only | `id` (int, required); at least one of `name`/`content`/`category`/`quick_reply` present per PHP `isset()` semantics | `{success: true, message: 'Template updated successfully'}` | 200 / 400 `Template ID is required` / 400 `No data to update` / 503 `Template service not available` / 400 `Failed to update template` (not-found) / 400 `Failed to update template: ...` (validation/DB) / 405 on `GET` |
| 3 | `delete-template` | `POST` only | `id` (int, required) | `{success: true, message: 'Template deleted successfully'}` | 200 / 400 `Template ID is required` / 503 `Template service not available` / 400 `Failed to delete template` (not-found) / 400 `Failed to delete template: ...` (DB) / 405 on `GET` |

Every route reads the JSON body ONLY (never a `$_POST`-equivalent) —
PHP's own `$_POST['x'] ?? $body['x'] ?? default` pattern is dead for every
real JSON caller of this API, matching every other ported action in the
`api/inbox/actions/*` family (see `../customer-crm/route.ts`'s doc for the
same rationale spelled out at length).

`lineAccountId` resolves as `session.currentBotId ?? 1` in all three routes
— the established 2-tier convention from `poll`/`get-admins`/`customer-crm`
in this family, NOT PHP's own broader `$_SESSION`/`$_GET`/`$_POST`
resolution chain for `$lineAccountId` at the top of `api/inbox-v2.php`.
`created_by` (create only) binds `session.adminUserId` unconditionally —
`TenantSession.adminUserId` is always a `number`, matching
`add-customer-note`'s own precedent for PHP's `$adminId ?? null`.

## 2. The mockable `loadTemplateService()` 503 gate

Each of the 3 `_lib` files exports its own, independently-duplicated
`loadTemplateService(db, lineAccountId)` — a literal port of
`api/inbox-v2.php`'s `loadService('TemplateService', $db, $lineAccountId)`
(lines 84-98: `file_exists()` + `require_once` + `class_exists()` -> `new
$className($db, $lineAccountId)`, `null` on either check failing).
`classes/TemplateService.php` is a committed file in this repo and always
resolves both checks, so on real traffic `loadTemplateService()` always
returns a real handle — the `503 'Template service not available'` branch
every route's `!service` check guards is defensively coded and
structurally unreachable in production, the same "never fabricate a real
way to hit this" posture this whole `api/inbox/actions/*` family already
established for every other `loadService(...)`-gated action (e.g.
`detect-urgency`, `check-drug-interactions`).

What's different for this batch: the brief calls for the branch to remain
exercisable from a test, not just documented as unreachable. Each
`route.test.ts` does so with a targeted `jest.mock` of ONLY the
`loadTemplateService` named export (every other export, including the real
DB-hitting `createTemplate`/`updateTemplate`/`deleteTemplate` functions,
passes through via `jest.requireActual` unmodified) — `beforeEach` wires
the default behavior to call straight through to the real
`loadTemplateService`, and only the dedicated 503 test overrides it with
`mockReturnValueOnce(null)` for that one call. This is an explicit
unit-test-only mock, not a real code path — no production branch was added
to make it reachable.

## 3. The two PHP-semantics gotchas this batch's brief called out

### 3.1 `empty()` string semantics — `''` AND the literal `'0'`, not generic falsy

PHP's `empty($v)` on a `string` is `true` for exactly two values: `''` and
the literal one-character string `'0'` — NOT a generic JS
falsy/whitespace check (a string like `' '` or `'00'` is NOT `empty()`).
This shows up in two independent places:

- `create-template/route.ts`'s pre-service gate: `empty($name) ||
  empty($content)` runs on the RAW (pre-`trim()`) value ->
  `'Name and content are required'`. Test:
  `400 {success:false, error:"Name and content are required"} for
  body={"name":"0","content":"hi"}...` and the mirrored `content: '0'`
  case in `create-template/route.test.ts`.
- `_lib/createTemplate.ts`'s `createTemplate()` (and
  `_lib/updateTemplate.ts`'s `updateTemplate()`) own POST-`trim()` checks
  -> `InvalidArgumentException`-equivalent `throw new Error(...)`, caught
  by the route's outer `try/catch` -> `'Failed to create/update template:
  Template name/content ... '`. Test:
  `name trims to the literal string "0" -> PHP empty()-string-zero edge
  case -> throws "Template name cannot be empty"` in
  `update-template/route.test.ts` (and the whitespace-only-name/content
  variants in both `create-template/route.test.ts` and
  `update-template/route.test.ts`, proving a name that is non-empty
  PRE-trim but empty POST-trim passes the route-level gate and still
  throws from the service).

### 3.2 `update-template`'s `quick_reply`: `isset()`-false-for-null vs. the `'' -> null` clear

`api/inbox-v2.php`'s `case 'update_template':` builds its payload with
`isset($body[key])` for `name`/`content`/`category`/`quick_reply`
independently — PHP's `isset()` on an array key is `false` for BOTH a
genuinely absent key AND a key present with an explicit `null` value. For
`quick_reply` specifically, an extra rule then applies ONLY when `isset()`
already passed: `$val === '' ? null : $val`. The two resulting cases are
genuine, easy-to-invert opposites:

| Body | `isset()` | Payload | Effect |
|---|---|---|---|
| `{quick_reply: ''}` | `true` (not `null`) | `quick_reply: null` | Column **cleared** — `SET quick_reply = NULL` |
| `{quick_reply: null}` | `false` (PHP's null-is-unset rule) | key never added | Column **left untouched** — not in the `SET` clause at all |

`update-template/route.ts`'s `hasIssetKey()` (`key in body && body[key]
!== null`) implements the `isset()` half; the route's own `val === '' ?
null : ...` implements the clear-coercion half — both ported at the exact
point PHP applies them (inside the payload-build loop, before the
`'No data to update'` emptiness check). `update-template/route.test.ts`'s
`describe('quick_reply: isset()-vs-null gotcha', ...)` block covers all
four shapes explicitly by test name:

- `quick_reply: "" (exact empty string) IS isset() -> included in payload,
  coerced to null -> UPDATE clears the column`
- `quick_reply: null in the body is NOT isset() -> treated as absent ->
  "No data to update" when it is the only field, column left untouched`
- `quick_reply: null alongside a real field (e.g. name) -> quick_reply key
  dropped entirely, column untouched, only name is in the SET clause`
- `quick_reply: a real string value passes through raw (already
  null-coerced upstream only for the "" case)`

## 4. Not-found is 400, not 404 (`update-template` and `delete-template`)

Both `TemplateService::updateTemplate()` and `::deleteTemplate()` call
`getById($id)` (scoped to `line_account_id`) FIRST and return `false`
WITHOUT throwing when no row matches — a template that doesn't exist, or
exists but belongs to a different LINE account. PHP's
`sendError('Failed to update/delete template')` uses `sendError()`'s
DEFAULT status code, which is `400`, NOT `404` — genuinely easy to get
backwards when porting a "not found" condition. Both routes' `false`
branch returns `400` explicitly (not `404`), and both `_lib` ports
(`updateTemplate()`/`deleteTemplate()`) issue the `getById` `SELECT` first
and skip the `UPDATE`/`DELETE` entirely when it finds nothing — asserted
directly in both test suites via the recorded-query list (`queries` has
exactly 1 entry, the `SELECT`, with no mutating statement following it).

## 5. Schema check (re-verified, no drift, no fix-forward needed)

`quick_reply_templates` (`id`, `line_account_id`, `name`, `content`,
`category`, `quick_reply`, `usage_count`, `last_used_at`, `created_by`,
`created_at`, `updated_at`) is confirmed present in
`packages/db/src/generated/tenant-db.d.ts` as `QuickReplyTemplates`, with
every column all 3 ported `TemplateService.php` methods
(`getById`/`createTemplate`/`updateTemplate`/`deleteTemplate`) touch
correctly typed:

```ts
export interface QuickReplyTemplates {
  category: Generated<string | null>;
  content: string;
  created_at: Generated<Date | null>;
  created_by: Generated<number | null>;
  id: Generated<number>;
  last_used_at: Generated<Date | null>;
  line_account_id: number;
  name: string;
  quick_reply: Generated<string | null>;
  shortcuts: Generated<string | null>;
  updated_at: Generated<Date | null>;
  usage_count: Generated<number | null>;
  variables: Generated<string | null>;
}
```

Re-verified independently against the live file this round (not just
trusting the orchestrator's prior pass) — no drift found, all 3 ports are
fully literal with no schema-drift fix needed.

## 6. Deferred scope

Out of scope for this batch: `get_templates`/`templates` (the read-side
`GET` action backing the same table — a separate case label this batch's
brief did not include), `fillPlaceholders()`, `recordUsage()`,
`getByCategory()`, `getCategories()`, `getMostUsed()`, `getCount()` (none
of `TemplateService.php`'s other public methods are called from any of the
3 ported case blocks, so none were ported). The already-merged Phase-2
`/templates` PAGE (`apps/admin/src/app/(tenant)/templates/**`) and its own
queries/actions were not opened, imported from, or modified. The sibling
`analyticsAndPerf` stream's 4 action directories
(`analytics/`, `record-analytics/`, `log-performance-metric/`,
`get-performance-metrics/`) and its own runbook are owned by that stream,
not this one.

## 7. How to run each route's own test suite locally

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/create-template
npx jest src/app/api/inbox/actions/update-template
npx jest src/app/api/inbox/actions/delete-template
```

Or all three at once:

```bash
cd apps/admin
npx jest --testPathPattern="create-template|update-template|delete-template"
```

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

## 8. Acceptance criteria (mig-verify executes these)

- [ ] `cd apps/admin && npx jest --testPathPattern="create-template|update-template|delete-template"`
      — all pass (45 tests: 16 create-template + 19 update-template + 10
      delete-template).
- [ ] Each `route.test.ts` covers: 401 on missing session (DB never
      touched), 405 for the wrong verb, the documented validation 400s
      (including the `empty()` string-`'0'` edge case for `create` and the
      `id`-required / `No data to update` cases for `update`, `id`-required
      for `delete`), the 503 service-unavailable branch via an explicit
      `loadTemplateService` mock, the not-found-is-400-not-404 case for
      `update`/`delete`, a happy-path exact-envelope assertion, and (for
      `update-template` only) the `quick_reply: null` vs. `quick_reply: ''`
      isset()-semantics proof (§3.2).
- [ ] `cd <worktree> && pnpm --filter admin lint` — zero errors, exit 0.
- [ ] `git diff --name-only origin/main...HEAD` (or `git status --short`)
      touches only
      `apps/admin/src/app/api/inbox/actions/{create-template,update-template,delete-template}/**`
      and this runbook — no PHP file, no
      `apps/admin/src/app/(tenant)/templates/**`, no
      `apps/admin/src/nav/manifest.ts`, no `infra/nginx/routes.json`, and
      no pre-existing or sibling-stream directory under
      `apps/admin/src/app/api/inbox/actions/**`.
- [ ] This document
      (`docs/runbooks/phase4-batch8-templates-crud-parity.md`) exists,
      contains the alias table (§1.1) and the ownership-choice statement
      (§1), and is a genuinely new file.
