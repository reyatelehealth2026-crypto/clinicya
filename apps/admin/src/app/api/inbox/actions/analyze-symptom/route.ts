import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { isConfigured, analyzeSymptom } from './_lib/imageAnalyzer';

/**
 * POST /api/inbox/actions/analyze-symptom — literal port of
 * api/inbox-v2.php's `case 'analyze_symptom': case 'analyze-symptom':`
 * (lines 199-235), backed by
 * `PharmacyImageAnalyzerService::analyzeSymptom()` (now `_lib/imageAnalyzer.ts`
 * — see that file's module doc for the full engine, including the
 * documented cache-hit fix-forward).
 *
 * ```php
 * case 'analyze_symptom':
 * case 'analyze-symptom':
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
 *     $result = $imageAnalyzer->analyzeSymptom($imageUrl);
 *     if (!($result['success'] ?? false)) {
 *         sendError($result['error'] ?? 'การวิเคราะห์อาการล้มเหลว');
 *     }
 *
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * "Image analyzer service not available" (PHP's `loadService()` returning
 * falsy) has no equivalent gap here — `imageAnalyzer.ts`'s exports are
 * static imports, always present. Same "structurally unreachable in Next"
 * reasoning as `drug-info`'s own `DrugPricingEngineService` 503 note.
 *
 * The 503 message below is the LONG Thai-suffixed form
 * ('AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings')
 * — this is `analyze_symptom`'s own literal PHP string, distinct from the
 * SHORT English-only 503 the `ghost-draft` (draftAndClassify builder)
 * route uses for its own unconfigured-AI case; the two are NOT
 * interchangeable and must not be mixed up between builders.
 *
 * `result.error ?? 'การวิเคราะห์อาการล้มเหลว'` — this Thai default is
 * specific to this route; `analyze-drug` and `analyze-prescription` each
 * have their OWN distinct default string (see those routes' own docs).
 *
 * The `data` envelope intentionally carries a REDUNDANT nested
 * `data.success: true` — PHP wraps the whole `analyzeSymptom()` return
 * array (which already has its own `success` key) under `'data' =>
 * $result`, producing `{success: true, data: {success: true, ...}}`.
 * Preserved exactly, not stripped.
 *
 * Body is JSON-only (`request.json()`), matching this batch's convention
 * for new endpoints (no `$_POST` fallback to reproduce — see e.g.
 * `mark-as-read-on-line/route.ts`'s own note on this).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six `TenantRole` values).
 */

const AI_NOT_CONFIGURED_MESSAGE = 'AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings';
const DEFAULT_FAILURE_MESSAGE = 'การวิเคราะห์อาการล้มเหลว';

/**
 * PHP `filter_var($imageUrl, FILTER_VALIDATE_URL)` — PHP's validator
 * accepts any absolute, scheme-prefixed URI (including `data:` URIs, a
 * well-known quirk of this exact filter). `new URL()` throws on anything
 * that isn't an absolute, scheme-prefixed URI (relative paths, bare
 * strings) and accepts `data:`/`http(s):`/arbitrary-scheme URIs just like
 * PHP's filter does — close enough behaviorally for every real image URL
 * shape this endpoint accepts (`data:image/...`, `https://...`), without
 * reproducing FILTER_VALIDATE_URL's exact regex byte-for-byte.
 */
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

  const result = await analyzeSymptom(db, lineAccountId, imageUrl);

  if (!result.success) {
    return NextResponse.json({ success: false, error: result.error ?? DEFAULT_FAILURE_MESSAGE }, { status: 400 });
  }

  return NextResponse.json({ success: true, data: result }, { status: 200 });
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
