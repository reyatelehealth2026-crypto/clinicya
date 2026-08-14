# Phase 4 batch 9 — `/inbox` chat composer media send (image / PDF / analysis upload)

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time"), §1.5 (strangler edge), §3
(Phase 4 acceptance criteria). Team doc:
`docs/agents/nextjs-migration-team.md` (reduced review flow — Phase 4 is on
the low-risk list, not the high-risk co-sign list `0, 3, 5, 6, 7`, so a clean
`mig-verify` PASS is sufficient for merge). Owner: mediaSend
(`apps/admin/src/app/api/inbox/actions/{send-image,upload-for-analysis,send-pdf}/**`
exclusively this round). Cross-reference:
`docs/runbooks/phase4-batch7-ai-copilot-parity.md` (the prior actions-batch
runbook whose shape — scope note, alias table, confirmed-schema/behavior-drift
prose, deferred scope, acceptance-criteria tail — this one follows) and the
sibling batchAndContent stream's own runbook for this same round (its exact
filename is that stream's call; expect something under
`docs/runbooks/phase4-batch9-*-parity.md` covering
`get-chat-content`/`send-batch-messages`/`upload-batch-file` — see §5 for why
this batch does not attempt to guess or fabricate that file's contents).

## 0. Scope note (read first)

This batch ports the chat composer's three remaining media-upload buttons —
**send image**, **upload image for AI analysis**, **send PDF** — the last
unported case labels from `inbox-v2.php`'s OWN same-page AJAX switch
(`if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']))`,
line 230; `switch ($action)`, line 235) besides `ai_reply`, which stays
explicitly out of scope (see §1.1).

