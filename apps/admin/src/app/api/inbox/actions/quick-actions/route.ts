import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getQuickActions } from './_lib/quickActions';
import { detectStage } from '../consultation-stage/_lib/consultationStage';

/**
 * GET /api/inbox/actions/quick-actions — port of api/inbox-v2.php's
 * `case 'quick_actions': case 'quick-actions': case 'get_quick_actions':`
 * (lines ~1604-1642):
 *
 * ```php
 * case 'quick_actions':
 * case 'quick-actions':
 * case 'get_quick_actions':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     $stage = $_GET['stage'] ?? '';
 *     $hasUrgent = filter_var($_GET['has_urgent'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
 *     $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);
 *     if (!$consultationAnalyzer) { sendError('Consultation analyzer service not available', 503); }
 *     // If no stage provided, detect it from user messages
 *     if (empty($stage) && $userId) {
 *         $stageResult = $consultationAnalyzer->detectStage($userId);
 *         $stage = $stageResult['stage'];
 *         $hasUrgent = $stageResult['hasUrgentSymptoms'] ?? $hasUrgent;
 *     }
 *     // Default to symptom assessment if still no stage
 *     if (empty($stage)) { $stage = 'symptom_assessment'; }
 *     $actions = $consultationAnalyzer->getQuickActions($stage, $hasUrgent);
 *     sendResponse(['success' => true, 'data' => $actions]);
 *     break;
 * ```
 *
 * Port of `ConsultationAnalyzerService::getQuickActions()` — see
 * `_lib/quickActions.ts`'s module doc for the full literal action-list
 * port. The `detectStage($userId)` composition above is reproduced HERE
 * (route.ts), not in `_lib/quickActions.ts`, which stays a pure function —
 * `detectStage` is imported from
 * `../consultation-stage/_lib/consultationStage`, the ONE cross-import this
 * batch's brief specifies (mirroring the established `drug-info ->
 * max-discount` precedent from Phase 4 batch 4a).
 *
 * Query params:
 *   - `user_id` (int, required, 400 `'Invalid user ID'` if `<= 0`).
 *   - `stage` (string, optional) — PHP's `$_GET['stage'] ?? ''` (isset()-
 *     based; absent -> `''`).
 *   - `has_urgent` (optional) — `filter_var($_GET['has_urgent'] ?? 'false',
 *     FILTER_VALIDATE_BOOLEAN)`: without `FILTER_NULL_ON_FAILURE`, only
 *     `'1'`/`'true'`/`'on'`/`'yes'` (case-insensitive) validate `true`;
 *     every other string — including `'0'`/`'false'`/`'off'`/`'no'`/`''`
 *     AND any unrecognized string — validates `false`. Ported as
 *     `phpFilterVarBoolean()` below. `params.get('has_urgent') ?? 'false'`
 *     mirrors PHP's isset()-based `??` correctly here because
 *     `URLSearchParams.get()` already returns `null` (not `''`) when the
 *     key is absent from the query string, same as a PHP array access on a
 *     missing key.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * COMPOSITION: `stage` empty -> `detectStage(userId)` -> adopts BOTH its
 * `stage` AND its `hasUrgentSymptoms` (overriding any `has_urgent` param)
 * ═══════════════════════════════════════════════════════════════════════
 * `$userId` is always > 0 by the time PHP reaches `if (empty($stage) &&
 * $userId)` (the earlier `$userId <= 0` check already rejected anything
 * else), so that condition is equivalent in practice to `if
 * (empty($stage))`. `$stageResult['hasUrgentSymptoms'] ?? $hasUrgent` always
 * takes the LEFT side (`detectStage()` never returns `hasUrgentSymptoms` as
 * `null`), so a caller-supplied `has_urgent=true` is silently discarded
 * whenever `stage` was empty and detection says otherwise. Ported exactly:
 * `hasUrgent` is unconditionally reassigned from `stageResult.
 * hasUrgentSymptoms` inside the `if (!stage)` branch.
 *
 * PHP's `empty($stage)` treats both `''` and the literal string `'0'` as
 * empty — ported via `isPhpEmptyString()` below (same helper shape as
 * `../check-allergy/route.ts`'s `isPhpEmptyString`).
 *
 * The second `if (empty($stage)) { $stage = 'symptom_assessment'; }`
 * fallback is kept literally (cheap, matches PHP's structure) even though
 * it is practically unreachable: `detectStage()` always returns one of the
 * 4 non-empty stage strings (defaulting to `'symptom_assessment'` itself on
 * its own 0-messages short circuit — see `../consultation-stage/_lib/
 * consultationStage.ts`), so by the time this second check runs, `stage` is
 * never still empty. The natural "0 messages -> detectStage defaults to
 * `symptom_assessment`" case is what the acceptance test exercises to
 * demonstrate the END-TO-END "falls back to symptom_assessment" behavior,
 * not this textually-present-but-dead second branch itself.
 *
 * `success: true` is UNCONDITIONAL — neither `detectStage()` nor
 * `getQuickActions()` ever throws (see their own module docs), so the
 * `try/catch` below is a defensive addition, following the house precedent
 * set by Phase 4 batch 4a's `low-stock-drugs`/`drug-inventory`.
 *
 * "Consultation analyzer service not available" 503 — never fabricated in
 * this port, same "static import vs. PHP's runtime file_exists()/
 * class_exists() probe" reasoning as `../max-discount/_lib/
 * drugPricingEngine.ts`'s module doc (Phase 4 batch 4a).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` for a query-string value — leading numeric parse, else 0. */
function toIntParam(value: string): number {
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `empty($v)` for a string value already known to be a `string` — true for `''` and the exact string `'0'`. */
function isPhpEmptyString(value: string): boolean {
  return value === '' || value === '0';
}

/**
 * PHP `filter_var($v, FILTER_VALIDATE_BOOLEAN)` without `FILTER_NULL_ON_FAILURE`:
 * case-insensitive `'1'`/`'true'`/`'on'`/`'yes'` -> `true`; every other
 * string (including unrecognized ones) -> `false`.
 */
function phpFilterVarBoolean(value: string): boolean {
  const normalized = value.toLowerCase();
  return normalized === '1' || normalized === 'true' || normalized === 'on' || normalized === 'yes';
}

export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const params = request.nextUrl.searchParams;
  const userId = toIntParam(params.get('user_id') ?? '');
  if (userId <= 0) {
    return NextResponse.json({ success: false, error: 'Invalid user ID' }, { status: 400 });
  }

  let stage = params.get('stage') ?? '';
  let hasUrgent = phpFilterVarBoolean(params.get('has_urgent') ?? 'false');

  const { db } = auth.value;

  try {
    // If no stage provided, detect it from user messages (userId is already > 0 here).
    if (isPhpEmptyString(stage)) {
      const stageResult = await detectStage(db, userId);
      stage = stageResult.stage;
      hasUrgent = stageResult.hasUrgentSymptoms;
    }

    // Default to symptom assessment if still no stage (textually present, practically unreachable — see module doc).
    if (isPhpEmptyString(stage)) {
      stage = 'symptom_assessment';
    }

    const actions = getQuickActions(stage, hasUrgent);
    return NextResponse.json({ success: true, data: actions });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
