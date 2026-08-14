import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getContextWidgets } from './_lib/contextWidgets';

/**
 * GET /api/inbox/actions/context-widgets — port of api/inbox-v2.php's
 * `case 'context_widgets': case 'context-widgets': case
 * 'get_context_widgets':` (lines ~1521-1564):
 *
 * ```php
 * case 'context_widgets':
 * case 'context-widgets':
 * case 'get_context_widgets':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     $message = $_GET['message'] ?? '';
 *     if (!$userId) { sendError('User ID is required'); }
 *     // Message is optional - return empty widgets if no message
 *     if (empty($message)) {
 *         sendResponse(['success' => true, 'data' => ['widgets' => [], 'count' => 0]]);
 *     }
 *     $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);
 *     if (!$consultationAnalyzer) { sendError('Consultation analyzer service not available', 503); }
 *     $widgets = $consultationAnalyzer->getContextWidgets($message, $userId);
 *     sendResponse(['success' => true, 'data' => ['widgets' => $widgets, 'count' => count($widgets)]]);
 *     break;
 * ```
 *
 * Port of `ConsultationAnalyzerService::getContextWidgets()` — see
 * `_lib/contextWidgets.ts`'s module doc for the full literal port,
 * including the SAFETY-CRITICAL allergy-warning unshift-then-cap ordering
 * and the `checkForDrugNames()`/`searchDrugsFromMessage()` deliberate
 * duplication note.
 *
 * Query params:
 *   - `user_id` (int, required, 400 `'Invalid user ID'` if `<= 0`).
 *   - `message` (string, optional) — PHP's `empty($message)` check treats
 *     BOTH `''` and the literal string `'0'` as empty, returning
 *     `{success: true, data: {widgets: [], count: 0}}` at HTTP 200 — this is
 *     NOT an error path, ported exactly via `isPhpEmptyString()`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * Same reasoning as `../medical-history/route.ts`'s doc — only the
 * reachable `'Invalid user ID'` 400 is ported.
 *
 * `success: true` is UNCONDITIONAL — `getContextWidgets()` never throws
 * (every DB-touching helper it calls swallows its own PDOException — see
 * `_lib/contextWidgets.ts`), so the `try/catch` below is a defensive
 * addition, following the house precedent set by Phase 4 batch 4a's
 * `low-stock-drugs`/`drug-inventory`.
 *
 * "Consultation analyzer service not available" 503 — never fabricated in
 * this port, same "static import vs. PHP's runtime file_exists()/
 * class_exists() probe" reasoning as `../max-discount/_lib/
 * drugPricingEngine.ts`'s module doc (Phase 4 batch 4a).
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the established
 * `api/inbox/conversations/route.ts` precedent, matching
 * `../search-drugs/route.ts` and every sibling action that needs it.
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

  const message = params.get('message') ?? '';
  if (isPhpEmptyString(message)) {
    return NextResponse.json({ success: true, data: { widgets: [], count: 0 } });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const widgets = await getContextWidgets(db, lineAccountId, message, userId);
    return NextResponse.json({ success: true, data: { widgets, count: widgets.length } });
  } catch (error) {
    const errMessage = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${errMessage}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
