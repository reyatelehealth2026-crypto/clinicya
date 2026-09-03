# Phase 4 batch 6 — `/inbox` consultation-intelligence copilot actions

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time"). Owner: mig-api
(consultationWidgets stream —
`apps/admin/src/app/api/inbox/actions/{context-widgets,consultation-stage,quick-actions,detect-urgency}/**`
exclusively this round). Cross-reference:
`docs/runbooks/phase4-batch4b-patient-clinical-parity.md` (the sibling
runbook whose §1.1 alias-table format and overall structure this one
follows) and `docs/runbooks/phase4-batch3-inbox-actions-parity.md` §1
("intentional, flagged deviation from a genuine PHP bug" framing, though
this batch found **no** schema drift to fix — see §3). The sibling
`interactionsAndAlternatives` builder ported
`apps/admin/src/app/api/inbox/actions/{check-interactions,check-drug-interactions,suggest-alternatives,safe-alternatives,validate-recommendation,recommendations,drug-card}/**`
this same round — see §4 for the one deliberate duplication between the two
builders' work.

## 1. What landed — 4 ported actions, all backed by `ConsultationAnalyzerService` only

All four are literal ports of `api/inbox-v2.php`'s action switch (the same
cursor/AI-copilot API file batches 3, 4a, and 4b ported from), decomposed
into their own Next.js Route Handlers under
`apps/admin/src/app/api/inbox/actions/**`. Every handler requires a valid
tenant session (any of the six `TenantRole` values) via
`resolveInboxApiContext()` — the same per-route auth gate every prior batch
established. Confirmed (via `grep -n
"PharmacyIntegrationService\|DrugPricingEngineService\|DrugRecommendEngineService\|CustomerHealthEngineService"
classes/ConsultationAnalyzerService.php`, zero matches) that this entire
method family — `detectStage()`, `getContextWidgets()`, `getQuickActions()`,
`detectUrgency()`, and every private helper they call — touches no other
service class. `ConsultationAnalyzerService` itself makes no LLM/Odoo calls
either (`grep -n "curl\|Gemini\|OpenAI\|GeminiAI\|OdooAPIClient"
classes/ConsultationAnalyzerService.php` — no matches).

### 1.1 Alias table — every PHP case label maps to exactly one route

| PHP case labels (`api/inbox-v2.php`) | Backing method (`classes/ConsultationAnalyzerService.php`) | Route |
|---|---|---|
| `context_widgets`, `context-widgets`, `get_context_widgets` (lines ~1521-1564) | `getContextWidgets()` (432-488, private helpers 494-1107 + 1339-1403) | `apps/admin/src/app/api/inbox/actions/context-widgets/route.ts` |
| `consultation_stage`, `consultation-stage`, `detect_stage` (lines ~1570-1598) | `detectStage()` (116-153, private helpers 161-417) | `apps/admin/src/app/api/inbox/actions/consultation-stage/route.ts` |
| `quick_actions`, `quick-actions`, `get_quick_actions` (lines ~1604-1642) | `getQuickActions()` (1419-1627) | `apps/admin/src/app/api/inbox/actions/quick-actions/route.ts` |
| `detect_urgency`, `detect-urgency` (lines ~1648-1675) | `detectUrgency()` (1638-1728, `getUrgencyLabel()` 1735-1744) | `apps/admin/src/app/api/inbox/actions/detect-urgency/route.ts` |

Every case label an action responds to (including every hyphen/underscore
variant and every `get_*`/`detect_*` alias) is covered by its route above —
no caller relying on any one of these action-name spellings is orphaned by
this port.

### 1.2 Request/response summary

| # | Route | Verb | Query params | Response (`success` semantics) |
|---|---|---|---|---|
| 1 | `context-widgets` | `GET` | `user_id` (int, required); `message` (string, optional — `empty($message)` short-circuits to `{widgets: [], count: 0}` at 200, NOT an error) | `{success: true, data: {widgets, count}}` — unconditional |
| 2 | `consultation-stage` | `GET` | `user_id` (int, required) | `{success: true, data: stageResult}` — unconditional. **Has a DB WRITE side effect** (see §2) |
| 3 | `quick-actions` | `GET` | `user_id` (int, required); `stage` (string, optional — empty triggers a `detectStage()` call, itself a DB write, see §2); `has_urgent` (`FILTER_VALIDATE_BOOLEAN`-style, default `false`) | `{success: true, data: actionsResult}` — unconditional |
| 4 | `detect-urgency` | `GET` | `user_id` (int, required) | `{success: true, data: urgencyResult}` — unconditional. Response shape has **two variants** (see §2) |

