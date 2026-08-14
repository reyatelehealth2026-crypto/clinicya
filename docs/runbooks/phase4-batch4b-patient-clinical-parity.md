# Phase 4 batch 4b — `/inbox` patient/clinical copilot actions

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time"). Owner: mig-api
(patientClinical stream —
`apps/admin/src/app/api/inbox/actions/{medical-history,patient-profile,check-allergy,prescription-history,refill-reminders}/**`
exclusively this round). Cross-reference:
`docs/runbooks/phase4-batch3-inbox-actions-parity.md` (the sibling runbook
whose §1/§2 structure this one follows, and whose "intentional, flagged
deviation from a genuine PHP bug" precedent this batch's four schema-drift
fixes extend) and Phase 4 batch 4a (`claude/phase4-batch4-copilot-data`,
already merged into this worktree's base — the drug-data copilot actions:
`drug-info`/`search-drugs`/`drug-pricing`/`max-discount`/
`drug-pricing-data`/`low-stock-drugs`/`drug-inventory`; batch 4a shipped no
runbook of its own, so this is the first runbook to reference its
`is_prescription` -> `requires_prescription` precedent by name).

## 1. What landed — 5 ported actions, all pure-data (no LLM/AI calls)

All five are literal ports of `api/inbox-v2.php`'s action switch (the same
cursor/AI-copilot API file batch 3 and batch 4a ported from), decomposed
into their own Next.js Route Handlers under
`apps/admin/src/app/api/inbox/actions/**`. Every handler requires a valid
tenant session (any of the six `TenantRole` values) via
`resolveInboxApiContext()` — the same per-route auth gate every prior batch
established.

### 1.1 Alias table — every PHP case label maps to exactly one route

| PHP case labels (`api/inbox-v2.php`) | Route |
|---|---|
| `medical_history`, `medical-history`, `get_medical_history` (lines ~918-946) | `apps/admin/src/app/api/inbox/actions/medical-history/route.ts` |
| `patient_profile`, `patient-profile`, `get_patient_profile` (lines ~952-980) | `apps/admin/src/app/api/inbox/actions/patient-profile/route.ts` |
| `check_allergy`, `check-allergy` (lines ~1088-1120) | `apps/admin/src/app/api/inbox/actions/check-allergy/route.ts` |
| `prescription_history`, `prescription-history` (lines ~1126-1155) | `apps/admin/src/app/api/inbox/actions/prescription-history/route.ts` |
| `refill_reminders`, `refill-reminders`, `get_refill_reminders` (lines ~1410-1438) | `apps/admin/src/app/api/inbox/actions/refill-reminders/route.ts` |

Every case label an action responds to (including every hyphen/underscore
variant and every `get_*` alias) is covered by its route above — no caller
relying on any one of these action-name spellings is orphaned by this port.

### 1.2 Request/response summary

| # | Route | Verb | Query params | Response (`success` semantics) |
|---|---|---|---|---|
| 1 | `medical-history` | `GET` | `user_id` (int, required) | `{success: result.found ?? false, data: result}`, always HTTP 200 |
| 2 | `patient-profile` | `GET` | `user_id` (int, required) | `{success: result.found ?? false, data: result}`, always HTTP 200 |
| 3 | `check-allergy` | `GET` | `user_id` (int, required), `drug_name`\|`drug` (string, first-set-wins, required) | `{success: true, data: result}` — unconditional, NOT tied to `found` |
| 4 | `prescription-history` | `GET` | `user_id` (int, required), `limit` (int, default 20, unclamped) | `{success: true, data: result, count: result.length}` |
| 5 | `refill-reminders` | `GET` | `user_id` (int, required) | `{success: true, data: result}` — **no `count` key** (unlike `prescription-history`; verified directly against the PHP `sendResponse()` call, which has only `success`/`data` here) |

All five reject `POST` with `{success: false, error: 'Method not allowed'}`
at HTTP 405, and all five return `{success: false, error: 'Invalid user ID'}`
at HTTP 400 when `user_id <= 0`. PHP's second, textually-unreachable
`if (!$userId) { sendError('User ID is required'); }` check (present in all
5 case blocks, immediately after the reachable `$userId <= 0` check) is not
ported anywhere in this batch — every value that would make `!$userId` true
is already `<= 0` and would have exited via the first check, so the second
`sendError()` call can never execute in the PHP source either.

