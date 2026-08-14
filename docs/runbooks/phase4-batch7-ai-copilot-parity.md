# Phase 4 batch 7 — `/inbox` AI-copilot actions (image analysis + ghost draft + customer classification)

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 4
(actions-batch phasing, "~5 actions at a time"), §1.5 (strangler edge), §3
(Phase 4 acceptance criteria — cursor pagination golden tests, latency ≤ PHP
baseline, soak). Team doc: `docs/agents/nextjs-migration-team.md` (reduced
review flow — Phase 4 is on the **low-risk list (1, 2, 4, 8, 9, 10, 11,
12)**, so a clean `mig-verify` PASS is sufficient for merge; Phase 4 is
**not** on the high-risk co-sign list (0, 3, 5, 6, 7) — no `mig-orchestrator`
co-sign gate applies to this batch). Owner: mig-infra (this runbook +
`infra/nginx/routes.json`'s `/inbox` entry note + the regenerated
`infra/nginx/generated/strangler-edge.conf` — **documentation-and-routing-note-only**
for this batch, no compose/Dockerfile/nginx-generator changes, no new
service, no traffic flip) / imageAnalyzer
(`apps/admin/src/app/api/inbox/actions/{analyze-symptom,analyze-drug,analyze-prescription}/**`
exclusively this round) / draftAndClassify
(`apps/admin/src/app/api/inbox/actions/{ghost-draft,learn-draft,draft-style,customer-health,classify-customer}/**`
exclusively this round). Cross-reference:
`docs/runbooks/phase4-batch4b-patient-clinical-parity.md` (the prior
mig-infra-owned `/inbox`-note-append runbook whose structure this one
follows) and `docs/runbooks/phase4-batch3-inbox-actions-parity.md` (the
"documentation-and-routing-note-only, no live-traffic shadow test, no
rollback drill" §0 framing this batch reuses verbatim, since it applies
identically here).

## 0. Scope note (read first)

Same shape as batch 3's and batch 4b's own §0: this batch is
**documentation-and-routing-note-only** for mig-infra. `infra/nginx/routes.json`'s
`/inbox` entry gets one more sentence appended to its `note` field —
`upstream` stays `"php_backend"`, `tenants` stays `"all"`. No canary ramp,
no traffic flip, no rollback drill: nothing in this batch (or any prior
Phase 4 batch) has moved `/inbox` off its pre-existing `php_backend`
default, so there is nothing to roll back. Evidence is unit/route-test only
— `apps/admin/src/app/api/inbox/actions/**/*.test.ts` (§4) — matching every
prior actions-batch's own precedent; `infra/e2e/parity.mjs` /
`infra/e2e/api-parity.mjs` are **not** extended this round.

## 1. What landed — 8 ported actions, 4 genuinely LLM-backed, 4 plain SQL/heuristic

All 8 are literal ports of `api/inbox-v2.php`'s action switch (the same
cursor/AI-copilot API file batches 1-4b ported from), decomposed into their
own Next.js Route Handlers under `apps/admin/src/app/api/inbox/actions/**`.
Every handler requires a valid tenant session (any of the six `TenantRole`
values) via `resolveInboxApiContext()` — the same per-route auth gate every
prior batch established.

### 1.1 Alias table — every PHP case label maps to exactly one route

| PHP case labels (`api/inbox-v2.php`) | Lines | Route | Builder |
|---|---|---|---|
| `analyze_symptom`, `analyze-symptom` | 200-235 | `apps/admin/src/app/api/inbox/actions/analyze-symptom/route.ts` | imageAnalyzer |
| `analyze_drug`, `analyze-drug` | 241-276 | `apps/admin/src/app/api/inbox/actions/analyze-drug/route.ts` | imageAnalyzer |
| `analyze_prescription`, `analyze-prescription` | 282-323 | `apps/admin/src/app/api/inbox/actions/analyze-prescription/route.ts` | imageAnalyzer |
| `customer_health`, `customer-health`, `get_customer_health` | 329-357 | `apps/admin/src/app/api/inbox/actions/customer-health/route.ts` | draftAndClassify |
| `classify_customer`, `classify-customer` | 363-391 | `apps/admin/src/app/api/inbox/actions/classify-customer/route.ts` | draftAndClassify |
| `draft_style`, `draft-style` | 397-421 | `apps/admin/src/app/api/inbox/actions/draft-style/route.ts` | draftAndClassify |
| `ghost_draft`, `ghost-draft`, `generate_draft` | 428-469 | `apps/admin/src/app/api/inbox/actions/ghost-draft/route.ts` | draftAndClassify |
| `learn_draft`, `learn-draft` | 475-512 | `apps/admin/src/app/api/inbox/actions/learn-draft/route.ts` | draftAndClassify |