All four reject `POST` with `{success: false, error: 'Method not allowed'}`
at HTTP 405, and all four return `{success: false, error: 'Invalid user ID'}`
at HTTP 400 when `user_id <= 0`. PHP's second, textually-unreachable
`if (!$userId) { sendError('User ID is required'); }` check (present in all
4 case blocks, immediately after the reachable `$userId <= 0` check) is not
ported anywhere in this batch — every value that would make `!$userId` true
is already `<= 0` and would have exited via the first check, so the second
`sendError()` call can never execute in the PHP source either. Same
precedent as every prior batch's runbook.

### 1.3 Internal call graph — the ONE specified cross-import

`consultation-stage/_lib/consultationStage.ts` exports `detectStage()` as
the CANONICAL, single-owner implementation. `quick-actions/route.ts` imports
it directly (`from '../consultation-stage/_lib/consultationStage'`) to
reproduce `api/inbox-v2.php` lines ~1625-1629 (`if (empty($stage) &&
$userId) { $stageResult = $consultationAnalyzer->detectStage($userId); ... }`)
— the same "same builder, same round" cross-import precedent Phase 4 batch
4a established (`drug-info/_lib/drugInfo.ts` importing `calculateMargin`
from `../max-discount/_lib/drugPricingEngine`) and batch 4b reused
(`check-allergy`/`patient-profile` importing from `medical-history`).

This is the **only** cross-import within this batch. Every other PHP
constant/helper the four actions happen to share — `$urgentKeywords` (used
by both `detectStage()`'s `hasUrgentSymptoms()` helper and
`detectUrgency()` itself), `getRecentMessages()`, `getStageLabelTh()` — is
kept as an independent, duplicated copy per route, matching this codebase's
established `session.ts`/`testHelpers/fakeTenantDb.ts` per-route
duplication convention (every `api/inbox/actions/*` sibling already
duplicates those two files rather than importing them).

## 2. Two write/shape subtleties verified this round

### 2.1 `consultation-stage` (and transitively `quick-actions`) has a real DB WRITE side effect

`detectStage()`'s private `saveStage()` helper
(`classes/ConsultationAnalyzerService.php` lines 341-364) issues:

```sql
INSERT INTO consultation_stages (user_id, stage, confidence, signals, has_urgent_symptoms, updated_at)
VALUES (?, ?, ?, ?, ?, NOW())
ON DUPLICATE KEY UPDATE
    stage = VALUES(stage), confidence = VALUES(confidence), signals = VALUES(signals),
    has_urgent_symptoms = VALUES(has_urgent_symptoms), updated_at = NOW()
```

on every call that finds at least one message (i.e. every call except the
0-messages short circuit). This is a `GET` action with a write — it is
**not** dropped or converted to a read-only stub. Ported via a raw
Kysely `sql` tagged template (`consultation-stage/_lib/consultationStage.ts`),
not `.insertInto().onDuplicateKeyUpdate()`, so the exact column list is
asserted directly against the fake pool's captured SQL/params in
`consultation-stage/route.test.ts` — in particular, **no `line_account_id`
column** in the INSERT, even though `ConsultationStages` (see
`packages/db/src/generated/tenant-db.d.ts`) declares that column
`Generated<number>` (has a DB-side default): the PHP SQL genuinely omits it,
and this port does not add one. `saveStage()` never throws — write failures
are swallowed via PHP's own `catch (PDOException $e) { error_log(...); }`,
ported the same way.

Because `quick-actions` calls `detectStage()` whenever `stage` is empty, a
`GET /api/inbox/actions/quick-actions?user_id=42` with no `stage` param also
triggers this same write, transitively. `quick-actions/route.test.ts`
exercises the "stage explicitly provided -> `detectStage()` (and therefore
the write) never runs at all" branch explicitly (asserts zero DB queries).

### 2.2 `detect-urgency`'s two response shapes, and the exact critical-keyword subset

`detectUrgency()`'s 0-messages short circuit returns **only 4 keys**
(`needsReferral`, `reason`, `urgency`, `detectedKeywords` — no
`urgencyLabel`, no `recommendation` at all, not even as `null`); the full
path always returns all 6 keys. Ported as a discriminated union
(`UrgencyResultShort`/`UrgencyResultFull`) in
`detect-urgency/_lib/detectUrgency.ts`, and `route.test.ts` asserts the
short-circuit response has exactly the 4-key shape via `toEqual` plus
explicit `not.toHaveProperty()` checks on the two omitted keys.

