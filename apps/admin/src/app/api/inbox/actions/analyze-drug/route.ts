import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { isConfigured, identifyDrug } from '../analyze-symptom/_lib/imageAnalyzer';

/**
 * POST /api/inbox/actions/analyze-drug — literal port of api/inbox-v2.php's
 * `case 'analyze_drug': case 'analyze-drug':` (lines 240-276), backed by
 * `PharmacyImageAnalyzerService::identifyDrug()` (now
 * `../analyze-symptom/_lib/imageAnalyzer.ts`'s `identifyDrug()` — a
 * deliberate, documented single-owner cross-route import; all 3
 * `analyze-*` routes are owned by this same builder this round, mirroring
 * the precedent set by Phase 4 batch 4a's drug-info -> max-discount
 * import. See that file's module doc for the full shared engine, including
 * the `matchDrugToProduct()` schema-drift fix-forward and the cache-hit
 * fix-forward).
 *
 * ```php
 * case 'analyze_drug':
 * case 'analyze-drug':
 *     if ($method !== 'POST') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     $imageUrl = $_POST['image_url'] ?? getJsonBody()['image_url'] ?? '';
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
 *     $result = $imageAnalyzer->identifyDrug($imageUrl);
 *     if (!($result['success'] ?? false)) {
 *         sendError($result['error'] ?? 'การวิเคราะห์รูปภาพล้มเหลว');
 *     }
 *
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * Same shape as `analyze-symptom/route.ts` in every other respect — see
 * that file's own doc for the "Image analyzer service not available"
 * structurally-unreachable note, the long-vs-short 503 message
 * disambiguation, the redundant nested `data.success`, and the JSON-body
 * convention. The only differences here: the imported analyzer function
 * (`identifyDrug` instead of `analyzeSymptom`) and this route's OWN
 * distinct default failure message, `'การวิเคราะห์รูปภาพล้มเหลว'` — NOT
 * `analyze-symptom`'s `'การวิเคราะห์อาการล้มเหลว'` or
 * `analyze-prescription`'s `'การอ่านใบสั่งยาล้มเหลว'`.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six `TenantRole` values).
 */

const AI_NOT_CONFIGURED_MESSAGE = 'AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings';
const DEFAULT_FAILURE_MESSAGE = 'การวิเคราะห์รูปภาพล้มเหลว';

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

  const result = await identifyDrug(db, lineAccountId, imageUrl);

  if (!result.success) {
    return NextResponse.json({ success: false, error: result.error ?? DEFAULT_FAILURE_MESSAGE }, { status: 400 });
  }

  return NextResponse.json({ success: true, data: result }, { status: 200 });
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