### 1.3 Internal call graph (single-owner cross-route imports)

`medical-history/_lib/medicalHistory.ts` and
`prescription-history/_lib/prescriptionHistory.ts` are the CANONICAL,
single-owner implementations of `getUserMedicalHistory()` and
`getUserPrescriptionHistory()` respectively — every other route in this
batch that needs the same data imports the function directly rather than
reimplementing it, the same "same builder, same round" cross-import
precedent Phase 4 batch 4a already established (`drug-info/_lib/drugInfo.ts`
importing `calculateMargin` from `../max-discount/_lib/drugPricingEngine`):

- `check-allergy/_lib/checkAllergy.ts` imports `getUserMedicalHistory` from
  `../../medical-history/_lib/medicalHistory`.
- `patient-profile/_lib/patientProfile.ts` imports `getUserMedicalHistory`
  from `../../medical-history/_lib/medicalHistory` AND
  `getUserPrescriptionHistory` from
  `../../prescription-history/_lib/prescriptionHistory` (called with
  `limit=10`, matching PHP line 851).

`patient-profile/_lib/patientProfile.ts` additionally ports
`getUserTagsAndNotes()`, `checkDrugInteractions()`/`findInteraction()`/
`getHigherSeverity()`/`getSeverityLabel()` (with the `SEVERITY_*`
constants), and `generatePatientWarnings()` — all four are
`PharmacyIntegrationService` methods, but per this batch's brief they are
used HERE purely as private implementation details of
`getComprehensivePatientProfile()` (`checkDrugInteractions` fires only when
`count(currentMedications) > 1`, PHP line 856). They are **not** exposed at
their own route — in particular this is **not** a port of the separate
`check_drug_interactions` action, which remains fully out of scope this
round (see §3).

## 2. Two scoping corrections verified this round

Both corrections were made against a direct re-read of the PHP source
before any code was written, superseding an earlier draft of this batch's
brief that assumed otherwise.

### 2.1 `CustomerHealthEngineService` is not used by any of these 5 actions

`grep -n "CustomerHealthEngineService" api/inbox-v2.php` finds exactly 6
call sites, at lines 345, 379, 409, 1346, 1393, and 1503 — none of them
inside any of this batch's 5 case blocks (918-946, 952-980, 1088-1120,
1126-1155, 1410-1438). `CustomerHealthEngineService` is not ported, imported,
or referenced anywhere under this batch's five route directories.

### 2.2 `refill_reminders` is backed by `DrugRecommendEngineService`, not `PharmacyIntegrationService`

