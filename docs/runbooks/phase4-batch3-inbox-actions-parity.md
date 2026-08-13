# Phase 4 batch 3 — `/inbox` assignment + read-status + tag actions

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time"), §1.5 (strangler edge), §4
item 1 / "§4.1" (schema-governance drift audit — see §2 below), §7.3 (canary
ramp). Owner: mig-infra (this runbook + `infra/nginx/routes.json`'s `/inbox`
entry note — **documentation-and-routing-note-only** for this batch, no
compose/Dockerfile/nginx-`map`-generator changes, no new service) /
assignmentFeature (`apps/admin/src/app/api/inbox/actions/{assign-conversation,unassign-conversation,get-assignment,get-admins}/**`)
/ readStatusAndTags (`apps/admin/src/app/api/inbox/actions/{mark-all-read,mark-as-read-on-line,assign-tag}/**`)
/ mig-orchestrator (canary-ramp authorization — not this batch's decision) /
mig-kernel (owns the plan's §4.1 schema-governance drift-audit workstream
this batch's 3 findings are flagged into — not actioned by this batch).
Cross-reference: `docs/runbooks/phase4-batch1-inbox-reads-parity.md` (the 2
read actions + conversationList/messageThread pages this batch's actions
sit alongside) and `docs/runbooks/phase4-batch2-inbox-actions-parity.md`
(the sibling runbook this batch extends — same directory, same "Scope note
first" convention, covers the first 5 write actions:
send-message/tags/notes/medical + the realtime wiring layer that batch
shipped).

## 0. Scope note (read first)

This batch is **documentation-and-routing-note-only** for mig-infra: no
compose changes, no Dockerfile changes, no nginx `map`-generator changes, no
new service, no traffic flip. Unlike batch 2's mig-infra deliverable (the
`infra/compose/docker-compose.worker.yml` host port publish for the
realtime worker), this batch adds pure Next.js Route Handlers with **no new
infra footprint at all** — there is nothing for mig-infra to wire up.
Concretely:

- **No live-traffic shadow test.** Nothing in `infra/nginx/routes.json`
  moved off `php_backend` — `/inbox`'s `upstream` is unchanged by this
  batch (see §4). There is no canary ramp and therefore no rollback drill
  to document: nothing landed on any upstream other than its pre-existing
  `php_backend` default, so there is nothing to roll back.
- **Evidence is unit/route-test only**, matching batch 2's own precedent
  (§0 of `phase4-batch2-inbox-actions-parity.md`) for an actions-batch of
  this size — each builder's own Jest suite under
  `apps/admin/src/app/api/inbox/actions/**/*.test.ts` (see §5). Same as
  batch 2, `infra/e2e/parity.mjs` / `infra/e2e/api-parity.mjs` are **not**
  extended this round — that remains the explicit TODO batch 2's own
  runbook left for whichever future batch actually flips `/inbox`'s
  canary.
- **Not exhaustive.** Only the 7 actions listed in §1 are covered. See §3
  for the full deferred-scope list.

## 1. What landed — 7 ported actions

All seven are literal ports of `api/inbox-v2.php`'s action switch (the
separate cursor/AI-copilot API file — **not** `inbox-v2.php`'s own
same-page AJAX switch that batch 2's send-message/tags/notes/medical
actions came from; see the note under the table), decomposed into their
own Next.js Route Handlers under `apps/admin/src/app/api/inbox/actions/**`.
Every handler requires a valid tenant session (any of the six `TenantRole`
values) via `resolveInboxApiContext()` — the same per-route auth gate every
batch-2 action already established, since each of these is reachable
directly and not wrapped by `(tenant)/layout.tsx`.

