import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * recordAnalytics.ts — literal port of `classes/ConsultationAnalyzerService.php`'s
 * `recordAnalytics()` (lines 1802-1832), backing `api/inbox-v2.php`'s
 * `case 'record_analytics': case 'record-analytics':` (lines 1792-1832).
 *
 * ```php
 * public function recordAnalytics(int $userId, array $data): bool
 * {
 *     try {
 *         $stmt = $this->db->prepare("
 *             INSERT INTO consultation_analytics
 *             (user_id, pharmacist_id, communication_type, stage_at_close,
 *              response_time_avg, message_count, ai_suggestions_shown, ai_suggestions_accepted,
 *              resulted_in_purchase, purchase_amount, symptom_categories, drugs_recommended, successful_patterns)
 *             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
 *         ");
 *         return $stmt->execute([
 *             $userId,
 *             $data['pharmacistId'] ?? null,
 *             $data['communicationType'] ?? null,
 *             $data['stageAtClose'] ?? null,
 *             $data['responseTimeAvg'] ?? null,
 *             $data['messageCount'] ?? null,
 *             $data['aiSuggestionsShown'] ?? 0,
 *             $data['aiSuggestionsAccepted'] ?? 0,
 *             $data['resultedInPurchase'] ?? 0,
 *             $data['purchaseAmount'] ?? null,
 *             json_encode($data['symptomCategories'] ?? [], JSON_UNESCAPED_UNICODE),
 *             json_encode($data['drugsRecommended'] ?? [], JSON_UNESCAPED_UNICODE),
 *             json_encode($data['successfulPatterns'] ?? [], JSON_UNESCAPED_UNICODE)
 *         ]);
 *     } catch (PDOException $e) {
 *         error_log("ConsultationAnalyzer recordAnalytics error: " . $e->getMessage());
 *         return false;
 *     }
 * }
 * ```
 *
 * `consultation_analytics` (columns `user_id`, `pharmacist_id`,
 * `communication_type`, `stage_at_close`, `response_time_avg`,
 * `message_count`, `ai_suggestions_shown`, `ai_suggestions_accepted`,
 * `resulted_in_purchase`, `purchase_amount`, `symptom_categories`,
 * `drugs_recommended`, `successful_patterns`) is confirmed present in
 * `packages/db/src/generated/tenant-db.d.ts` (`ConsultationAnalytics`) with
 * every column this INSERT touches correctly typed — a fully literal,
 * unmodified port, no schema-drift fix needed.
 * `ConsultationAnalytics.communication_type` is typed as the narrow union
 * `"A" | "B" | "C" | null` there, but PHP's own `recordAnalytics()` never
 * validates `communicationType` against that whitelist before binding it —
 * this port uses a raw `sql` tagged-template INSERT (not Kysely's typed
 * `.insertInto()` query builder) for exactly this reason: it must accept
 * and bind whatever string (or `null`) the caller supplies, unvalidated,
 * exactly like PHP's own unchecked bound parameter.
 *
 * The internal PHP `?? null` / `?? 0` / `?? []` fallbacks on each `$data[...]`
 * key are, in practice, DEAD CODE for every real caller of this method: the
 * ONE call site (`../route.ts`, mirroring the case block's own
 * `$analyticsData = [...]` construction at lines 1811-1824) always supplies
 * every key with an already-resolved value (never an actually-missing PHP
 * array key) — see `../route.ts`'s own module doc for the field-by-field
 * resolution, including the `pharmacistId`-forced-to-`0`-never-`null` quirk.
 * This TypeScript port's `RecordAnalyticsData` interface therefore makes
 * every field required (not optional) rather than re-deriving PHP's
 * per-key `??` fallbacks a second time — the caller (`../route.ts`) is the
 * single place those defaults are computed, matching the PHP case block's
 * own division of labor between the switch body and the service method.
 *
 * `json_encode($arr, JSON_UNESCAPED_UNICODE)` <-> `JSON.stringify(arr)` —
 * JS's `JSON.stringify` never escapes non-ASCII characters to `\uXXXX`
 * sequences by default (unlike PHP's `json_encode()`, which DOES escape
 * unicode UNLESS `JSON_UNESCAPED_UNICODE` is explicitly passed) — so
 * `JSON.stringify()` is already the behavioral equivalent of PHP's
 * `JSON_UNESCAPED_UNICODE`-flagged call, not merely an approximation: Thai
 * text embedded in `symptomCategories`/`drugsRecommended`/
 * `successfulPatterns` round-trips un-mangled either way.
 *
 * `$stmt->execute(...)`'s boolean return (`true` on success) becomes an
 * unconditional `return true` on a successful `INSERT` here — PHP's PDO
 * `execute()` returns `true` for a successful statement regardless of rows-
 * affected count (an `INSERT` always affects exactly 1 row when it
 * succeeds at all), so there is no "0 rows affected" case to special-case.
 * `catch (PDOException $e) { error_log(...); return false; }` -> a bare
 * `catch { return false; }` here — no `console.error`/analogous call,
 * matching the established house precedent for this exact
 * "PHP `catch` that only logs+returns false, never rethrows" shape (see
 * e.g. `../../consultation-stage/_lib/consultationStage.ts`'s own
 * `saveStage()` — its module doc explicitly notes it "swallows write
 * failures", the same choice made here).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `loadConsultationAnalyzerService()` — the mockable port of PHP's
 * `loadService('ConsultationAnalyzerService', $db, $lineAccountId)` gate
 * ═══════════════════════════════════════════════════════════════════════
 * `api/inbox-v2.php`'s `case 'record_analytics':` DOES call
 * `loadService('ConsultationAnalyzerService', $db, $lineAccountId)` and DOES
 * gate on it (`if (!$consultationAnalyzer) { sendError('Consultation
 * analyzer service not available', 503); }`, lines 1805-1809) — a real,
 * textually-present branch in the PHP source, unlike this batch's
 * `analytics`/`log-performance-metric`/`get-performance-metrics` siblings
 * (none of which have any `loadService()` call at all — see each of their
 * own module docs). In THIS repo `classes/ConsultationAnalyzerService.php`
 * is a committed file that always resolves both of `loadService()`'s
 * internal checks (`file_exists()` + `class_exists()`), so this factory
 * always returns a real handle on real traffic — the `503 'Consultation
 * analyzer service not available'` branch it backs is defensively coded
 * and structurally unreachable in production, the same "static import vs.
 * PHP's runtime file_exists()/class_exists() probe" situation as every
 * OTHER `ConsultationAnalyzerService`-backed action in this family
 * (`../../detect-urgency`, `../../consultation-stage`, `../../quick-actions`,
 * `../../context-widgets` — none of which fabricate a runtime 503 branch).
 * UNLIKE those four siblings, though, this batch's brief explicitly calls
 * for this ONE action's branch to remain exercisable from a test —
 * `route.test.ts` does so with an explicit `jest.mock` of this named
 * export, never by finding a real way to make it return `null` — the exact
 * same pattern this same batch's `../../create-template/_lib/
 * createTemplate.ts` already established for `TemplateService`'s own
 * identical `loadService()` gate. This is a deliberate, brief-driven,
 * batch-local choice for this one action, not a claim that the other four
 * `ConsultationAnalyzerService` actions' "never fabricate it" choice was
 * wrong.
 */

export interface RecordAnalyticsData {
  /** Forced to an `int` (never `null`) by `../route.ts` — see that file's own module doc for the exact quirk. */
  pharmacistId: number;
  communicationType: string | null;
  stageAtClose: string | null;
  responseTimeAvg: number | null;
  messageCount: number | null;
  aiSuggestionsShown: number;
  aiSuggestionsAccepted: number;
  resultedInPurchase: 0 | 1;
  purchaseAmount: number | null;
  /** PHP never validates these are arrays before `json_encode()`-ing them — typed `unknown`, not `unknown[]`, for full fidelity. Route-level default is `[]` when absent from the body. */
  symptomCategories: unknown;
  drugsRecommended: unknown;
  successfulPatterns: unknown;
}

export async function recordAnalytics(db: Kysely<TenantDB>, userId: number, data: RecordAnalyticsData): Promise<boolean> {
  try {
    await sql`
      INSERT INTO consultation_analytics
      (user_id, pharmacist_id, communication_type, stage_at_close,
       response_time_avg, message_count, ai_suggestions_shown, ai_suggestions_accepted,
       resulted_in_purchase, purchase_amount, symptom_categories, drugs_recommended, successful_patterns)
      VALUES (
        ${userId}, ${data.pharmacistId}, ${data.communicationType}, ${data.stageAtClose},
        ${data.responseTimeAvg}, ${data.messageCount}, ${data.aiSuggestionsShown}, ${data.aiSuggestionsAccepted},
        ${data.resultedInPurchase}, ${data.purchaseAmount}, ${JSON.stringify(data.symptomCategories ?? [])},
        ${JSON.stringify(data.drugsRecommended ?? [])}, ${JSON.stringify(data.successfulPatterns ?? [])}
      )
    `.execute(db);
    return true;
  } catch {
    // Matches PHP's `catch (PDOException $e) { error_log(...); return false; }` — swallowed, never rethrown.
    return false;
  }
}

export interface ConsultationAnalyzerServiceHandle {
  recordAnalytics(userId: number, data: RecordAnalyticsData): Promise<boolean>;
}

/** Port of `loadService('ConsultationAnalyzerService', $db, $lineAccountId)` — see module doc. */
export function loadConsultationAnalyzerService(db: Kysely<TenantDB>, lineAccountId: number): ConsultationAnalyzerServiceHandle | null {
  void lineAccountId; // PHP's constructor stores it but recordAnalytics() never reads $this->lineAccountId.
  return {
    recordAnalytics: (userId, data) => recordAnalytics(db, userId, data),
  };
}
