import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getRecommendationsData } from './_lib/recommendations';

/**
 * GET /api/inbox/actions/recommendations — port of api/inbox-v2.php's
 * `case 'recommendations': case 'get_recommendations': case
 * 'drug_recommendations':` (lines ~1191-1350). See `_lib/recommendations.ts`'s
 * module doc for the full literal PHP source and the 3-tier priority
 * cascade (chat-history search -> current-message search -> popular-drugs
 * fallback / symptom-based `getForSymptoms()`).
 *
 * Query params: `user_id` (int, required, 400 `'Invalid user ID'` if
 * `<= 0`); `symptoms` (string, default `''`); `type` (string, default
 * `''` — `'context'` triggers the chat-history/popular-fallback branches);
 * `message` (string, default `''`); `limit` (int, default `10`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * Same reasoning as `../medical-history/route.ts`'s doc — only the
 * reachable `'Invalid user ID'` 400 is ported.
 *
 * `success: true` is UNCONDITIONAL across every branch of the cascade —
 * even the popular-drugs fallback's own DB-error path responds
 * `{success: true, data: {..., error: '...'}}` (see `_lib/recommendations.ts`'s
 * `getPopularDrugs()`), matching PHP's literal `sendResponse()` calls. The
 * `try/catch` below is therefore a defensive addition ONLY for the
 * symptom-based `getForSymptoms()` branch and any genuinely unexpected
 * failure, using the house `'Database error: {message}'` 500 shape.
 *
 * "Recommendation engine service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch.
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

  const symptoms = params.get('symptoms') ?? '';
  const type = params.get('type') ?? '';
  const message = params.get('message') ?? '';
  const limit = params.has('limit') ? toIntParam(params.get('limit') ?? '') : 10;

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const data = await getRecommendationsData(db, { userId, symptoms, type, message, limit, lineAccountId });
    return NextResponse.json({ success: true, data });
  } catch (error) {
    const errMessage = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${errMessage}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