| # | PHP source (`api/inbox-v2.php`) | Route | Verb | Owner stream | Request | Response (`success:true`) |
|---|---|---|---|---|---|---|
| 1 | `case 'get_admins':` — lines ~2434-2456 | `apps/admin/src/app/api/inbox/actions/get-admins/route.ts` | `GET` | assignmentFeature | none | `{ success: true, data: AdminRow[] }` (`id, username, display_name, role`, scoped to `(line_account_id = ? OR line_account_id IS NULL) AND is_active = 1`, `ORDER BY display_name ASC`) |
| 2 | `case 'assign_conversation':` — lines ~2461-2519, backed by `classes/InboxService.php::assignConversation()` (lines 1015-1082) | `apps/admin/src/app/api/inbox/actions/assign-conversation/route.ts` | `POST` | assignmentFeature | `{ user_id, assign_to: number \| number[] \| string }` (JSON) | `{ success: true, message, assigned_count }` — dual-write to `conversation_multi_assignees` (one row per admin id) **and** `conversation_assignments` (legacy, `adminIds[0]` only) every call; see §2 finding 2 |
| 3 | `case 'unassign_conversation':` — lines ~2529-2559, backed by `InboxService::removeAssignee()`/`unassignConversation()` (lines 1084-1113) | `apps/admin/src/app/api/inbox/actions/unassign-conversation/route.ts` | `POST` | assignmentFeature | `{ user_id, admin_id? }` (JSON; `admin_id > 0` selects the single-admin `removeAssignee()` branch, Thai message `ยกเลิกการมอบหมายสำเร็จ`; omitted/0 selects the remove-all `unassignConversation()` branch, `ยกเลิกการมอบหมายทั้งหมดสำเร็จ`) | `{ success: true, message }` |
| 4 | `case 'get_assignment':` — lines ~2565-2588, backed by `InboxService::getAssignment()` (lines 1157-1189) | `apps/admin/src/app/api/inbox/actions/get-assignment/route.ts` | `GET` | assignmentFeature | `?user_id=N` (query param) | `{ success: true, data: { user_id, assignees: [], is_assigned: false } }` when empty, else `{ ..., is_assigned: true, status, assigned_at }` — `assignees` rows carry `admin_id, assigned_by, assigned_at, status, resolved_at, username, display_name` via `LEFT JOIN admin_users` |
| 5 | `case 'mark_all_read':` — lines 2417-2435 | `apps/admin/src/app/api/inbox/actions/mark-all-read/route.ts` | `POST` | readStatusAndTags | none | `{ success: true, message: "Marked {affected} messages as read" }` — `UPDATE messages SET is_read = 1 WHERE line_account_id = ? AND direction = 'incoming' AND is_read = 0`, `affected` = PDO `rowCount()` |
| 6 | `case 'mark_as_read_on_line':` — lines 2601-2694 | `apps/admin/src/app/api/inbox/actions/mark-as-read-on-line/route.ts` | `POST` | readStatusAndTags | `{ user_id }` (JSON) | `{ success: true, message, marked_count, line_api_success, errors: [] }` — looks up the tenant's `line_accounts.channel_access_token`, calls `LineAPI::markAsRead()` on the latest of up to 10 unread `mark_as_read_token`s (LINE's API marks all older ones read too), always flips local `is_read=1` even if the LINE call itself fails |
| 7 | `case 'assign_tag':` — lines 2135-2160 | `apps/admin/src/app/api/inbox/actions/assign-tag/route.ts` | `POST` | readStatusAndTags | `{ user_id, tag_id }` (JSON) | `{ success: true, message: 'Tag assigned successfully' }` — `INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())`; see §2 finding 3 |

