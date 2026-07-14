# Phase 4 batch 1 — `/inbox` reads (conversationList + messageThread) parity harness

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time"), §1.5 (strangler edge), §7.3
(canary ramp). Owner: mig-infra (this harness + `infra/nginx/routes.json`'s
`/inbox` entry) / mig-api (conversationList's Route Handlers +
cursor-pagination data layer, `apps/admin/src/app/api/inbox/**`) / mig-ui
(messageThread's Server Components/UI, `apps/admin/src/app/(tenant)/inbox/**`)
/ mig-orchestrator (canary-ramp authorization — not this batch's decision, see
§6). Cross-reference: `docs/runbooks/phase2-batch1-users-dashboard-parity.md`
(the page-pair harness this batch extends — same file, same JSON-line-output
convention) and `docs/runbooks/phase3-batch1-miniapp-api-parity.md` (the
*other* identity model, deliberately NOT used here — see §2).

## 0. Scope note (read first — same documented-limits pattern every other
runbook in this directory uses)

`infra/e2e/parity.mjs` (extended by this batch) proves, on ONE seeded tenant
and ONE fixed golden-dataset fixture, on the REAL stack (genuine MariaDB
10.11 + Redis 7 + `php:8.2-apache` containers, a genuine `next build` +
standalone-server `apps/admin`):

- **JSON cursor-pagination contract parity** for the two NEW Route Handlers
  this batch ships, `GET /api/inbox/conversations` and `GET
  /api/inbox/messages` — walked end-to-end against a 215-conversation /
  130-message golden dataset (§4), asserting the envelope shape, the
  documented internal page-size caps, cursor advancement, ordering, and
  (for conversations) five badge-satellite conversations' exact enrichment
  fields (tags/assignment/chat_status/unread_count).
- **Data-point parity** for the messageThread chat pane
  (`/inbox-v2.php?user=N` vs `/inbox/N`) — chat header name, message count,
  and presence of every one of 13 marked message types/forms (§5).
- **A confirmed, pre-existing PHP defect** in the conversation-list *sidebar*
  itself (`/inbox-v2.php` vs `/inbox`) that this batch's own harness run
  discovered — documented as two single-side assertions, not a diff (§7).

**What this is NOT** (same three bullets every sibling runbook in this
directory already carries, restated for this batch):

- **Not a live-traffic shadow test.** One seeded tenant, one fixture, no
  real admin sessions or real production load.