The `criticalKeywords` list used inside `detectUrgency()` (lines 1672-1676)
is a **strictly shorter, hand-picked subset** of the full 46-entry
`$urgentKeywords` list (13 entries: `หายใจลำบาก`, `หายใจไม่ออก`,
`แน่นหน้าอก`, `เจ็บหน้าอก`, `ชัก`, `หมดสติ`, `เลือดออก`,
`อาเจียนเป็นเลือด`, `difficulty breathing`, `cant breathe`, `chest pain`,
`seizure`, `unconscious`) — notably `เลือดไหล` and `chest tight` are in the
full list but **not** critical.  `route.test.ts` has a dedicated test
(`เลือดไหล` alone -> `moderate`, not `critical`) proving this distinction is
preserved, alongside the full decision tree: `critical` (any detected
keyword substring-overlaps a critical keyword, bidirectionally) >
`high` (>= 2 detected keywords, none critical) > `moderate` (exactly 1,
or the `consultation_stages.has_urgent_symptoms` fallback bump when
otherwise `normal`) > `normal`.

`array_unique($detectedKeywords)` is ported via `[...new Set(...)]` rather
than reproducing PHP's own gap-key artifact (a duplicate removed from a
non-tail position leaves gaps in the array's integer keys, which would make
`json_encode()` serialize `detectedKeywords` as a JSON object instead of an
array on some requests) — same precedent as
`../medical-history/_lib/medicalHistory.ts`'s `conditions` field
(Phase 4 batch 4b). `array_slice($detectedKeywords, 0, 3)` (used for the
`reason` string's keyword-list interpolation) is unaffected either way,
since PHP's `array_slice()` always reindexes a numerically-keyed array.

## 3. No schema drift found — confirmed, not merely assumed

Every column this batch's queries touch was cross-checked against
`packages/db/src/generated/tenant-db.d.ts` (the live source of truth for
what actually exists on tenant DBs today):

- `consultation_stages` — `ConsultationStages` interface confirmed present
  (`user_id`, `stage`, `confidence`, `signals`, `has_urgent_symptoms`,
  `updated_at`, plus a `line_account_id` this batch's INSERT deliberately
  does not populate — see §2.1).
- `messages.direction` — confirmed present on `Messages`
  (`"incoming" | "outgoing"`, NOT NULL), matching every `getRecentMessages()`
  copy's `WHERE user_id = ? AND message_type = 'text'` read (itself
  unscoped by `line_account_id`, ported literally — see each `_lib` file's
  own module doc).
- `users.drug_allergies` / `users.current_medications` — both confirmed
  present on `Users` (`Generated<string | null>` each), backing
  `getUserAllergies()`/`getUserMedications()` (`context-widgets`) exactly as
  the PHP source reads them.
- `pharmacy_context_keywords` — `PharmacyContextKeywords` interface
  confirmed present, including the `widget_type` MySQL enum
  (`"allergy" | "drug_info" | "interaction" | "pregnancy" | "pricing" |
  "symptom"`) matching `buildWidget()`'s switch exactly.
- `business_items.generic_name` / `.name_en` / `.active_ingredient` /
  `.manufacturer` / `.unit` — all five confirmed present on `BusinessItems`,
  so `searchDrugsFromMessage()`'s `SHOW COLUMNS FROM business_items` runtime
  probe is dropped (always-true branch only), same simplification precedent
  as `../search-drugs/_lib/searchDrugs.ts` and
  `../drug-pricing/_lib/drugPricing.ts` (Phase 4 batch 4a).
- `item_categories.name` — confirmed present, backing the
  `getSymptomRecommendations()`/`getPopularDrugs()` `LEFT JOIN`.

No fix-forward deviation was needed anywhere in this batch — every route is
a purely literal port (plus the one documented `SHOW COLUMNS` simplification
above, which changes no observable behavior on this schema).
`cd apps/admin && npm run lint` (`tsc --noEmit`) passing with zero errors
under this batch's 4 directories is the structural proof: every raw-`sql`
column reference in `context-widgets/_lib/contextWidgets.ts`,
`consultation-stage/_lib/consultationStage.ts`,
`detect-urgency/_lib/detectUrgency.ts` compiles against the typed
`TenantDB` interfaces.

