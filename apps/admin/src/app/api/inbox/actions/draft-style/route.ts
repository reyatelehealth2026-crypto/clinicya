import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getDraftStyle } from './_lib/draftStyle';

/**
 * GET /api/inbox/actions/draft-style — port of api/inbox-v2.php's
 * `case 'draft_style': case 'draft-style':` (lines ~397-421):
 *
 * ```php
 * case 'draft_style':
 * case 'draft-style':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $type = $_GET['type'] ?? 'A';
 *     if (!in_array($type, ['A', 'B', 'C'])) {
 *         sendError('Invalid communication type. Must be A, B, or C');
 *     }
 *     $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);
 *     if (!$healthEngine) { sendError('Health engine service not available', 503); }
 *     $style = $healthEngine->getDraftStyle($type);
 *     sendResponse(['success' => true, 'data' => $style]);
 *     break;
 * ```
 *
 * Query param: `type` (string), defaulting to `'A'` when absent (`$_GET['type']
 * ?? 'A'` is an isset()-style default — an EXPLICITLY present but empty
 * `?type=` still reaches the `in_array()` check as `''`, not `'A'`). PHP's
 * `in_array($type, ['A', 'B', 'C'])` (loose mode, default) is still a
 * case-sensitive strict-string comparison for these all-uppercase-letter
 * needles — `'a'` does NOT match `'A'` — replicated below with a plain `===`
 * membership check against the same three literal strings.
 *
 * "Health engine service not available" 503 — PHP's `loadService()` guard
 * does a runtime `file_exists()`/`class_exists()` probe with no Next
 * analogue (a static TypeScript import either compiles and is present in the
 * bundle, or the build fails outright) — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch 4a)
 * for the full reasoning. This port does NOT fabricate a runtime 503 branch
 * for "service unavailable".
 *
 * `getDraftStyle()` is pure and DB-free (see `_lib/draftStyle.ts`'s module
 * doc) and never throws — no case-level try/catch is needed here, matching
 * PHP's own case (no try/catch around `$healthEngine->getDraftStyle($type)`).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */
export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const params = request.nextUrl.searchParams;
  const type = params.get('type') ?? 'A';

  if (type !== 'A' && type !== 'B' && type !== 'C') {
    return NextResponse.json(
      { success: false, error: 'Invalid communication type. Must be A, B, or C' },
      { status: 400 }
    );
  }

  const style = getDraftStyle(type);
  return NextResponse.json({ success: true, data: style });
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
