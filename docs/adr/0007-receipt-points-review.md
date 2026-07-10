# ADR-007: Receipt Points Review Admin Page

**Status:** Accepted (2026-07-10)
**Deciders:** Engineering (design brainstormed with tenant-0001 owner)
**Feature area:** Loyalty / receipt-photo OCR points claims (`receipt_point_claims` — distinct from the QR/phone counter-sale `points_claims` system)

---

## Context

Customers earn loyalty points by photographing a purchase receipt and
sending it via LINE. The system OCRs the receipt (Gemini Vision, with an
OCR.Space fallback) and auto-awards points when confident. When OCR
fails or confidence is low, the claim is written to
`receipt_point_claims` with `status = 'pending_review'`, the customer is
told "our team will review later," and an admin-only Flex card is
buried in that customer's chat thread in the general inbox.

**There is no admin page for this today.** Pending claims are a dead
end: no list, no badge, no notification, no way to award points from
the claim record, and no code path ever changes `status` after insert.
Additionally, auto-approved claims never save the receipt image at all
(only pending ones do), and the OCR-read amount for a *failed* claim is
shown transiently in a LINE message but never persisted to the
database — so today there is no way to see what OCR actually read, or
why it wasn't good enough, for a stuck claim.

Relevant existing code (as of this ADR):
- `webhook.php:5273-5417` — `handleReceiptPointsClaim()`, the
  synchronous auto-approve/reject-to-review path.
- `webhook.php:5202-5271` — `recordPendingReceiptPointClaim()`.
- `webhook.php:5173-5200` — `ensureReceiptPointClaimsTable()`, which
  adds `status`/`image_hash`/`image_path` to `receipt_point_claims` at
  runtime — **not** in the committed migration
  `database/migration_2026-06-29_receipt_point_claims.sql`.
- `classes/GeminiAI.php:741-824` — `analyzeReceiptImage()` (Gemini
  Vision), with `analyzeReceiptImageWithOcrSpace()`
  (`classes/GeminiAI.php:281-361`) as an in-code fallback that in
  practice always runs against OCR.Space's public demo key
  (`OCR_SPACE_API_KEY` is never defined anywhere in the codebase).
- `classes/LoyaltyPoints.php:41-48,171-200` — `calculatePoints()` /
  `addPoints()`, the shared ledger every points source writes to.

### Goal

Build an admin page where a pharmacist/shop owner reviews receipt-point
claims once a day: see the receipt photo, the points the system added
(or would add), what OCR read and how confident it was, and manually
finish off claims OCR couldn't resolve — with **the admin page itself
acting as the final fallback** when automated OCR isn't enough (no
second AI/OCR service, no automated daily cron).

### Non-goals

- No automated daily batch job that re-runs OCR or auto-awards points.
  Review is a manual, admin-triggered daily habit.
- No fix to the existing OCR.Space fallback (still uses the
  "helloworld" demo key) — the admin page is the intended fallback of
  last resort, not a better automated OCR.
- No "reject" action / no rejection notification to the customer.
  Claims the pharmacist doesn't want to award simply stay unresolved in
  the list.
- No raw OCR response/text storage — only the read amount, a confidence
  label, and a short failure-reason code.

### Approaches considered

| Approach | Description | Verdict |
|---|---|---|
| A. Minimal `webhook.php` edits + new admin page | Extend the existing claim-recording functions to persist what's missing; new page reads/acts on the table | **Chosen** — lowest risk, smallest diff |
| B. Extract claim logic into a service class first | Refactor `handleReceiptPointsClaim()` et al. out of `webhook.php` into `classes/ReceiptPointsService.php` | Rejected — unjustified refactor risk to a live, high-traffic LINE webhook entry point for a feature that doesn't require it |
| C. New admin page only, don't touch `webhook.php` | Read/act on the table as-is | Rejected — doesn't satisfy the agreed requirement to persist the image for every claim and the OCR read-amount/confidence/reason for failed ones, since that data isn't captured today |

---

## Decision

Implement Approach A.

### 1. Schema changes

