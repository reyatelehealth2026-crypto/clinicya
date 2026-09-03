# Phase 5 — dispense chain parity

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 5
("Dispense + Documents/VAT (flip พร้อมกันเป็น atomic ต่อ tenant)"). This
runbook covers **only the dispense chain half** of Phase 5 —
`dispensing_records` → `transactions`/`transaction_items` → seed cart
(transfer/later) → guarded stock decrement → RefillTracking → Flex ฉลากยา
(LIFF-or-OA URL fallback) → `messages` `sent_by='system:dispense'`. The
Documents/VAT half (`genDocNumber`, `calcVAT`, `formatThaiDate`,
`packages/core`, `documents.php`/`api/documents.php`, printable A4 HTML) is
a **separate builder's territory (documentsVat)** and is explicitly out of
scope here — see §3.

Owner: dispenseChain (`apps/admin/src/app/api/inbox/actions/dispense/**`
exclusively).

## 1. What landed

Literal port of `inbox-v2.php`'s `case 'dispense':` (lines 469-736), the
same-page AJAX action fired from the dispense modal (ระบบจ่ายยา), as a
standalone Next.js Route Handler:

| PHP source | Next port | Notes |
|---|---|---|
| `inbox-v2.php` `case 'dispense':` (469-736) | `apps/admin/src/app/api/inbox/actions/dispense/route.ts` + `_lib/dispense.ts` | POST, JSON body `{ user_id, items, total_amount, payment_method, notes, shop_name, pharmacist_name }` |
| `classes/RefillTrackingHelper.php::parsePackSize()` + `::trackFromDispense()` | `_lib/refillTracking.ts` | `ensureTable()` **not** ported — see §2 |
| `includes/liff-helper.php`'s `reya_is_real_liff_id()`, `reya_oa_chat_url()`, `reya_append_liff_context_params()`, `reya_liff_url_or_oa()` | `_lib/checkoutUrl.ts` | Only this subset — `getUnifiedLiffId()`/`getShopSettings()`/`getLineAccountIdFromUser()` skipped (dispense never calls them) |
| The `liff_apps` lookup + business_items image hydration + `classes/FlexTemplates.php`'s `medicineLabel()`/`medicineLabelsCarousel()`/`toMessage()` dispatch + `classes/LineAPI.php::sendMessage()` + the outgoing `messages` insert | `_lib/flexSend.ts` | Calls the already-merged `@reya/line` package for the Flex builders and the reply-token-first/push-fallback sender |
| Auth gate (`includes/header.php`'s logged-in-admin requirement, reached via the AJAX `HTTP_X_REQUESTED_WITH` switch) | `_lib/session.ts` | Local copy of the established `resolveInboxApiContext()` pattern — one copy per action-family directory, not imported from a sibling action |

Response shape (`{success, order_number, dispense_id}` on success;
`{success:false, error}` on any thrown validation/DB error, HTTP 400) is
unchanged from PHP.

### 1.1 Fault-tolerance contract (mirrors PHP's own scoping, not an all-or-nothing rewrite)

Only two things can abort the whole action: `user_id` missing/falsy
("User ID is required"), `items` empty/unparseable ("No items to
dispense"), and the `users` lookup returning no row ("User not found").
Every step after that is independently fault-tolerant, exactly matching
PHP's own try/catch scoping:

- business_items hydration — try/catch **per item**, a failure leaves that
  item raw (PHP lines 496-531).
- `dispensing_records` insert — **unguarded** (PHP has no try/catch around
  it either); a failure here propagates all the way to `route.ts`'s outer
  catch and surfaces as a flat 400, same as PHP's outer switch-level catch
  would.
- transaction + transaction_items + (non-cash) cart delete/insert — one
  try/catch around the whole block (PHP lines 591-622); `transactionId`
  may stay `null` on failure and the dispense continues regardless.
- cash-only stock decrement — **unguarded** (no try/catch, matching PHP);
  the `AND stock >= ?` WHERE guard is the race-safety mechanism and is
  never weakened, and — matching PHP exactly — its affected-row-count is
  never checked (a silent no-op on insufficient stock does not fail the
  dispense).
- RefillTracking — try/catch-and-continue (PHP lines 634-645), **and**
  `trackFromDispense()` itself has its own per-item try/catch — belt and
  suspenders, both layers replicated verbatim rather than collapsed to one.
- LINE Flex send — try/catch-and-continue (PHP lines 647-721), only
  attempted when the user has a `line_user_id`.
- activity log — try/catch-and-continue (PHP lines 723-731).

`_lib/dispense.test.ts` proves this directly: a thrown error injected into
the (mocked) RefillTracking step, and separately into the (mocked)
Flex-send step, each still yield a `200 {success:true}` response carrying
the same `order_number`/`dispense_id` a failure-free run would produce.

## 2. Deliberate deviations (read before "fixing" any of these)

1. **`sent_by` schema-probe branching is KEPT, on purpose.** PHP runs
   `SHOW COLUMNS FROM messages LIKE 'sent_by'` at runtime and branches the
   outgoing `messages` INSERT's column list on the result. The sibling
   `send-message/_lib/sendMessage.ts` treats this same probe as
   unreachable dead code against the current committed schema (`sent_by`
   always exists per `packages/db/src/generated/tenant-db.d.ts`'s
   `Messages` interface) and drops it, inserting only the
   `sent_by`-inclusive shape. **`_lib/flexSend.ts` does NOT follow that
   precedent** — this batch's brief explicitly required both insert-shape
   branches to survive the port. Do not "simplify" this away; it is a
   documented, intentional inconsistency with `send-message`'s own
   convention, not an oversight.
2. **No `RefillTrackingHelper::ensureTable()` port.** The PHP helper
   auto-creates `medication_refill_tracking` via
   `CREATE TABLE IF NOT EXISTS` on first use. `_lib/refillTracking.ts`
   drops this entirely — the table is already present in the committed
   generated schema (`MedicationRefillTracking` in
   `packages/db/src/generated/tenant-db.d.ts`), and CLAUDE.md's
   "Auto-create tables" convention prefers a versioned migration over
   page-load auto-create for new code.
3. **Order-number scheme is unchanged from PHP, and deliberately NOT
   `genDocNumber`'s scheme.** `'DIS' + date('ymdHis') + rand(100,999)` and
   `'TXN' + date('YmdHis') + rand(100,999)` (12/14-digit timestamp +
   3-digit random suffix) are ported byte-for-byte via
   `_lib/bangkokTime.ts` + `mtRand()`. These are **not** Buddhist-era
   `{PREFIX}-{YYMM}-{seq4}` document numbers — that scheme
   (`genDocNumber`, `INSERT IGNORE` + `SELECT…FOR UPDATE`) belongs to
   `packages/core` and the Documents/VAT half of Phase 5, a different
   builder's (documentsVat's) territory, and is untouched by this batch.
   `dispense.test.ts` asserts the literal regexes
   (`/^DIS\d{15}$/`, `/^TXN\d{17}$/`) specifically to pin this distinction.
4. **`currentBotId` vs `user.line_account_id` dual scoping, preserved
   verbatim.** PHP's dispense case uses `$currentBotId` (the session's
   `current_bot_id`) for every "business table" write
   (`dispensing_records`/`cart`/`transactions`/RefillTracking context), but
   `$user['line_account_id']` (the actual LINE account the customer
   belongs to) for every shop-lookup/LINE-send query
   (`shop_settings`/`line_accounts`/`liff_apps`/the outgoing `messages`
   row). This is a real quirk in the PHP source, not a bug this port
   silently "fixes" — both `dispense.ts` and `flexSend.ts` keep the two
   scoping variables distinct.