- **Not a pixel/HTML diff.** Thread-page markers are checked via substring
  inclusion against a comment-stripped copy of the raw HTML (see §5.2's
  "flex rendering asymmetry" note) — this is deliberately looser than the
  label-anchored `HtmlCursor` extraction other runbooks' pages use, because
  `/inbox`'s per-conversation/per-message markup repeats hundreds of times
  (unlike a KPI dashboard's unique labels) and PHP defers ALL flex-message
  rendering to client-side JS this fetch-only harness never executes.
- **Not exhaustive.** Only conversationList's and messageThread's OWN reads
  are covered — see §8 for the full, cross-checked list of what this batch
  (and therefore this harness) explicitly does not touch.

## 1. How to run it

```bash
node infra/e2e/parity.mjs
```

Single command — this batch adds NO new script, NO new flag, and NO new
compose file. It is a straight extension of the existing harness (same
`docker compose -p reya-e2e-parity -f infra/e2e/docker-compose.yml`
lifecycle, same `pnpm --filter admin run build` + standalone-server flow,
same single JSON-line stdout + `finally`-block teardown every other batch in
this file already established — see `infra/e2e/parity.mjs`'s own module doc
for the full mechanics, unchanged by this batch). The only two additions to
the *run sequence* are:

1. `seedDatabase()`'s `FIXTURE_FILES` list now also applies
   `infra/e2e/seed/70-phase4-batch1-inbox-fixture.sql.tmpl` (additive, after
   `30-`/`40-`/`60-phase2-*`, onto the SAME tenant DB — same "additive, never
   a replacement" convention those three files already established).
2. Five new entries append to the `pages` array (§3's table) — before this
   batch's code exists, EACH fails loudly and diagnosably as its OWN named
   entry (a 404 from a route that doesn't exist yet, or a PHP-vs-Next
   markup mismatch on a page that doesn't exist yet — see `fetchNextJson()`'s
   own doc comment in `infra/e2e/parity.mjs` for how a non-200/non-JSON
   response becomes a loud, attributable `Error`), never a silent skip and
   never a false PASS — same never-throws-past-its-own-entry contract
   `runPagePair()`/`runSingleSideCheck()` already guarantee for every entry
   in this file.

Exit code `0` only on `{"result":"PASS", ...}`.

## 2. Identity model decision (read before adding a 6th /inbox request)

**This batch's harness extension uses `infra/e2e/parity.mjs`'s identity
model — tenant `Host: <slug>.re-ya.com` header + session cookie
(`PHPSESSID=<sid>` / `reya_sid=<sid>`) — for every single request it adds,
never `infra/e2e/api-parity.mjs`'s unauthenticated root-domain +
`line_account_id`-resolution model.** This was a deliberate choice, not a
default, and it is written down here specifically so a later inbox-actions
batch does not have to re-derive or re-litigate it:

| | `/users`, `/inbox` (this batch) | `/api/miniapp/**` |
|---|---|---|
| Harness | `infra/e2e/parity.mjs` | `infra/e2e/api-parity.mjs` |
| Who calls it | A logged-in pharmacy admin, via the admin dashboard | An anonymous LIFF visitor, via line-mini-app |
| Tenant resolution | Subdomain (`Host: tenant-XXXX.re-ya.com`) | `line_account_id` (root domain, no tenant-pinning Host) |
| Auth | Real admin session (PHPSESSID / `reya_sid` cookie) | None — trust-on-input, per `api-parity.mjs`'s own "contractNote" |
| `/api/inbox/**`'s own gate | `resolveInboxApiContext()` / the messages route's inline check — BOTH require a valid tenant session (see §8.1's "one deliberate ADDITION over the literal PHP source" for why) | n/a |

`/inbox` and `/inbox/[userId]` are pharmacy-admin-facing pages reached from
the SAME admin dashboard `/users`/`/dashboard`/etc. already live at — a
logged-in admin, resolved by subdomain, exactly like every phase-2-batch
page this harness already tests. It is **not** like `/api/miniapp/**`
(line-mini-app's public, unauthenticated, `line_account_id`-keyed API
surface, deliberately reachable with no tenant Host header at all — see
`docs/runbooks/phase3-batch1-miniapp-api-parity.md` §3). Reusing
`api-parity.mjs`'s identity model for `/inbox` would test the WRONG
resolution path (`bootstrap/route_by_account.php`'s phase-(b) fallback,
which real admin-dashboard traffic never takes) and would silently miss any
regression that ONLY shows up under real subdomain + session-cookie
resolution — e.g. `getTenantDb(session.tenantId)` being wired to the wrong
tenant, or a role-gate regression only a real `TenantSession` object can
trigger.

Practically, this meant extending `infra/e2e/parity.mjs` (not
`api-parity.mjs`): the new `/api/inbox/conversations` and `/api/inbox/messages`
cursor walks call `fetchNextJson()` (a new helper in `parity.mjs`, NOT a copy
of anything in `api-extract.mjs`) with the SAME `Host: TENANT_HOST` +
`Cookie: reya_sid=<nextSid>` headers `fetchNextPage()` already uses — see
`fetchNextJson()`'s own doc comment in `infra/e2e/parity.mjs`.

## 3. What's covered — the five new `pages` entries

| `page` value | What it proves | Diff shape |
|---|---|---|
| `inbox-conversations-cursor-walk` | `GET /api/inbox/conversations` end-to-end cursor pagination against all 215 golden conversations + 5 badge-satellite spot checks | Single-side (Next-only) contract walk — no PHP equivalent request is made; see §2 |
| `inbox-messages-cursor-walk` | `GET /api/inbox/messages?user_id=7001` end-to-end cursor pagination against HERO's 130 golden messages, all 13 marked types/forms covered | Single-side (Next-only) contract walk |
| `inbox:php-empty-currentbotid-clobbered` | PHP's `/inbox-v2.php` sidebar is confirmed EMPTY under this harness's zero-`line_accounts` invariant (a real defect — see §7) | Single-side (PHP-only) positive assertion |
| `inbox:next-baseline` | Next's `/inbox` sidebar correctly shows the golden dataset (200-row SSR cap, HERO + 3 badge satellites visible with correct `data-*` attributes) | Single-side (Next-only) positive assertion |
| `inbox-thread:id=7001` | PHP `/inbox-v2.php?user=7001` vs Next `/inbox/7001` — chat header name, message count, all 13 marked message-type/form markers | Normal `runPagePair()` diff |

The first two are genuinely NEW to this harness (no prior batch had a JSON
cursor-pagination contract walk with no PHP side) — see
`infra/e2e/parity.mjs`'s `runConversationsCursorWalk()` /
`runMessagesCursorWalk()` doc comments for the full assertion list each one
runs. The middle two replace what would otherwise be a SIXTH entry
(`inbox:baseline`, a normal page-pair diff) — see §7 for why that shape does
not work here. The last one is an ordinary `runPagePair()` entry, same shape
every phase-2 page uses.

## 4. Golden dataset — `infra/e2e/seed/70-phase4-batch1-inbox-fixture.sql.tmpl`

Reuses the SAME tenant + admin fixture every phase-2 batch already
established (`e2e-parity-harness`, `15-plan-and-tenant.sql.tmpl` +
`20-admin-user.sql.tmpl`) — no new tenant stood up, per the brief's "reuse
unless there's a concrete conflict" instruction; there was none (ID ranges
were chosen to avoid any collision with the existing `30-`/`40-`/`60-`
fixtures — see the file's own header comment).

**215 conversations** (`users` rows with `line_account_id = 1` — see the
fixture's own "LINE_ACCOUNT_ID = 1 ON PURPOSE" note for exactly why this
differs from every earlier batch's `line_account_id IS NULL` convention, and
its "PHP-SIDE ASYMMETRY" note for why that choice ALSO exposed the §7
defect), each with a strictly distinct `last_message_at` (SECOND-level
offsets for HERO, MINUTE-level for everything else) so `ORDER BY last_time
DESC` is fully deterministic on both stacks:

| id | role | what it exercises |
|---|---|---|
| 7001 | **HERO** | messageThread golden dataset — 130 messages, most-recent conversation, zero unread (see below for why) |
| 7002 | tagged | `user_tags.id=1` ('VIP', reused from `30-phase2-batch1-fixture.sql.tmpl`) → `ConversationRow.tags` + sidebar `data-tags="1"` |
| 7003 | assigned (single) | one `conversation_assignments` row → `ConversationRow.assigned_to`/`assignment_status` (JSON-API-only — never rendered in the sidebar markup) |
| 7004 | assigned (multi) | one `conversation_multi_assignees` row → `ConversationRow.assignees` + sidebar `data-assigned="1"` |
| 7005 | pending status | `users.chat_status='pending'` → `ConversationRow.chat_status` + sidebar `data-chat-status="pending"` |
| 7006 | unread | 2 unread incoming + 1 read outgoing → `ConversationRow.unread_count=2` |
| 7007–7215 | filler | 209 plain single-message conversations, pure volume for the cursor walk (>=210 total required by this batch's brief; 215 clears it with margin) |

**Why HERO (7001) has zero unread, and 7006 is never used for a page-pair
fetch**: `(tenant)/inbox/[userId]/page.tsx`'s own module doc documents that
this batch deliberately does NOT reproduce inbox-v2.php's page-load
mark-as-read side effect (`UPDATE messages SET is_read = 1 ...`, line 1123)
yet. Fetching PHP's `/inbox-v2.php?user=N` DOES still run that mutation as a
real side effect of the GET request. Since `inbox-thread:id=7001` fetches
BOTH stacks' thread pages for the SAME conversation (7001), giving HERO any
unread messages would let the PHP-side fetch silently mutate `is_read` out
from under any later assertion on that same conversation — so HERO is
seeded pre-read, and the dedicated "has unread" case (7006) is exercised
ONLY via the JSON conversations-cursor-walk's badge-satellite spot check,
which never fetches a PHP `?user=` page and therefore never mutates
anything.

**130 messages for HERO**, oldest-first, interleaved (not bunched at either
end) so a small-`limit` cursor walk crosses page boundaries both before and
after encountering a marked message. Full inventory in §5.

## 5. Message-type coverage (messageThread's chat pane)

### 5.1 The 13 marked types/forms, including the two CLAUDE.md-list gaps

CLAUDE.md's own architecture table lists the inbox's message-rendering
surface without enumerating every branch `MessageBubble.tsx`/inbox-v2.php's
SSR renderer actually has. Two of those branches are easy to miss reading
the headline docs alone; `MessageBubble.tsx`'s own module doc calls them out
by name, and this fixture/harness deliberately covers both:

- **`location`** — SSR-only (`[location] Address (lat,lng)` format), entirely
  absent from inbox-v2.php's *client-side* `renderSingleMessage()` JS (i.e.
  it only ever renders on first paint, never on a live-appended message).
- **text-content-that-is-actually-a-video-URL** — a `text`-typed message
  whose `content` matches `/uploads/line_videos/` or a
  `.mp4|.mov|.avi|.webm` extension re-renders through the SAME `video`
  branch as a literal `video`-typed message.

Full list, each proven by BOTH the HTML page-pair (`inbox-thread:id=7001`,
substring markers) AND the JSON messages-cursor-walk (`message_type` +
`content` predicates — see `infra/e2e/parity.mjs`'s
`INBOX_MESSAGE_TYPE_CHECKS`):

| Type/form | Fixture content shape | Resolved marker checked |
|---|---|---|
| plain text | `INBOXB1-PLAINTEXT-001 ...` | literal text |
| text w/ embedded quickReply JSON | `{"type":"text","text":"...","quickReply":{"items":[...]}}`  | bubble text + both quick-reply button labels |
| text-that-is-a-video-URL | `/uploads/line_videos/inboxb1-demo-clip.mp4` (`message_type='text'`) | resolved `src="api/line_content.php?id=/uploads/line_videos/..."` |
| image, `ID:N` form | `ID:778899` | `src="api/line_content.php?id=778899"` |
| image, absolute-URL form | `https://picsum.photos/seed/inboxb1demo/400/300` | same URL verbatim in `src` |
| sticker, JSON `stickerId` form | `{"type":"sticker","packageId":"11537","stickerId":"52002734"}` | resolved LINE sticker-shop URL |
| sticker, legacy `'Sticker: N'` text form | `Sticker: 183892` | resolved LINE sticker-shop URL |
| flex bubble | `{"type":"bubble","body":{...text leaf...}}` | bubble's text leaf content |
| flex carousel (>=2 bubbles) | 2-bubble carousel with text/image/separator/button (bubble 1) and text/spacer/button (bubble 2) leaf components | both bubbles' text + both buttons' labels |
| file | `{"name":"INBOXB1-FILE-ใบรับรองยา.pdf","url":"..."}` | file name text |
| video | `https://example-media.invalid/videos/inboxb1-clip2.mp4` (`message_type='video'`) | same URL verbatim in `src` |
| audio | `ID:990011` (`message_type='audio'`) | `src="api/line_content.php?id=990011"` |
| location | `[location] INBOXB1-LOCATION-... (13.7563,100.5018)` | address text + `"13.7563, 100.5018"` |

### 5.2 Flex message rendering asymmetry (read before touching a flex marker)

PHP defers ALL flex-message rendering to a client-side `<script>`
(`renderFlexMessage()`, inbox-v2.php lines 3473-3505) — the raw SSR response
only ever contains `<div class="flex-message-container"
data-flex-content='<escaped JSON>'></div>` plus that inert `<script>` tag;
this fetch-only harness never executes JavaScript, so it never sees the
client-rendered tree. This port renders the SAME tree SERVER-SIDE
(`FlexBubble`/`FlexCarousel`/`FlexText`/`FlexButton`/... — real HTML in the
initial response). The two representations are structurally different (a
raw, `htmlspecialchars()`-escaped JSON string sitting in an attribute vs a
real DOM tree of nested `<div>`s) but BOTH still contain the same literal
Thai/English marker text verbatim, because `htmlspecialchars()` does not
alter non-ASCII text or a quote-free string, and every marker string this
fixture uses is quote-free by construction. A plain substring-inclusion
check against a comment-stripped copy of the HTML (see `extractInboxThreadPage()`'s
own doc in `infra/e2e/lib/extract.mjs`) is therefore the CORRECT level of
assertion for flex content here — not a structural diff, which would need
real JS execution on the PHP side to ever pass and would be testing this
harness's fetch mechanics rather than the product. This is a documented,
deliberate exception in the same family as `runCrmDashboardAdvancedChecks()`'s
500-vs-200 exception, not an extraction gap.

Two extraction bugs this batch's own harness run caught and fixed while
building this section — both were false NEXT-side failures (Next was
correct; the extractor was wrong), not product bugs:

1. **`messageCount` over-counted on the PHP side by exactly 3.**
   inbox-v2.php ALSO embeds three client-side JS templates containing the
   literal string `data-msg-id="${msg.id}"` (a websocket live-append
   handler + its own duplicate-detection `querySelector`, lines
   4780/4815/8625) inside `<script>` blocks. A naive `data-msg-id="` count
   matched those too. Fix: require one-or-more DIGITS inside the quotes
   (`data-msg-id="\d+"`), which only ever matches a REAL rendered bubble on
   either stack.
2. **`"13.7563, 100.5018"` (the location lat/lng line) false-negative on the
   Next side.** React's SSR renderer inserts an empty `<!-- -->` comment
   between adjacent JSX text expressions (`{lat}, {lng}` is two separate
   expressions) as a hydration-boundary marker — the same confirmed
   React-SSR quirk `infra/e2e/lib/extract.mjs`'s own module doc already
   documents for other pages, just newly hit here because THIS is the first
   marker check in this file to span more than one JSX expression. Fix:
   marker checks run against a `<!--...-->`-stripped copy of the HTML.

## 6. `infra/nginx/routes.json`'s `/inbox` entry

Appended, never replacing any existing entry: `upstream: "php_backend"`,
`tenants: "all"` — a schema-valid, functional no-op (php_backend is already
the strangler default). This batch's brief is explicit that this placeholder
must NOT be read as flip-ready: inbox-v2.php exposes ~29 actions total
(send/dispense/tag/note/assign/mark-as-read/AI-copilot/...); this batch
ports exactly 2 READ actions (`getConversations`/`getMessages`). Later
batches land ~5 more actions at a time per the migration plan's phasing.
mig-orchestrator will not start a canary ramp for `/inbox` until enough of
those land to make the Next page a usable PHP replacement, and only
mig-orchestrator — never this batch's executor — decides when that ramp
starts.

## 7. PHP `/inbox-v2.php`'s sidebar is confirmed EMPTY under this harness (a genuine finding, not a fixture bug)

Discovered by this batch's own harness run (`inbox:baseline` originally
failed with `totalUnreadBadge: php=0 next=200` and every known conversation
`visible: php=false next=true`) — not previously flagged by any prior
batch's brief or runbook. Root cause, traced by reading the real PHP source
(not guessed):

1. `inbox-v2.php` line 81: `$currentBotId = $_SESSION['current_bot_id'] ?? 1;`
   — resolves to the literal `1` in this harness (no `line_accounts` rows
   are ever seeded — see §4/every earlier fixture's own "WHY NO
   line_accounts ROWS" reasoning).
2. `inbox-v2.php` line 991: `require_once 'includes/header.php';` — a plain
   TOP-LEVEL include, sharing `inbox-v2.php`'s own global scope (the exact
   same class of bug this harness already documented for
   `line-group-detail.php`'s `$group`-clobbering defect — see
   `LINE_GROUP_DETAIL_EXPECTED_HEADER`'s own doc comment in
   `infra/e2e/parity.mjs`).
3. `includes/header.php` line 174: `$currentBotId = $currentBot['id'] ??
   null;` — UNCONDITIONALLY overwrites the SAME `$currentBotId` variable.
   Since there are no accessible `line_accounts` rows, `$currentBot` is
   never resolved, so this evaluates to `NULL`.
4. `inbox-v2.php` lines 1023-1054 (the conversation-list SQL, which runs
   AFTER header.php) then binds this now-`NULL` value into `u.line_account_id
   = ?` — an EQUALITY test. Per SQL's 3-valued logic, `column = NULL` never
   matches ANY row, including rows whose OWN `line_account_id` is itself
   `NULL` (unlike the `(line_account_id = ? OR line_account_id IS NULL)`
   NULL-tolerant pattern `users.php`/`groups.php`/etc. use instead).

**Net effect**: any tenant/session with zero accessible `line_accounts` rows
— a real, valid production state (any freshly-provisioned tenant before
their first LINE OA is connected) — sees a PERMANENTLY EMPTY `/inbox-v2.php`
LINE-tab conversation list. This is independent of what this fixture seeds;
there is no dataset this harness could add that would make PHP's side
genuinely non-empty without ALSO seeding a `line_accounts` row, which would
retroactively break every EARLIER phase-2/phase-3 batch's own
zero-`line_accounts` invariant (see `30-phase2-batch1-fixture.sql.tmpl`'s
"CURRENT-BOT-ID NOTE" and `60-phase2-batch3-fixture.sql.tmpl`'s "WHY NO
line_accounts ROWS" for why that invariant is deliberately held constant for
the whole shared `phpSid`/`nextSid` session this harness reuses across
every single page-pair, this batch's included).

**Resolution, matching this repo's own established precedent**
(`runCrmDashboardAdvancedChecks()`'s 500-vs-200 exception;
`extractLineGroupDetailHeaderPhpDefect()`'s header-defect exception): `/inbox`
is proven via TWO separate, positively-asserting, single-stack checks
(`runInboxSidebarChecks()` in `infra/e2e/parity.mjs`) instead of a diff —
diffing Next's genuinely-correct sidebar against PHP's genuinely-broken one
would just look like "Next is wrong" and bury the real finding:

- `inbox:php-empty-currentbotid-clobbered` — asserts PHP's sidebar shows
  `totalUnreadBadge=0` AND the "ยังไม่มีแชท" empty-state text, AND that
  none of the four known conversations (HERO/tagged/multiAssignee/
  pendingStatus) appear anywhere in the markup. A future PHP fix (either
  `inbox-v2.php` stops relying on a pre-`header.php` `$currentBotId`, or
  `header.php` stops unconditionally overwriting it) would make THIS
  assertion itself start failing — that failure is the signal to delete
  this exception and switch `/inbox` back to a normal `runPagePair()` diff,
  per the forward-looking error message `runInboxSidebarChecks()` prints.
- `inbox:next-baseline` — asserts Next's `/inbox` sidebar correctly shows
  `totalUnreadBadge=200` and all four known conversations with their
  expected `data-*` attributes, proving the PORT is correct (not "matching
  a broken PHP").

**The thread page (`?user=N` / `/inbox/N`) is UNAFFECTED** — verified by
reading the "Get Selected User" block (inbox-v2.php lines 1104-1167): it
queries `users`/`messages`/`user_tags`/`user_tag_assignments` all by plain
`id`/`user_id`, with NO `line_account_id` filtering anywhere. This is why
`inbox-thread:id=7001` is still a normal, symmetric `runPagePair()` diff.

## 8. What this batch explicitly defers

Cross-checked line-by-line against every "NOT ported" / "OUT OF SCOPE" /
"DEFERRED" module-doc comment actually written into the two builders' own
code (conversationList's `apps/admin/src/app/api/inbox/**`, messageThread's
`apps/admin/src/app/(tenant)/inbox/**`) — this section should read as a
precise starting line for whichever batch picks up the next ~5 actions, not
a guess:

### 8.1 API surface (conversationList)

- **`get_chat_content`** (api/inbox-v2.php) — superseded by Next's native
  navigation to `/inbox/[userId]`; not ported at all. Its **mark-as-read
  side effect** is therefore also not reproduced by the API route (see
  8.2's messageThread entry for the SAME side effect's SSR-path status).
- **ETag / `If-None-Match` / 304 short-circuit caching** — a caching
  optimization, not user-visible behavior; can be added later without
  changing the response contract this batch's routes promise. (Both
  `getConversations` and `getMessages`.)
- **`segment=new_followers`** — the query param is accepted as a no-op
  (falls through to the normal conversations query, not an error); the
  actual VIEW SWITCH to `InboxService::getUncontactedFollowersDelta()` is
  not ported. The new-followers COUNT badge itself IS ported (server-side,
  via `(tenant)/inbox/_lib/filterOptions.ts`'s `countUncontactedFollowers()`)
  — only the toggled list view is deferred; the sidebar's chip renders
  `disabled` with a "เร็ว ๆ นี้" (coming soon) tooltip.
- **One deliberate ADDITION over the literal PHP source, not a
  subtraction**: `/api/inbox/messages` requires a valid tenant session —
  the real `api/inbox-v2.php` performs NO login check at all on this
  action. Left unauthenticated, this NEW Next Route Handler (unlike the
  literal PHP file, which is only reachable through the gated page shell)
  would let anyone read a tenant's conversation history directly. See
  `apps/admin/src/app/api/inbox/messages/route.ts`'s own module doc.

### 8.2 UI surface (messageThread + conversationList sidebar chrome)

- **The page-load mark-as-read side effect**
  (`UPDATE messages SET is_read = 1 WHERE user_id = ? AND direction =
  'incoming' AND is_read = 0`, inbox-v2.php line 1123) — deferred with
  every other mutation; unread badges do not clear on opening a
  conversation in this batch.
- **`allTags`** (every tag available for the tag-picker, DISTINCT from
  `allTagsForFilter`'s sidebar-dropdown query) — no tag-editor UI exists
  yet to read it.
- **HealthEngine profile/classification** (`getHealthProfile()`,
  `classifyCustomer()`) and the resulting **customer-type badge**
  (⚡ Direct / 💝 Concerned / 📊 Detailed) in the chat header.
- **PDPA health-data-consent lookup** (`user_consents` table,
  `consent_type='health_data'`) — feeds the consult HUD, not built.
- **The "ให้แต้ม (ขายหน้าร้าน)" give-points button** (+ its `$pointsPerBaht`
  data) — opens a checkout/points-claim modal, a write flow.
- **Sound-toggle / live-indicator controls** — wired to the websocket
  real-time layer (`websocket-server.js` today; a different stream's
  territory this phase).
- **The `sla-warning` class** (`AnalyticsService::getConversationsExceedingSLA()`)
  — a different service, not part of this batch's read path.
- **The Ghost Draft / HUD / customer-info / โน้ต (notes) / จ่ายยา (dispense)
  / ออเดอร์ (orders) / นัดหมาย (appointments) / สินค้า (products) / ส่งเมนู
  (send menu) chat-workflow-bar buttons** — these, together with
  send/tag/assign/note and everything else, are the ~19 AI-copilot-adjacent
  and general admin actions the plan's "~5 actions at a time" phasing holds
  for later batches.
- **All ~29 total inbox-v2.php actions** beyond the 2 read actions this
  batch ports (`getConversations`/`getMessages`) — send, dispense, tag,
  note, assign, mark-as-read, broadcast-to-conversation, the ~19
  AI-copilot/Ghost-Draft/HUD actions, and everything else in
  `api/inbox-v2.php`'s action switch.
- **Websocket/realtime updates** — new incoming messages / conversation-list
  changes do not push into either the sidebar or an open thread; a page
  refresh is required. Owned by a different stream this phase.

### 8.3 Simplifications (not scope cuts — documented behavior notes)

- **`hasPlatformColumn`** (inbox-v2.php's runtime `SHOW COLUMNS FROM users
  LIKE 'platform'` probe, for degrading to LINE-only on tenants that predate
  `migration_add_platforms`) is not reproduced — `packages/db`'s generated
  `TenantDB` schema already has `users.platform` unconditionally, since it's
  introspected from the current committed tenant template. Not a behavior
  change on any tenant DB created from that template (same simplification
  `(tenant)/users/queries.ts` already documents for its own column-existence
  probes).
- **Single-assignee display name asymmetry** — PHP's SSR-only prefetch joins
  `admin_users` for a real display name in the single-assignee sidebar case;
  this port's canonical `ConversationRow.assignees` shape only carries raw
  `admin_id` integers (matching what the AJAX "load more" path already falls
  back to), so this port always shows the generic "มอบหมายแล้ว" text — a
  known, documented simplification, not a bug (see
  `ConversationListItem.tsx`'s own "KNOWN SIMPLIFICATION" doc).

## 9. Acceptance criteria (mig-verify executes these)

- [ ] `node -e "JSON.parse(require('fs').readFileSync('infra/nginx/routes.json'))"`
      exits `0`; the `/inbox` entry is present with `upstream === 'php_backend'`;
      every pre-existing entry's `upstream` is unchanged.
- [ ] `node infra/e2e/parity.mjs` exits `0` and prints `{"result":"PASS",...}`
      with `inbox-conversations-cursor-walk`, `inbox-messages-cursor-walk`,
      `inbox:php-empty-currentbotid-clobbered`, `inbox:next-baseline`, and
      `inbox-thread:id=7001` all reporting `ok:true`, alongside every
      pre-existing entry from phase 2 batches 1-3 (still `ok:true`,
      unmodified by this batch).
- [ ] Before conversationList's/messageThread's code exists, the SAME command
      fails LOUDLY with a distinct `{ok:false, mismatches:[...]}` entry per
      missing route/page (a 404 from `/api/inbox/conversations`,
      `/api/inbox/messages`, `/inbox`, or `/inbox/7001`) — never a silent
      skip, never a false PASS, never a hang, and teardown still runs.
- [ ] `infra/e2e/seed/70-phase4-batch1-inbox-fixture.sql.tmpl` loads cleanly
      against `database/migration_2026-05-25_tenant_template.sql` with no
      FK/constraint errors, via the SAME `seedDatabase()`-style path
      `infra/e2e/run.mjs` already uses (proven by this harness's own
      `seed_fixture` step, part of every full run above).
- [ ] This document is linked from `infra/nginx/routes.json`'s `/inbox`
      entry's `note` field (done).
