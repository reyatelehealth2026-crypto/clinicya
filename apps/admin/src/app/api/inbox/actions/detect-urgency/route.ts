import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { detectUrgency } from './_lib/detectUrgency';

/**
 * GET /api/inbox/actions/detect-urgency — port of api/inbox-v2.php's
 * `case 'detect_urgency': case 'detect-urgency':` (lines ~1648-1675):
 *
 * ```php
 * case 'detect_urgency':
 * case 'detect-urgency':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     if (!$userId) { sendError('User ID is required'); }
 *     $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);
 *     if (!$consultationAnalyzer) { sendError('Consultation analyzer service not available', 503); }
 *     $urgency = $consultationAnalyzer->detectUrgency($userId);
 *     sendResponse(['success' => true, 'data' => $urgency]);
 *     break;
 * ```
 *
 * Port of `ConsultationAnalyzerService::detectUrgency()` — SAFETY-CRITICAL
 * surface (critical-keyword classification, urgency levels
 * normal/moderate/high/critical). See `_lib/detectUrgency.ts`'s module doc
 * for the full literal port: the exact critical-keyword subset, the exact
 * decision tree, the `consultation_stages.has_urgent_symptoms` fallback
 * bump, and the exact Thai reason/recommendation strings (ported
 * byte-for-byte, not retranslated or reworded).
 *
 * Query param: `user_id` (int, required, 400 `'Invalid user ID'` if `<= 0`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * Same reasoning as `../medical-history/route.ts`'s doc — only the
 * reachable `'Invalid user ID'` 400 is ported.
 *
 * `success: true` is UNCONDITIONAL — `detectUrgency()` never throws (its
 * `getRecentMessages()` call and its `consultation_stages` lookup both
 * swallow their own DB errors — see `_lib/detectUrgency.ts`), so the
 * `try/catch` below is a defensive addition for genuinely unexpected
 * errors, following the house precedent set by Phase 4 batch 4a's
 * `low-stock-drugs`/`drug-inventory` (uniform `'Database error:
 * {message}'` 500 shape).
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

  const { db } = auth.value;

  try {
    const urgency = await detectUrgency(db, userId);
    return NextResponse.json({ success: true, data: urgency });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
