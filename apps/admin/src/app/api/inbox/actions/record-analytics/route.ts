import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { loadConsultationAnalyzerService, type RecordAnalyticsData } from './_lib/recordAnalytics';

/**
 * POST /api/inbox/actions/record-analytics — literal port of
 * `api/inbox-v2.php`'s `case 'record_analytics': case 'record-analytics':`
 * (lines 1792-1832):
 *
 * ```php
 * case 'record_analytics':
 * case 'record-analytics':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     if (!$userId) { sendError('User ID is required'); }
 *
 *     $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);
 *     if (!$consultationAnalyzer) { sendError('Consultation analyzer service not available', 503); }
 *
 *     $analyticsData = [
 *         'pharmacistId' => (int) ($_POST['pharmacist_id'] ?? $body['pharmacist_id'] ?? $adminId ?? null),
 *         'communicationType' => $_POST['communication_type'] ?? $body['communication_type'] ?? null,
 *         'stageAtClose' => $_POST['stage_at_close'] ?? $body['stage_at_close'] ?? null,
 *         'responseTimeAvg' => isset($_POST['response_time_avg']) ? (int) $_POST['response_time_avg'] : (isset($body['response_time_avg']) ? (int) $body['response_time_avg'] : null),
 *         'messageCount' => isset($_POST['message_count']) ? (int) $_POST['message_count'] : (isset($body['message_count']) ? (int) $body['message_count'] : null),
 *         'aiSuggestionsShown' => (int) ($_POST['ai_suggestions_shown'] ?? $body['ai_suggestions_shown'] ?? 0),
 *         'aiSuggestionsAccepted' => (int) ($_POST['ai_suggestions_accepted'] ?? $body['ai_suggestions_accepted'] ?? 0),
 *         'resultedInPurchase' => filter_var($_POST['resulted_in_purchase'] ?? $body['resulted_in_purchase'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
 *         'purchaseAmount' => isset($_POST['purchase_amount']) ? (float) $_POST['purchase_amount'] : (isset($body['purchase_amount']) ? (float) $body['purchase_amount'] : null),
 *         'symptomCategories' => $_POST['symptom_categories'] ?? $body['symptom_categories'] ?? [],
 *         'drugsRecommended' => $_POST['drugs_recommended'] ?? $body['drugs_recommended'] ?? [],
 *         'successfulPatterns' => $_POST['successful_patterns'] ?? $body['successful_patterns'] ?? []
 *     ];
 *
 *     $success = $consultationAnalyzer->recordAnalytics($userId, $analyticsData);
 *
 *     sendResponse([
 *         'success' => $success,
 *         'message' => $success ? 'Analytics recorded successfully' : 'Failed to record analytics'
 *     ]);
 *     break;
 * ```
 *
 * Body: JSON only — `$_POST` is dead for every real JSON caller (same
 * rationale as `../customer-crm/route.ts`'s doc, spelled out at length
 * there; do not attempt a form-encoded path). Every `$_POST[...] ?? ` half
 * of each PHP `??` chain below is therefore dropped; only the
 * `$body[...] ?? ...` tail is ported.
 *
 * `userId = (int) (body.user_id ?? 0)`; falsy (i.e. exactly `0`, matching
 * PHP's `!$userId` on an int) -> 400 `'User ID is required'`.
 *
 * `loadConsultationAnalyzerService()` — the mockable port of PHP's
 * `loadService('ConsultationAnalyzerService', ...)` gate — see `_lib/
 * recordAnalytics.ts`'s module doc for why this branch is defensively
 * coded (structurally unreachable on real traffic, a static import always
 * resolves) YET, per this batch's explicit brief, kept as a real,
 * `jest.mock`-exercisable code path — unlike the sibling
 * `ConsultationAnalyzerService` actions elsewhere in this family
 * (`detect-urgency`, `consultation-stage`, `quick-actions`,
 * `context-widgets`), which decline to fabricate this branch at all. Same
 * pattern this batch's `../create-template/_lib/createTemplate.ts`
 * (`TemplateService`) already established.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * LOAD-BEARING QUIRK: `pharmacistId` is FORCED to an `int` (never `null`)
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `(int) ($_POST['pharmacist_id'] ?? $body['pharmacist_id'] ?? $adminId
 * ?? null)` wraps the WHOLE `??` chain — including its trailing `?? null`
 * fallback — in an `(int)` cast. `(int) null === 0` in PHP. So an ABSENT
 * `pharmacist_id` (and an absent/`null` `$adminId`) resolves to the literal
 * int `0`, NEVER `null`/`undefined` — ported as `phpIntCast(body.pharmacist_id
 * ?? session.adminUserId ?? null)`, which always returns a `number`. This is
 * DIFFERENT from `responseTimeAvg`/`messageCount`/`purchaseAmount` below,
 * which use PHP's `isset(...) ? (cast) ... : null` ternary form and so CAN
 * genuinely end up `null`. `TenantSession.adminUserId` is always a real
 * `number` (never `null`), so in practice `pharmacistId` here always ends up
 * being either the caller-supplied value or `session.adminUserId` — the
 * `?? null` tail is structurally unreachable, kept only for literal parity
 * with the PHP expression shape (same precedent as
 * `../add-customer-note/route.ts`'s own `createdBy` handling).
 *
 * `responseTimeAvg`/`messageCount` — PHP `isset($body[key]) ? (int)
 * $body[key] : null` — genuinely nullable, present-and-non-null -> int cast,
 * else `null`. `purchaseAmount` — same isset-ternary shape, `(float)` cast
 * instead of `(int)`.
 *
 * `aiSuggestionsShown`/`aiSuggestionsAccepted` — PHP `(int) ($body[key] ??
 * 0)` — absent/`null` -> `0`, never `null`.
 *
 * `resultedInPurchase` — PHP `filter_var($body['resulted_in_purchase'] ??
 * false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0`. `phpFilterVarBoolean()` below
 * is empirically verified against a real `php` 8.4 CLI across
 * bool/string/number/null/array inputs (see this batch's runbook): PHP
 * casts the value to a string first (`true`->`'1'`, `false`->`''`, an
 * int/float -> its decimal text, e.g. `1.0`->`'1'`; arrays are rejected
 * outright) then matches, case-insensitively and trimmed, against the
 * fixed true-list `1|true|on|yes` — everything else (the fixed false-list
 * `0|false|off|no|''` AND any unrecognized value) is `false`, since
 * `FILTER_NULL_ON_FAILURE` is not passed.
 *
 * `symptomCategories`/`drugsRecommended`/`successfulPatterns` — PHP
 * `$body[key] ?? []` — absent/`null` -> `[]`; JSON-stringified inside
 * `recordAnalytics()` itself (`_lib/recordAnalytics.ts`), not here — this
 * route only resolves the raw value, matching how the PHP case block hands
 * the raw (un-encoded) value to the service method, which does its own
 * `json_encode(...)` internally.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * LOAD-BEARING: RESPONSE IS UNCONDITIONALLY HTTP 200, EVEN ON `success:false`
 * ═══════════════════════════════════════════════════════════════════════
 * `sendResponse(['success' => $success, 'message' => ...])` passes NO
 * explicit status code — `$success === false` (a genuine, real outcome:
 * `recordAnalytics()`'s own internal `catch` swallows DB errors into a
 * `false` return, see `_lib/recordAnalytics.ts`) still comes back as HTTP
 * 200, `{success:false, message:'Failed to record analytics'}`. This is a
 * literal-parity PHP quirk, not a bug — `route.test.ts` asserts the 200
 * directly for this branch. Do NOT wrap a `false` result in a 400/500.
 *
 * This case block has NO case-level `try/catch` of its own in PHP (same
 * situation as `../poll`/`../detect-urgency`/`../low-stock-drugs`) — a
 * genuinely unexpected error here would fall through to
 * `api/inbox-v2.php`'s generic outer `catch (Throwable $e)`. Following the
 * house precedent those siblings already established for this identical
 * no-case-catch situation, this route wraps the service call in a
 * defensive `try/catch` producing the uniform `'Database error: {message}'`
 * shape at HTTP 500 — documented as UNREACHABLE IN PRACTICE, since the
 * ported `recordAnalytics()` itself must swallow its own DB errors and
 * return `false` (mirroring PHP's own internal `try/catch` around the
 * `INSERT`), never throw.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` cast on a decoded JSON body value (number, numeric string, or other scalar). */