New migration `database/migration_2026-07-10_receipt_points_review.sql`,
idempotent (`SHOW COLUMNS` guarded, matching the existing
`ensureReceiptPointClaimsTable()` style):

- Formally add `status` / `image_hash` / `image_path` if not already
  present (folds the runtime-only columns into a real migration).
- `ocr_amount DECIMAL(10,2) DEFAULT NULL` — the OCR-read total even
  when unverified/low-confidence (currently only shown in a transient
  LINE message, never persisted).
- `confidence VARCHAR(20) DEFAULT NULL` — one of `high` / `low` /
  `unverified` / `none` (`none` = OCR returned nothing at all).
- `fail_reason VARCHAR(50) DEFAULT NULL` — short code: `no_ocr_result`,
  `zero_amount`, `low_confidence`. NULL for approved claims.
- `reviewed_by INT DEFAULT NULL`, `reviewed_at DATETIME DEFAULT NULL` —
  set when an admin manually awards a pending claim.
- `image_path` becomes populated for **every** claim (approved or
  pending), not just pending ones — see below.

New runner
`install/migrate_all_tenants_receipt_points_review.php`, following the
established pattern
(`install/migrate_all_tenants_payment_slips_verification.php`,
`install/migrate_all_tenants_flex_studio.php`): enumerate
`zrismpsz_reya_t_%` + the legacy DB via the platform DB, apply
idempotently, report per-DB status.

### 2. `webhook.php` changes

All changes are localized to the existing receipt-claim functions; no
restructuring of surrounding webhook logic.

- **`handleReceiptPointsClaim()`** (auto-approve path): currently never
  saves the image. Add the same save-to-disk step already used by
  `recordPendingReceiptPointClaim()`
  (`uploads/receipt-claims/{Y}/{m}/{hash}.{ext}`, sha256-named) so
  `image_path`/`image_hash` are populated on the `INSERT INTO
  receipt_point_claims` for approved claims too. `confidence` is
  written as `'high'` (the gate that let it auto-approve);
  `fail_reason` stays NULL.
- **`recordPendingReceiptPointClaim()`**: already saves the image;
  additionally persist `ocr_amount` (the read total, nullable),
  `confidence` (`'low'`/`'unverified'`/`'none'` depending on which
  failure branch of `handleReceiptPointsClaim()` reached it), and
  `fail_reason` (`no_ocr_result`, `zero_amount`, `low_confidence`
  respectively).