Unlike the other four actions in this batch, `refill_reminders` calls
`DrugRecommendEngineService::getRefillReminders()` (line 399) via
`loadService('DrugRecommendEngineService', ...)` — its own distinct
service-unavailable message is `'Recommendation engine service not
available'` (503), NOT `'Integration service not available'` (503, the
message the other four actions' `loadService('PharmacyIntegrationService',
...)` guard produces). `refill-reminders/route.ts`'s module doc documents
this message; it is not fabricated as a runtime branch (see §4).
`grep -n "curl\|Gemini\|OpenAI\|GeminiAI" classes/DrugRecommendEngineService.php`
returns no matches either, confirming `getRefillReminders()` itself makes no
LLM calls — consistent with this batch's "pure-data" framing.

## 3. Four confirmed schema-drift fixes (A-D)

Each fix was verified by cross-checking every column
`classes/PharmacyIntegrationService.php`'s SQL touches against
`packages/db/src/generated/tenant-db.d.ts` (the live source of truth for
what actually exists on tenant DBs today). Each currently makes production
silently degrade or fail, and each follows the same fix-forward precedent
Phase 4 batch 4a already established for `is_prescription` ->
`requires_prescription` (`docs/runbooks/phase4-batch3-inbox-actions-parity.md`
§1's "intentional, flagged deviation from a genuine PHP bug" framing) — the
real column is aliased back to the PHP-era key name wherever the fix touches
a `SELECT` list, so the JSON response shape is unchanged.

### Fix (A) — `users.birth_date` does not exist; the real column is `birthday`

`birth_date` is an `admin_users`-only column (added by
`install/run_admin_user_fields_migration.php`, an internal-staff field).
The customer-facing `users` table instead has `birthday` (used everywhere
else in this codebase for this purpose — `users.php`, `user-detail.php`,
`api/member.php`, `classes/AutoTagManager.php`,
`modules/PDPA/Services/DataRightsService.php`) and a separate
`date_of_birth` column used only by the unrelated `retail-api/` per
CLAUDE.md.

**Before**: `PharmacyIntegrationService::getUserMedicalHistory()`'s
`SELECT ... birth_date ...` throws `Unknown column` on every real call,
caught by its own `catch (PDOException $e)`, which returns `found: false`
with an `error` string. Because `medical_history`'s `success` field is
derived from `result.found`, this means `medical_history`,
`patient_profile`, and `check_allergy` (which all depend on
`getUserMedicalHistory()`, directly or transitively) **always** returned a
degraded, `success: false` result in production — regardless of whether the
user actually exists.

**After**: `medical-history/_lib/medicalHistory.ts`'s query selects
`birthday AS birth_date`, so the row-shaping/age-calculation code is
otherwise unchanged and the response's `age`/`weight`/`height`/etc. fields
are now populated with real data.

### Fix (B) — `users.chronic_diseases` does not exist on tenant DBs (dropped from SELECT)

`chronic_diseases` IS defined in the legacy `database/install_complete.sql`
/ `database/schema_complete.sql` dumps — this is a template-vs-live drift,
not a nonexistent concept — but it has no interface property in
`packages/db/src/generated/tenant-db.d.ts`'s `Users` type. An in-repo
precedent already treats the same concept as sourced from
`medical_conditions` when a dedicated column isn't available:
`includes/ai-chat-context.php` line 124 sets `$ctx['chronic_diseases']`
directly from `$user['medical_conditions']`.

**Before**: combined with fix (A), the unfixed `SELECT ... birth_date, ...,
chronic_diseases, ...` throws regardless of which of the two unknown
columns is hit first — both had to be fixed together for the query to
succeed at all.

**After**: `chronic_diseases` is dropped from the SELECT entirely.
`getUserMedicalHistory()`'s PHP `conditions` field was
`array_unique(array_merge(parseTextToArray($chronic_diseases),
parseTextToArray($medical_conditions)))`; with `chronic_diseases` removed,
this degrades gracefully to a deduplicated `parseTextToArray($medical_conditions)`
alone — strictly better than today's total failure (an empty `conditions: []`
on every call).

### Fix (C) — `customer_notes.note_type` does not exist anywhere (dropped from SELECT)

Verified genuinely absent — not merely drifted — from
`packages/db/src/generated/tenant-db.d.ts` and every committed SQL file.
`PharmacyIntegrationService::getUserTagsAndNotes()`'s `customer_notes`
query is wrapped in its OWN local `catch (PDOException $e)`, independent
from the `user_tags`/`user_tag_assignments` query's try/catch in the same
method — so this bug affects only the `notes` half of `patient_profile`'s
response; the `tags` half (`user_tags`/`user_tag_assignments`, columns
`id`/`name`/`color`/`description`/`tag_id`/`user_id`, all confirmed present)
is untouched.

**Before**: the `customer_notes` SELECT throws on every call, silently
degrading `notes` to `[]` in every `patient_profile` response.

**After**: `patient-profile/_lib/patientProfile.ts`'s `getUserTagsAndNotes()`
drops `note_type` from the SELECT (`SELECT id, note, created_at, created_by
FROM customer_notes ...`), so real notes are now returned.

### Fix (D) — `business_items.is_prescription` does not exist; the real column is `requires_prescription` (SELECT *and* WHERE)

Same confirmed root cause as Phase 4 batch 4a's `drug-inventory`/
`low-stock-drugs` fix. `getUserPrescriptionHistory()`'s query is the one
place in this batch where the nonexistent column appears **twice** — once
in the SELECT list (`bi.is_prescription`) and once in the WHERE clause
(`bi.is_prescription = 1 OR bi.drug_category IN (...)`). Both had to be
fixed together: correcting only the WHERE clause while leaving the SELECT's
reference to the nonexistent column in place would still make the entire
query throw on every call, leaving the action exactly as broken as before.

**Before**: the unfixed query throws `Unknown column` on every call,
caught by `getUserPrescriptionHistory()`'s own `catch (PDOException $e)`,
which returns a bare `[]`. Since `prescription_history`'s
`sendResponse(['success' => true, 'data' => $result, 'count' =>
count($result)])` has no `found`/error check, this action **always**
returned `{success: true, data: [], count: 0}` in production, even when the
user has real dispensed-drug transactions. This transitively also broke
`patient_profile`'s `prescriptionHistory` field (both actions share this
one function).

**After**: `prescription-history/_lib/prescriptionHistory.ts` selects
`bi.requires_prescription AS is_prescription` (SELECT, alias preserves the
output key name) and filters on `bi.requires_prescription = 1` (WHERE, no
aliasing needed/possible for a filter condition) — both actions now return
real prescription data.

### `refill_reminders` has no schema drift — verified, not merely assumed

`refill_reminders`'s own SQL
(`classes/DrugRecommendEngineService.php::getRefillReminders()`, lines
412-434 — columns `bi.id`/`name`/`sku`/`price`/`stock`/`image_url`/
`category_id`, `t.id`/`user_id`/`status`/`created_at`,
`ti.transaction_id`/`product_id`/`quantity`, `ic.id`/`name`) touches no
column affected by any of fixes (A)-(D); every one of those columns is
confirmed present in `packages/db/src/generated/tenant-db.d.ts`. This is a
purely literal port, no fix-forward deviation — see
`refill-reminders/_lib/refillReminders.ts`'s module doc.

## 4. "Integration/Recommendation engine service not available" 503 — documented, not implemented

PHP's `loadService()` guard (`if (!$integration) { sendError('Integration
service not available', 503); }` / the `DrugRecommendEngineService`
equivalent for `refill_reminders`) does a runtime `file_exists()`/
`class_exists()` probe with no Next analogue — a static TypeScript import
either compiles and is present in the built bundle, or the build fails
outright. Same reasoning as Phase 4 batch 4a's
`max-discount/_lib/drugPricingEngine.ts` module doc. None of this batch's 5
routes fabricate a runtime 503 branch for "service unavailable"; each
route's own module doc documents the PHP message text instead.

Separately: every one of this batch's 5 case blocks has **no case-level
try/catch** in `api/inbox-v2.php`'s switch (unlike e.g. `drug_info`'s own
`catch (PDOException $e)`). A genuinely unexpected error here would fall
through to the outer `catch (Throwable $e)` (line ~3553), producing
`Internal server error: <message, truncated to 200 chars>...` at HTTP 500 —
a DIFFERENT shape than `drug_info`'s own catch produces. Following the
house precedent Phase 4 batch 4a's `low-stock-drugs`/`drug-inventory`
already established for this identical no-case-catch situation, every route
in this batch uses the uniform `'Database error: {message}'` shape for this
defensive branch instead, for consistency across the whole
`api/inbox/actions/*` family. In practice this branch is unreachable in
every one of this batch's 5 routes: every `_lib` function this batch ports
has its own internal `catch (PDOException $e)`-equivalent (a literal port
of the PHP method's own swallow-to-degraded-result behavior) and never
throws — so, matching the identical precedent already set by
`../low-stock-drugs/route.test.ts` and `../drug-inventory/route.test.ts`
(Phase 4 batch 4a, structurally the same situation), none of this batch's 5
`route.test.ts` files contain a "Database error: ..." 500 test; each
documents why inline instead.

## 5. `medical-history` vs. the pre-existing `medical` route — do not conflate

`apps/admin/src/app/api/inbox/actions/medical-history/**` (this batch, new)
and `apps/admin/src/app/api/inbox/actions/medical/**` (Phase 4 batch 2's
`save_medical` action, pre-existing, untouched by this batch) are two
**unrelated** actions that happen to share a name prefix:

- `medical` (batch 2) ports `inbox-v2.php`'s same-page AJAX `save_medical`
  case — a `POST` write action that updates a user's medical fields
  (`drug_allergies`/`chronic_diseases`/`current_medications`/
  `medical_conditions`) directly on the `users` table.
- `medical-history` (this batch) ports `api/inbox-v2.php`'s cursor/copilot
  API `medical_history`/`get_medical_history` case — a `GET` read action
  that returns a structured medical-history summary
  (`PharmacyIntegrationService::getUserMedicalHistory()`).

Different source files (`inbox-v2.php` vs. `api/inbox-v2.php`), different
HTTP verbs, different backing logic. This batch does not read, edit, or
otherwise touch `apps/admin/src/app/api/inbox/actions/medical/**`.

## 6. Deferred scope

Out of scope for this batch, unchanged from prior batches' own deferred
lists (`phase4-batch1-inbox-reads-parity.md` §8,
`phase4-batch3-inbox-actions-parity.md` §3): `check_drug_interactions` as
its own route (its underlying method is ported ONLY as `patient-profile`'s
private internal dependency, per §1.3 — it is never exposed at its own
path), `analyze_symptom`/`analyze_drug`/`analyze_prescription`,
`ghost_draft`/`learn_draft`/`draft_style`,
`classify_customer`/`customer_health`, `recommendations`,
`safe_alternatives`, `context_widgets`, `consultation_stage`,
`quick_actions`, `detect_urgency`, `analytics`/`record_analytics`,
`save_pending_order`, `customer_crm`,
`add_customer_note`/`remove_customer_note`/`add_customer_tag`/
`remove_customer_tag`, `drug_card`, `validate_recommendation`, `poll`, and
`dispense` (owned by a sibling stream this round, per this batch's brief).

## 7. How to run each route's own test suite locally

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/medical-history
npx jest src/app/api/inbox/actions/patient-profile
npx jest src/app/api/inbox/actions/check-allergy
npx jest src/app/api/inbox/actions/prescription-history
npx jest src/app/api/inbox/actions/refill-reminders
```

Or all five at once:

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/medical-history src/app/api/inbox/actions/patient-profile src/app/api/inbox/actions/check-allergy src/app/api/inbox/actions/prescription-history src/app/api/inbox/actions/refill-reminders
```

`cd apps/admin && npm run lint` (`tsc --noEmit -p tsconfig.json`) passes
with zero errors across all new/changed files — this also structurally
validates every Kysely/raw-`sql` table and column reference used compiles
against `packages/db/src/generated/tenant-db.d.ts`, the strongest available
proof fixes (A)-(D) reference real columns. (Building
`@reya/config`/`@reya/db`/`@reya/auth`/`@reya/tenant`/`@reya/contracts` via
`pnpm --filter <pkg> run build` first is required for `tsc` to resolve
their `dist/*.d.ts` — jest resolves the same workspace packages straight
from source instead, via `apps/admin/jest.config.js`'s `moduleNameMapper`,
so `npx jest` never needs this build step.)

## 8. Acceptance criteria (mig-verify executes these)

- [ ] `cd apps/admin && npx jest src/app/api/inbox/actions/medical-history
      src/app/api/inbox/actions/patient-profile
      src/app/api/inbox/actions/check-allergy
      src/app/api/inbox/actions/prescription-history
      src/app/api/inbox/actions/refill-reminders` — all pass.
- [ ] `cd apps/admin && npm run lint` — zero errors.
- [ ] `grep -R "is_prescription\|chronic_diseases\|note_type\|'birth_date'"
      apps/admin/src/app/api/inbox/actions/{medical-history,patient-profile,check-allergy,prescription-history,refill-reminders}`
      returns no actual query-building reference to any of the four
      nonexistent columns (alias-target mentions like `AS birth_date` /
      `AS is_prescription`, and doc-comment/test-fixture mentions, are
      expected and fine — see §3's fix write-ups).
- [ ] `refillReminders.ts`'s day-math: `lastPurchaseDate = now - 23 days`,
      default-category duration 30 => `daysUntilRefill` is exactly `7`,
      `status: 'due'`, `urgency: 'normal'` (per the literal PHP thresholds
      — `overdue` only if `< 0`, `medium` only if `<= 3`, else `normal`;
      7 is neither, so `normal` — see the dedicated comment on this exact
      test case in `refill-reminders/route.test.ts` for why an earlier
      draft of this batch's own brief text asserted `'medium'` for this
      fixture and why that was not followed).
- [ ] This document (`docs/runbooks/phase4-batch4b-patient-clinical-parity.md`)
      exists, contains the full 5-row alias table (§1.1), and is a
      genuinely new file — `git diff` shows no other file under
      `docs/runbooks/` modified.
- [ ] `git diff --stat origin/main` (or the batch 4a base branch) shows
      changes confined to
      `apps/admin/src/app/api/inbox/actions/{medical-history,patient-profile,check-allergy,prescription-history,refill-reminders}/**`
      and this runbook — no edits to any other `actions/**` route
      (in particular `medical/**`, §5), `apps/admin/src/nav/manifest.ts`,
      `infra/nginx/routes.json`, or any `docs/runbooks/phase4-batch{1,2,3}-*.md`
      file.