5. **Shop-logo absolute-URL construction uses the incoming request's own
   scheme+host** (`route.ts` derives `origin` from `request.url` and
   threads it into `dispenseAction()`), mirroring PHP's
   `$_SERVER['HTTPS']`/`$_SERVER['HTTP_HOST']` reads — same established
   convention as `api/miniapp/checkout/order`'s `uploadSlip.ts` (contrast
   that route's sibling `notify.ts`, which instead uses a fixed env-var-
   backed literal for a *different* PHP constant — not applicable here
   since PHP's shop-logo branch explicitly reads `$_SERVER`, not a
   `BASE_URL` constant).

## 3. Explicitly out of scope

- `genDocNumber`, `calcVAT`, `formatThaiDate`, `packages/core`,
  `documents.php` / `api/documents.php` / `includes/document-helpers.php`,
  any printable A4 HTML document view — documentsVat's territory, a fully
  disjoint set of paths (`packages/core/**`,
  `apps/admin/src/app/api/documents/**`,
  `apps/admin/src/app/(tenant)/documents/**`).
- `infra/nginx/routes.json` — mig-infra/mig-orchestrator's exclusive
  append this round; not touched here.
- Any sibling `apps/admin/src/app/api/inbox/actions/{notes,tags,medical,
  send-message,assign-*,mark-*,get-*,unassign-conversation}/**` directory —
  read only for pattern reference (session.ts / fakeTenantDb.ts shape),
  never imported from.