function phpIntCast(value: unknown): number {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? Math.trunc(value) : 0;
  }
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  if (typeof value === 'boolean') {
    return value ? 1 : 0;
  }
  return 0;
}

/** PHP `(float) $v` cast on a decoded JSON body value. */
function phpFloatCast(value: unknown): number {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : 0;
  }
  if (typeof value === 'string') {
    const n = Number.parseFloat(value);
    return Number.isFinite(n) ? n : 0;
  }
  if (typeof value === 'boolean') {
    return value ? 1 : 0;
  }
  return 0;
}

/** PHP `isset($body[key])` on an already-decoded JSON value — false for both a missing key (`undefined`) and an explicit JSON `null`. */
function issetBody(value: unknown): boolean {
  return value !== undefined && value !== null;
}

/**
 * PHP `filter_var($v ?? false, FILTER_VALIDATE_BOOLEAN)` (no
 * `FILTER_NULL_ON_FAILURE`) applied to a decoded-JSON-body value. See
 * module doc for the empirically-verified truth table.
 */
function phpFilterVarBoolean(value: unknown): boolean {
  let str: string;
  if (value === null || value === undefined) return false;
  if (typeof value === 'boolean') {
    str = value ? '1' : '';
  } else if (typeof value === 'number') {
    if (!Number.isFinite(value)) return false;
    str = String(value);
  } else if (typeof value === 'string') {
    str = value;
  } else {
    // Arrays/objects — PHP's filter_var() rejects these outright (empirically false, no string coercion attempted).
    return false;
  }
  const normalized = str.trim().toLowerCase();
  return normalized === '1' || normalized === 'true' || normalized === 'on' || normalized === 'yes';
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const raw: unknown = await request.json().catch(() => ({}));
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const userId = phpIntCast(body.user_id);
  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  const service = loadConsultationAnalyzerService(db, lineAccountId);
  if (!service) {
    return NextResponse.json({ success: false, error: 'Consultation analyzer service not available' }, { status: 503 });
  }

  // pharmacistId — forced to an int, never null (see module doc's LOAD-BEARING QUIRK).
  const pharmacistId = phpIntCast(body.pharmacist_id ?? session.adminUserId ?? null);
  const communicationType = (body.communication_type ?? null) as string | null;
  const stageAtClose = (body.stage_at_close ?? null) as string | null;
  const responseTimeAvg = issetBody(body.response_time_avg) ? phpIntCast(body.response_time_avg) : null;
  const messageCount = issetBody(body.message_count) ? phpIntCast(body.message_count) : null;
  const aiSuggestionsShown = phpIntCast(body.ai_suggestions_shown ?? 0);
  const aiSuggestionsAccepted = phpIntCast(body.ai_suggestions_accepted ?? 0);
  const resultedInPurchase: 0 | 1 = phpFilterVarBoolean(body.resulted_in_purchase ?? false) ? 1 : 0;
  const purchaseAmount = issetBody(body.purchase_amount) ? phpFloatCast(body.purchase_amount) : null;
  const symptomCategories = body.symptom_categories ?? [];
  const drugsRecommended = body.drugs_recommended ?? [];
  const successfulPatterns = body.successful_patterns ?? [];

  const analyticsData: RecordAnalyticsData = {
    pharmacistId,
    communicationType,
    stageAtClose,
    responseTimeAvg,
    messageCount,
    aiSuggestionsShown,
    aiSuggestionsAccepted,
    resultedInPurchase,
    purchaseAmount,
    symptomCategories,
    drugsRecommended,
    successfulPatterns,
  };

  try {
    const success = await service.recordAnalytics(userId, analyticsData);
    // Unconditional HTTP 200, even when success === false — see module doc.
    return NextResponse.json({
      success,
      message: success ? 'Analytics recorded successfully' : 'Failed to record analytics',
    });
  } catch (error) {
    // Defensive, house-precedent addition — unreachable in practice (recordAnalytics() swallows its own DB errors). See module doc.
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
