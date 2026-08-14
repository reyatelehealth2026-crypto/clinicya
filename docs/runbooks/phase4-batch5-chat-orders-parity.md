# Phase 4 batch 5 — `/inbox` polling, pending-order, and chat-status actions

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time"). Owner: mig-api
(chatAndOrders stream —
`apps/admin/src/app/api/inbox/actions/{poll,save-pending-order,update-chat-status}/**`
exclusively this round). This batch runs in the same worktree, same round,
as the sibling customerCrm stream
(`apps/admin/src/app/api/inbox/actions/{customer-crm,customer-note,customer-tag,update-customer-info,customer-loyalty}/**`)
— the two builders' path sets are fully disjoint by construction (see §5);
this runbook covers chatAndOrders only and does not describe or modify
customerCrm's files or its own runbook. Cross-reference:
`docs/runbooks/phase4-batch4b-patient-clinical-parity.md` (the sibling
runbook whose structure, "intentional deviation" framing, and §4
no-case-catch precedent this one follows) and Phase 4 batch 4a
(`claude/phase4-batch4-copilot-data`, already merged — the drug-data
copilot actions, whose `max-discount/_lib/drugPricingEngine.ts` dropped
runtime-column-probe precedent this batch's own SHOW-KEYS simplification
extends, per §3).

## 1. What landed — 3 ported actions from `api/inbox-v2.php`

All three are literal ports of `api/inbox-v2.php`'s action switch (the same
cursor/AI-copilot API file batch 3 and batch 4a/4b ported from), decomposed
into their own Next.js Route Handlers under
`apps/admin/src/app/api/inbox/actions/**`. Every handler requires a valid
tenant session (any of the six `TenantRole` values) via
`resolveInboxApiContext()` — the same per-route auth gate every prior batch
established.

### 1.1 Alias table — every PHP case label maps to exactly one route (no aliases this round)

| PHP case label (`api/inbox-v2.php`) | Route | Verb |
|---|---|---|
| `poll` (lines ~172-193) | `apps/admin/src/app/api/inbox/actions/poll/route.ts` | `GET` |
| `save_pending_order` (lines ~1832-1878) | `apps/admin/src/app/api/inbox/actions/save-pending-order/route.ts` | `POST` |
| `update_chat_status` (lines ~2362-2406) | `apps/admin/src/app/api/inbox/actions/update-chat-status/route.ts` | `POST` |

Unlike batch 4b's 5 actions (each of which had 2-3 case-label spellings —
e.g. `patient_profile`/`patient-profile`/`get_patient_profile` all mapping to
one route), **all three of this batch's PHP case labels are single,
unaliased strings** — `poll`, `save_pending_order`, `update_chat_status` each
appear exactly once in `api/inbox-v2.php`'s `switch ($action)`, with no
hyphenated or `get_*`-prefixed variant to also cover. There is nothing to
list in an alias table beyond the 1:1 mapping above.

### 1.2 Request/response summary

| # | Route | Verb | Input | Response (`success` semantics) |
|---|---|---|---|---|
| 1 | `poll` | `GET` | `since` (int query param, default 0) | `{success: true, data: {new_messages, conversation_updates}}` — unconditional; PHP's `pollUpdates()` return value's own `count` key is dropped (not read by the case body) |
| 2 | `save-pending-order` | `POST` | JSON body `{user_id, items, subtotal, discount, total}` | `{success: true, message: 'Pending order saved', expires_at}` |
| 3 | `update-chat-status` | `POST` | JSON body `{user_id, status}` | `{success: true, message: 'Chat status updated successfully'}` |

All three reject the wrong HTTP verb with `{success: false, error: 'Method
not allowed'}` at HTTP 405 (`poll` rejects `POST`; the other two reject
`GET`).

`poll`'s response shape needs its own callout: `pollUpdates()` (the ported
`InboxService` method backing this action, see `poll/_lib/poll.ts`) returns
`{new_messages, updated_conversations}` internally, but `case 'poll':`
renames the second key to `conversation_updates` at the response boundary
(`'conversation_updates' => $updates['updated_conversations']`) — this
route reproduces that exact rename, so the wire contract is
`data.conversation_updates`, never `data.updated_conversations`.

## 2. `save_pending_order` and `update_chat_status` both use `sendError()`'s DEFAULT status (400), not the "no-case-catch" 500 shape

