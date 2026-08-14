# Phase 4 batch 5 — `/inbox` customer CRM actions

Source of truth: this batch's own brief (the `customerCrm` stream —
`apps/admin/src/app/api/inbox/actions/{customer-crm,add-customer-note,
add-customer-tag,remove-customer-tag,update-customer-info,
customer-loyalty}/**` exclusively this round) and the sibling `chatAndOrders`
stream's own runbook (its own file, not this one — covers
`poll/`, `save-pending-order/`, `update-chat-status/`; both streams ran in
the same worktree in parallel this round, file-disjoint by construction).
Structure follows `docs/runbooks/phase4-batch4b-patient-clinical-parity.md`
exactly (§1 alias table + landed-actions summary, §2 same-table/adjacent-case
collision write-ups, §3 confirmed schema-drift fixes in Before/After prose,
§4+ deferred scope, jest/acceptance-criteria tail).

## 1. What landed — 6 ported actions

All six are literal ports of `api/inbox-v2.php`'s action switch (the same
cursor/AI-copilot API file batches 3, 4a, and 4b ported from), decomposed
into their own Next.js Route Handlers under
`apps/admin/src/app/api/inbox/actions/**`. Every handler requires a valid
tenant session (any of the six `TenantRole` values) via
`resolveInboxApiContext()` — the same per-route auth gate every prior batch
established.

### 1.1 Alias table — every PHP case label maps to exactly one route

| PHP case labels (`api/inbox-v2.php`) | Route |
|---|---|
| `customer_loyalty`, `customer-loyalty` (lines 843-868) | `apps/admin/src/app/api/inbox/actions/customer-loyalty/route.ts` |
| `customer_crm` (line 1908) | `apps/admin/src/app/api/inbox/actions/customer-crm/route.ts` |
| `add_customer_note` (line 2026) | `apps/admin/src/app/api/inbox/actions/add-customer-note/route.ts` |
| `add_customer_tag` (line 2057) | `apps/admin/src/app/api/inbox/actions/add-customer-tag/route.ts` |
| `remove_customer_tag` (line 2105) | `apps/admin/src/app/api/inbox/actions/remove-customer-tag/route.ts` |
| `update_customer_info` (line 2165) | `apps/admin/src/app/api/inbox/actions/update-customer-info/route.ts` |

`customer_loyalty` is this batch's ONE alias pair — the only action in this
batch (and one of very few across the whole `api/inbox/actions/*` family)
with two case labels, both falling through to the same body with no `break`
between them. Every other action in this batch has exactly one case label —
no widening, no extra aliases invented.

**NOT part of this alias table, and deliberately not ported at all**:
`assign_tag` (line 2135, already merged — see §2) and `save_note`
(root `inbox-v2.php`, already merged — see §2) are pre-existing, unrelated
routes this batch does not touch.

### 1.2 Request/response summary

| # | Route | Verb(s) | Required input | Response shape | Status codes |
|---|---|---|---|---|---|
| 1 | `customer-loyalty` | `GET` only | `user_id` (int, required, `<=0` rejected) | `{success: true, data: result}` — success is UNCONDITIONAL, no `found` flag | 200 / 400 `Invalid user ID` / 500 `Database error: ...` / 405 on `POST` |
| 2 | `customer-crm` | **`GET` AND `POST`** — no 405 stub for either verb, the one action in the whole family with no method guard | `user_id` (int, required; query string on `GET`, JSON body on `POST`) | `{success: true, data: {user, points, tier, stats, tags, all_tags, notes, transactions}}` | 200 / 400 `User ID is required` / 404 `User not found` (immediate, bypasses the outer catch) / 400 `Failed to load CRM data: ...` |
| 3 | `add-customer-note` | `POST` only | `{user_id, content}` | `{success: true, message: 'Note added successfully', note_id}` | 200 / 400 `User ID and content are required` / 400 `Failed to add note: ...` / 405 on `GET` |
| 4 | `add-customer-tag` | `POST` only | `{user_id, tag_name}` | `{success: true, message: 'Tag added successfully', tag_id}` | 200 / 400 `User ID and tag name are required` / 400 `Failed to add tag: ...` / 405 on `GET` |
| 5 | `remove-customer-tag` | `POST` only | `{user_id, tag_id}` | `{success: true, message: 'Tag removed successfully'}` — UNCONDITIONAL, no `rowCount` check | 200 / 400 `User ID and tag ID are required` / 400 `Failed to remove tag: ...` / 405 on `GET` |
| 6 | `update-customer-info` | `POST` only | `{user_id, field, value}`, `field` gated by the 12-entry `ALLOWED_FIELDS` whitelist | `{success: true, message: 'Customer info updated successfully'}` | 200 / 400 `Invalid user ID or field` / 400 `Failed to update customer info: ...` / 405 on `GET` |

