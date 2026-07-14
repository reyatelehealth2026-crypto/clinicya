# Phase 4 batch 2 — `/inbox` write actions (send-message, tags, notes, medical) + realtime wiring

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time"), §1.5 (strangler edge), §7.3
(canary ramp). Owner: mig-infra (this runbook + `infra/nginx/routes.json`'s
`/inbox` entry note + `infra/compose/docker-compose.worker.yml`'s host port
publish) / messagingActions (`apps/admin/src/app/api/inbox/actions/send-message/**`)
/ tagNoteMedicalActions (`apps/admin/src/app/api/inbox/actions/{tags,notes,medical}/**`)
/ realtimeUiWiring (`(tenant)/inbox/_lib/useRealtimeSocket.ts`,
`_components/RealtimeSocketProvider.tsx`, the single mount-point edit to
`layout.tsx`) / mig-orchestrator (canary-ramp authorization — not this
batch's decision). Cross-reference: `docs/runbooks/phase4-batch1-inbox-reads-parity.md`
(the sibling runbook this batch extends — same directory, same "Scope note
first" convention, covers the 2 read actions + the conversationList/
messageThread pages this batch's write actions sit alongside).

## 0. Scope note (read first)

This batch's evidence is **unit/component-test only**, matching Phase 4's
low-risk mig-verify gate for an actions-batch of this size. Concretely:

- **No live-traffic shadow test.** Nothing in `infra/nginx/routes.json` moved
  off `php_backend` — `/inbox`'s `upstream` is unchanged by this batch (see
  §6). There is no canary ramp and therefore no rollback drill to document:
  nothing landed on any upstream other than its pre-existing `php_backend`
  default, so there is nothing to roll back.
- **No full docker e2e parity harness run this round.** Unlike batch 1
  (which extended `infra/e2e/parity.mjs` with five new page/JSON-walk
  entries against a real MariaDB + Redis + `php:8.2-apache` stack), this
  batch deliberately does **not** extend `infra/e2e/parity.mjs` or
  `infra/e2e/api-parity.mjs` — see §9 for why and what's left as a TODO.
- **Evidence is each builder's own Jest suite** (route handler unit tests
  under `apps/admin/src/app/api/inbox/actions/**/*.test.ts`, plus whatever
  component tests `realtimeUiWiring` ships for the socket hook/provider) —
  see §7 for exact invocations.
- **Not exhaustive.** Only the 5 actions listed in §1 are covered. See §2 for
  the full deferred-scope list.

## 1. What landed — 5 ported actions