Unlike `poll` (§4), both `save_pending_order` and `update_chat_status` DO
have their own case-level `try/catch` in `api/inbox-v2.php`, and both
catches call `sendError('Failed to ... : ' . $e->getMessage())` with no
explicit status argument — `sendError()`'s signature is `function
sendError(string $message, int $statusCode = 400)`, so a DB failure inside
either of these two actions is HTTP 400, not 500. This is a genuine
difference in shape from `poll`'s own defensive 500 branch (§4) — do not
conflate the two when reading route.ts's status codes.

## 3. SIMPLIFICATION — `save-pending-order` drops PHP's runtime PRIMARY-KEY
introspection probe; the branch it guards is dead on every tenant
provisioned from the committed template

`case 'save_pending_order':`'s PHP source runs a MySQL key-metadata
statement against `user_states` (scoped to `Key_name = 'PRIMARY'`) before
every write, branching between an `INSERT ... ON DUPLICATE KEY UPDATE`
(when the probe reports `user_id` as the primary key) and a
`DELETE`-then-`INSERT` (when it doesn't). `database/migration_2026-05-25_tenant_template.sql`
(line ~5212) gives `user_states` an unconditional `PRIMARY KEY (user_id)` —
there is no tenant DB built from the committed template on which the
`DELETE`-then-`INSERT` branch can ever be taken.

Same "runtime schema probe with only one live outcome on the committed
schema" shape already established by Phase 4 batch 4a's
`max-discount/_lib/drugPricingEngine.ts` (its own dropped
`cost_price`-column-existence probe) — see that module's doc for the
identical reasoning this batch extends. `save-pending-order/_lib/savePendingOrder.ts`
therefore always issues a single `INSERT ... ON DUPLICATE KEY UPDATE`
(Kysely's `.insertInto('user_states').values({...}).onDuplicateKeyUpdate({...})`),
never a `DELETE`, and never reproduces the runtime probe itself — see that
module's own doc comment for the full write-up (the probe's exact SQL text
is deliberately NOT quoted verbatim anywhere under this batch's three
route directories, only described in prose, so that
`grep -R "SHOW KEYS\|SHOW TABLES" apps/admin/src/app/api/inbox/actions/{poll,save-pending-order,update-chat-status}`
— this batch's own acceptance check — returns no matches at all, including
in doc comments).

## 4. `poll` has no case-level try/catch — the uniform "Database error: ..." 500 precedent applies

`case 'poll':` (api/inbox-v2.php lines ~172-193), like batch 4a/4b's
`low-stock-drugs`/`drug-inventory`/all-five-batch-4b-actions before it, has
**no case-level try/catch** in `api/inbox-v2.php`'s switch. A genuinely
unexpected error here would fall through to the file's generic outer
`catch (Throwable $e)` (line ~3553), producing `Internal server error:
<message, truncated to 200 chars>...` at HTTP 500 — a DIFFERENT shape than
`save_pending_order`/`update_chat_status`'s own case-level catches produce
(§2). Following the house precedent already established for this identical
no-case-catch situation, `poll/route.ts` uses the uniform `'Database error:
{message}'` shape at HTTP 500 instead, for consistency across the whole
`api/inbox/actions/*` family — a defensive addition, not a literal PHP
reproduction. In practice this branch is unreachable in normal operation:
`pollUpdates()` never swallows its own errors internally (unlike e.g.
`getLowStockDrugs()`), so a throw really would mean a genuine DB failure.

`update_chat_status` has its OWN, separate best-effort swallow worth
distinguishing from this section: the `chat_status_history` INSERT is
wrapped in its own inner `try/catch` that logs and ignores any failure
("History table might not exist yet, ignore") WITHOUT flipping the outer
response to an error — see `update-chat-status/_lib/updateChatStatus.ts`'s
own doc comment. This is unrelated to `poll`'s no-case-catch situation
above; it is a deliberate, PHP-literal inner swallow around one specific
statement, not a "no try/catch at all" situation.

## 5. `lineAccountId` resolution — `session.currentBotId ?? 1`, not PHP's 4-tier chain