**Important file-identity correction, verified by reading the source, not
assumed from the brief:** this batch's brief describes these as "the file's
~29-action switch". That count belongs to a DIFFERENT (if similarly named)
file — `api/inbox-v2.php`, the separate cursor-pagination + AI-copilot API
file that Phase 4 batches 1 through 7 have been porting from (116 `case`
labels / ~29 unique actions once hyphen/underscore aliases are collapsed —
see `phase4-batch7-ai-copilot-parity.md`'s own alias table for that file).
The root `inbox-v2.php` (the admin page, not under `api/`) that this batch's
three case bodies actually live in has its OWN, much smaller same-page AJAX
switch — exactly **10 case labels total**:

```
$ grep -n "case '" inbox-v2.php | awk -F: '$1 < 985'
236:            case 'send_message':
338:            case 'ai_reply':
397:            case 'update_tags':
414:            case 'save_note':
431:            case 'delete_note':
444:            case 'save_medical':
469:            case 'dispense':
737:            case 'send_image':
836:            case 'upload_for_analysis':
876:            case 'send_pdf':
```

Of those 10: `send_message` and `dispense` were already ported by prior
batches (`apps/admin/src/app/api/inbox/actions/{send-message,dispense}/**`);
`update_tags` → `apps/admin/src/app/api/inbox/actions/tags/**`, `save_note`/
`delete_note` → `apps/admin/src/app/api/inbox/actions/notes/**`, and
`save_medical` → `apps/admin/src/app/api/inbox/actions/medical/**` (all
confirmed via each folder's own module-doc comment citing its exact PHP line
range). That leaves exactly 4 case labels unported before this batch:
`send_image`, `upload_for_analysis`, `send_pdf`, and `ai_reply` — this batch
ports the first three and leaves `ai_reply` untouched, matching the brief's
actual scope even though its "~29" figure describes a different file. Filed
here so `mig-verify`/`mig-orchestrator` don't chase a phantom 26-actions-left
count against the wrong source file.

### 0.1 `ai_reply` — explicitly OUT OF SCOPE, not ported, not stubbed

`inbox-v2.php`'s `case 'ai_reply':` (line 338) is the admin-side AI-drafted
Copilot reply generator — it `require_once`s `modules/AIChat/Autoloader.php`
and drives the same SSE-streamed Gemini pipeline `CLAUDE.md` calls out as
Phase 7 territory (`modules/AIChat/**`, `api/ai-chat*.php`). This batch does
**not** port it, does **not** stub a placeholder route for it, and does
**not** reference it from any of the three shipped route folders — the only
mention of it anywhere in this batch's deliverables is this runbook section
and the module-doc comments' own scope notes. No file under
`apps/admin/src/app/api/inbox/actions/{send-image,upload-for-analysis,send-pdf}/**`
imports anything under `modules/AIChat/` or calls any Gemini/OpenAI client.

## 1. What landed — 3 ported actions, all literal ports of `inbox-v2.php`

### 1.1 Alias table

| PHP case label (`inbox-v2.php`) | Lines | Route | Method | Content-Type |
|---|---|---|---|---|
| `send_image` | 737-834 | `apps/admin/src/app/api/inbox/actions/send-image/route.ts` | `POST` | `multipart/form-data` (`user_id` field + `image` file field) |
| `upload_for_analysis` | 836-874 | `apps/admin/src/app/api/inbox/actions/upload-for-analysis/route.ts` | `POST` | `multipart/form-data` (`image` file field only — `user_id`, if sent, is read by PHP but never checked or used, see §2) |
| `send_pdf` | 876-977 | `apps/admin/src/app/api/inbox/actions/send-pdf/route.ts` | `POST` | `multipart/form-data` (`user_id` field + `pdf` file field) |

Each is a literal port of its PHP case body, backed by `classes/LineAPI.php`
(now `@reya/line`'s `sendMessage()`/`pushMessage()`) for `send-image`/
`send-pdf`, and `classes/ActivityLogger.php::logMessage()` for the same two
— `upload-for-analysis` touches neither `$db` nor LINE at all (§2).

Every handler requires a valid tenant session (any of the six `TenantRole`
values) via each folder's own `resolveInboxApiContext()` — the same
per-route auth gate every prior batch established, duplicated per folder
(not imported across folders) per this codebase's "every consumer resolves
its own session" convention, matching `send-message/_lib/session.ts` and
`dispense/_lib/session.ts`'s own precedent.

### 1.2 File-upload targets — same on-disk directories PHP writes to

| Route | On-disk dir (both stacks) | Filename scheme | Env override |
|---|---|---|---|
| `send-image` | `<repo-root>/uploads/chat_images/` | `chat_<unix-seconds>_<token>.<ext>` (`ext` = `pathinfo(...) ?: 'jpg'`) | `INBOX_CHAT_IMAGES_UPLOAD_DIR` |
| `upload-for-analysis` | `<repo-root>/uploads/analysis_images/` | `analysis_<unix-seconds>_<token>.<ext>` (same ext fallback) | `INBOX_ANALYSIS_IMAGES_UPLOAD_DIR` |
| `send-pdf` | `<repo-root>/uploads/chat_files/` | `pdf_<unix-seconds>_<token>.pdf` (**always** `.pdf` — original extension never inspected) | `INBOX_CHAT_FILES_UPLOAD_DIR` |

`<token>` stands in for PHP's `uniqid()` — a `crypto.randomBytes(8).toString('hex')`
opaque per-call token, not a byte-for-byte reproduction of PHP's
microtime-derived format (nothing downstream parses `uniqid()`'s internal
structure, only its uniqueness). Directory resolution is the same
env-override-then-walk-up-to-`pnpm-workspace.yaml` strategy
`apps/admin/src/app/api/miniapp/checkout/order/_lib/uploadSlip.ts`'s
`resolveSlipsUploadDir()` established — each of the three `_lib` files keeps
its own copy of the resolver (same "duplicate per folder" precedent as
`session.ts`), not a shared cross-folder helper, since no second builder
needed the exact same resolver this round. The public URL for all three is
built from the incoming request's own `new URL(request.url)` origin
(`route.ts` computes it, passes it down as a plain string) — never a
hardcoded `BASE_URL` constant.

## 2. Request/response summary — validation order and the 400-only status split

Unlike batch 7's mixed 200/400 split (some backing services never fail, so
their routes are unconditionally 200 once validated), **every error path in
this batch — validation or LINE-send failure — is HTTP 400**, matching
`inbox-v2.php`'s single outer `catch (Exception $e) { http_response_code(400);
echo json_encode(['success' => false, 'error' => $e->getMessage()]); }`
(lines 982-985) uniformly across all three case bodies. There is no
200-with-`success:false` branch anywhere in this batch.

| # | Route | Validation order (first failure wins) | Happy-path status | Error status |
|---|---|---|---|---|
| 1 | `send-image` | `user_id` truthy → `image` file present → MIME ∈ {jpeg,png,gif,webp} → size ≤ 10MB → `users` row exists → *(file written)* → `line_accounts` row exists (Next-side addition) → LINE send succeeds | `200` | `400` for every branch, literal PHP message text (see §3 for the two Next-side messages) |
| 2 | `upload-for-analysis` | `image` file present → MIME ∈ {jpeg,png,gif,webp} → size ≤ 10MB | `200` | `400`, literal PHP message text |
| 3 | `send-pdf` | `user_id` truthy → `pdf` file present → MIME **exactly** `application/pdf` (no allow-set, unlike the two image routes) → size ≤ 10MB → `users` row exists → *(file written)* → `line_accounts` row exists (Next-side addition) → LINE send succeeds | `200` | `400` for every branch, literal PHP message text (see §3) |

**`upload-for-analysis` never validates `user_id` at all** — PHP reads
`$_POST['user_id']` into a local variable and then never references it again
anywhere in the case body (grep-verified dead code). The Next port does not
add a `user_id` requirement that does not exist in the source; a request
with no `user_id` field and a valid `image` file still succeeds.

### 2.1 Exact literal error strings, in validation order

- **`send-image`**: `'User ID required'` → `'No image uploaded'` →
  `'Invalid image type. Allowed: JPG, PNG, GIF, WEBP'` → `'Image too large.
  Max 10MB'` → `'User not found'` → *(Next-side)* a Thai "no LINE OA
  connection" message on a missing `line_accounts` row → `'Failed to send
  image via LINE (HTTP {code}, {method}): {json body}'`.
