import { NextResponse, type NextRequest } from 'next/server';
import { getConversationsDelta, parseConversationsQuery, type ConversationFilters } from './_lib/query';
import { resolveInboxApiContext } from './_lib/session';

/**
 * GET /api/inbox/conversations — port of api/inbox-v2.php's
 * `getConversations`/`get_conversations` action (lines 2706-2783), backed by
 * classes/InboxService.php::getConversationsDelta().
 *
 * RESPONSE SHAPE IS A WIRE CONTRACT, byte-matched to PHP's `sendResponse([
 * 'success' => true, 'data' => $result, 'search' => $search, 'filters' =>
 * $filters])` — snake_case field names, same nesting/key order as the PHP
 * source (see _lib/query.ts's ConversationRow doc for the row-level field
 * order). This is why response fields are NOT camelCased the way
 * (tenant)/users/queries.ts's UsersListRow is — that convention is for an
 * internal TS interface never serialized to JSON; this is a wire contract
 * other consumers (ConversationListLoader, SearchBox, and any future
 * external caller) rely on byte-for-byte.
 *
 * PHP-QUIRK REPLICATED: an empty PHP associative array (`$filters = []`
 * with nothing pushed into it) serializes via `json_encode()` as a JSON
 * ARRAY `[]`, not an object `{}` — PHP arrays are only encoded as JSON
 * objects once they have at least one string key. So when no filter query
 * params were given, this response's `filters` field is `[]`, matching
 * PHP exactly; once any filter is present it's a `{...}` object.
 *
 * `search`/`filters` are accepted and echoed back for parity with the
 * documented contract (a future combined search+filter reload), but the
 * production "load more" cursor walk (ConversationLoader) never sends them
 * — see _lib/query.ts's module doc and (tenant)/inbox/_components/
 * ConversationListLoader.tsx.
 *
 * NOT ported: PHP's ETag/If-None-Match/304 caching (lines 2756-2771) and
 * the `segment=new_followers` branch (routes to
 * InboxService::getUncontactedFollowersDelta() instead) — this batch's
 * brief explicitly defers the new-followers segment VIEW (the badge count
 * itself is served by (tenant)/inbox/_lib/filterOptions.ts for SSR). A
 * `segment` param on this endpoint is currently a no-op (falls through to
 * the normal conversations query), not an error.
 */
export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const { db, session } = auth.value;
  // Mirrors `$_SESSION['current_bot_id'] ?? 1` (api/inbox-v2.php line 71's
  // first fallback link) — see system-status/page.tsx for the same
  // `session.currentBotId ?? 1` precedent elsewhere in this app.
  const accountId = session.currentBotId ?? 1;

  const url = new URL(request.url);
  const { since, cursor, limit, search, filters } = parseConversationsQuery(url.searchParams);

  try {
    const data = await getConversationsDelta(db, accountId, { since, cursor, limit, search, filters });
    return NextResponse.json({
      success: true,
      data,
      search,
      filters: toWireFilters(filters),
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to get conversations: ${message}` }, { status: 400 });
  }
}

/** PHP empty-array-encodes-as-[] quirk — see this file's module doc. */
function toWireFilters(filters: ConversationFilters): ConversationFilters | [] {
  return Object.keys(filters).length === 0 ? [] : filters;
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