All three routes resolve `lineAccountId` as `session.currentBotId ?? 1` —
the established `../get-admins/route.ts` precedent (itself following
`api/inbox-v2.php` line 71's first fallback link, `$_SESSION['current_bot_id']
?? 1`), NOT PHP's full 4-tier `$_SESSION['current_bot_id'] ??
$_SESSION['line_account_id'] ?? $_GET['line_account_id'] ??
$_POST['line_account_id'] ?? 1` chain, per this batch's brief. `changed_by`
on `update-chat-status` similarly resolves as `session.adminUserId`
directly (always a number on `TenantSession`) rather than PHP's
`$_SESSION['admin_user']['id'] ?? null` re-read — see
`update-chat-status/route.ts`'s own doc comment for why both resolve to the
same `admin_users.id` in practice.

## 6. Path disjointness with the sibling customerCrm stream (same round, same worktree)

Both builders ran against the same worktree this round. The split is
file-disjoint by construction, so no merge conflict is possible if both
respect their own boundary:

- **chatAndOrders (this runbook)** owns
  `apps/admin/src/app/api/inbox/actions/{poll,save-pending-order,update-chat-status}/**`
  and this file.
- **customerCrm** owns
  `apps/admin/src/app/api/inbox/actions/{customer-crm,customer-note,customer-tag,update-customer-info,customer-loyalty}/**`
  and its own runbook — neither read, edited, nor referenced by this batch.

Neither stream touched any pre-existing route directory (`notes/`, `tags/`,
`assign-tag/`, `medical/`, `medical-history/`, `patient-profile/`, and every
other already-merged `actions/**` directory are all untouched by this
batch), `apps/admin/src/nav/manifest.ts`, or `infra/nginx/routes.json`.

## 7. Deferred scope

Out of scope for this batch, unchanged from prior batches' own deferred
lists (`phase4-batch1-inbox-reads-parity.md` §8,
`phase4-batch3-inbox-actions-parity.md` §3,
`phase4-batch4b-patient-clinical-parity.md` §6): `customer_crm`,
`add_customer_note`/`remove_customer_note`/`add_customer_tag`/
`remove_customer_tag` (owned by the sibling customerCrm stream this round,
per §6), `analyze_symptom`/`analyze_drug`/`analyze_prescription`,
`ghost_draft`/`learn_draft`/`draft_style`,
`classify_customer`/`customer_health`, `recommendations`,
`safe_alternatives`, `context_widgets`, `consultation_stage`,
`quick_actions`, `detect_urgency`, `analytics`/`record_analytics`,
`drug_card`, `validate_recommendation`, and `dispense` (owned by a
different stream, already merged in a prior round per CLAUDE.md's Dispense
System section).

## 8. How to run each route's own test suite locally

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/poll
npx jest src/app/api/inbox/actions/save-pending-order
npx jest src/app/api/inbox/actions/update-chat-status
```

Or all three at once:

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/poll src/app/api/inbox/actions/save-pending-order src/app/api/inbox/actions/update-chat-status
```

`cd apps/admin && npm run lint` (`tsc --noEmit -p tsconfig.json`) reports
zero errors for every file under this batch's three route directories —
this also structurally validates every Kysely/raw-`sql` table and column
reference used (`messages`, `users`, `user_states`, `chat_status_history`)
compiles against `packages/db/src/generated/tenant-db.d.ts`. (Building
`@reya/config`/`@reya/db`/`@reya/auth`/`@reya/tenant`/`@reya/contracts` via
`pnpm --filter <pkg> run build` first is required for `tsc` to resolve
their `dist/*.d.ts` — jest resolves the same workspace packages straight
from source instead, via `apps/admin/jest.config.js`'s `moduleNameMapper`,
so `npx jest` never needs this build step. Pre-existing `tsc` errors under
`apps/admin/src/app/api/{documents,inbox/actions/dispense,inbox/actions/send-message}/**`
and the sibling customerCrm stream's in-flight
`update-customer-info/_lib/updateCustomerInfo.ts` — all outside this
batch's allowed paths — are unrelated to this batch and are not introduced
by it.)

## 9. Acceptance criteria (mig-verify executes these)

- [ ] `cd apps/admin && npx jest src/app/api/inbox/actions/poll
      src/app/api/inbox/actions/save-pending-order
      src/app/api/inbox/actions/update-chat-status` — all pass.
- [ ] `cd apps/admin && npm run lint` — zero errors under this batch's three
      route directories.
- [ ] `grep -R "SHOW KEYS\|SHOW TABLES"
      apps/admin/src/app/api/inbox/actions/{poll,save-pending-order,update-chat-status}`
      returns no matches (confirms the dead runtime-probe branch was
      dropped, not reproduced — see §3).
- [ ] `poll/route.test.ts`'s dedupe assertion: feeding 2 rows sharing one
      `user_id` into the fake DB's messages-query response yields exactly 1
      `conversation_updates` entry, and the unread-count query fires
      exactly once (not twice).
- [ ] `save-pending-order/route.test.ts` proves the upsert is a single
      `INSERT ... ON DUPLICATE KEY UPDATE` statement (its own SQL-text
      assertion checks for `on duplicate key update`, case-insensitively);
      no `DELETE` statement anywhere in the recorded queries for the
      happy-path test.
- [ ] `update-chat-status/route.test.ts` proves an empty-string `status` is
      accepted (not rejected as invalid) and stored as `NULL`, and proves a
      `chat_status_history` INSERT failure does not flip the HTTP response
      to an error.
- [ ] This document
      (`docs/runbooks/phase4-batch5-chat-orders-parity.md`) exists and is a
      genuinely new file — `git diff` shows no other file under
      `docs/runbooks/` modified.
- [ ] `git diff --stat origin/main` shows changes confined to
      `apps/admin/src/app/api/inbox/actions/{poll,save-pending-order,update-chat-status}/**`
      and this runbook — no edits to `apps/admin/src/nav/manifest.ts`,
      `infra/nginx/routes.json`, any pre-existing `actions/**` directory, or
      the sibling customerCrm stream's directories/runbook.
