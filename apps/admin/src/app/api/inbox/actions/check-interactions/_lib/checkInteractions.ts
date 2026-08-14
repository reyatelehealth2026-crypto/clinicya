import { checkDrugInteractions, type DrugInteractionCheckResult } from '../../patient-profile/_lib/patientProfile';

/**
 * checkInteractions.ts — thin params-parsing wrapper around
 * `PharmacyIntegrationService::checkDrugInteractions()`, as driven by
 * api/inbox-v2.php's `case 'check_interactions': case 'check-interactions':`
 * (lines ~881-906):
 *
 * ```php
 * case 'check_interactions':
 * case 'check-interactions':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $drugNames = $_POST['drugs'] ?? $body['drugs'] ?? [];
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     if (empty($drugNames)) { sendError('Drug names array is required'); }
 *     if (is_string($drugNames)) {
 *         $drugNames = json_decode($drugNames, true) ?? explode(',', $drugNames);
 *     }
 *     $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);
 *     if (!$integration) { sendError('Integration service not available', 503); }
 *     $result = $integration->checkDrugInteractions($drugNames, $userId ?: null);
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * The service method itself is the ALREADY-PORTED, ALREADY-EXPORTED
 * `checkDrugInteractions()` from `../../patient-profile/_lib/patientProfile.ts`
 * (Phase 4 batch 4b) — imported directly rather than re-ported, per this
 * batch's brief's read-only cross-batch import precedent (same "single
 * owner" rule as Phase 4 batch 4a's `drug-info` -> `max-discount` import).
 * `check_interactions` therefore transitively benefits from every fix and
 * simplification already documented on that module (the dropped
 * `SHOW TABLES LIKE 'drug_interactions'` guard, the `LOWER()`+`UNION`
 * bidirectional search, etc.) — see that module's own doc for the full
 * detail. Do NOT conflate this with `check_drug_interactions`'s OWN,
 * independent `findInteraction()`/`checkInteractions()` port in the sibling
 * `../check-drug-interactions/_lib/checkDrugInteractions.ts` — the two
 * services implement genuinely different interaction-search algorithms
 * (see this batch's runbook §1 for the full scoping correction).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `$drugNames = $_POST['drugs'] ?? $body['drugs'] ?? []` — JSON-body-only here
 * ═══════════════════════════════════════════════════════════════════════
 * A Route Handler has no `$_POST` equivalent, so only the JSON request body
 * is read (`body.drugs`), matching every other ported action in this batch.
 *
 * `empty($drugNames)` — PHP's `empty()` on an ARRAY checks LENGTH, not
 * truthiness (`empty([])` is `true`; a non-empty array is never "empty"
 * regardless of contents); on a STRING it's the usual falsy check (`''`/
 * `'0'`). This 400 guard runs on the RAW, pre-parse value (before the
 * `is_string()` JSON/CSV parse step below) — `isPhpEmpty()` reproduces
 * both branches for exactly that raw value.
 *
 * `is_string($drugNames) -> json_decode(...) ?? explode(',', ...)` — a
 * `drugs` value that arrives as a JSON-encoded string (`'["A","B"]'`) is
 * JSON-parsed; PHP's `??` here only short-circuits on `json_decode`
 * returning literal `null` (invalid JSON, OR the JSON literal `"null"`
 * itself) — any other decode result (including a JSON string/number
 * literal) is used as-is. `parseDrugNamesValue()` below ports this.
 *
 * `$userId ?: null` — PHP's `?:` short ternary is a TRUTHINESS check: a
 * `userId` of `0` (absent/non-numeric `user_id`) becomes `null`, matching
 * this port's `userId || null` (JS `||` is likewise truthiness-based).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP's `(int) $v` — loose int cast, non-numeric -> 0. */
export function intval(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'string') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

/**
 * PHP `empty($v)` on a raw JSON-body value: array -> length check, string
 * -> falsy-string check (`''`/`'0'`), number -> `=== 0`, bool -> `=== false`,
 * `null`/`undefined` -> `true`. An object (JSON body value is never
 * meaningfully "empty" in the array sense) is treated as non-empty, mirroring
 * PHP's `empty()` on a non-empty-castable object-like value.
 */
export function isPhpEmpty(value: unknown): boolean {
  if (Array.isArray(value)) return value.length === 0;
  if (typeof value === 'string') return value === '' || value === '0';
  if (typeof value === 'number') return value === 0;
  if (typeof value === 'boolean') return value === false;
  return value === null || value === undefined;
}

/**
 * PHP `is_string($drugNames) ? (json_decode($drugNames, true) ?? explode(',', $drugNames)) : $drugNames`.
 * The array branch is a passthrough (stringified defensively — this port's
 * `checkDrugInteractions()` expects `string[]`); a non-array, non-string
 * JSON body value (number/object/bool) has no faithful PHP-array
 * equivalent here and normalizes to `[]`.
 */
export function parseDrugNamesValue(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value.map((v) => String(v));
  }
  if (typeof value !== 'string') {
    return [];
  }
  try {
    const decoded: unknown = JSON.parse(value);
    if (decoded !== null) {
      return Array.isArray(decoded) ? decoded.map((v) => String(v)) : [String(decoded)];
    }
  } catch {
    // json_decode() returning null on invalid JSON — fall through to explode(',', ...).
  }
  return value.split(',');
}

export interface CheckInteractionsParseResult {
  drugNames: string[];
  userId: number | null;
}

export type CheckInteractionsParseOutcome =
  | { ok: true; value: CheckInteractionsParseResult }
  | { ok: false; error: string };

/** Parses the JSON body per the literal PHP shape documented above. */
export function parseCheckInteractionsBody(body: Record<string, unknown>): CheckInteractionsParseOutcome {
  const rawDrugNames = body.drugs ?? [];
  const userIdRaw = intval(body.user_id ?? 0);

  if (isPhpEmpty(rawDrugNames)) {
    return { ok: false, error: 'Drug names array is required' };
  }

  const drugNames = parseDrugNamesValue(rawDrugNames);

  return { ok: true, value: { drugNames, userId: userIdRaw || null } };
}

export { checkDrugInteractions, type DrugInteractionCheckResult };