Every `POST`-body-reading route in this batch reads the JSON body ONLY
(never a `$_POST`-equivalent) — matching every other ported action in the
`api/inbox/actions/*` family. Several PHP case blocks (`remove_customer_tag`,
`update_customer_info`) call `getJsonBody()` with a `$_POST`-first fallback;
a Route Handler has no `$_POST` analogue for a JSON-bodied request, so only
the JSON body is read.

### 1.3 Internal call graph

- `customer-crm/_lib/loyaltyPoints.ts` is a fresh, independently-owned
  literal port of `classes/LoyaltyPoints.php`'s `getUserPoints($userId)`
  ONLY (lines 50-96) — construct-and-call only, `loadSettings()` and
  `calculatePoints()` are NOT ported (unused by this call path; see that
  file's own module doc for the full reasoning). This is a SEPARATE copy
  from `apps/admin/src/app/api/miniapp/{member,rewards}/_lib/loyaltyPoints.ts`
  (unrelated, pre-existing ports of DIFFERENT `LoyaltyPoints` methods for the
  miniapp surface) — no import or naming collision either direction.
- `customer-loyalty/_lib/customerLoyalty.ts` is a literal, self-contained
  port of `classes/DrugPricingEngineService.php`'s `getCustomerLoyalty()` +
  `getUserTierInfo()` + `getPurchaseStats()` + `getAverageDiscount()` +
  `calculateDiscountExpectation()` (lines 268-520) — no cross-route imports,
  everything lives in this one file.
- No other cross-route imports in this batch. `add-customer-tag/_lib/
  addCustomerTag.ts` copies (does not import) `resolveAssignedBy()` from
  the already-merged `assign-tag/_lib/assignTag.ts` — same "every consumer
  keeps its own copy" precedent this whole family already established for
  `session.ts`/`testHelpers/fakeTenantDb.ts`.

## 2. Same-table / byte-adjacent collisions checked against already-merged siblings

This batch's brief explicitly flagged two collision risks — both checked
directly against the PHP source before any code was written, and both
confirmed to be genuinely separate code paths sharing only a target table.

### 2.1 `add-customer-note` vs. the already-merged `actions/notes/route.ts`

`actions/notes/route.ts` ports ROOT `inbox-v2.php`'s (**not** `api/inbox-v2.php`'s)
same-page-AJAX `case 'save_note':` (lines 414-429) — a DIFFERENT PHP FILE's
own switch statement entirely. Both write to `user_notes`, and that is the
ONLY thing they share:

| | `save_note` (`actions/notes/route.ts`, already merged) | `add_customer_note` (`actions/add-customer-note/route.ts`, this batch) |
|---|---|---|
| Source file | `inbox-v2.php` (root) | `api/inbox-v2.php` |
| INSERT columns | `user_id, note, created_at` (3) | `user_id, note, created_by, created_at` (4) |
| `created_by` | never bound | `session.adminUserId` |
| Response shape | `{success, id}` | `{success, message, note_id}` |
| Side effects | also writes an `activity_logs` row | none |

`add-customer-note/_lib/addCustomerNote.ts`'s own module doc spells out the
exact SQL-text diff, and `add-customer-note/route.test.ts` contains an
explicit test asserting the 4-parameter, `created_by`-carrying INSERT this
action issues, cross-referencing `actions/notes/route.ts` by name in the
test description. Neither file imports from, or otherwise touches, the
other.

### 2.2 `add-customer-tag`/`remove-customer-tag` vs. the already-merged `actions/assign-tag/route.ts`

