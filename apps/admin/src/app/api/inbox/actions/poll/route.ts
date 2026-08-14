import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { pollUpdates } from './_lib/poll';

/**
 * GET /api/inbox/actions/poll — port of api/inbox-v2.php's `case 'poll':`
 * (lines ~172-193):
 *
 * ```php
 * case 'poll':
 *     if ($method !== 'GET') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     $since = (int) ($_GET['since'] ?? 0);
 *
 *     $inboxService = loadService('InboxService', $db, $lineAccountId);
 *     if (!$inboxService) {
 *         sendError('Inbox service not available', 503);
 *     }
 *
 *     $updates = $inboxService->pollUpdates($lineAccountId, $since);
 *
 *     sendResponse([
 *         'success' => true,
 *         'data' => [
 *             'new_messages' => $updates['new_messages'],
 *             'conversation_updates' => $updates['updated_conversations']
 *         ]
 *     ]);
 *     break;
 * ```
 *
 * Port of `InboxService::pollUpdates()` — see `_lib/poll.ts`'s module doc
 * for the full literal port, including why its own `count` key is dropped
 * (not read by this case body).
 *
 * RESPONSE SHAPE mirrors the case body exactly:
 * `data.conversation_updates` (NOT `data.updated_conversations`) is the wire
 * key — `pollUpdates()`'s own return value uses `updated_conversations`
 * internally; the rename to `conversation_updates` happens ONLY at this
 * boundary, matching PHP's own `'conversation_updates' =>
 * $updates['updated_conversations']` rename in the case body.
 *
 * "Inbox service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch (static import vs
 * PHP's runtime `file_exists()`/`class_exists()` probe).
 *
 * `case 'poll':` has NO case-level try/catch of its own (unlike e.g.
 * `drug_info`'s own `catch (PDOException $e)`) — a genuinely unexpected
 * error here would fall through to api/inbox-v2.php's generic outer
 * `catch (Throwable $e)` (line ~3553), producing `Internal server error:
 * <message, truncated to 200 chars>...` at HTTP 500 — a DIFFERENT shape
 * than that other action's own catch produces. Following the house
 * precedent Phase 4 batch 4a's `low-stock-drugs`/`drug-inventory` already
 * established for this identical no-case-catch situation (also documented
 * in `docs/runbooks/phase4-batch4b-patient-clinical-parity.md` §4), this
 * route uses the uniform `'Database error: {message}'` shape at HTTP 500
 * instead, for consistency across the whole `api/inbox/actions/*` family —
 * a defensive addition, not a literal PHP reproduction. In practice this
 * branch is unreachable in normal operation: `pollUpdates()` never swallows
 * its own errors (unlike e.g. `getLowStockDrugs()`), so a throw here really
 * would mean a genuine DB failure.
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the established
 * `../get-admins/route.ts` precedent (itself following
 * `api/inbox-v2.php` line 71's first fallback link, `$_SESSION['current_bot_id']
 * ?? 1`), NOT the full 4-tier `$_SESSION['current_bot_id'] ??
 * $_SESSION['line_account_id'] ?? $_GET['line_account_id'] ??
 * $_POST['line_account_id'] ?? 1` chain (per this batch's brief).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) ($_GET['since'] ?? 0)` — missing/non-numeric query value -> 0. */
function toIntParam(value: string): number {
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) ? n : 0;
}

export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;
  const since = toIntParam(request.nextUrl.searchParams.get('since') ?? '');

  try {
    const updates = await pollUpdates(db, lineAccountId, since);
    return NextResponse.json({
      success: true,
      data: {
        new_messages: updates.new_messages,
        conversation_updates: updates.updated_conversations,
      },
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
