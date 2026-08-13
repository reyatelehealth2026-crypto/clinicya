import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { searchDrugs } from './_lib/searchDrugs';

/**
 * GET /api/inbox/actions/search-drugs — port of api/inbox-v2.php's
 * `case 'search_drugs': case 'search-drugs':` (lines ~617-699). See
 * `_lib/searchDrugs.ts`'s module doc for the full literal PHP source and
 * the `SHOW COLUMNS` simplification note.
 *
 * Query param: `query` (string, required). Validation, in order:
 *   - `trim($_GET['query'] ?? '')` empty -> 400 `'Search query is required'`
 *   - `mb_strlen($query) < 2` -> 400 `'Query must be at least 2 characters'`
 *   - `mb_strlen($query) > 100` -> 400 `'Query is too long (max 100 characters)'`
 * Length checks are Unicode-codepoint-aware (via `[...query].length`, not
 * `.length`'s UTF-16-code-unit count) to match PHP's `mb_strlen()` — Thai
 * text stays within the BMP so this only matters for rarer
 * surrogate-pair characters (e.g. emoji), but the brief calls this out
 * explicitly as "mb-aware".
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the
 * established `api/inbox/conversations/route.ts` precedent, matching every
 * sibling action in this batch/batch 3. PHP's `if ($lineAccountId) { ...
 * AND (line_account_id = ? OR line_account_id IS NULL) }` clause is
 * always-true in this port (`session.currentBotId ?? 1` is always a
 * truthy positive int) but is kept literally in `_lib/searchDrugs.ts` for
 * WHERE-shape parity, per this batch's brief.
 *
 * 500 `'Database error: {message}'` on any DB failure — this action's own
 * inline SQL has no internal swallow (unlike `drug-inventory`/
 * `drug-pricing-data`/`low-stock-drugs`'s `PharmacyIntegrationService`
 * methods), matching `case 'search_drugs':`'s own case-level
 * `catch (PDOException $e)`.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const rawQuery = request.nextUrl.searchParams.get('query') ?? '';
  const query = rawQuery.trim();

  if (!query) {
    return NextResponse.json({ success: false, error: 'Search query is required' }, { status: 400 });
  }
  // `mb_strlen()` — count Unicode codepoints, not UTF-16 code units.
  const codepointLength = [...query].length;
  if (codepointLength < 2) {
    return NextResponse.json({ success: false, error: 'Query must be at least 2 characters' }, { status: 400 });
  }
  if (codepointLength > 100) {
    return NextResponse.json({ success: false, error: 'Query is too long (max 100 characters)' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const results = await searchDrugs(db, query, lineAccountId);
    return NextResponse.json({ success: true, data: results, count: results.length, query });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