- **`upload-for-analysis`**: `'No image uploaded'` → `'Invalid image type'`
  (note: no "Allowed: ..." suffix, unlike `send-image` — this is PHP's own
  distinct, shorter message for this case body, not a typo) → `'Image too
  large. Max 10MB'`.
- **`send-pdf`**: `'User ID required'` → `'No PDF uploaded'` → `'Invalid
  file type. Only PDF allowed'` → `'PDF too large. Max 10MB'` → `'User not
  found'` → *(Next-side)* the same Thai "no LINE OA connection" message →
  `'Failed to send PDF via LINE (HTTP {code}, {method}): {json body}'`.

All literal strings above are asserted byte-for-byte in each folder's
`route.test.ts` — see §4.

## 3. Next-side additions, documented deviations from the literal PHP source

1. **No legacy `line_accounts` config fallback (`send-image`, `send-pdf`
   only).** `inbox-v2.php` calls
   `classes/LineAccountManager.php::getLineAPI($user['line_account_id'])`,
   which silently falls back to a legacy config-constant-backed
   `new LineAPI()` when no `line_accounts` row matches the user's
   `line_account_id` — so in production PHP, this branch is unreachable in
   practice. Next has no such fallback (same decision already established by
   `send-message/_lib/sendMessage.ts` and `dispense/_lib/flexSend.ts`): a
   missing/invalid `line_accounts` row is an explicit `400` here instead of
   silently sending through some default channel token.
