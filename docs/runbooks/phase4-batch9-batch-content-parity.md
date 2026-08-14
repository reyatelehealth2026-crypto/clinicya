# Phase 4 batch 9 — `/inbox` chat-content read + batch-send + batch-file-stage

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time"), §1.5 (strangler edge), §3
(Phase 4 acceptance criteria). Team doc:
`docs/agents/nextjs-migration-team.md` (reduced review flow — Phase 4 is on
the low-risk list, not the high-risk co-sign list `0, 3, 5, 6, 7`, so a clean
`mig-verify` PASS is sufficient for merge). Owner: batchAndContent
(`apps/admin/src/app/api/inbox/actions/{get-chat-content,send-batch-messages,upload-batch-file}/**`
exclusively this round). Cross-reference:
`docs/runbooks/phase4-batch7-ai-copilot-parity.md` (the prior actions-batch
runbook whose shape — scope note, alias table, confirmed-schema/
behavior-drift prose, deferred scope, acceptance-criteria tail — both this
runbook and the sibling one below follow) and
`docs/runbooks/phase4-batch9-media-send-parity.md` (the sibling mediaSend
stream's own runbook for this exact same round, covering
`send-image`/`upload-for-analysis`/`send-pdf` — fully disjoint scope, no
file overlap; that runbook's own §5 anticipated this file's filename).

## 0. Scope note (read first)

This batch ports the admin inbox's AJAX-switch chat-content reader and the
"send up to 5 messages / attachments at once" composer flow — three case
labels from `api/inbox-v2.php`'s (the separate cursor-pagination + AI-copilot
API file `api/` — NOT the root `inbox-v2.php` admin page mediaSend's own
batch ported from this same round) same-page AJAX switch:

| PHP case label (`api/inbox-v2.php`) | Lines | Route | Method |
|---|---|---|---|
| `get_chat_content` | 3057-3163 | `apps/admin/src/app/api/inbox/actions/get-chat-content/route.ts` | `GET` |
| `send_batch_messages` | 3169-3487 | `apps/admin/src/app/api/inbox/actions/send-batch-messages/route.ts` | `POST` |
| `upload_batch_file` | 3489-3543 | `apps/admin/src/app/api/inbox/actions/upload-batch-file/route.ts` | `POST` |