All five are literal ports of `inbox-v2.php`'s same-page AJAX `switch
($action)` block (the `$_SERVER['REQUEST_METHOD'] === 'POST' &&
isset($_SERVER['HTTP_X_REQUESTED_WITH'])` gate, inbox-v2.php line ~230),
decomposed into their own Next.js Route Handlers under
`apps/admin/src/app/api/inbox/actions/**`. Every handler requires a valid
tenant session (any of the six `TenantRole` values) via
`resolveInboxApiContext()` — inbox-v2.php's page shell (`includes/header.php`)
enforces this for the whole same-page AJAX switch at once; each new
standalone Route Handler must perform the same check itself since it is
reachable directly, not wrapped by `(tenant)/layout.tsx`.

| # | PHP source (`inbox-v2.php`) | Route | Verb | Request body | Response (`success:true`) |
|---|---|---|---|---|---|
| 1 | `case 'send_message':` — lines 236-336 | `/api/inbox/actions/send-message` | `POST` | `{ user_id: number, message: string, reply_to_id?: number \| null }` (JSON) | `{ success: true, message_id, content, time, sent_by, method, method_label }` |
| 2 | `case 'update_tags':` — lines 397-412 | `/api/inbox/actions/tags` | `POST` | `{ user_id: number, tag_id: number, operation?: 'add' \| string }` (JSON; anything other than the literal string `'add'` falls into delete, matching PHP's `=== 'add'` check) | `{ success: true, tags: UserTagRow[] }` (full `user_tags` rows, all columns) |
| 3 | `case 'save_note':` — lines 414-429 | `/api/inbox/actions/notes` | `POST` | `{ user_id: number, note: string }` (JSON) | `{ success: true, id: number }` |
| 4 | `case 'delete_note':` — lines 431-442 | `/api/inbox/actions/notes/[noteId]` | `DELETE` | none (id in the URL path segment — see §1.1) | `{ success: true }` |
| 5 | `case 'save_medical':` — lines 444-464 | `/api/inbox/actions/medical` | `POST` | `{ user_id: number, medical_conditions?: string, drug_allergies?: string, current_medications?: string }` (JSON; every field defaults to `''` and always overwrites — no "leave unchanged" semantics, matching PHP's `trim($_POST[...] ?? '')`) | `{ success: true }` |

All five return `{ success: false, error: string }` with an HTTP 4xx status
on failure — matching inbox-v2.php's outer `catch (Exception $e) {
http_response_code(400); echo json_encode(['success' => false, 'error' =>
$e->getMessage()]); }` (lines ~980-985), except `send_message`'s "User not
found" case, which deliberately returns `404` instead of PHP's flat `400`
(see `_lib/sendMessage.ts`'s doc comment) — a documented, intentional
improvement, not a byte-for-byte port at that one point.

### 1.1 Routing-shape deviation: `delete_note`

PHP reads `note_id` from the same-page AJAX POST body
(`$_POST['note_id']`). This port instead takes `noteId` from a dynamic route
segment (`/api/inbox/actions/notes/[noteId]`, `DELETE`) — a more idiomatic
Next.js shape per this batch's brief, which favors decomposing routing over
transliterating it while preserving DB-level behavior byte-for-byte
(including PHP's permissive-by-design "always returns `{success:true}`
even if zero rows matched" behavior — no row-count check was added).

### 1.2 `activity_logs` behavior — literal, including its asymmetries

- `update_tags` writes **no** `activity_logs` row at all (verified directly
  against inbox-v2.php's source — there is no `ActivityLogger` call in that
  `case`). Do not add one.
- `save_note` / `save_medical` write `user_id` into the log row;
  `delete_note` deliberately does **not** (PHP's own `logData()` call for
  `delete_note` omits the `user_id` key from its options array) — this
  asymmetry is preserved literally, not a bug.
- `line_account_id` is omitted from the `user_tag_assignments` /
  `user_notes` INSERTs (PHP's prepared statements never bind it either;
  the column defaults to `1` / is nullable in the tenant template schema).

## 2. Deferred scope

**Explicitly out of scope for this batch** — the following `inbox-v2.php`
actions are **not** ported and have no Next Route Handler yet:

| Action | `inbox-v2.php` line | Why deferred |
|---|---|---|
| `dispense` | line 469 | Reserved path — a separate future stream (ระบบจ่ายยา cross-cutting flow, shared with `messages.php`; see CLAUDE.md's "Dispense System" section). |
| `ai_reply` | line 338 | AI-copilot surface, held for a later actions batch per the plan's "~5 actions at a time" phasing. |
| `send_image` | line 737 | File-upload write flow, held for a later batch. |
| `upload_for_analysis` | line 836 | File-upload write flow, held for a later batch. |
| `send_pdf` | line 876 | File-upload write flow, held for a later batch. |

**Also explicitly out of scope**: the `facebook` and `tiktok` branches of
`send_message` itself (inbox-v2.php lines 250-274). Only the `line` platform
branch is ported this round — there is no `@reya/facebook` package yet.
A `platform === 'facebook'` or `platform === 'tiktok'` user's `send_message`
call collapses to a single explicit `UNSUPPORTED_PLATFORM_MESSAGE` 400
response (`ยังไม่รองรับการส่งข้อความ Facebook/TikTok จากหน้านี้
(ยังไม่ได้ย้ายมา Next.js)`) instead of PHP's two distinct per-platform
branches (a real Facebook send vs. a bare TikTok "not supported" throw) —
see `apps/admin/src/app/api/inbox/actions/send-message/_lib/sendMessage.ts`'s
module doc. This matches the pattern of every other "NOT ported yet" callout
already established in this codebase (e.g. `(tenant)/inbox/layout.tsx`'s own
"NOT ported" note for the give-points button, `LoadOlderMessagesButton.tsx`'s
scope note) — a documented, intentional limitation, not an oversight,
flagged here for whichever future batch stands up `@reya/facebook`.

Everything else batch 1's own runbook already listed as deferred (§8 of
`phase4-batch1-inbox-reads-parity.md`) — `get_chat_content`, ETag/304
caching, `segment=new_followers`'s view switch, the page-load mark-as-read
side effect, `allTags`, HealthEngine profile/classification, PDPA
health-data-consent lookup, the give-points button, sound-toggle/live
controls, `sla-warning`, and the remaining ~19 AI-copilot/Ghost-Draft/HUD
chat-workflow-bar actions — remains deferred; this batch does not touch any
of it.

## 3. FROZEN realtime wire contract — do not rename without flagging mig-orc

Two layers make up the realtime contract. Both are now load-bearing for
more than one consumer and must not be silently renamed.

### 3.1 Socket.io transport contract (already fixed, cited not restated)

Already fixed by `apps/worker/src/realtime/socketServer.ts`'s own header
comment — that file is the canonical source, this section only cites it:

> STABLE WIRE CONTRACT: the infra brief's Docker-based smoke test is built
> in parallel against this exact `join_account` / `{ lineAccountId }` /
> `account_<id>` shape — do not rename any of it without flagging mig-orc.

Concretely: a client emits `join_account` with payload `{lineAccountId:
number}`; the server joins that socket to room `account_<lineAccountId>`
(the same room-naming convention `websocket-server.js`'s authenticated
connection handler and `apps/worker/src/realtime/inboxRelay.ts` both already
use). `inboxRelay.ts` (the Redis `inbox_updates` → Socket.io relay, ported
from `websocket-server.js` lines ~350-384) emits exactly two events onto
that room for every relayed message: `new_message` (the raw message
payload, unmodified) and `conversation_update` (`{ user_id,
last_message_at, last_message_preview, unread_count, timestamp }`).

### 3.2 NEW: client-side DOM-selector contract (this batch)

This batch's realtime UI wiring reads/writes the conversation-list DOM
directly, which makes `ConversationListItem.tsx`'s existing selector
contract doubly load-bearing — previously only `FilterBar.tsx`'s
client-side filtering depended on it (see that component's own "`data-*`
attributes are NOT decorative" doc comment); now the realtime layer's
live-update logic depends on the exact same selectors to find and patch the
right row in place. Do not rename any of the following without updating
`ConversationListItem.tsx` and `FilterBar.tsx` in lockstep:

- `#userList` — the conversation-list container.
- `.user-item` — one conversation row (the `<a>` element).
- `[data-user-id]` — the row's conversation/user id, used to target a
  specific row for a live patch.
- `.last-msg` — the row's message-preview text node.
- `.last-time` — the row's relative-time text node.
- `.unread-badge` — the row's unread-count badge.

### 3.3 NEW: env var contract (this batch)

`NEXT_PUBLIC_REALTIME_URL` — the browser-side Socket.io client's connection
target. In dev, this points at `apps/worker`'s published realtime port (see
§5). Set in `apps/admin`'s own `.env.local` (not tracked in this repo, not
`infra/compose/.env.example` — `apps/admin` is not part of that compose
stack):

```
NEXT_PUBLIC_REALTIME_URL=http://localhost:8100
```

### 3.4 `router.refresh()`-based thread-append mechanism — documented limitation

The open-thread pane (`(tenant)/inbox/[userId]/**`) appends live-arriving
messages by calling Next.js's `router.refresh()` (re-running the Server
Component tree for the current route) rather than an in-place client-side
DOM patch. This has a known, documented limitation:
`LoadOlderMessagesButton.tsx`'s "load older messages" pagination state
(`olderMessages`, `cursor`, `hasMore` — all local `useState`) lives in a
Client Component that gets freshly remounted on every `router.refresh()` of
its Server Component parent. A `router.refresh()` triggered by an incoming
live message therefore **resets any already-loaded older-message pages**
back to empty — a user who scrolled up and loaded two pages of history sees
that history collapse back to just the initial SSR'd 300 the next time a
new message arrives. This is a known limitation of this batch's realtime
wiring, not a regression to chase down separately; a future batch converting
the live-append path to an in-place DOM/state patch (rather than a full
`router.refresh()`) would resolve it as a side effect.

## 4. Compose/env plumbing (mig-infra, this batch)

`infra/compose/docker-compose.worker.yml`'s `worker` service now publishes
its realtime port to the host:

```yaml
ports:
  - "${WORKER_REALTIME_HOST_PORT:-8100}:8100"
```

`WORKER_HEALTH_PORT` (8099) is deliberately **not** published — out of scope
for this batch, only the realtime port is needed. `infra/compose/.env.example`
documents `WORKER_REALTIME_HOST_PORT` (defaults to 8100, matching
`apps/worker/src/env.ts`'s `DEFAULT_WORKER_REALTIME_PORT`) and cross-references
`NEXT_PUBLIC_REALTIME_URL` (§3.3) for `apps/admin`'s own `.env.local`. This
lets a developer run `apps/admin` on the host via `next dev` (no container
exists for it yet — confirmed, no `admin`/`next_admin` service appears in
any compose file today) while still reaching a docker-composed
`apps/worker`'s realtime server through the published port mapping.

Verified with a config-only dry run (no containers brought up):

```bash
MARIADB_ROOT_PASSWORD=x MARIADB_PASSWORD=x docker compose \
  -f docker-compose.dev.yml \
  -f infra/compose/docker-compose.strangler.yml \
  -f infra/compose/docker-compose.worker.yml \
  config
```

exits `0`; the rendered `worker` service's `ports:` block resolves to
`target: 8100, published: "8100"` with the default unset, and to the
overridden value when `WORKER_REALTIME_HOST_PORT` is set in the
environment/`.env` file — confirming the interpolation syntax is correct.

## 5. `infra/nginx/routes.json`'s `/inbox` entry — still no flip

This batch appended one sentence to the existing `/inbox` entry's `note`
field only — `upstream` stays `"php_backend"`, `tenants` stays `"all"`,
matching batch 1's precedent and this batch's explicit brief instruction.
No canary ramp, no traffic flip, no rollback drill: nothing in this batch
landed on any upstream other than its pre-existing `php_backend` default, so
there is nothing to roll back. The file still contains exactly one `/`
catch-all entry (the generator's hard requirement) and remains valid JSON —
verified via:

```bash
node -e "JSON.parse(require('fs').readFileSync('infra/nginx/routes.json','utf8'))"
```

mig-orchestrator continues to own the decision of when `/inbox` is
flip-ready (see the `/users` entry's note for the general flip mechanic).

## 6. How to run each builder's own test suite locally

Each of the three sibling streams' Jest suites can be run independently by
path, from `apps/admin`:

```bash
cd apps/admin

# messagingActions
npx jest src/app/api/inbox/actions/send-message

# tagNoteMedicalActions
npx jest src/app/api/inbox/actions/tags
npx jest src/app/api/inbox/actions/notes
npx jest src/app/api/inbox/actions/medical

# realtimeUiWiring (socket hook/provider component tests, if/when shipped —
# see (tenant)/inbox/_lib/useRealtimeSocket.ts's own test file for the exact
# path once that stream lands)
npx jest src/app/\(tenant\)/inbox/_lib
```

Or the whole `apps/admin` suite at once: `cd apps/admin && npm test`.

## 7. What this batch explicitly did NOT do

- **`infra/e2e/parity.mjs` / `infra/e2e/api-parity.mjs` were NOT extended
  this round** — unlike batch 1, which added five new page/JSON-walk
  entries to `parity.mjs` against the real docker stack. This is a
  **deliberate scope-down given batch size** (5 write actions + a realtime
  wiring layer, landing across three parallel builder streams in one
  batch), not an oversight. It is left as an explicit **TODO for whichever
  future batch flips `/inbox`'s canary** — that batch should add
  write-action + realtime-event coverage to the e2e harness before any
  traffic-flip decision, not retroactively after.
- No live-traffic shadow test (see §0).
- No rollback drill — nothing to roll back (see §0, §5).

## 8. Acceptance criteria (mig-verify executes these)

- [ ] `node -e "JSON.parse(require('fs').readFileSync('infra/nginx/routes.json','utf8'))"`
      exits `0`.
- [ ] `git diff infra/nginx/routes.json` (against `origin/main`) touches only
      the `/inbox` entry's `note` field — no other entry, no `upstream`/
      `tenants` key, appears in the diff hunk.
- [ ] `docker compose -f docker-compose.dev.yml -f infra/compose/docker-compose.strangler.yml -f infra/compose/docker-compose.worker.yml config`
      exits `0` and the rendered `worker` service's `ports:` block resolves
      `WORKER_REALTIME_HOST_PORT` (default `8100`) to container port `8100`.
- [ ] `git diff infra/compose/docker-compose.worker.yml` shows only an added
      `ports:` block under `services.worker`.
- [ ] `git diff infra/compose/.env.example` shows only added comment lines.
- [ ] This document names all 5 deferred PHP actions (`dispense`, `ai_reply`,
      `send_image`, `upload_for_analysis`, `send_pdf`) plus the
      facebook/tiktok `send_message` branches (§2) and states the FROZEN
      realtime wire contract verbatim (§3): `join_account`, `account_`,
      `new_message`, `conversation_update`, `NEXT_PUBLIC_REALTIME_URL`,
      `#userList`, `.user-item`, `[data-user-id]`, `.last-msg`,
      `.last-time`, `.unread-badge`.