2. **File cleanup on the missing-`line_accounts` branch — a deliberate
   consistency addition beyond the literal PHP source.** By the time the
   `line_accounts` lookup runs, the uploaded file has already been written
   to disk (PHP writes the file BEFORE building the LINE client, in both
   `send_image` and `send_pdf`). Since PHP's own fallback never actually
   fails at this point (see #1), PHP has no code path that unlinks the file
   here — there was nothing to clean up in the original. Next's new failure
   branch, being new, deliberately reuses the SAME best-effort
   `fs.unlink(filepath).catch(() => {})` cleanup pattern PHP's own
   LINE-send-failure branch (`@unlink($filepath)`) already uses, so a
   rejected upload never leaves an orphaned file behind regardless of WHICH
   of the two failure branches (missing line account vs. LINE API error)
   rejected it. This is a genuinely new safety property, not a port of an
   existing PHP behavior — flagged here and in each `_lib/*.ts` file's own
   inline comment at the call site.
3. **`$originalName = pathinfo($_FILES['pdf']['name'], PATHINFO_FILENAME)`
   (inbox-v2.php line 896) — dead code, NOT ported.** Assigned once, never
   referenced again anywhere in `case 'send_pdf':` (grep-verified). The
   ORIGINAL uploaded filename (`$_FILES['pdf']['name']`, unmodified) is what
   actually flows into the LINE text message, the stored `content` JSON, and
   the response's `file_name` — `sendPdf.ts` reads `file.name` directly for
   all three uses, never this dead local.
4. **`SHOW COLUMNS FROM messages LIKE 'sent_by'` defensive branch — dead
   code on the current schema, NOT replicated.** All three PHP case bodies
   (well, the two that touch `messages` at all) probe at runtime whether the
   `sent_by` column exists and fall back to a narrower INSERT if not.
   `packages/db/src/generated/tenant-db.d.ts`'s `Messages` interface
   confirms `sent_by` always exists on the committed schema — this is the
   same "unreachable dead code, not replicated" call
   `send-message/_lib/sendMessage.ts` already made; `send-image`/`send-pdf`
   always insert the `sent_by`-inclusive shape unconditionally.
5. **`activity_logs.new_value` is left `NULL` for both `send-image` and
   `send-pdf` — do not copy `send-message`'s `new_value` field over by
   reflex.** `send-message/_lib/sendMessage.ts`'s own `activity_logs` insert
   sets `new_value: JSON.stringify({message: ...})` because
   `inbox-v2.php`'s `send_message` case really does pass a `new_value` option
   to `ActivityLogger::logMessage()`. Neither `send_image`'s nor
   `send_pdf`'s own `logMessage()` call passes a `new_value` option at all —
   ported faithfully by simply omitting that key from the `.values({...})`
   object passed to Kysely, leaving the column at its schema default
   (`NULL`). Verified in each `route.test.ts`'s happy-path test via
   `expect(activityInsert?.sql.toLowerCase()).not.toContain('new_value')`.
6. **`messages` INSERT has no `reply_to_id` column for either `send-image`
   or `send-pdf`** — unlike `send-message`'s own insert (which has a real
   `reply_to_id` concept, since a customer's inbound message can be quoted).
   Neither PHP case body's `INSERT INTO messages (...)` column list includes
   `reply_to_id` at all; the port matches — 7 bound columns
   (`line_account_id, user_id, direction, message_type, content, sent_by,
   is_read`, with `created_at` as a raw `NOW()` SQL expression, not a bound
   parameter) instead of send-message's 8.
7. **Response envelopes carry no `method`/`method_label` keys**, unlike
   `send-message`'s response (`{..., method, method_label}`). Neither
   `send_image` nor `send_pdf`'s PHP `echo json_encode([...])` call includes
   those two keys — the response bodies here are exactly `{success,
   message_id, image_url, time, sent_by}` and `{success, message_id,
   file_url, file_name, time, sent_by}` respectively.

## 4. Zero live network calls / filesystem escapes in tests

Both `send-image/route.test.ts` and `send-pdf/route.test.ts`
`jest.mock('@reya/line', ...)` at module scope (mocking the exact
`sendMessage()` export their `_lib/*.ts` file imports), exactly matching
`send-message/route.test.ts`'s own established pattern — no real `fetch`
call is reachable from any test in this batch.
`upload-for-analysis/route.test.ts` has no LINE mock at all, since
`uploadForAnalysisAction()` never imports `@reya/line` in the first place
(§2's validation table — this action never reaches a LINE call).

Every test that writes a file does so under its own `mkdtempSync(path.join(
tmpdir(), '...'))` temp directory, wired in via each route's own upload-dir
env-var override (`INBOX_CHAT_IMAGES_UPLOAD_DIR` /
`INBOX_ANALYSIS_IMAGES_UPLOAD_DIR` / `INBOX_CHAT_FILES_UPLOAD_DIR`) set in
`beforeEach`/cleared with `rmSync(..., {recursive:true,force:true})` in
`afterEach` — the exact same pattern
`apps/admin/src/app/api/miniapp/checkout/order/_lib/uploadSlip.test.ts`
established. No test writes anywhere outside its own temp directory, and no
test leaves a stray temp directory behind (each `afterEach` unconditionally
removes it, whether or not the test's assertions passed).

Confirmed by running all three suites in this worktree:

```
$ cd apps/admin && npx jest src/app/api/inbox/actions/send-image src/app/api/inbox/actions/upload-for-analysis src/app/api/inbox/actions/send-pdf
PASS src/app/api/inbox/actions/upload-for-analysis/route.test.ts
PASS src/app/api/inbox/actions/send-image/route.test.ts
PASS src/app/api/inbox/actions/send-pdf/route.test.ts
Test Suites: 3 passed, 3 total
Tests:       26 passed, 26 total
```

The full pre-existing `src/app/api/inbox/**` suite (68 suites / 784 tests,
including this batch's 3/26) stays green — no regression in any
already-merged sibling action's test file:

```
$ cd apps/admin && npx jest src/app/api/inbox
Test Suites: 68 passed, 68 total
Tests:       784 passed, 784 total
```

## 5. Deferred scope

- **`ai_reply`** (`inbox-v2.php` line 338) — explicitly out of scope, see
  §0.1. Not ported, not stubbed, not referenced by any file this batch
  ships.
- **`get_chat_content`, `send_batch_messages`, `upload_batch_file`** —
  owned by the sibling batchAndContent stream this same round
  (`apps/admin/src/app/api/inbox/actions/{get-chat-content,send-batch-messages,upload-batch-file}/**`,
  explicitly out of this batch's allowed paths). This runbook does not
  document that stream's request/response shapes, validation order, or LINE
  parity beyond the shared-helper note below — see that stream's own
  runbook (filename per its own deliverable, expected under
  `docs/runbooks/phase4-batch9-*-parity.md`) for its own scope note and
  acceptance criteria.
- **Shared upload-validation helper** — per this round's ownership split,
  mediaSend (this batch) defines any reusable upload-validation helper under
  its OWN `_lib` and batchAndContent would import it from there rather than
  duplicating; as shipped, this batch kept each of its three folders'
  validation/upload-dir helpers self-contained and folder-local (matching
  every prior batch's "duplicate small helpers per folder" convention), so
  there is currently no single exported helper for batchAndContent to import
  — if `upload-batch-file`'s own validation logic turns out to need the
  exact same MIME-allow-list/size-cap/extension-fallback rules this batch
  already wrote, that stream should point at
  `send-image/_lib/sendImage.ts`'s `phpFileExtensionOrJpg()` pattern rather
  than re-deriving it from scratch, but no cross-folder import exists
  between the two batches as of this runbook.
- **`ai-chat*.php` / `modules/AIChat/**` consultation pipeline** — a
  completely separate SSE-streamed system from `inbox-v2.php`'s same-page
  AJAX switch, untouched and out of scope (same boundary `ai_reply` sits on
  the near side of).

## 6. How to run this batch's tests locally

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/send-image
npx jest src/app/api/inbox/actions/upload-for-analysis
npx jest src/app/api/inbox/actions/send-pdf
```

Or all three at once:

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/send-image src/app/api/inbox/actions/upload-for-analysis src/app/api/inbox/actions/send-pdf
```

Typecheck:

```bash
cd apps/admin && npx tsc --noEmit -p tsconfig.json
```

(Requires `packages/{config,core,auth,tenant,contracts,db,line}` to have
been built at least once in this worktree — `pnpm install` at the repo root
does not itself run each package's `build` script; run `npm run -s build`
inside each of those seven package directories, or `pnpm -r run build`,
before the first `tsc --noEmit` in a fresh worktree.)

## 7. Acceptance criteria (mig-verify executes these)

- [ ] `cd apps/admin && npx tsc --noEmit -p tsconfig.json` passes with zero
      errors.
- [ ] `cd apps/admin && npx jest src/app/api/inbox/actions/send-image src/app/api/inbox/actions/upload-for-analysis src/app/api/inbox/actions/send-pdf`
      — all green (3 suites / 26 tests as of this writing); zero real `fetch`
      calls reachable from any test (`@reya/line` is `jest.mock()`'d exactly
      like `send-message/route.test.ts`).
- [ ] Every literal validation error string in §2.1 is asserted verbatim, at
      HTTP 400, in validation order, in each folder's `route.test.ts`.
- [ ] A real file is written under a temp dir (env-var override + `mkdtemp`)
      for each of the three routes' target subdirectory
      (`uploads/chat_images/`, `uploads/analysis_images/`,
      `uploads/chat_files/`) on the happy path — the test asserts both the
      actual bytes on disk and the URL path segment returned.
- [ ] `send-image`'s happy-path test asserts the exact 7-column `messages`
      INSERT (no `reply_to_id`) and an `activity_logs` INSERT whose SQL text
      does not contain `new_value`.
- [ ] `send-pdf`'s happy-path test asserts `message_type='file'`,
      `content = JSON.stringify({url, name})`, and the same `new_value`-omitted
      rule as `send-image`.
- [ ] `send-image`/`send-pdf` response envelopes have no `method`/
      `method_label` keys; `send-pdf`'s `file_name` is the ORIGINAL uploaded
      filename, not the generated on-disk filename.
- [ ] On a non-200 LINE result, `send-image`/`send-pdf` delete the
      just-written file (best-effort) and return the literal `'Failed to
      send image via LINE (HTTP {code}, {method}): {json body}'` /
      `'Failed to send PDF via LINE (HTTP {code}, {method}): {json body}'`
      format at 400 — distinct from `send-batch-messages`'s own LINE-failure
      format (a different builder's file this same round; not unified with
      this batch's format).
- [ ] A missing/invalid `line_accounts` row for the user's
      `line_account_id` returns 400 before any LINE API attempt, for both
      `send-image` and `send-pdf`, and the just-uploaded file is cleaned up
      in that branch too (§3, item 2).
- [ ] `git diff --stat` touches only files under
      `apps/admin/src/app/api/inbox/actions/{send-image,upload-for-analysis,send-pdf}/**`
      and this runbook — no edits under
      `apps/admin/src/app/api/inbox/actions/{get-chat-content,send-batch-messages,upload-batch-file}/**`,
      `infra/nginx/**`, `apps/admin/src/nav/manifest.ts`, or any PHP file.
- [ ] No `mig-orchestrator` co-sign required for this PR — Phase 4 clears on
      `mig-verify`'s single gate alone (reduced-review low-risk list; see
      header).