## 4. `searchDrugsFromMessage()` — deliberate duplication, not a cross-builder import

`ConsultationAnalyzerService::searchDrugsFromMessage()` (lines 938-1107) is
called by this batch's own `checkForDrugNames()` (`context-widgets`) **and**
is independently needed by the sibling `interactionsAndAlternatives`
builder's `recommendations` route this same round (see that builder's own
runbook for its own port of the same PHP method). Per this round's
ownership split — two different builders, not the "same builder, same
round" precedent that justifies e.g. `check-allergy` importing from
`medical-history` (§1.3) — `context-widgets/_lib/contextWidgets.ts` ports
its **own independent copy** of `searchDrugsFromMessage()` rather than
cross-importing from `../recommendations/_lib/**`. This is a deliberate
duplication to avoid a cross-builder file dependency during parallel
authoring; both copies must be kept in parity with the PHP source
independently if `ConsultationAnalyzerService::searchDrugsFromMessage()`
ever changes upstream.

`context-widgets/_lib/contextWidgets.ts`'s own module doc carries the same
note at the point of duplication.

## 5. SAFETY-CRITICAL surfaces — ported byte-for-byte, not "cleaned up"

Per this batch's brief, two surfaces are explicitly flagged as
safety-critical and were ported with every keyword list, threshold,
ordering, and Thai string preserved exactly (no reordering, no
retranslation):