Each is a literal port of its PHP case body — `get_chat_content` backed by
raw SQL against `users`/`messages`/`user_tag_assignments`/`user_tags`/
`conversation_multi_assignees`; `send_batch_messages` backed by
`classes/LineAPI.php::pushMessage()` (now `@reya/line`'s `pushMessage()`)
ONLY, never `sendMessage()`'s reply-token-first dispatcher; `upload_batch_file`
touching neither `$db` nor LINE at all (pure filesystem staging for a later
`send_batch_messages` call).

Every handler requires a valid tenant session (any of the six `TenantRole`
values) via each folder's own `resolveInboxApiContext()` — the same
per-route auth gate every prior batch established, duplicated per folder
(not imported across folders) per this codebase's "every consumer resolves
its own session" convention, matching `poll/_lib/session.ts`'s own
precedent (`api/inbox-v2.php` itself has no hard auth check ahead of its
action switch at all — see that file's own doc comment for the citation).

## 1. `get_chat_content` — GET, offset-window chat read + read-receipt side effect

`?user_id=N` (or `?user=N`) returns a user summary, a windowed slice of
`messages`, `total_messages`, `tags`, `assignees` — and, as an unusual side
effect on a GET request, marks that user's unread incoming messages read.

- **Query params**: `$_GET['user_id'] ?? $_GET['user'] ?? 0` (isset()-based:
  `user_id` wins whenever that key is present at all, even empty; `user` is
  only consulted when `user_id` is entirely absent). `limit =
  min((int)($_GET['limit'] ?? 50), 100)` (default 50, hard-capped at 100, no
  lower bound). `offset = (int)($_GET['offset'] ?? 0)` (default 0).
- **400 'User ID is required'** for a missing/falsy user id — this check
  runs BEFORE PHP's `try` block, so it is a literal, immediate 400, never
  routed through the case-level catch.
- **404 'User not found'** (scoped by `id` AND `line_account_id`) — via
  `sendError('User not found', 404)`, which calls PHP's `exit()`
  immediately. This NEVER reaches the case-level `catch (Exception $e)`
  either — it is a literal PHP status code, not a Next-side reinterpretation
  the way `send-message`'s own 404 is (that one IS a deliberate
  reinterpretation of a PHP `throw` that would otherwise have been a flat
  400 — see `send-message/_lib/sendMessage.ts`'s own doc comment for that
  distinction; `get_chat_content`'s 404 needs no such justification because
  it is already, literally, PHP's own 404).
- **Messages come back oldest-first via DESC-fetch-then-reverse, NOT
  `ORDER BY id ASC`.** `ORDER BY id DESC LIMIT ? OFFSET ?` fetches the
  LATEST `limit` rows counting back from the newest message, then
  `array_reverse()`s them to chat display order. This is a materially
  different result from `ORDER BY id ASC LIMIT ? OFFSET ?`, which would
  page forward from the OLDEST message instead.
- **`has_more = (offset + count($messages)) < $totalMessages`** — uses the
  COUNT of messages ACTUALLY RETURNED in this page (which can be less than
  `limit` on the last page), not `limit` itself.
- **`assignees` is queried unconditionally — the SHOW-TABLES probe is
  dropped, not ported.** See §4 below for the full schema-drift decision.
- **Read-receipt side effect, preserved verbatim**: every successful call —
  regardless of whether the caller even looked at unread state — runs
  `UPDATE messages SET is_read = 1 WHERE user_id = ? AND line_account_id = ?
  AND direction = 'incoming' AND is_read = 0`. This happens AFTER the
  assignees read and BEFORE the response is built.
- **Error shape**: `case 'get_chat_content':` HAS its own case-level
  `catch (Exception $e) { sendError('Failed to get chat content: ' .
  $e->getMessage()); }` — reproduced literally at HTTP 400 for anything
  genuinely unexpected (e.g. the `admin_users` schema-drift throw described
  in §4). This is NOT `poll/route.ts`'s own `'Database error: ...'` 500
  shape — that shape only applies to case bodies with NO case-level catch
  of their own (`poll` has none); `get_chat_content` has one, so its own
  literal text/status is used instead.

## 2. `send_batch_messages` — POST, SAFETY-CRITICAL real LINE push

Sends up to 5 messages (text / image / file-as-Flex / payment-as-Flex) to a
user's LINE account in a single `pushMessage()` call, then persists one
`messages` row per successfully-built item and bumps
`users.last_message_at`.

### 2.1 Validation order (every branch is a literal, immediate `sendError()`
— none of them route through the case-level catch; only a genuinely
unexpected exception does)

1. `userId` truthy -> 400 `'User ID is required'`
2. `messages` parsed (`is_string($messages)` -> `json_decode(...) ?? []`,
   ported as `JSON.parse` with the same null-coalesce-to-`[]` fallback on
   parse failure) then non-empty -> 400 `'Messages array is required'`
3. `count($messages) > 5` -> 400 `'Maximum 5 messages allowed per batch'`
4. `line_user_id` resolved: use the request param verbatim if non-empty,
   else `SELECT line_user_id FROM users WHERE id = ? AND line_account_id =
   ?`; still empty -> 400 `'LINE User ID not found for user'`
5. `line_accounts.channel_access_token` found and non-empty -> else 400
   `'LINE account token not found'`
6. Build `lineMessages`/`dbRecords` from the raw array (§2.2) -> if
   `lineMessages` ends up empty -> 400 `'No valid messages to send'` (no
   per-item error is ever surfaced — a batch of 4 malformed items just
   silently produces this one aggregate error)
7. `pushMessage()` returns HTTP 200 -> else 400 `'Failed to send messages
   via LINE: {result.body.message ?? "Unknown error"}'` — a DIFFERENT
   literal format from the sibling mediaSend batch's own LINE-error string
   this same round (`'Failed to send image via LINE (HTTP {code},
   {method}): {json body}'`); the two are NOT unified, by design.
8. DB writes (§2.3) -> response `{success, message: 'Sent {n} messages
   successfully', count: n}`

### 2.2 Per-item build logic — the two-stage `if`/`elseif` and the magic
payment template

The PHP loop is TWO separate conditional stages per item, not one `switch`:
first `if ($type === 'text') { ... }` (which can MUTATE `$type` to
`'payment'` when `$content === '{{PAYMENT_TEMPLATE_V1}}'`), THEN a
completely separate `if/elseif` chain re-reading the — possibly just
mutated — `$type` against `'image'`/`'file'`/`'payment'`. This two-stage
shape is what lets a `type: 'text'` item carrying the magic string fall
through into the payment branch within the SAME loop iteration. Ported as
two sequential `if` statements in `_lib/sendBatchMessages.ts`'s
`buildLineMessagesAndDbRecords()`, not collapsed into a single switch.

| Item `type` | Guard | On pass | On fail |
|---|---|---|---|
| `text` (default when `type` absent) | trimmed `content` non-empty AND not the magic string | `{type:'text', text}` LINE message; `{type:'text', content}` DB record | dropped, no error |
| `text` w/ `content === '{{PAYMENT_TEMPLATE_V1}}'` | (switches to `payment`, see below) | — | — |
| `image` | BOTH `originalContentUrl` AND `previewImageUrl` non-empty | `{type:'image', originalContentUrl, previewImageUrl}`; DB record `content` = `originalContentUrl` | dropped, no error |
| `file` | `originalContentUrl` non-empty | file-attachment Flex bubble (§2.4); DB record `{type:'file', content: JSON.stringify({url, name})}` | dropped, no error |
| `payment` | **NONE** — always produces a message | payment Flex bubble (§2.4); DB record `{type:'text', content: <Thai payment summary text>}` — **message_type is `'text'`, NOT `'payment'`** | n/a |
| anything else (unrecognized `type`) | — | — | silently dropped, no error |

A batch whose every item fails its guard (or carries an unrecognized type)
produces the single aggregate 400 `'No valid messages to send'` from step 6
above — never a per-item error list.

### 2.3 The two hardcoded Flex JSON literals — byte-identical ports

`api/inbox-v2.php` hand-inlines both Flex bubbles directly in the case body
(NOT built via `classes/FlexTemplates.php` — that class backs the dispense
flow only, ported separately to `packages/line/src/flex.ts` by the
dispenseChain batch). Per the brief, this batch inlines the equivalent JS
object literals locally in `_lib/flexTemplates.ts` rather than routing
through that other package's file.

- **File-attachment bubble** (`buildFileFlexMessage()`): a hero image
  pointing at a HARDCODED CNY Healthcare branding icon URL
  (`https://cny.re-ya.com/uploads/chat_images/chat_1769145030_697302c699ee0.png`),
  a body showing the file name + `"{EXT} Document"` (uppercased extension,
  via `pathinfo($fileName, PATHINFO_EXTENSION)` — empty string, not an
  error, when the name has no extension), and a "Download File" button
  linking the raw `originalContentUrl`. Two PHP locals (`$fileSize =
  "Unknown Size"` and `$expiryDate = date('d M Y H:i', strtotime('+7
  days'))`) are assigned but grep-verified NEVER referenced again anywhere
  in the case body — dead code, not ported. A THIRD local, `$fileType`'s
  FIRST assignment (`... . " File"`), is likewise dead: immediately
  overwritten by a second assignment (`... . " Document"`) before ever
  being read, so only the final `" Document"`-suffixed value is
  reproduced.
- **Payment-request bubble** (`buildPaymentFlexMessage()` +
  `buildPaymentDbText()`): shows the formatted amount, hardcoded KBANK bank
  details (`KBANK (กสิกรไทย)` / `068-3-84622-8` / `บจก.ซี เอ็น วาย
  เฮลท์แคร์`), and two clipboard-copy buttons whose `clipboardText` is the
  account number with hyphens stripped (`0683846228`). `amount =
  number_format((float)($msg['amount'] ?? 0), 2)` — reproduced as
  `phpNumberFormat()`, a manual (locale-independent, not
  `toLocaleString()`-based) port with thousands separators + exactly 2
  decimals; absent `amount` defaults to `0.00`, never an error (§2.2 table).

### 2.4 ★ Two confirmed, deliberately-preserved PHP quirks — NOT bugs to fix ★

1. **`sent_by` is the RAW admin id, not `"admin:{name}"`.** Every OTHER
   action in this whole `api/inbox/actions/*` family (`send-message`,
   `send-image`, `send-pdf`, `dispense`, ...) formats `messages.sent_by` as
   the string `"admin:{adminName}"`. `send_batch_messages` does not — its
   `$insertStmt->execute([..., $adminId])` writes `$adminId` (from
   `$_SESSION['admin_id'] ?? $_GET['admin_id'] ?? $_POST['admin_id'] ??
   null`, computed once near the top of `api/inbox-v2.php`) straight into
   the column. Reproduced exactly as `String(session.adminUserId)` (the
   `session.currentBotId ?? 1`-style 2-tier simplification of that same
   PHP fallback chain, per this batch's brief) or `null` — never
   `"admin:..."`. Flagged loudly in `_lib/sendBatchMessages.ts`'s own doc
   comment and here so a future cleanup pass does not "helpfully"
   homogenize it without a deliberate decision to do so.
2. **`is_read` is hardcoded to `1` on every inserted row**, DIFFERENT from
   the sibling mediaSend batch's `send-image`/`send-pdf` (both `is_read:
   0`). `send_batch_messages`'s own `INSERT INTO messages (..., is_read,
   ...) VALUES (..., 1, ...)` is a literal constant in the SQL text — every
   row this action writes is inserted already marked read.

## 3. `upload_batch_file` — POST, pure filesystem stage (no DB, no LINE)

Validates and saves ONE uploaded file, staging it for a later
`send_batch_messages` call's `originalContentUrl`/`previewImageUrl`/
`fileName` fields. Same "no `db`/`session` needed by the action itself, but
the route still gates on a valid tenant session" shape as
`upload-for-analysis/route.ts` (mediaSend batch, this same round).

- **Validation order — SIZE is checked BEFORE TYPE**, the OPPOSITE order
  from `send-image`/`send-pdf`'s own type-then-size checks: file presence
  (`'No file uploaded or upload error'`) -> size ≤ 10MB
  (`'File too large (Max 10MB)'`) -> MIME ∈ `{image/jpeg, image/png,
  image/webp, image/gif, application/pdf}` (`'Invalid file type. Allowed:
  JPG, PNG, WEBP, GIF, PDF'`) -> [file written] -> a write failure ->
  `'Failed to save file'`.
- **Directory is chosen by MIME PREFIX, not extension**: `strpos($file['type'],
  'image/') === 0` sends the file to `uploads/chat_images/`; everything
  else (in practice, only `application/pdf` ever survives the allow-list)
  goes to `uploads/chat_files/`.
- **Filename scheme**: `(img_|file_)<unix-seconds>_<token>.<ext>` where
  `<ext> = pathinfo($file['name'], PATHINFO_EXTENSION)` — an EMPTY string
  when the original filename has no dot, with **NO `'jpg'` fallback**. This
  is a real, confirmed difference from the sibling mediaSend batch's
  `send-image`/`upload-for-analysis`, both of whose extension helpers DO
  default to `'jpg'` when the name has none. The two behaviors are
  deliberately NOT unified — a filename with no extension uploaded through
  THIS action produces a trailing-dot filename (e.g.
  `img_1699999999_abc123.`), while the same filename through `send-image`
  would get `.jpg` appended.
- **Response**: `{success, type: 'image'|'file', url, previewUrl, fileName}`
  — `previewUrl` is ALWAYS identical to `url`. PHP's own inline comment
  ("For images, typically same. For videos/files, might differ.") is
  aspirational; the case body has no actual branch that ever diverges the
  two, so this port does not invent one either.
- **NO database writes of any kind** — confirmed by a full read of the case
  body (lines 3489-3543): it never references `$db` once. This action only
  stages a file on disk; persisting it to `messages` happens later, if at
  all, via a subsequent `send_batch_messages` call from the client.
- **Directory reuse with the sibling mediaSend batch**: `upload_batch_file`
  writes to the SAME two physical directories `send-image`/`send-pdf`
  already write to (`uploads/chat_images/`, `uploads/chat_files/`) — this
  IS the same on-disk location in the PHP source
  (`__DIR__ . '/../uploads/{chat_images,chat_files}/'` in both files). This
  port therefore reuses the SAME env-var override names those two folders
  already established (`INBOX_CHAT_IMAGES_UPLOAD_DIR` /
  `INBOX_CHAT_FILES_UPLOAD_DIR`) — a deliberate naming convergence on
  physical-directory identity, not a cross-folder code import. No shared
  helper file exists between the two batches (see §5's "shared upload
  helper" note) — `_lib/uploadBatchFile.ts`'s own directory-resolution
  logic is fully self-contained, per this round's ownership split.

## 4. Schema-drift decision — `conversation_multi_assignees` unconditional read

`get_chat_content`'s PHP source wraps its assignees read in a runtime
`SHOW TABLES LIKE 'conversation_multi_assignees'` probe plus a defensive
`catch` that swallows ANY exception (not just "table missing") down to an
empty array:

```php
$assignees = [];
try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'conversation_multi_assignees'");
    if ($tableCheck->rowCount() > 0) {
        $assignStmt = $db->prepare("
            SELECT cma.admin_id, au.username, au.display_name
            FROM conversation_multi_assignees cma
            LEFT JOIN admin_users au ON cma.admin_id = au.id
            WHERE cma.user_id = ? AND cma.status = 'active'
        ");
        $assignStmt->execute([$userId]);
        $assignees = $assignStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $assignees = []; // Table doesn't exist, continue with empty assignees
}
```

`conversation_multi_assignees` IS present on the committed tenant schema
(`packages/db/src/generated/tenant-db.d.ts`'s `ConversationMultiAssignees`
interface, confirmed by direct read — line ~1102) — this defensive
machinery is a leftover from before the table was part of the template.
Per the brief, this port drops BOTH the `SHOW TABLES` probe AND the
swallow-any-exception `catch` entirely, querying the table unconditionally
just like every other query `get_chat_content` runs.

This is the SAME call `get-assignment/_lib/getAssignment.ts` (Phase 4 batch
3) already made for the structurally identical query. That file's own
"CONFIRMED FINDING" doc block documents the one remaining wrinkle this
decision inherits: `admin_users` is itself a PLATFORM-level table, absent
from the tenant DB template — the `LEFT JOIN admin_users au ON cma.admin_id
= au.id` has no type-safe `.leftJoin('admin_users', ...)` Kysely path and
is issued via a raw `sql` tagged template. If a tenant DB genuinely lacks
`admin_users`, this query throws — and, matching `get-assignment.ts`'s own
precedent exactly, that throw is NOT caught inside `getChatContent()`
either; it propagates to `route.ts`'s own try/catch, becoming `'Failed to
get chat content: {message}'` at HTTP 400 (§1's error-shape note) — the
SAME generic shape every other unexpected error in this function already
produces. `route.test.ts` asserts this exact propagation via a
`conversation_multi_assignees`-query-throws fixture.

## 5. Zero live network calls in `send-batch-messages/route.test.ts`

`send-batch-messages/route.test.ts` `jest.mock('@reya/line', ...)` at
module scope — a FULL module-boundary replacement (not a partial spy),
mocking ONLY the `pushMessage` export, exactly matching
`send-message/route.test.ts`'s own established pattern.

**Which module is mocked, at which boundary, and why no code path can
bypass it:** `_lib/sendBatchMessages.ts` is the ONLY file in this batch's
three route folders that imports anything from `@reya/line` — a single
`import { pushMessage, type LineApiCallResult, type LineMessage } from
'@reya/line'` at its top, and `pushMessage()` is called exactly once, at
one call site, after every validation branch has already returned. Because
`jest.mock('@reya/line', () => ({ pushMessage: (...args) => mockPushMessage(...args) }))`
replaces the ENTIRE module (a factory-based mock, not
`jest.spyOn`/`jest.requireActual` partial-mocking), Jest never evaluates
`packages/line/src/api.ts`'s real implementation for this test file at
all — `defaultFetch`/`globalThis.fetch` inside that real file is simply
unreachable code as far as this test run is concerned, regardless of how
deep a bug might otherwise route execution. Every test that expects a
LINE-sending code path to run configures `mockPushMessage.mockResolvedValue(...)`
explicitly; every test that expects a route to short-circuit BEFORE
`pushMessage()` (auth failure, missing user id, empty/oversized messages
array, missing `line_user_id`/`line_accounts` row, an all-invalid messages
batch) additionally asserts `expect(mockPushMessage).not.toHaveBeenCalled()`.
`get-chat-content` and `upload-batch-file` never import `@reya/line` at
all (grep-verified), so neither of their test files needs — or has — any
LINE mock.

Confirmed by running all three suites in this worktree:

```
$ cd apps/admin && npx jest src/app/api/inbox/actions/get-chat-content src/app/api/inbox/actions/send-batch-messages src/app/api/inbox/actions/upload-batch-file
PASS src/app/api/inbox/actions/send-batch-messages/route.test.ts
PASS src/app/api/inbox/actions/get-chat-content/route.test.ts
PASS src/app/api/inbox/actions/upload-batch-file/route.test.ts
Test Suites: 3 passed, 3 total
Tests:       60 passed, 60 total
```

The full pre-existing `src/app/api/inbox/**` suite (71 suites / 844 tests,
including this batch's 3/60 and the sibling mediaSend batch's own 3/26)
stays green — no regression in any already-merged sibling action's test
file:

```
$ cd apps/admin && npx jest src/app/api/inbox
Test Suites: 71 passed, 71 total
Tests:       844 passed, 844 total
```

## 6. Deferred scope

- **`ai_reply`** — out of scope for this whole round (Phase 7 AI SSE
  territory); see `phase4-batch9-media-send-parity.md` §0.1 for the full
  scope note (that batch's own file identity, since `ai_reply` lives in the
  root `inbox-v2.php` admin page, not this batch's `api/inbox-v2.php`).
- **Shared upload-validation helper** — per this round's ownership split,
  mediaSend defines any reusable upload-validation helper under its OWN
  `_lib` and this batch would import it from there rather than duplicating;
  as of `phase4-batch9-media-send-parity.md`'s own §5, mediaSend shipped no
  such exported helper (each of its three folders kept validation
  self-contained), so `upload-batch-file/_lib/uploadBatchFile.ts` is
  likewise fully self-contained — see §3's directory-reuse note for the one
  place the two batches deliberately converge (env var NAMES only, not
  code).
- **`ai-chat*.php` / `modules/AIChat/**` consultation pipeline** — untouched,
  out of scope, same boundary noted by every other Phase 4 batch runbook.

## 7. How to run this batch's tests locally

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/get-chat-content
npx jest src/app/api/inbox/actions/send-batch-messages
npx jest src/app/api/inbox/actions/upload-batch-file
```

Or all three at once:

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/get-chat-content src/app/api/inbox/actions/send-batch-messages src/app/api/inbox/actions/upload-batch-file
```

Typecheck:

```bash
cd apps/admin && npx tsc --noEmit -p tsconfig.json
```

(Requires `packages/{config,core,auth,tenant,contracts,db,line}` to have
been built at least once in this worktree — see
`phase4-batch9-media-send-parity.md` §6 for the exact build commands if a
fresh worktree's `tsc --noEmit` fails to resolve a `@reya/*` package.)

## 8. Acceptance criteria (mig-verify executes these)

- [ ] `cd apps/admin && npx tsc --noEmit -p tsconfig.json` passes with zero
      errors.
- [ ] `cd apps/admin && npx jest src/app/api/inbox/actions/get-chat-content src/app/api/inbox/actions/send-batch-messages src/app/api/inbox/actions/upload-batch-file`
      — all green (3 suites / 60 tests as of this writing).
- [ ] `send-batch-messages/route.test.ts` `jest.mock('@reya/line', ...)` at
      module scope, mocking `pushMessage` only, exactly matching
      `send-message/route.test.ts`'s pattern — zero real `fetch` calls
      reachable from any test in this batch (§5).
- [ ] `get-chat-content`: `?user_id=` and `?user=` both resolve the same
      user; missing/falsy user id -> 400 `'User ID is required'`; user not
      found (scoped by id AND line_account_id) -> literal 404 `'User not
      found'`; `limit` clamped with `min(limit, 100)`; `has_more = (offset +
      messages.length) < total_messages`; messages returned oldest-first
      (DESC fetch, reversed in code); `assignees` queried unconditionally
      from `conversation_multi_assignees` (no table-existence probe); every
      call marks that user's unread incoming messages read as a side
      effect; an unhandled error returns the literal `'Failed to get chat
      content: {message}'` at HTTP 400 (case-level catch, not a 500).
- [ ] `send-batch-messages`: >5 messages -> 400 `'Maximum 5 messages allowed
      per batch'`; empty/missing messages -> 400 `'Messages array is
      required'`; an all-invalid messages batch silently yields 400 `'No
      valid messages to send'` (no per-item error); the magic
      `'{{PAYMENT_TEMPLATE_V1}}'` text content switches to the payment Flex
      card; `image` requires BOTH url fields or is dropped; `file` requires
      `originalContentUrl` and produces the exact hardcoded-icon Flex bubble
      or is dropped; `payment` has NO required-field guard, defaults
      `amount` to `0.00`, and its Flex JSON + KBANK bank details match
      byte-for-byte; a non-200 `pushMessage()` result yields `'Failed to
      send messages via LINE: {message}'` at 400 (a format DISTINCT from
      mediaSend's own LINE-error string this round); happy path writes one
      `messages` row per `dbRecords` entry with `is_read=1` and `sent_by`
      bound to the RAW admin id (not `'admin:{name}'` — §2.4's flagged
      quirk); `users.last_message_at` is touched; response is `{success,
      message: 'Sent {n} messages successfully', count: n}`.
- [ ] `upload-batch-file`: allowed types are exactly `image/jpeg`,
      `image/png`, `image/webp`, `image/gif`, `application/pdf`, else 400
      `'Invalid file type. Allowed: JPG, PNG, WEBP, GIF, PDF'`; size is
      checked BEFORE type (>10MB -> 400 `'File too large (Max 10MB)'` even
      for an otherwise-invalid MIME); a write failure -> 400 `'Failed to
      save file'`; directory chosen by MIME prefix
      (`uploads/chat_images/`/`uploads/chat_files/`); filename has NO
      `'jpg'` fallback when the original name has no extension (a confirmed
      difference from mediaSend's own upload helpers this round);
      `previewUrl` always equals `url`; NO database writes of any kind.
- [ ] `git diff --stat` (this batch's own changes) touches only files
      under `apps/admin/src/app/api/inbox/actions/{get-chat-content,send-batch-messages,upload-batch-file}/**`
      and this runbook — no edits under
      `apps/admin/src/app/api/inbox/actions/{send-image,upload-for-analysis,send-pdf}/**`,
      `infra/nginx/**`, `apps/admin/src/nav/manifest.ts`, or any PHP file.
- [ ] No `mig-orchestrator` co-sign required for this PR — Phase 4 clears on
      `mig-verify`'s single gate alone (reduced-review low-risk list; see
      header).