- **New file `classes/ReceiptPointsAdmin.php`**, containing only
  `awardPendingReceiptClaim(PDO $db, int $claimId, int $lineAccountId,
  int $points, string $description, int $adminUserId): array` — the
  admin-page award action:
  1. Loads the claim row, verifies `status === 'pending_review'` and
     `line_account_id` matches the current tenant (defense in depth
     alongside the admin page's own tenant scoping).
  2. `LoyaltyPoints::addPoints($claim['user_id'], $points, 'receipt',
     $claimId, $description)`.
  3. `UPDATE receipt_point_claims SET status='approved',
     points_awarded=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?`.
  4. Sends the customer the same `buildReceiptPointsFlex()`-style LINE
     confirmation already used on the auto-approve path, via
     `LineAPI::pushMessage()` — a push, not a reply, since the original
     reply token is long expired by the time a claim is reviewed a day
     later.
  5. Returns a small result array for the admin page's AJAX handler.

  This lives in its own narrow `classes/` file — not folded into
  `webhook.php` (which executes top-level request-dispatch code on
  include, so it can't just be `require_once`d from an admin page) and
  not a broader service-class refactor of the whole claim flow (out of
  scope per Approach A). `webhook.php` `require_once`s it once,
  matching the existing `file_exists()`-guarded optional-class
  convention used for `BusinessBot`/`WebSocketNotifier`.

### 3. New admin page: `receipt-points-review.php`

Standard admin page template (`config.php` → `database.php` →
`header.php` → page body → `footer.php`), same-page POST AJAX gated on
`$_SERVER['REQUEST_METHOD'] === 'POST' &&
isset($_SERVER['HTTP_X_REQUESTED_WITH'])`, matching the
`inbox-v2.php`/`messages.php` convention.

**List view** (default = `status = 'pending_review'`, newest first):
- Filter controls: status (pending / approved / all), date range.
- Each row: thumbnail of the receipt photo (click to view full-size),
  customer name, submitted-at timestamp, `ocr_amount` (or "อ่านไม่ได้"
  if NULL), `confidence` badge, `fail_reason` in plain Thai (e.g.
  "จำนวนเงินไม่ตรงกับยอดรวม"), and for approved rows `points_awarded` +
  `reviewed_by`/`reviewed_at`.
- Pending rows only: a points input, pre-filled via
  `LoyaltyPoints::calculatePoints($claim['ocr_amount'])` when
  `ocr_amount` is present (editable — the pharmacist can override), and
  an "อนุมัติ" button that POSTs to the AJAX handler, which calls
  `awardPendingReceiptClaim()`. No reject button (per non-goals).

**Navigation:** added to `includes/header.php`'s menu registry
(matching the `flex-studio` entry pattern), grouped near
`loyalty-members.php` under the existing loyalty/membership section.

**Tenant scoping:** identical to every other admin page — relies on
`TenantContext`/session `current_bot_id`; all queries filtered by
`line_account_id`.

### 4. Testing

Same verification discipline already established for this codebase's
prod-deploy process:
1. `php -l` every changed/new file in a staging copy on the server
   before touching the live files.
2. Empirical probes via a throwaway PHP script over SSH — construct a
   fake pending claim, call `awardPendingReceiptClaim()`, confirm
   `points_transactions` gets the row, `receipt_point_claims.status`
   flips to `approved`, and no exception is thrown for a tenant with
   normal `points_settings`.
3. Run the new `migrate_all_tenants_receipt_points_review.php` runner
   against all tenant DBs and confirm 0 failures.
4. Manual browser check of `receipt-points-review.php`: load the page,
   confirm no PHP warnings/errors, confirm the list renders, confirm
   the approve flow works end-to-end against a seeded test claim.
5. Full-file backup (`.bak-<timestamp>`) before every prod overwrite.
6. Explicit confirmation before each prod deploy step (files, then
   migration runner).

---

## Consequences

**Positive:**
- Pending receipt-point claims stop being a silent dead end; there's a
  visible daily queue with enough diagnostic context (read amount,
  confidence, reason) to act on without re-opening the original LINE
  chat thread.
- `receipt_point_claims`'s schema drift (runtime-only columns) gets
  formalized into a real, all-tenant migration, closing a gap flagged
  in `CLAUDE.md`'s own guidance against the auto-create-on-page-load
  pattern.
- Every claim — not just stuck ones — now has its receipt image on
  file, which also gives the shop an audit trail for approved claims.

**Negative / accepted trade-offs:**
- Auto-approved claims now write an extra file to disk on every
  successful webhook request (previously zero I/O beyond the DB
  insert) — a minor latency/storage cost accepted for the audit-trail
  benefit.
- The OCR.Space fallback remains effectively non-functional (demo key).
  Claims it can't resolve still land on a human being once a day, by
  design — not a regression, but explicitly not fixed here.
- No automated recovery path exists for claims a pharmacist never gets
  to — they simply persist as `pending_review` indefinitely. Acceptable
  given the "human review is the fallback" decision, but worth
  revisiting if the queue grows unbounded in practice.

**Open items to verify during implementation (not blocking design
approval):**
- `LineAPI::pushMessage()`'s exact signature, to confirm the push-vs-reply
  distinction holds as described.
- The admin-user-identity convention (`reviewed_by`) must match
  whatever `includes/header.php`'s auth already exposes
  (`$currentUser['id']` or equivalent) — no new auth mechanism.
- Pre-existing stuck `pending_review` rows (created before this ADR)
  will have `ocr_amount`/`confidence`/`fail_reason` all NULL — the
  admin page must render that gracefully ("ไม่มีข้อมูล"), not backfill
  data that was never captured.