`actions/assign-tag/route.ts` ports the byte-adjacent `case 'assign_tag':`
(lines 2135-2153, the SAME `api/inbox-v2.php` file, a DIFFERENT case label
just below `remove_customer_tag`). Its `INSERT IGNORE INTO
user_tag_assignments` is BYTE-FOR-BYTE IDENTICAL to `add_customer_tag`'s own
second half:

```php
// Both case blocks, byte-identical:
$stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())");
$stmt->execute([$userId, $tagId, $adminId ?? 'Admin']);
```

The genuine, load-bearing difference is entirely UPSTREAM of that shared
INSERT:

| | `assign_tag` (already merged) | `add_customer_tag` (this batch) |
|---|---|---|
| Input | an EXISTING `tag_id` directly | a `tag_name` STRING |
| Preamble | none — goes straight to the `INSERT IGNORE` | `SELECT id FROM user_tags WHERE name = ? AND (...)`, falling back to `INSERT INTO user_tags (...)` with a random color from a 7-color palette, to resolve a `tag_id` first |
| Final INSERT | byte-identical | byte-identical |

`remove_customer_tag` is unrelated to `assign_tag` (a `DELETE`, not an
`INSERT`) but shares the same `user_tag_assignments` target table — also
checked and confirmed to be its own simple, unconditional `DELETE FROM
user_tag_assignments WHERE user_id = ? AND tag_id = ?` with no find/lookup
preamble at all.

`add-customer-tag/_lib/addCustomerTag.ts`'s own module doc spells out the
full SQL-text cross-reference, and `add-customer-tag/route.test.ts` contains
an explicit test (`"NOT the same route as the already-merged
actions/assign-tag/route.ts..."`) proving this action always issues the
`SELECT id FROM user_tags` preamble that `assign_tag`'s own flow never does.
Neither file imports from, or otherwise touches, `actions/assign-tag/**` or
`actions/tags/**`.

## 3. Two confirmed schema-drift fixes in `DrugPricingEngineService`

Both were verified by cross-checking every column
`classes/DrugPricingEngineService.php`'s SQL touches against
`packages/db/src/generated/tenant-db.d.ts` — the same methodology Phase 4
batch 4a and 4b already established for their own fixes. Both are fixed
FORWARD (not silently reproduced as an always-throwing query) per this
batch's explicit brief.

### Fix (1) — `member_tiers.name`/`badge_color` do not exist; the real columns are `tier_name`/`color`

PHP's `getUserTierInfo()` custom-tiers query:

```php
$stmt = $this->db->prepare("
    SELECT name, tier_name, min_points, badge_color as color
    FROM member_tiers
    WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1
    ORDER BY min_points ASC
");
```

`MemberTiers` in `tenant-db.d.ts` has `tier_name: string` and `color:
Generated<string | null>` — it does NOT have `name` or `badge_color` at
all.

**Before**: this query ALWAYS throws `Unknown column` on the committed
schema, caught by PHP's own `catch (PDOException $e)`, which falls through
to the `points_tiers` fallback query. This permanently skips ANY tenant's
own configured `member_tiers` custom tiers on EVERY call — there was no way
to reach them, ever, in production.

**After**: `customer-loyalty/_lib/customerLoyalty.ts`'s query selects
`tier_name, min_points, color` (the two nonexistent columns dropped
entirely, not merely aliased away), mapped to `{name: row.tier_name,
min_points: row.min_points, color: row.color ?? '#6B7280'}` — matching
PHP's own row-mapping fallback logic with the now-dead `$t['name']`
alternative removed (it can never populate once the nonexistent column is
gone from the SELECT). A tenant's real, configured `member_tiers` rows are
now actually reachable.

### Fix (2) — `getAverageDiscount()`'s fallback `discount` column does not exist anywhere (dropped entirely)

```php
private function getAverageDiscount(int $userId): float
{
    try {
        // primary query — discount_amount — FINE AS-IS, confirmed present
        if ($result && $result['avg_discount'] > 0) { return (float)$result['avg_discount']; }

        // fallback query — discount — DOES NOT EXIST
        $stmt = $this->db->prepare("SELECT COALESCE(AVG(discount), 0) as avg_discount FROM transactions WHERE user_id = ? AND status NOT IN (...) AND discount > 0");
        ...
        return (float)($result['avg_discount'] ?? 0);
    } catch (PDOException $e) {
        return 0.0;
    }
}
```