- Shadow-parity report against real production traffic (the 7-day,
  field-level ≥99.9% parity report Phase 3/4 endpoints get) — this is a
  same-page AJAX action, not a Phase-3-style customer-facing API with its
  own origin-map/shadow-traffic pipeline; parity evidence here is the unit
  test suite in §4, matching the size/shape of evidence Phase 4's
  action-batch runbooks already established as sufficient for
  `api/inbox/actions/**` Route Handlers of this kind.

## 4. Test commands

```bash
# From repo root, in this worktree:
pnpm install                              # only if node_modules is missing

# @reya/line (and every other @reya/* dependency) must be BUILT before a
# bare `pnpm --filter admin test`/`lint` will resolve it — it is not
# aliased in apps/admin/jest.config.js's moduleNameMapper (that file is
# out of this batch's allowed paths). Either:
pnpm turbo run build --filter='./packages/*'
pnpm --filter admin test
pnpm --filter admin lint

# ...or let turbo resolve the build graph automatically:
pnpm turbo run test --filter=admin
pnpm turbo run lint --filter=admin

# Just the dispense-chain suites:
pnpm --filter admin exec jest src/app/api/inbox/actions/dispense --no-coverage
```

As of this batch: `pnpm turbo run test --filter=admin` — **190 suites,
1736 tests, all green** (60 of those tests are new, across
`route.test.ts` + one `*.test.ts` per `_lib/*.ts` file:
`dispense.test.ts`, `refillTracking.test.ts`, `checkoutUrl.test.ts`,
`flexSend.test.ts`). `pnpm turbo run lint --filter=admin` — clean
(`tsc --noEmit` over the whole `apps/admin` package).

## 5. Deliverables (paths, all under this batch's exclusive ownership)

- `apps/admin/src/app/api/inbox/actions/dispense/route.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/route.test.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/session.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/dispense.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/dispense.test.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/refillTracking.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/refillTracking.test.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/checkoutUrl.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/checkoutUrl.test.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/flexSend.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/flexSend.test.ts`
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/types.ts` (shared
  `DispenseItem` shape — extends `@reya/line`'s `MedicineLabelItem` with
  the `product_id` field the Flex-template layer never reads by that name)
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/phpCompat.ts` (local
  `intval`/`floatval`/`trimOrEmpty`/`phpEmpty`/`phpTruthy`/`phpElvis`/
  `mtRand` helpers, shared across this action family's own `_lib/*.ts`
  files only — not imported by, or from, any sibling action)
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/bangkokTime.ts`
  (local Asia/Bangkok wall-clock helpers for order-number generation +
  RefillTracking day-supply arithmetic)
- `apps/admin/src/app/api/inbox/actions/dispense/_lib/testHelpers/fakeTenantDb.ts`
- `docs/runbooks/phase5-dispense-parity.md` (this file)