All seven return `{ success: false, error: string }` on failure — matching
`api/inbox-v2.php`'s per-case `try { ... } catch (Exception $e) {
logInboxApiException($e, 'catch'); sendError('...: ' . $e->getMessage()); }`
pattern (a clean HTTP 400 by `sendError`'s default), except
`assign-conversation`'s intentional, flagged deviation from a genuine PHP
bug — see `_lib/assignConversation.ts`'s own module doc: PHP's
`InboxService::assignConversation()` always returns a truthy non-empty
array even on its own `USER_NOT_FOUND`/`ADMIN_NOT_FOUND`/`ASSIGN_FAILED`
failure paths, so the calling `case`'s `if ($success)` is always true in
practice and PHP silently responds HTTP 200 on a failed assignment. This
port reads the domain result's own `.success` field and maps failures to
real non-2xx statuses (404/404/500) instead of reproducing that bug.

### 1.1 Two different PHP switch blocks — do not confuse `assign_tag` with batch 2's `update_tags`

`api/inbox-v2.php` (this batch's source file, the cursor/AI-copilot API
endpoint) and `inbox-v2.php` (the page file with its own same-page AJAX
switch, batch 2's source for `send_message`/`update_tags`/`save_note`/
`delete_note`/`save_medical`) are two separate `switch ($action)` blocks in
two separate files. `assign_tag` (this batch, `api/inbox-v2.php` lines
2135-2160) and `update_tags` (batch 2, `inbox-v2.php` lines 397-412, ported
to `apps/admin/src/app/api/inbox/actions/tags/route.ts`) are **different
actions that both write the same `user_tag_assignments` table** —
`assign_tag` via `INSERT IGNORE`, `update_tags`'s `add` branch also via
`INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by)
VALUES (?, ?, 'manual')` (see `tags/route.ts` lines ~16-19). Neither this
batch's builders nor batch 2's touch the other's route — `assign-tag` and
`tags` are and remain two distinct Route Handlers.

## 2. Schema-drift findings (3 confirmed) — flagged for §4.1, not actioned here

Per this batch's brief, `database/**` is off-limits to both builder
streams and to mig-infra. All three findings below are **documented only**;
fixing them (reconciliation migrations against the committed tenant
template) is the plan's §4 item 1 ("**drift audit ครั้งเดียวบังคับ**" —
the one-time forced drift audit) — **mig-kernel territory**, not this
batch's or any Phase 4 batch's job.

### 2.1 `admin_users` — absent from the committed tenant template, no Kysely interface

`admin_users` is a PLATFORM-level table per
`database/migration_2026-05-25_tenant_template.sql`'s own header ("Platform-
level tables (admin_users, dev_logs, etc.) live in `reya_platform` and are
defined by a separate migration") — it does not exist in a tenant DB built
from the committed template, and there is no `AdminUsers` interface in
`packages/db/src/generated/tenant-db.d.ts` or `master-db.d.ts`. Every query
against it in this batch is issued via a raw `sql` tagged template, not
`.selectFrom('admin_users')`. This is the third/fourth confirmed sighting
of a gap first documented in Phase 2 batch 1
((tenant)/settings/_lib/shop-tax-queries.ts's `resolveLineAccountId()` doc
and consent-queries.ts's module doc) — not a new root cause. Inline
comments carrying this finding, this batch:

- `apps/admin/src/app/api/inbox/actions/get-admins/_lib/getAdmins.ts` —
  module doc, "CONFIRMED FINDING — `admin_users` has no Kysely interface,
  and does not exist in a tenant DB built from the committed template".
- `apps/admin/src/app/api/inbox/actions/assign-conversation/_lib/assignConversation.ts`
  — module doc, same finding, cites `getAdmins.ts` as the prior sighting.
- `apps/admin/src/app/api/inbox/actions/get-assignment/_lib/getAssignment.ts`
  — module doc, same finding (its `LEFT JOIN admin_users au ON
  cma.admin_id = au.id` is likewise a raw `sql` template, no
  `.leftJoin('admin_users', ...)` type-safe path).
- `apps/admin/src/app/api/inbox/actions/get-admins/route.ts` and
  `get-assignment/route.ts` — each points back to its own `_lib` module
  doc rather than repeating the finding.

Behaviorally: unlike the shop-tax/consent precedents (which locally
swallow the missing-table throw), none of `get_admins`/`assign_conversation`/
`get_assignment`'s PHP sources catch this exception themselves — a
missing-table error propagates to the outer `case` try/catch and becomes a
clean `sendError(...)` (HTTP 400), reproduced literally by each Route
Handler leaving the throw uncaught for its own try/catch to convert.

### 2.2 `conversation_multi_assignees` / `conversation_assignments` — missing their production UNIQUE KEYs in the committed template

`database/install_complete_latest.sql` (production's actual schema) has
`UNIQUE KEY uk_user_admin (user_id, admin_id)` on
`conversation_multi_assignees` and `UNIQUE KEY uk_user (user_id)` (plus
`unique_user_account (user_id, line_account_id)`) on
`conversation_assignments`. `database/migration_2026-05-25_tenant_template.sql`
— the schema this repo's tenant DBs are actually provisioned from — has
only non-unique `KEY idx_cma_user_status (user_id, status)` /
`KEY idx_ca_user (user_id)` on those same two tables. Effect: on a
freshly-provisioned committed-schema tenant DB,
`assign-conversation`'s `ON DUPLICATE KEY UPDATE` dual-write (§1 row 2)
cannot dedupe — re-assigning the same `(user_id, admin_id)` pair inserts a
second row instead of updating the first. Inline comment carrying this
finding:

- `apps/admin/src/app/api/inbox/actions/assign-conversation/_lib/assignConversation.ts`
  — module doc, "CONFIRMED FINDING — the committed tenant template's
  UNIQUE keys don't match production, so `ON DUPLICATE KEY UPDATE` cannot
  dedupe there", with the DDL comparison spelled out in full.
- `apps/admin/src/app/api/inbox/actions/unassign-conversation/_lib/unassignConversation.ts`
  — cross-references (does not re-derive) the same doc when explaining why
  `conversation_assignments`'s real production `uk_user (user_id)` key
  matters for its own "remove all" branch.

### 2.3 `user_tag_assignments` — missing its production UNIQUE KEY in the committed template

`database/install_complete_latest.sql` has `UNIQUE KEY unique_user_tag
(user_id, tag_id)` on `user_tag_assignments`.
`database/migration_2026-05-25_tenant_template.sql` has only non-unique
`KEY idx_uta_user (user_id, tag_id)` on the same table. Effect: both
`assign_tag` (this batch, §1 row 7) and batch 2's `update_tags`/`add`
branch (`tags/route.ts`) issue `INSERT IGNORE INTO user_tag_assignments
(...)`, whose silent-dedupe-on-conflict behavior depends on that unique
key existing; on a freshly-provisioned committed-schema tenant DB without
it, re-assigning the same `(user_id, tag_id)` pair inserts a duplicate row
instead of being silently ignored. This is the first batch to flag it —
batch 2's own `tags/route.ts` module doc does not call out the missing
key. Inline comment carrying this finding, this batch:

- `apps/admin/src/app/api/inbox/actions/assign-tag/_lib/assignTag.ts` —
  module doc, "SCHEMA-DRIFT NOTE" section, spelling out the identical
  `KEY idx_uta_user(user_id, tag_id)` (template) vs. `UNIQUE KEY
  unique_user_tag(user_id, tag_id)` (production) gap independently
  verified above, and explicitly cross-referencing `tags/_lib`'s sibling
  `update_tags`/`add` branch as already, silently exposed to the same gap
  on the same table.

## 3. Deferred scope

**Explicitly out of scope for this batch** — the following remain
unported, no Next Route Handler exists for any of them yet:

| Action | PHP source | Why deferred |
|---|---|---|
| `dispense` | `inbox-v2.php` line 469 | Reserved path — a separate future stream (ระบบจ่ายยา cross-cutting flow, shared with `messages.php`; see CLAUDE.md's "Dispense System" section). |
| `ai_reply` | `inbox-v2.php` line 338 | AI-copilot surface, held for a later actions batch per the plan's "~5 actions at a time" phasing. |
| `send_image` | `inbox-v2.php` line 737 | File-upload write flow, held for a later batch. |
| `upload_for_analysis` | `inbox-v2.php` line 836 | File-upload write flow, held for a later batch. |
| `send_pdf` | `inbox-v2.php` line 876 | File-upload write flow, held for a later batch. |

**Also still deferred, unchanged from batch 2's own list**: the `facebook`
and `tiktok` branches of `send_message` itself (`inbox-v2.php` lines
250-274) — only the `line` platform branch is ported (batch 2); a
`platform === 'facebook'`/`'tiktok'` user's `send_message` call still
collapses to the single explicit `UNSUPPORTED_PLATFORM_MESSAGE` 400
response documented in `phase4-batch2-inbox-actions-parity.md` §2. This
batch does not touch `send-message/**` at all.

**Also out of scope**: the **~19 AI-copilot actions** living in this same
`api/inbox-v2.php` action switch this batch ported 7 actions from —
`analyze_symptom` (line 200), `drug_info` (line 518),
`check_interactions` (line 881), plus `analyze_drug`, `analyze_prescription`,
`customer_health`, `classify_customer`, `draft_style`, `ghost_draft`,
`learn_draft`, `search_drugs`, `drug_pricing`, `max_discount`,
`suggest_alternatives`, `customer_loyalty`, `medical_history`,
`patient_profile`, `drug_inventory`, `recommendations`, and the rest of
that switch's AI/Ghost-Draft/HUD-widget actions — these are **Phase 7 /
mig-ai territory** per the migration plan, **not part of any Phase 4
batch**, batch 3 included. This is the same "~19 AI-copilot" figure batch
1's runbook §8 already established (`phase4-batch1-inbox-reads-parity.md`)
— not a new count, just confirmed still-accurate against this batch's own
read of `api/inbox-v2.php`.

Everything else batch 1's and batch 2's runbooks already listed as
deferred remains deferred; this batch does not touch any of it —
`get_chat_content`, ETag/304 caching, `segment=new_followers`'s view
switch, the page-load mark-as-read side effect (superseded for the
"mark all" case by this batch's `mark-all-read`, but the messageThread
page's own per-open-conversation side effect is still not wired), `allTags`,
HealthEngine profile/classification, PDPA health-data-consent lookup, the
give-points button, sound-toggle/live controls, `sla-warning`, and the
realtime UI wiring's own documented limitations (batch 2 §3.4).

## 4. `infra/nginx/routes.json`'s `/inbox` entry — still no flip

This batch appended one sentence to the existing `/inbox` entry's `note`
field only — `upstream` stays `"php_backend"`, `tenants` stays `"all"`,
matching batch 1's and batch 2's precedent and this batch's explicit brief
instruction. No canary ramp, no traffic flip, no rollback drill: nothing in
this batch landed on any upstream other than its pre-existing `php_backend`
default, so there is nothing to roll back. The file still contains exactly
one `/` catch-all entry (the generator's hard requirement) and remains
valid JSON — verified via:

```bash
node -e "JSON.parse(require('fs').readFileSync('infra/nginx/routes.json','utf8'))"
```

which exits `0`. `git diff origin/main -- infra/nginx/routes.json` shows a
single-line change confined to the `/inbox` entry's `note` string — no
other entry, and no `upstream`/`tenants` key, appears in the diff hunk.
mig-orchestrator continues to own the decision of when `/inbox` is
flip-ready (see the `/users` entry's note for the general flip mechanic).

## 5. How to run each builder's own test suite locally

Each stream's Jest suite can be run independently by path, from
`apps/admin`:

```bash
cd apps/admin

# assignmentFeature
npx jest src/app/api/inbox/actions/get-admins
npx jest src/app/api/inbox/actions/assign-conversation
npx jest src/app/api/inbox/actions/unassign-conversation
npx jest src/app/api/inbox/actions/get-assignment

# readStatusAndTags
npx jest src/app/api/inbox/actions/mark-all-read
npx jest src/app/api/inbox/actions/mark-as-read-on-line
npx jest src/app/api/inbox/actions/assign-tag
```

Or the whole `apps/admin` suite at once: `cd apps/admin && npm test`. This
runbook's own scope is documentation-and-routing-note-only (§0) — mig-infra
did not author or execute these suites; each stream's own route-handler
tests, run in its own build/verify cycle, are the acceptance evidence for
its 4 (assignmentFeature) or 3 (readStatusAndTags) actions.

## 6. What this batch explicitly did NOT do

- **No compose, Dockerfile, or nginx-generator changes** — the only
  infra-owned file touched is `infra/nginx/routes.json`'s `/inbox`
  `note` field (§4). Unlike batch 2, there is no new host-port publish,
  no new env var, no docker-compose config-dry-run to verify.
- **No `infra/e2e/parity.mjs` / `infra/e2e/api-parity.mjs` extension** —
  same deliberate scope-down batch 2 already documented (§0); still an
  open TODO for whichever future batch flips `/inbox`'s canary.
- No live-traffic shadow test (see §0).
- No rollback drill — nothing to roll back (see §0, §4).
- **No `database/**` edits** — both confirmed-unique-key findings (§2.2,
  §2.3) are documented only; fixing the committed tenant template is
  explicitly mig-kernel's §4.1 drift-audit workstream, not this batch's.

## 7. Acceptance criteria (mig-verify executes these)

- [ ] `node -e "JSON.parse(require('fs').readFileSync('infra/nginx/routes.json','utf8'))"`
      exits `0`.
- [ ] `git diff origin/main -- infra/nginx/routes.json` shows changes
      confined to the `/inbox` entry's `note` field — no other entry, and
      no `upstream`/`tenants` key, appears in the diff hunk.
- [ ] `grep -F '"upstream": "php_backend"' infra/nginx/routes.json` still
      matches the `/inbox` entry after the edit (upstream unchanged).
- [ ] This document names all 7 actions ported this batch
      (`assign_conversation`, `unassign_conversation`, `get_assignment`,
      `get_admins`, `mark_all_read`, `mark_as_read_on_line`, `assign_tag`)
      plus their `route.ts` paths (§1).
- [ ] This document names all 3 schema-drift findings (`admin_users`;
      `conversation_multi_assignees`/`conversation_assignments` unique
      keys; `user_tag_assignments` unique key) and cross-references the
      `_lib` files carrying their inline comments (§2).
- [ ] This document names the still-fully-deferred scope: `dispense`,
      `ai_reply`, `send_image`, `upload_for_analysis`, `send_pdf`; the
      ~19 AI-copilot actions (Phase 7 / mig-ai territory); and the
      facebook/tiktok `send_message` branches already deferred in batch
      2's runbook (§3).
- [ ] `git diff --stat origin/main` touches only `infra/nginx/routes.json`
      and `docs/runbooks/phase4-batch3-inbox-actions-parity.md` for this
      agent's own commits — no `apps/admin/**`, `packages/**`, or
      `database/**` changes.
