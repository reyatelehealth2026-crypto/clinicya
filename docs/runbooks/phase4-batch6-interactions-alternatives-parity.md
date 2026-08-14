# Phase 4 batch 6 — `/inbox` drug-interaction & alternative-recommendation actions

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time" — this batch ships 7, one
over the nominal batch size, because the interaction-checking and
alternative-recommendation actions form one coherent safety surface that
does not split cleanly). Owner: mig-api (interactionsAndAlternatives
stream — `apps/admin/src/app/api/inbox/actions/{check-interactions,
check-drug-interactions,suggest-alternatives,safe-alternatives,
validate-recommendation,recommendations,drug-card}/**` exclusively this
round). Cross-reference: `docs/runbooks/phase4-batch4b-patient-clinical-parity.md`
(the sibling runbook whose §1.1 alias-table format and §1.3 cross-import
precedent this one follows) and Phase 4 batch 4a (drug-info/max-discount/
drug-inventory — the `is_prescription` -> `requires_prescription` and
`bi.*` -> explicit-column-list precedents this batch extends into three
more service classes).

## 1. Scoping correction — FOUR backing service classes, not two

The task brief's framing ("backed by DrugRecommendEngineService +
ConsultationAnalyzerService") undersold this batch: verified by a direct
re-read of `api/inbox-v2.php` and all five backing classes, the 7 actions
span **four** service classes, and three of them additionally need pieces
of a **fifth** (`CustomerHealthEngineService`) for full clinical fidelity.

### 1.1 Alias table — every PHP case label maps to exactly one route

| PHP case labels (`api/inbox-v2.php`) | Backing PHP method | Route |
|---|---|---|
| `check_interactions`, `check-interactions` (lines ~881-906) | `PharmacyIntegrationService::checkDrugInteractions()` | `apps/admin/src/app/api/inbox/actions/check-interactions/route.ts` |
| `check_drug_interactions`, `check-drug-interactions` (lines ~1363-1401) | `DrugRecommendEngineService::checkInteractions()` | `apps/admin/src/app/api/inbox/actions/check-drug-interactions/route.ts` |
| `suggest_alternatives`, `suggest-alternatives` (lines ~807-833) | `DrugPricingEngineService::suggestAlternatives()` | `apps/admin/src/app/api/inbox/actions/suggest-alternatives/route.ts` |
| `safe_alternatives`, `safe-alternatives`, `get_safe_alternatives` (lines ~1478-1508) | `DrugRecommendEngineService::getSafeAlternatives()` | `apps/admin/src/app/api/inbox/actions/safe-alternatives/route.ts` |
| `validate_recommendation`, `validate-recommendation` (lines ~1052-1081) | `PharmacyIntegrationService::validateDrugRecommendation()` | `apps/admin/src/app/api/inbox/actions/validate-recommendation/route.ts` |
| `recommendations`, `get_recommendations`, `drug_recommendations` (lines ~1191-1350) | `ConsultationAnalyzerService::{searchDrugsFromChatHistory,searchDrugsFromMessage,extractSearchTerms}` + inline-SQL popular fallback + `DrugRecommendEngineService::getForSymptoms()` | `apps/admin/src/app/api/inbox/actions/recommendations/route.ts` |
| `drug_card`, `drug-card`, `generate_drug_card` (lines ~1447-1475) | `DrugRecommendEngineService::generateDrugCard()` | `apps/admin/src/app/api/inbox/actions/drug-card/route.ts` |

### 1.2 The two similarly-named `checkDrugInteractions`/`checkInteractions` methods are NOT the same algorithm