`Transactions` in `tenant-db.d.ts` has `discount_amount: Generated<Decimal |
null>` — the primary query is a fully literal, unmodified port, confirmed
correct. It does NOT have a bare `discount` column anywhere.

**Before**: the fallback query always throws, and that throw is silently
caught by the SAME outer `try`'s own `catch (PDOException $e) { return 0.0;
}` — always yielding `0.0` from that branch today, net-identically to
simply not running it.

**After**: the fallback query is DROPPED ENTIRELY.
`customer-loyalty/_lib/customerLoyalty.ts`'s `getAverageDiscount()` returns
`0.0` directly when the primary query's average is `0`/absent — a
NET-IDENTICAL observable result to today's always-failing fallback, but
with no query left in this codebase that is guaranteed to fail against the
real schema.

Everything else `DrugPricingEngineService::getCustomerLoyalty()` touches
(`getPurchaseStats()`'s `transactions`/`orders` branches, `points_tiers`'s
`name`/`min_points`/`color` fallback columns) is confirmed present in
`tenant-db.d.ts` and ported literally, no fix needed — see
`customer-loyalty/_lib/customerLoyalty.ts`'s own module doc for the
column-by-column confirmation.

## 4. Pre-existing `@reya/db` packaging gap discovered this round (not fixed, out of allowed paths)

While wiring `update-customer-info`'s whitelist-gated dynamic-column
`UPDATE` (`ALLOWED_FIELD_TO_COLUMN: Record<AllowedField, keyof Users &
string>`), `npm run lint` (`tsc --noEmit -p tsconfig.json`) surfaced a
genuine, PRE-EXISTING gap in `packages/db`'s build: `packages/db/tsconfig.json`'s
`"include": ["src/**/*.ts"]` compiles only `.ts` sources — `tsc -b` never
copies the hand/codegen-authored `packages/db/src/generated/{tenant,master}-db.d.ts`
files into `dist/generated/`. `dist/index.d.ts`'s `export type { DB as
TenantDB } from './generated/tenant-db'` therefore points at a module that
does not exist under `dist/`, and `TenantDB` silently resolves to `any` for
any consumer importing the BUILT `@reya/db` package — confirmed with a
throwaway probe (`db.insertInto('totally_bogus_table_xyz').values({nonsense:
1}).execute()` type-checked with ZERO errors against the built package).

This degrades EVERY `Kysely<TenantDB>` column/table reference across the
WHOLE `api/inbox/actions/*` family to unchecked `any` under `npm run lint`
today — not something introduced by this batch, and not unique to
`update-customer-info`. `jest` never surfaces it (its `moduleNameMapper`
resolves `@reya/db` straight to `packages/db/src/index.ts` SOURCE, where the
real generated types are intact), which is why it was previously invisible
to any test suite in this repo.

`packages/db/**` is outside this batch's allowed paths (no schema/build
edits authorized this round), so this is documented here, not fixed.
`update-customer-info/_lib/updateCustomerInfo.ts`'s own module doc carries
the full technical write-up and the exact probe used to confirm it. The
type annotations in that file (`Record<AllowedField, keyof Users &
string>`) are kept as-authored — correct by inspection against
`tenant-db.d.ts` today, and will start being MECHANICALLY enforced by `tsc`
the moment a future change adds `dist/generated/*.d.ts` emission to
`packages/db`'s build, with no changes needed in this file.

## 5. Deferred scope

Out of scope for this batch, matching batch 4b §6's own list minus the
actions this batch (and the sibling `chatAndOrders` stream landing in the
same round) actually ported: `check_drug_interactions` as its own route
(still only reachable as `patient-profile`'s private internal dependency),
`analyze_symptom`/`analyze_drug`/`analyze_prescription`,
`ghost_draft`/`learn_draft`/`draft_style`,
`classify_customer`/`customer_health`, `recommendations`,
`safe_alternatives`, `context_widgets`, `consultation_stage`,
`quick_actions`, `detect_urgency`, `analytics`/`record_analytics`,
`remove_customer_note` (no `add_customer_note` equivalent for deletion
exists in this batch — only add/create was in scope), `drug_card`,
`validate_recommendation`, and `dispense` (owned by a separate, already
completed stream). `poll`, `save_pending_order`, `update_chat_status` are
owned by this round's sibling `chatAndOrders` stream — its own runbook, not
this one, covers them.

## 6. How to run each route's own test suite locally

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/customer-loyalty
npx jest src/app/api/inbox/actions/customer-crm
npx jest src/app/api/inbox/actions/add-customer-note
npx jest src/app/api/inbox/actions/add-customer-tag
npx jest src/app/api/inbox/actions/remove-customer-tag
npx jest src/app/api/inbox/actions/update-customer-info
```

Or all six at once:

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/customer-crm src/app/api/inbox/actions/add-customer-note src/app/api/inbox/actions/add-customer-tag src/app/api/inbox/actions/remove-customer-tag src/app/api/inbox/actions/update-customer-info src/app/api/inbox/actions/customer-loyalty
```

`cd apps/admin && npm run lint` (`tsc --noEmit -p tsconfig.json`) passes
with zero errors across all new/changed files. (Building
`@reya/config`/`@reya/db`/`@reya/auth`/`@reya/tenant`/`@reya/contracts` via
`pnpm --filter <pkg> run build` first is required for `tsc` to resolve
their `dist/*.d.ts` — jest resolves the same workspace packages straight
from source instead, via `apps/admin/jest.config.js`'s `moduleNameMapper`,
so `npx jest` never needs this build step. See §4 for the one caveat this
build step surfaced.)

## 7. Acceptance criteria (mig-verify executes these)

- [ ] `cd apps/admin && npx jest src/app/api/inbox/actions/customer-crm
      src/app/api/inbox/actions/add-customer-note
      src/app/api/inbox/actions/add-customer-tag
      src/app/api/inbox/actions/remove-customer-tag
      src/app/api/inbox/actions/update-customer-info
      src/app/api/inbox/actions/customer-loyalty` — all pass (79 tests).
- [ ] `cd apps/admin && npm run lint` — zero errors (see §4 for the one
      pre-existing gap this surfaced and how this batch's code accommodates
      it without reproducing an error).
- [ ] `grep -R "badge_case\|'name, tier_name'\|FROM transactions.*discount "
      apps/admin/src/app/api/inbox/actions/customer-loyalty` returns no
      query-building reference to the two dead columns (`badge_color`, bare
      `discount`) — only doc-comment/before-after-prose mentions of them are
      expected, per §3's fix write-ups.
- [ ] `customer-crm/route.test.ts` proves: (a) the user-not-found 404
      short-circuits before any of the 6 best-effort blocks run any query,
      (b) each of the 6 best-effort blocks independently degrading to its
      documented default without 500ing the whole response, (c) both
      GET-query-string and POST-JSON-body callers reach the same handler.
- [ ] `add-customer-tag/route.test.ts` and `add-customer-note/route.test.ts`
      each contain an explicit assertion/comment cross-referencing the
      already-merged `assign-tag/route.ts` and `notes/route.ts`
      respectively (see §2).
- [ ] This document (`docs/runbooks/phase4-batch5-customer-crm-parity.md`)
      exists, contains the alias table (§1.1) and both schema-drift
      write-ups (§3), and is a genuinely new file.
- [ ] `git diff --stat origin/main` confined to
      `apps/admin/src/app/api/inbox/actions/{customer-crm,add-customer-note,
      add-customer-tag,remove-customer-tag,update-customer-info,
      customer-loyalty}/**` and this runbook — no edits to `assign-tag/**`,
      `tags/**`, `notes/**`, `apps/admin/src/nav/manifest.ts`,
      `infra/nginx/routes.json`, `packages/db/**`, any `database/**/*.sql`
      file, or the sibling `chatAndOrders` builder's directories
      (`poll/`, `save-pending-order/`, `update-chat-status/`) or its
      runbook.