- **Urgent-symptom detection** — the 46-entry `$urgentKeywords` list (used
  by both `detectStage()`'s `hasUrgentSymptoms()` and `detectUrgency()`),
  the 13-entry critical-keyword subset (§2.2), the `normal` /
  `moderate` / `high` / `critical` urgency-level decision tree, and every
  Thai reason/recommendation/label string (`'ตรวจพบอาการฉุกเฉิน: '`,
  `'ตรวจพบอาการรุนแรงหลายอย่าง: '`, `'ตรวจพบอาการที่ควรระวัง: '`,
  `'มีประวัติอาการที่ควรระวังก่อนหน้านี้'`, `'แนะนำให้พบแพทย์โดยเร็ว'`,
  `'ควรติดตามอาการอย่างใกล้ชิด'`, and the `getUrgencyLabel()` map
  `ปกติ`/`ควรระวัง`/`รุนแรง`/`ฉุกเฉิน`).
- **The allergy-warning widget** — `checkAllergyWarnings()` /
  `buildAllergyWidget()` / `getUserAllergies()`, including the
  filter-BEFORE-trim split-array semantics (§ below) and the
  `array_unshift()`-then-`array_slice(0, 4)` ordering that guarantees the
  allergy warning is always the first widget shown, even when it displaces
  an already-matched widget out of the 4-widget cap.
  `context-widgets/route.test.ts` has a dedicated test for exactly this
  displacement behavior.

One subtle, easy-to-miss distinction worth calling out explicitly:
`ConsultationAnalyzerService::getUserAllergies()`/`getUserMedications()`
are **different private methods** from
`PharmacyIntegrationService::parseTextToArray()` (the one
`../medical-history/_lib/medicalHistory.ts` exports, Phase 4 batch 4b) —
different split regex (`/[,\n]+/`, no `;`) **and** a different operation
order: PHP's `array_map('trim', array_filter($list))` filters raw,
un-trimmed pieces first (dropping only the exact strings `''`/`'0'`), THEN
trims what survives — so a whitespace-only piece (e.g. `" "`) is NOT
dropped by the filter (it's non-empty) and comes out as `''` in the final
array. This is the opposite order from `parseTextToArray()`'s
trim-then-filter, which WOULD have dropped it. `context-widgets/_lib/
contextWidgets.ts`'s `splitFilterTrim()` reproduces this literally (not by
reusing `parseTextToArray`), and `context-widgets/route.test.ts` has a
dedicated test asserting an empty-string element survives in the output
array for exactly this reason.

## 6. Deferred scope

Out of scope for this batch, unchanged from prior batches' own deferred
lists: `check_drug_interactions`/`suggest_alternatives`/
`safe_alternatives`/`validate_recommendation`/`recommendations`/`drug_card`
(owned by the sibling `interactionsAndAlternatives` builder this round —
§4), `analyze_symptom`/`analyze_drug`/`analyze_prescription`,
`ghost_draft`/`learn_draft`/`draft_style`,
`classify_customer`/`customer_health`, `analytics`/`record_analytics`,
`save_pending_order`, `customer_crm`,
`add_customer_note`/`remove_customer_note`/`add_customer_tag`/
`remove_customer_tag`, `poll`, and `dispense` (owned by a sibling stream a
prior round, per Phase 4 batch 4b's own deferred list).
`ConsultationAnalyzerService::getSavedStage()`, `clearStage()`,
`recordAnalytics()`, `searchDrugsFromChatHistory()`, and
`extractSearchTerms()` are public methods on the same class but are not
called from any of this batch's 4 case blocks — not ported.

## 7. How to run each route's own test suite locally

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/context-widgets
npx jest src/app/api/inbox/actions/consultation-stage
npx jest src/app/api/inbox/actions/quick-actions
npx jest src/app/api/inbox/actions/detect-urgency
```

Or all four at once:

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/context-widgets src/app/api/inbox/actions/consultation-stage src/app/api/inbox/actions/quick-actions src/app/api/inbox/actions/detect-urgency
```

`cd apps/admin && npm run lint` (`tsc --noEmit -p tsconfig.json`) passes
with zero errors across all new/changed files under this batch's 4
directories — this also structurally validates every Kysely/raw-`sql`
table and column reference used compiles against
`packages/db/src/generated/tenant-db.d.ts` (§3). Building
`@reya/db`/`@reya/auth` via `pnpm --filter <pkg> run build` first is
required for `tsc` to resolve their `dist/*.d.ts` — jest resolves the same
workspace packages straight from source instead, via `apps/admin/jest.config.js`'s
`moduleNameMapper`, so `npx jest` never needs this build step. (At the time
this batch landed, several OTHER workspace packages this batch does not
touch — `@reya/core`, `@reya/contracts`, `@reya/line`, `@reya/tenant` — were
also unbuilt, producing pre-existing `tsc --noEmit` errors elsewhere in
`apps/admin` unrelated to any file under this batch's 4 directories; `grep`
the `tsc` output for `context-widgets\|consultation-stage\|quick-actions\|detect-urgency`
to confirm zero hits.)

## 8. Acceptance criteria (mig-verify executes these)

- [ ] `cd apps/admin && npx jest src/app/api/inbox/actions/context-widgets
      src/app/api/inbox/actions/consultation-stage
      src/app/api/inbox/actions/quick-actions
      src/app/api/inbox/actions/detect-urgency` — all pass.
- [ ] `cd apps/admin && npm run lint` — zero errors under this batch's 4
      directories (see §7's note on pre-existing, unrelated errors
      elsewhere in the repo from other unbuilt workspace packages).
- [ ] `consultation-stage`'s 0-messages test: exactly
      `{stage: 'symptom_assessment', confidence: 0.3, signals:
      ['no_messages'], hasUrgentSymptoms: false, scores: [], messageCount:
      0}`, and zero `INSERT` queries recorded against the fake pool (no
      `saveStage()` write on the short-circuit path).
- [ ] `detect-urgency`'s keyword tests: `เจ็บหน้าอก` alone -> `critical`/
      `needsReferral: true`/`ฉุกเฉิน`; one non-critical keyword (e.g.
      `ผื่นทั้งตัว`) -> `moderate`; two non-critical keywords -> `high`.
- [ ] `quick-actions`'s `hasUrgentSymptoms: true` always places
      `recommend_hospital` first (`priority: 100`) with the exact Thai
      template string, and each of the 4 stage branches + default branch
      returns its exact PHP-literal action list.
- [ ] `context-widgets`'s empty-message test returns `{success: true,
      data: {widgets: [], count: 0}}` at HTTP 200 (not the 400 error path);
      a matched allergy always unshifts `allergy_warning` first even when
      other widgets already matched; widgets are capped at 4
      (`array_slice(0, 4)`).
- [ ] This document
      (`docs/runbooks/phase4-batch6-consultation-widgets-parity.md`) exists,
      contains the full 4-row alias table (§1.1), and is a genuinely new
      file — no other file under `docs/runbooks/` is modified.
- [ ] `git diff --stat origin/main` shows changes confined to
      `apps/admin/src/app/api/inbox/actions/{context-widgets,consultation-stage,quick-actions,detect-urgency}/**`
      and this runbook — no edits to `apps/admin/src/nav/manifest.ts`,
      `infra/nginx/routes.json`, any other `actions/**` route (in
      particular the sibling `interactionsAndAlternatives` builder's 7
      directories — §4), or any other `docs/runbooks/*.md` file.