`check_interactions` (action #1) calls `PharmacyIntegrationService::checkDrugInteractions()`
— `LOWER()`-wrapped `LIKE`, a `UNION` of two directional searches, a
`SHOW TABLES LIKE` existence guard (dropped as unreachable — see Phase 4
batch 4b's identical precedent), and a returned interaction shape carrying
`id`/`source`.

`check_drug_interactions` (action #2) calls `DrugRecommendEngineService::checkInteractions()`
— its own private `findInteraction()` has NO `LOWER()` (relies on MySQL's
default case-insensitive collation instead), NO `UNION` (a single
`WHERE (...) OR (...)`), NO existence guard, and a returned interaction
shape WITHOUT `id`/`source`. Its own `severityOrder` (`mild:1/moderate:2/
severe:3/contraindicated:4`) and two-pass search (new-drugs × current-meds,
then new-drugs × new-drugs — current-meds × current-meds pairs are
deliberately never checked) are asserted directly in
`check-drug-interactions/route.test.ts`'s severity test.

These are ported as two fully independent files — `check-interactions/_lib`
imports the first (already merged), `check-drug-interactions/_lib` ports the
second fresh — and the Thai severity-label strings happen to be textually
identical between the two only by coincidence of the source domain, not
because the underlying constants/types are shared.

### 1.3 Cross-batch and within-batch reuse (read-only imports)

| Imported symbol | From (already-merged, read-only) | Used by |
|---|---|---|
| `checkDrugInteractions`, `SEVERITY_CONTRAINDICATED`, `Severity` | `patient-profile/_lib/patientProfile.ts` (Phase 4 batch 4b) | `check-interactions/_lib/checkInteractions.ts` (whole action, thin wrapper), `validate-recommendation/_lib/validateRecommendation.ts` (interaction-check step) |
| `getMaxDiscount` | `max-discount/_lib/drugPricingEngine.ts` (Phase 4 batch 4a) | `suggest-alternatives/_lib/suggestAlternatives.ts` |
| `getDrugInventory` | `drug-inventory/_lib/drugInventory.ts` (Phase 4 batch 4a) | `validate-recommendation/_lib/validateRecommendation.ts` |
| `getUserMedicalHistory` | `medical-history/_lib/medicalHistory.ts` (Phase 4 batch 4b) | `validate-recommendation/_lib/validateRecommendation.ts` |
| `getAllergies`, `getMedications` | `check-drug-interactions/_lib/customerHealthEngine.ts` (**new this batch** — see §2) | `recommendations/_lib/getForSymptoms.ts`, `safe-alternatives/_lib/safeAlternatives.ts` (within-batch, same-builder) |

None of `patient-profile/**`, `max-discount/**`, `drug-inventory/**`, or
`medical-history/**` were edited to make these imports work — every one of
them already exported the symbol this batch needed.

`findInteraction()`/`getDrugNames()`/`checkAllergyMatch()`/
`checkConditionSafety()`/`getUserConditions()` are each duplicated (not
shared) across `check-drug-interactions/_lib`, `recommendations/_lib`, and
`safe-alternatives/_lib` where the same private PHP method is needed by
more than one of this batch's own actions — same "every consumer resolves
its own copy of small private helpers" convention already established for
`session.ts`/`testHelpers/fakeTenantDb.ts` across the whole
`api/inbox/actions/*` family.

## 2. `CustomerHealthEngineService` port — mandatory, not optional

`api/inbox-v2.php`'s case blocks for `check_drug_interactions`,
`recommendations`, and `safe_alternatives` ALL call
`loadService('CustomerHealthEngineService', $db, $lineAccountId')` and, when
it loads (it always does in production — `classes/CustomerHealthEngineService.php`
is a committed class file, same "static import always succeeds" reasoning
already applied to every `loadService()` guard in this codebase), call
`$recommendEngine->setHealthEngine($healthEngine)` BEFORE invoking
`checkInteractions()`/`getForSymptoms()`/`getSafeAlternatives()`.
`DrugRecommendEngineService`'s own private `getUserAllergies()`/
`getUserCurrentMedications()` (PHP lines 1026-1085) branch to
`$this->healthEngine->getAllergies($userId)`/`getMedications($userId)`
whenever `healthEngine` is set — the ACTUAL production code path today, not
the plain-`users`-table fallback. This port therefore ALWAYS routes through
the canonical `CustomerHealthEngineService` port below and never
reimplements `DrugRecommendEngineService`'s own dead-code direct-query
fallback.

`CustomerHealthEngineService::getAllergies()` (PHP 158-235) and
`getMedications()` (PHP 245-342) each merge THREE sources:

1. **The `users` table text field** (`drug_allergies`/`current_medications`)
   — always live.
2. **The "detailed" table** (`user_allergies`/`user_medications`) —
   **CONFIRMED ABSENT** from `packages/db/src/generated/tenant-db.d.ts`. PHP's
   own inner `try { ... } catch (PDOException $e) { // table might not
   exist }` around each already degrades this source to a no-op on a real
   tenant DB. Ported LITERALLY (the query + inner try/catch is still
   issued) per this batch's brief — **this is NOT a bug to fix**, it is a
   genuinely-absent optional data source PHP itself already tolerates
   missing.
3. **The LINE-Mini-App-authored table** (`user_drug_allergies`/
   `user_current_medications`, keyed by `line_user_id` via
   `resolveLineUserId()`) — **CONFIRMED PRESENT** in the generated types
   (`UserDrugAllergies`, `UserCurrentMedications`), explicitly flagged
   "safety-critical" in the PHP source's own comments (this is where the
   mini-app itself writes a customer's allergy/medication profile — without
   merging it, the pharmacist HUD misses data the customer entered
   themselves). Ported as fully live queries via `mergeMiniAppAllergies()`/
   `mergeMiniAppMedications()`.

`getMedications()` additionally merges `getRecentPurchasedMedications()`
(PHP 525-592) — a `transactions`-table primary query with an `orders`-table
fallback on failure (both tables genuinely exist in the generated schema,
unlike `refill-reminders`' own inert no-op fallback from Phase 4 batch 4a's
runbook — this fallback IS reachable and DOES populate data on the primary
query's failure).

All five methods/helpers are ported ONCE, canonically, in
`check-drug-interactions/_lib/customerHealthEngine.ts` (the first of the
three consumers alphabetically), and cross-imported (within-batch,
same-builder) by `recommendations/_lib/getForSymptoms.ts` and
`safe-alternatives/_lib/safeAlternatives.ts`.

### Schema-drift fix — `getRecentPurchasedMedications()`'s primary query: `bi.is_prescription` -> `bi.requires_prescription` (WHERE clause only)

`getRecentPurchasedMedications()`'s primary (`transactions`-based) query
references `bi.is_prescription` in its `WHERE` clause (PHP line ~541):

```php
AND (ic.name LIKE '%ยา%' OR ic.name LIKE '%drug%' OR ic.name LIKE '%medicine%'
     OR bi.name LIKE '%ยา%' OR bi.is_prescription = 1)
```

Same confirmed finding as every other `is_prescription` occurrence in this
codebase (Phase 4 batch 4a's `drug-inventory`/`low-stock-drugs`, and this
batch's own `drug-card`/`recommendations` findings below):
`business_items.is_prescription` does not exist in either the committed
tenant template or production — the real column is `requires_prescription`.

**Effect in current production**: this WHERE-clause reference makes the
primary query throw ("Unknown column") on EVERY call — caught by PHP's own
outer `catch (PDOException $e)` around this method, which silently
re-runs the SECONDARY (`orders`-based) fallback query instead (that query
has no such reference and succeeds). So today, "recently purchased
medications" is silently sourced from `orders`, **never** `transactions`,
in production — a fact hidden entirely behind PHP's resilience fallback,
not surfaced as an error anywhere.

**Fix forward** (same framing as Phase 4 batch 4a's/4b's identical-column
precedent): the primary query's WHERE clause is corrected to
`bi.requires_prescription = 1` — WHERE-only, no SELECT-list aliasing
needed here since this particular query's SELECT list never reads
`is_prescription`/`requires_prescription` at all. The dual
try-primary/catch-fallback STRUCTURE is kept exactly as-is: the secondary
`orders`-table query remains a genuine, reachable resilience path for any
real, unrelated DB failure — it just stops being the ALWAYS-taken path now
that the schema-drift bug masking the primary query is fixed.

### Every other `is_prescription` occurrence this batch touches

- `drug-card/_lib/drugCard.ts`'s own `getDrugDetails()` — `bi.*` never
  actually populated a key literally named `is_prescription`
  (`requires_prescription` is the real column), so
  `(bool)($drug['is_prescription'] ?? false)` was silently, PERMANENTLY
  `false` in production. Fixed via `bi.requires_prescription AS
  is_prescription` in the explicit SELECT list.
- `recommendations/_lib/getForSymptoms.ts`'s own `searchDrugs()` — same
  fix, same `bi.*` -> explicit-column-list + alias pattern.
- `safe-alternatives/_lib/safeAlternatives.ts` never reads this column at
  all (its own `getDrugDetails()`/`getSimilarDrugs()` explicit column lists
  omit it — the action's own consumers never touch `isPrescription`), so no
  fix is needed there; this is called out explicitly in that file's module
  doc so a future reader doesn't wonder why it's missing.

`grep -R 'is_prescription' apps/admin/src/app/api/inbox/actions/{check-interactions,check-drug-interactions,suggest-alternatives,safe-alternatives,validate-recommendation,recommendations,drug-card}`
returns only `AS is_prescription`-style aliases, doc-comment mentions, and
test-fixture row properties that mirror the aliased column name — no actual
query-building reference to the nonexistent column.

## 3. `similar_text()` — PHP's built-in longest-common-substring algorithm

`DrugRecommendEngineService::calculateSimilarity()` (PHP 930-956, backing
`safe_alternatives`' result ordering) calls PHP's built-in
`similar_text($name1, $name2, $nameSimilarity)` to get a percentage-match
score. This is **not** Levenshtein distance and **not** a naive substring
check — it is PHP's own longest-common-substring-then-recurse-on-both-
remainders algorithm: find the longest common substring, then recursively
apply the same search to the unmatched left remainder and the unmatched
right remainder, summing matched-character counts. The third,
by-reference argument (`$nameSimilarity`) receives the PERCENTAGE
(`matchedChars * 2 / (len1 + len2) * 100`), not the function's own raw
return value (the matched-character count) — the return value itself is
discarded in the PHP source (no `$x = similar_text(...)` assignment).

Ported faithfully in `safe-alternatives/_lib/safeAlternatives.ts` as
`similarTextPercent()`, and validated against the PHP manual's own
documented example:

```
similar_text('World', 'Word', $percent);
// -> return value 4 (4 matched characters: "Wor" + "d")
// -> $percent ≈ 88.888891
```

`route.test.ts` asserts the exact computed value
(`similarTextPercent('World', 'Word') === 800 / 9`, ≈ 88.888888888888886),
not just relative ordering — getting this algorithm wrong would silently
reorder which alternative drug a pharmacist sees first when a customer
needs a substitute, which is why this batch treats it as safety-relevant
(same "getting this wrong is a safety bug, not a cosmetic one" framing as
the severity-ordering fidelity in §1.2).

### A second, independently-discovered PHP quirk in the same function family: `checkAllergyMatch()`'s empty-needle `stripos()` landmine

While porting `checkAllergyMatch()` (shared literal PHP source between
`safe-alternatives` and `recommendations/getForSymptoms`), testing surfaced
a genuine, reachable PHP behavior: since PHP 8.0, `stripos($haystack, '')`
returns `0` (a match), not `false`. The method's 4th disjunct,
`stripos($allergyName, $genericName) !== false`, therefore evaluates to
`0 !== false` = `true` WHENEVER a drug's `generic_name` is empty —
regardless of what the allergy actually is. Since most `business_items`
rows have no `generic_name` populated, this means: any time a customer has
at least one allergy on file, a drug lacking a `generic_name` is reported
as an allergy match for THAT allergy, even when the names are entirely
unrelated. This is preserved, not "fixed" — it is a genuine property of
the literal PHP source, not a schema-drift bug — and is documented + unit
tested directly (`checkAllergyMatch` exported from both `_lib` modules) so
a future reader recognizes it as intentional-per-source rather than a
regression.

## 4. "Service not available" 503s — documented, not implemented

Every action in this batch guards its backing service with
`loadService('...', $db, $lineAccountId); if (!$service) { sendError('...
not available', 503); }`. `loadService()` does a runtime `file_exists()`/
`class_exists()` probe in PHP because the class file may not be deployed on
a given box; every Next.js port in this batch uses a static TypeScript
import instead — either the module compiles and is present in the built
bundle, or the build fails outright. No route in this batch fabricates a
runtime 503 branch for "service unavailable" (same precedent as every
prior batch's `../max-discount/_lib/drugPricingEngine.ts` module doc).

## 5. How to run the tests

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/check-interactions \
         src/app/api/inbox/actions/check-drug-interactions \
         src/app/api/inbox/actions/suggest-alternatives \
         src/app/api/inbox/actions/safe-alternatives \
         src/app/api/inbox/actions/validate-recommendation \
         src/app/api/inbox/actions/recommendations \
         src/app/api/inbox/actions/drug-card
```

75 tests across the 7 suites, all passing. Notable non-405/401/400
coverage per action:

- `check-interactions`: drugs-as-array/JSON-string/CSV-string parsing,
  PHP `empty()` array-vs-string semantics.
- `check-drug-interactions`: the severity-max-across-both-passes test
  (mixing one `contraindicated` and one `mild` interaction across the two
  search passes asserts overall `severity: 'contraindicated'`) and a query-
  count assertion that current-meds × current-meds pairs are never queried.
- `suggest-alternatives`: exact value-formula assertions for all four
  alternative-offer types (free delivery flat `50.0`; bonus vitamins
  `round(excess*0.8,2)`; loyalty points `ceil(excess*2)`; next-purchase
  discount `round(excess*1.2,2)`).
- `safe-alternatives`: the `similarTextPercent`/`calculateSimilarity` exact-
  value tests (§3), the `checkAllergyMatch` empty-needle-quirk tests (§3),
  and an out-of-stock/allergy-exclusion + similarity-ordering route test.
- `validate-recommendation`: composition-only coverage (not-found, out-of-
  stock, allergy, contraindicated-interaction, clean-case) exercising the
  three imported building blocks together.
- `recommendations`: all 4 cascade branches (chat-history hit,
  message-search hit, popular-drugs fallback via both of its trigger
  conditions, symptom-based via `getForSymptoms()`).
- `drug-card`: exact-object-equality (`toEqual`, not snapshot) coverage for
  drug-not-found, in-stock non-prescription, in-stock prescription,
  out-of-stock, has-discount, and hero-image-present branches, plus the
  info-row ordering and generic-name-in-parentheses cases.

```bash
cd apps/admin && npm run lint   # tsc --noEmit; zero errors
```

`npm run lint` requires `@reya/core`, `@reya/line`, `@reya/contracts`, and
`@reya/tenant` to be built (`dist/` populated) in this worktree — those
four packages are owned by other migration streams and may not be built by
default in a fresh worktree; run `npm run build` inside each
`packages/{core,line,contracts,tenant}` first if `tsc` reports `Cannot find
module '@reya/...'` for files outside this batch's own directories (those
errors are pre-existing/environmental, not caused by this batch — none of
the flagged files are in this batch's scope).

## 6. Acceptance criteria (mig-verify executes these)

- `cd apps/admin && npx jest <7 directories>` — all pass (75/75).
- `cd apps/admin && npm run lint` — zero errors.
- `similarTextPercent('World', 'Word') === 800 / 9` (exact value, not
  approximate ordering).
- `check_drug_interactions` severity-max + current-meds-pairs-never-queried
  test.
- `grep -R 'is_prescription' <7 directories>` — no non-aliased query
  reference to the nonexistent column.
- `grep -n 'checkDrugInteractions' check-interactions/_lib/checkInteractions.ts
  validate-recommendation/_lib/validateRecommendation.ts` — both show an
  import from `../../patient-profile/_lib/patientProfile`, not a
  reimplementation.
- This document exists as a genuinely new file with the full 7-row alias
  table (§1.1).
- `git diff --stat origin/main` confined to this batch's 7 directories and
  this runbook — no edits to `patient-profile/**`, `max-discount/**`,
  `drug-inventory/**`, `medical-history/**`,
  `apps/admin/src/nav/manifest.ts`, `infra/nginx/routes.json`, any
  `consultationWidgets` directory, or any other `docs/runbooks/*.md` file.
