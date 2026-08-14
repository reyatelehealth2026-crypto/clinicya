import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { isConfigured, ocrPrescription } from '../analyze-symptom/_lib/imageAnalyzer';

/**
 * POST /api/inbox/actions/analyze-prescription — literal port of
 * api/inbox-v2.php's `case 'analyze_prescription': case
 * 'analyze-prescription':` (lines 281-323), backed by
 * `PharmacyImageAnalyzerService::ocrPrescription()` (now
 * `../analyze-symptom/_lib/imageAnalyzer.ts`'s `ocrPrescription()` —
 * deliberate single-owner cross-route import, see `analyze-drug/route.ts`'s
 * own doc for the precedent).
 *
 * ```php
 * case 'analyze_prescription':
 * case 'analyze-prescription':
 *     if ($method !== 'POST') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     $body = getJsonBody();
 *     $imageUrl = $_POST['image_url'] ?? $body['image_url'] ?? '';
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *
 *     if ($userId <= 0) {
 *         sendError('Invalid user ID');
 *     }
 *     if (!empty($imageUrl) && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
 *         sendError('Invalid image URL format');
 *     }
 *     if (empty($imageUrl)) {
 *         sendError('Image URL is required');
 *     }
 *
 *     $imageAnalyzer = loadService('PharmacyImageAnalyzerService', $db, $lineAccountId);
 *     if (!$imageAnalyzer) {
 *         sendError('Image analyzer service not available', 503);
 *     }
 *     if (!$imageAnalyzer->isConfigured()) {
 *         sendError('AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings', 503);
 *     }
 *
 *     $result = $imageAnalyzer->ocrPrescription($imageUrl, $userId ?: null);
 *     if (!($result['success'] ?? false)) {
 *         sendError($result['error'] ?? 'การอ่านใบสั่งยาล้มเหลว');
 *     }
 *
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * EXTRA LEADING CHECK, ORDER PRESERVED EXACTLY: unlike `analyze-symptom`/
 * `analyze-drug` (which have no `user_id` param at all), this route
 * validates `userId` FIRST — before either of the `imageUrl` checks. A
 * request with BOTH an invalid `user_id` AND a missing/malformed
 * `image_url` gets `'Invalid user ID'`, never an image-URL error.
 *
 * `ocrPrescription(db, lineAccountId, imageUrl, userId || null)` — PHP's
 * `$userId ?: null` (`?:`, PHP truthy-elvis, not `??`) turns a falsy
 * `userId` (only `0` is reachable here, since the leading `<= 0` guard
 * above already rejects anything else) into `null`. Since this route's own
 * guard already guarantees `userId > 0` by the time this call is reached,
 * `userId || null` is effectively always just `userId` — the `|| null`
 * fallback is unreachable in practice, kept for literal fidelity to the
 * PHP call site.
 *
 * Same shape as `analyze-symptom/route.ts` in every other respect (see
 * that file's own doc for the 503-message disambiguation, the redundant
 * nested `data.success`, and the JSON-body convention). This route's OWN
 * distinct default failure message is `'การอ่านใบสั่งยาล้มเหลว'`.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six `TenantRole` values).
 */

const AI_NOT_CONFIGURED_MESSAGE = 'AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings';
const DEFAULT_FAILURE_MESSAGE = 'การอ่านใบสั่งยาล้มเหลว';

/** PHP `filter_var($imageUrl, FILTER_VALIDATE_URL)` — see analyze-symptom/route.ts's own doc for the `new URL()` equivalence rationale. */
function isValidUrlFormat(value: string): boolean {
  try {
    // eslint-disable-next-line no-new
    new URL(value);
    return true;
  } catch {
    return false;
  }
}

/** PHP's `(int) $v` — non-numeric/null/undefined -> 0. */
function intval(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'string') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  let raw: unknown;
  try {
    raw = await request.json();
  } catch {
    raw = {};
  }
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const userId = intval(body.user_id);
  if (userId <= 0) {
    return NextResponse.json({ success: false, error: 'Invalid user ID' }, { status: 400 });
  }

  const imageUrl = typeof body.image_url === 'string' ? body.image_url : '';

  if (imageUrl !== '' && !isValidUrlFormat(imageUrl)) {
    return NextResponse.json({ success: false, error: 'Invalid image URL format' }, { status: 400 });
  }
  if (imageUrl === '') {
    return NextResponse.json({ success: false, error: 'Image URL is required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  if (!(await isConfigured(db, lineAccountId))) {
    return NextResponse.json({ success: false, error: AI_NOT_CONFIGURED_MESSAGE }, { status: 503 });
  }

  const result = await ocrPrescription(db, lineAccountId, imageUrl, userId || null);

  if (!result.success) {
    return NextResponse.json({ success: false, error: result.error ?? DEFAULT_FAILURE_MESSAGE }, { status: 400 });
  }

  return NextResponse.json({ success: true, data: result }, { status: 200 });
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