Line ranges verified directly against `api/inbox-v2.php` (case-label line
to matching `break;` line), not copied from the brief without re-checking:

```
sed -n '200,520p' api/inbox-v2.php | grep -n "break;\|case '"
```

Every case label an action responds to (including every hyphen/underscore
variant and every `get_*`/`generate_*` alias) is covered by its route above
— no caller relying on any one of these action-name spellings is orphaned
by this port. All 8 directories exist on disk and contain `route.ts` +
`route.test.ts` (verified via `ls apps/admin/src/app/api/inbox/actions/{analyze-symptom,analyze-drug,analyze-prescription,ghost-draft,learn-draft,draft-style,customer-health,classify-customer}/`).

### 1.2 Backing PHP services

- `analyze-symptom` / `analyze-drug` / `analyze-prescription` →
  `classes/PharmacyImageAnalyzerService.php` (1,448 LOC) →
  `analyze-symptom/_lib/imageAnalyzer.ts` (canonical shared engine;
  `analyze-drug`/`analyze-prescription` import `identifyDrug`/
  `ocrPrescription` from it directly — single-owner cross-route import,
  same builder owns all three routes this round, mirroring the precedent
  Phase 4 batch 4a's `drug-info` → `max-discount` import set).
- `customer-health` / `classify-customer` / `draft-style` →
  `classes/CustomerHealthEngineService.php` (1,260 LOC) →
  `customer-health/_lib/customerHealth.ts`,
  `classify-customer/_lib/classifyCustomer.ts`,
  `draft-style/_lib/draftStyle.ts` respectively (three independent ports of
  three independent methods on the same PHP class — not a single shared
  `_lib` file, since each route's response shape and validation are
  distinct).
- `ghost-draft` / `learn-draft` → `classes/PharmacyGhostDraftService.php`
  (1,116 LOC) → `ghost-draft/_lib/ghostDraft.ts` (`generateDraft()`),
  `learn-draft/_lib/learnDraft.ts` (`learnFromEdit()` +
  `calculateEditDistance()`/`unicodeLevenshtein()`, importing
  `extractMentionedDrugs`/`getLastCustomerMessage` from
  `../../ghost-draft/_lib/ghostDraft` — same-builder cross-route import,
  both routes owned by draftAndClassify this round).

## 2. Request/response summary — the 400-vs-200 split is the easiest thing to get wrong

| # | Route | Verb | Required params | Validation-order quirk | On internal service failure |
|---|---|---|---|---|---|
| 1 | `analyze-symptom` | `POST` (JSON body) | `image_url` (string, must be a valid absolute URL if non-empty, then required) | — | **HTTP 400**, `{success:false, error: result.error ?? 'การวิเคราะห์อาการล้มเหลว'}` |
| 2 | `analyze-drug` | `POST` (JSON body) | `image_url` (same shape as #1) | — | **HTTP 400**, `{success:false, error: result.error ?? 'การวิเคราะห์รูปภาพล้มเหลว'}` (own distinct default string) |
| 3 | `analyze-prescription` | `POST` (JSON body) | `user_id` (int, must be > 0), `image_url` (same shape as #1) | **`user_id` is validated FIRST**, before either `image_url` check — a request with both an invalid `user_id` and a missing `image_url` gets `'Invalid user ID'`, never an image-URL error | **HTTP 400**, `{success:false, error: result.error ?? 'การอ่านใบสั่งยาล้มเหลว'}` (own distinct default string) |
| 4 | `customer-health` | `GET` | `user_id` (int, must be > 0) | — | N/A — `getHealthProfile()` never throws (each internal query has its own catch); reaching the handler body always yields `{success:true, data:profile}` at HTTP 200 |
| 5 | `classify-customer` | `GET` | `user_id` (int, must be > 0); `min_messages` (int, default **5** — this route's own literal default, deliberately NOT `CustomerHealthEngineService::MIN_MESSAGES_FOR_CLASSIFICATION`'s method-signature default of 1, since the route never calls the method with the second argument omitted) | — | N/A — `classifyCustomer()` never throws; always `{success:true, data:classification}` at HTTP 200 |
| 6 | `draft-style` | `GET` | `type` (string, defaults to `'A'` when the query param is absent; must be exactly `'A'`/`'B'`/`'C'`, case-sensitive) | — | N/A — `getDraftStyle()` is a pure, DB-free switch; it cannot fail once `type` validates |
| 7 | `ghost-draft` | `POST` (JSON body) | `user_id` (int, only the bare `!userId` check — **no prior `<= 0` guard**, so a negative `user_id` genuinely reaches `generateDraft()`), `message` (string, PHP `empty()` semantics) | — | **HTTP 200 always** — `{success: result.success ?? false, data: result}`; failure is visible ONLY via `body.success === false` / `body.data.error` |
| 8 | `learn-draft` | `POST` (JSON body) | `user_id` (int, same bare `!userId` check as #7, no `<= 0` guard), `original_draft` + `final_message` (both PHP `empty()` semantics — the literal string `'0'` counts as empty) | — | **HTTP 200 always** — `{success, message}` — **no `data` key at all**, unlike every other route in this batch |

**All 8 reject a mismatched HTTP method with `{success:false, error:'Method not allowed'}` at HTTP 405** (`analyze-*`/`ghost-draft`/`learn-draft` are `POST`-only and 405 on `GET`; `customer-health`/`classify-customer`/`draft-style` are `GET`-only and 405 on `POST`).

**Front-loaded validation errors (missing/invalid `user_id`, missing/invalid
`image_url`, invalid `type`, missing `message`/`original_draft`/
`final_message`) are HTTP 400 for every one of the 8 routes, with no
exceptions** — the 400-vs-200 split below is about what happens **after**
validation passes, once the underlying service/engine call itself is made:

> **THE SPLIT THAT IS EASIEST TO GET WRONG:**
> - **`analyze-symptom`, `analyze-drug`, `analyze-prescription`** return
>   **HTTP 400** when the underlying analyzer call itself reports failure
>   (`result.success === false`) — a real, distinct-from-validation error
>   status.
> - **`ghost-draft`, `learn-draft`** always return **HTTP 200** regardless
>   of whether the underlying service call succeeds or fails — the only
>   signal is `body.success` (and, for `ghost-draft` only, `body.data.error`).
> - **`customer-health`, `classify-customer`, `draft-style`** also always
>   return HTTP 200 once validation passes — but for a *different* reason
>   than `ghost-draft`/`learn-draft`: their backing engine calls
>   (`getHealthProfile()`, `classifyCustomer()`, `getDraftStyle()`) **never
>   throw or report failure at all** (each internal query/branch has its own
>   catch-and-degrade, or the function is a pure switch that cannot fail).
>   There is no reachable `body.success === false` branch for these three —
>   unlike `ghost-draft`/`learn-draft`, which do have a real, exercised
>   `false` branch (see each route's own `route.test.ts`).

This matches the batch's brief framing ("customer-health/classify-customer/
draft-style/ghost-draft/learn-draft always return HTTP 200") at the HTTP-status
level for all five, while correcting one nuance found only by reading the
shipped code: only `ghost-draft` and `learn-draft` have a body that can
*actually* carry `success:false` at 200; the other three's 200 is
unconditional because failure is structurally unreachable, not because it is
merely un-surfaced.

## 3. Which of these 8 actions are genuinely LLM-backed — resolving this round's open question

This directly answers the question this round's orchestration brief asked
the draftAndClassify builder to resolve. Verified by grepping every route's
shipped `_lib` code for the Gemini REST endpoint
(`generativelanguage.googleapis.com`), not by trusting either brief's
PHP-source-only claim on faith:

```
$ for d in analyze-symptom analyze-drug analyze-prescription ghost-draft learn-draft draft-style customer-health classify-customer; do
    grep -rl "generativelanguage.googleapis" apps/admin/src/app/api/inbox/actions/$d/ 2>/dev/null || echo "$d: (no match)"
  done
analyze-symptom/_lib/geminiVisionClient.ts   <- match
ghost-draft/_lib/geminiTextClient.ts         <- match
analyze-drug: (no match)
analyze-prescription: (no match)
learn-draft: (no match)
draft-style: (no match)
customer-health: (no match)
classify-customer: (no match)
```

**Real-LLM (4 of 8):**
- `analyze-symptom`, `analyze-drug`, `analyze-prescription` — all three
  funnel through the ONE shared seam,
  `analyze-symptom/_lib/geminiVisionClient.ts`'s `callGeminiVisionApi()`
  (imported by `analyze-drug`/`analyze-prescription` at
  `../analyze-symptom/_lib/geminiVisionClient`, not duplicated), a literal
  port of `PharmacyImageAnalyzerService::callVisionAPI()`'s network half —
  a real `POST .../v1beta/models/{model}:generateContent` call to Gemini.
- `ghost-draft` — `ghost-draft/_lib/geminiTextClient.ts`'s
  `callGeminiTextApi()`, a literal port of the equivalent network call in
  `PharmacyGhostDraftService`.

**Plain SQL / deterministic heuristic, NO LLM call (4 of 8):**
- `customer-health`, `classify-customer`, `draft-style` — all three port
  methods on `CustomerHealthEngineService.php` (1,260 LOC); a full read of
  that file plus `grep -niE "curl_init|generativelanguage|openai|GeminiAI|gemini_api_key" classes/CustomerHealthEngineService.php`
  returns **zero matches** — confirmed nothing in this class ever calls a
  model. `classify-customer`/`customer-health` are SQL aggregation +
  threshold logic; `draft-style` is a pure in-memory A/B/C switch.
- `learn-draft` — plain SQL insert (`pharmacy_ghost_learning`) plus a
  hand-rolled Levenshtein edit-distance calculation
  (`calculateEditDistance()`/`unicodeLevenshtein()`), no AI/network call
  anywhere in `PharmacyGhostDraftService::learnFromEdit()`.

## 4. Zero live network calls in tests

Both builders use the same two-layer guard, independently arrived at:

1. **Module-level `jest.mock()` of the network seam.** Each `route.test.ts`
   mocks the exact function that would make the outbound HTTP call, never
   the route module itself, so the real orchestration logic
   (`imageAnalyzer.ts`'s `analyzeSymptom`/`identifyDrug`/`ocrPrescription`,
   `ghostDraft.ts`'s `generateDraft`) runs end-to-end against a fake
   Kysely DB (`_lib/testHelpers/fakeTenantDb`), with only the actual
   network hop stubbed out.
2. **A `global.fetch` guard that throws.** Every one of the 8 `route.test.ts`
   files sets `global.fetch = jest.fn(() => { throw new Error('unexpected
   real network call in <route> test'); })` at module scope, before the
   route module is imported — a loud, fail-fast backstop if any code path
   slips past the intended mock.

Network seams, one per builder:

- **imageAnalyzer builder** — `analyze-symptom/_lib/geminiVisionClient.ts`
  (`callGeminiVisionApi`) and `analyze-symptom/_lib/imageResolver.ts`
  (`getImageData`), a **deliberate split** of PHP's single
  `callVisionAPI()` method into two independently-mockable seams (the
  Gemini call vs. the image-bytes fetch), so a test can prove either step
  alone never reaches the network. `analyze-symptom/route.test.ts` mocks
  both at `./_lib/{geminiVisionClient,imageResolver}`;
  `analyze-drug/route.test.ts` and `analyze-prescription/route.test.ts`
  mock the SAME two files at their shared home,
  `../analyze-symptom/_lib/{geminiVisionClient,imageResolver}` (verified
  directly in each test file's `jest.mock()` calls).
- **draftAndClassify builder** — `ghost-draft/_lib/geminiTextClient.ts`
  (`callGeminiTextApi`), mocked at `./_lib/geminiTextClient` in
  `ghost-draft/route.test.ts`. `learn-draft`, `draft-style`,
  `customer-health`, `classify-customer` have no network seam to mock at
  all (§3) — their tests still carry the `global.fetch`-throws guard as a
  belt-and-suspenders backstop even though no code path in those four
  routes ever calls `fetch()`.

Confirmed by running all 8 suites plus the 2 standalone seam-unit-test files
(`analyze-symptom/_lib/{geminiVisionClient,imageResolver}.test.ts`) in this
worktree:

```
cd apps/admin && npx jest src/app/api/inbox/actions/{analyze-symptom,analyze-drug,analyze-prescription,ghost-draft,learn-draft,draft-style,customer-health,classify-customer}
# Test Suites: 10 passed, 10 total
# Tests:       108 passed, 108 total
```

## 5. Deviations from the migration plan / documented schema drift

Pulled directly from each builder's own shipped module docs, not
fabricated here:

1. **`imageAnalyzer.ts` — confirmed PHP bug, fixed forward: cache-hit
   responses missing `success`.** `PharmacyImageAnalyzerService`'s own
   caching order (cache the array → THEN set `$analysis['success'] =
   true`) means every `symptom_analysis_cache`/`drug_recognition_cache`
   HIT in current production is reported to the PHP caller as a failure
   (`success` is absent, treated as falsy). This port's
   `getCachedSymptomAnalysis()`/`getCachedDrugRecognition()` add
   `success: true` on a cache hit themselves — a deliberate, documented
   fix-forward, flagged for mig-orchestrator awareness in this batch's
   final report.
2. **`imageAnalyzer.ts` — confirmed schema drift, fixed forward:
   `business_items.stock_quantity` does not exist; the real column is
   `stock`.** PHP's `matchDrugToProduct()` throws `Unknown column` on
   every call (caught, falls through to `null`) — `identifyDrug()` never
   successfully attaches a matched product in production today. This port
   selects the real `stock` column instead.
3. **`ghostDraft.ts` — dead code, NOT ported: `containsPrescriptionDrug()`'s
   DB branch.** PHP's 4th check queries
   `business_items ... AND is_prescription = 1`; `is_prescription` does
   not exist (real column: `requires_prescription`) — this branch always
   throws, always caught, always contributes nothing. Left dead-but-documented
   per the brief (deliberately NOT fixed forward here, unlike finding #4
   below — two independent decisions for two independent PHP methods).
4. **`customerHealth.ts` — confirmed schema drift, fixed forward:
   `getRecentPurchasedMedications()` uses `is_prescription` in a WHERE
   clause; the real column is `requires_prescription`.** Unlike finding #3
   (a SELECT that merely never populates a key), this is a WHERE-clause
   reference that makes the entire primary query throw on every call in
   production, forcing PHP to always fall back to its secondary
   `orders`/`order_items` query regardless of which table actually holds
   the customer's real purchase history. This port fixes the column
   forward so the primary `transactions`/`transaction_items` query can
   succeed; the `orders` fallback is still ported, still tried second,
   exactly matching PHP's structure.
5. **`customerHealth.ts` — dead-code branches, NOT ported: `user_allergies`
   / `user_medications`.** Both tables are absent from
   `packages/db/src/generated/tenant-db.d.ts`'s `TenantDB` — PHP's own
   `catch (PDOException $e)` around each means they always throw and
   always contribute nothing in production today. The LINE-mini-app-authored
   `user_drug_allergies` / `user_current_medications` tables DO exist and
   ARE ported (`mergeMiniAppAllergies`/`mergeMiniAppMedications`).
6. **`GEMINI_API_KEY` env-var fallback — flagged for `packages/config`,
   independently, by both builders.** Both `imageAnalyzer.ts`'s
   `loadAiConfig()` and `ghostDraft.ts`'s `loadGhostDraftCredentials()`
   read `process.env.GEMINI_API_KEY` directly (matching PHP's
   `defined('GEMINI_API_KEY')` config-constant fallback) rather than
   through `packages/config`'s typed env schema — both builders' allowed
   paths this round were scoped exclusively to their own route
   directories, so `packages/config/src/env.ts` could not be touched here.
   Flagged by both, independently, as a follow-up for whichever future
   batch next touches that file to add `GEMINI_API_KEY` as a typed
   optional entry.
7. **No `loadService()`-unavailable 503 branch fabricated anywhere.** PHP's
   `loadService()` guard (`'Image analyzer service not available'` /
   `'Ghost draft service not available'` / `'Health engine service not
   available'`, all 503) does a runtime `file_exists()`/`class_exists()`
   probe with no Next analogue — a static TypeScript import either
   compiles and is present in the bundle, or the build fails outright.
   Same precedent already established by Phase 4 batch 4a's
   `max-discount/_lib/drugPricingEngine.ts`. Every route's module doc
   documents the PHP message text; none invent a runtime 503 branch for
   it.

## 6. `infra/nginx/routes.json`'s `/inbox` entry — still no flip

This batch appended one sentence to the existing `/inbox` entry's `note`
field only — `upstream` stays `"php_backend"`, `tenants` stays `"all"`,
matching batches 1-4b's precedent. No canary ramp, no traffic flip, no
rollback drill: nothing in this batch landed on any upstream other than its
pre-existing `php_backend` default, so there is nothing to roll back. The
file still contains exactly one `/` catch-all entry and exactly one
`/inbox` entry (the generator's hard requirement plus this batch's own
constraint — no new path entries were added), and remains schema-valid:

```
$ node infra/nginx/generate-routes.mjs --validate-only infra/nginx/routes.json
✓ ...routes.json validates against routes.schema.json (23 route(s))
```

`git diff -- infra/nginx/routes.json` shows a single-hunk change confined to
the `/inbox` entry's `note` string — the old text is a strict prefix of the
new text (a pure append), and no other entry, and no `upstream`/`tenants`
key, appears in the diff.

**Regenerating `infra/nginx/generated/strangler-edge.conf`** via
`node infra/nginx/generate-routes.mjs` (exit 0) produced a larger diff than
a pure note-only change would suggest — worth calling out explicitly so
mig-verify doesn't misread it as a traffic change introduced by this batch:

- The `/inbox` location block itself has **zero content diff**. The
  generator only emits a `# note: ...` comment (and a `map` block) for
  routes whose `tenants` is a per-tenant array — `/inbox`'s `tenants` is
  `"all"`, so it has no `map` block and its `note` text was never embedded
  in the generated `.conf` in the first place, before or after this
  batch's edit. The note lives purely in the source manifest.
- The diff DOES contain 4 new `location` blocks — `/articles`, `/article`,
  `/pharmacists`, `/api/documents` — all `set $upstream_name php_backend;`.
  These are **pre-existing drift, not introduced by this batch**: those 4
  path entries already existed in `routes.json` (added across Phase 2 tail
  and Phase 5), but the committed `strangler-edge.conf` was last generated
  2026-07-14 (Phase 2 batch 1 era) and was never regenerated after those
  entries landed — confirmed via
  `git log -1 -- infra/nginx/generated/strangler-edge.conf` vs.
  `git log -- infra/nginx/routes.json`. Functionally this is a no-op:
  every one of the 4 newly-materialized blocks sets `php_backend`, the
  same upstream nginx's longest-prefix-match already fell through to via
  the pre-existing `/` catch-all for these exact paths — no request that
  was served by `php_backend` before this regeneration is served by
  anything else after it. This batch's brief instructed a plain
  `node infra/nginx/generate-routes.mjs` regeneration and a commit of
  "the resulting diff"; per mig-infra's ownership of keeping the derived
  artifact in sync with the source manifest, that catch-up is included
  rather than hand-truncated to hide it. Flagged here for mig-verify/
  mig-orchestrator visibility, not something this batch's brief asked
  anyone to fix.

mig-orchestrator continues to own the decision of when `/inbox` is
flip-ready (see the `/users` entry's note for the general flip mechanic).
**No `mig-orchestrator` co-sign applies to this batch at all** — Phase 4 is
on the reduced-review low-risk list (1, 2, 4, 8, 9, 10, 11, 12), not the
high-risk co-sign list (0, 3, 5, 6, 7); a clean `mig-verify` PASS is
sufficient for merge.

## 7. How to run each builder's own test suite locally

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/analyze-symptom
npx jest src/app/api/inbox/actions/analyze-drug
npx jest src/app/api/inbox/actions/analyze-prescription
npx jest src/app/api/inbox/actions/ghost-draft
npx jest src/app/api/inbox/actions/learn-draft
npx jest src/app/api/inbox/actions/draft-style
npx jest src/app/api/inbox/actions/customer-health
npx jest src/app/api/inbox/actions/classify-customer
```

Or all 8 at once (plus the 2 standalone seam-unit-test files under
`analyze-symptom/_lib/`):

```bash
cd apps/admin
npx jest src/app/api/inbox/actions/{analyze-symptom,analyze-drug,analyze-prescription,ghost-draft,learn-draft,draft-style,customer-health,classify-customer}
```

## 8. Deferred scope

Out of scope for this batch, unchanged from prior batches' deferred lists
(`phase4-batch4b-patient-clinical-parity.md` §6): `recommendations`,
`safe_alternatives`, `context_widgets`, `consultation_stage`,
`quick_actions`, `detect_urgency`, `analytics`/`record_analytics`,
`save_pending_order`, `customer_crm`,
`add_customer_note`/`remove_customer_note`/`add_customer_tag`/
`remove_customer_tag`, `drug_card`, `validate_recommendation`, `poll`, and
`dispense` (already ported by a sibling stream, per Phase 5). PHP's
`api/ai-chat*.php` / `modules/AIChat/**` consultation pipeline (a
completely separate SSE-streamed system from this batch's `inbox-v2.php`
actions) is untouched and out of scope.

## 9. Acceptance criteria (mig-verify executes these)

- [ ] `node infra/nginx/generate-routes.mjs --validate-only infra/nginx/routes.json`
      exits `0`.
- [ ] `git diff infra/nginx/routes.json` shows a change to exactly one
      entry's `note` string field (an append, old text a strict prefix of
      new text) and touches no other field — no other array entry appears
      in the diff.
- [ ] `node infra/nginx/generate-routes.mjs` regenerates
      `infra/nginx/generated/strangler-edge.conf` cleanly (exit 0); the
      diff's only `set $upstream_name` values are `php_backend` (see §6 for
      why 4 unrelated location blocks appear — pre-existing drift, not a
      traffic change).
- [ ] `ls apps/admin/src/app/api/inbox/actions/ | grep -E '^(analyze-symptom|analyze-drug|analyze-prescription|ghost-draft|learn-draft|draft-style|customer-health|classify-customer)$'`
      returns all 8 names, matching this document's alias table (§1.1).
- [ ] `grep -rl "generativelanguage.googleapis" apps/admin/src/app/api/inbox/actions/{analyze-symptom,ghost-draft}/`
      matches (the endpoint string lives physically in exactly these two
      directories — `analyze-symptom/_lib/geminiVisionClient.ts` and
      `ghost-draft/_lib/geminiTextClient.ts`). **A naive PER-DIRECTORY grep
      of `analyze-drug`/`analyze-prescription` alone returns NO match and
      is misleading** — those two routes are still genuinely LLM-backed,
      they just import the shared client transitively, via
      `../analyze-symptom/_lib/imageAnalyzer` (which itself imports
      `./geminiVisionClient`), rather than duplicating it (§1.2, §3).
      Confirm their LLM-backed status instead via
      `grep -n "geminiVisionClient" apps/admin/src/app/api/inbox/actions/{analyze-drug,analyze-prescription}/route.test.ts`
      (both `route.test.ts` files `jest.mock()` the shared
      `../analyze-symptom/_lib/geminiVisionClient` module directly, proving
      the real call graph reaches it) — together these two checks correctly
      classify all 4 real-LLM routes (`analyze-symptom`, `analyze-drug`,
      `analyze-prescription`, `ghost-draft`) and leave the other 4
      (`learn-draft`, `draft-style`, `customer-health`,
      `classify-customer`) with no match under either check, matching §3.
- [ ] `cd apps/admin && npx jest src/app/api/inbox/actions/{analyze-symptom,analyze-drug,analyze-prescription,ghost-draft,learn-draft,draft-style,customer-health,classify-customer}`
      — all pass (10 suites / 108 tests as of this writing).
- [ ] This document (`docs/runbooks/phase4-batch7-ai-copilot-parity.md`)
      exists and is a genuinely new file — `git diff` shows no other file
      under `docs/runbooks/` modified.
- [ ] `git diff --stat origin/main` (for this agent's own commits) touches
      only `infra/nginx/routes.json`, `infra/nginx/generated/strangler-edge.conf`,
      and this runbook — no edits to any file under
      `apps/admin/src/app/api/inbox/actions/**`, `apps/admin/src/nav/manifest.ts`,
      `api/ai-chat.php`, `modules/AIChat/**`, `infra/nginx/generate-routes.mjs`,
      or `infra/nginx/routes.schema.json`.
- [ ] No `mig-orchestrator` co-sign required for this PR — Phase 4 clears on
      `mig-verify`'s single gate alone (reduced-review low-risk list; see
      header).
