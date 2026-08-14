import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getCrmUserById, getRestOfCrmData } from './_lib/customerCrm';

/**
 * GET+POST /api/inbox/actions/customer-crm — literal port of
 * `api/inbox-v2.php`'s `case 'customer_crm':` (lines 1908-1961), the CRM HUD
 * panel's aggregate customer-data endpoint.
 *
 * ```php
 * case 'customer_crm':
 *     $userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
 *     if (!$userId) { sendError('User ID is required'); }
 *     try {
 *         $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
 *         $stmt->execute([$userId]);
 *         $user = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if (!$user) { sendError('User not found', 404); }
 *         // ... 6 independent best-effort blocks, see _lib/customerCrm.ts ...
 *         sendResponse(['success' => true, 'data' => [...]]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to load CRM data: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE ONE ACTION IN THE WHOLE `actions/*` FAMILY WITH NO METHOD GUARD
 * ═══════════════════════════════════════════════════════════════════════
 * Unlike every sibling case block in `api/inbox-v2.php` (which each start
 * with `if ($method !== 'GET') { sendError('Method not allowed', 405); }` or
 * the `POST` equivalent), `case 'customer_crm':` has NO
 * `if ($method !== ...)` check at all — it is reachable via any HTTP verb
 * PHP's own dispatcher accepts for this file (`GET, POST, OPTIONS` per the
 * `Access-Control-Allow-Methods` header at the top of `api/inbox-v2.php`).
 * This Route Handler therefore exports BOTH `GET` and `POST` — no `405`
 * stub for either verb, the only action in this family without one.
 *
 * `userId` RESOLUTION — a DELIBERATE INTERPRETATION CHOICE, not a literal
 * transliteration of `$_GET['user_id'] ?? $_POST['user_id']`:
 * PHP's own expression reads `$_GET['user_id']` first, falling back to
 * `$_POST['user_id']` only when the GET param is absent — but `$_POST` is
 * PHP's form-encoded-body superglobal; it is NEVER populated for a
 * JSON-bodied `POST` request (which every real caller of this endpoint
 * sends — see `getJsonBody()`'s existence elsewhere in this same file,
 * which this specific case block conspicuously does NOT call). PHP's own
 * POST path is therefore effectively DEAD for any JSON caller — reachable
 * only via a legacy `application/x-www-form-urlencoded` submission, which
 * this codebase's LINE-inbox admin UI does not send. Rather than
 * reproducing that dead path (a Route Handler's `POST` has no `$_POST`
 * analogue to even attempt), this port resolves `userId` from the QUERY
 * STRING on `GET` and from the JSON BODY on `POST` — matching every other
 * ported action in this family's already-established "read the JSON body
 * for POST" convention (e.g. `assign-tag`, `remove-customer-tag`) rather
 * than a PHP code path no real caller can hit.
 *
 * `!$userId` -> `sendError('User ID is required')` — HTTP 400 (PHP's
 * `sendError()` default status code).
 *
 * `!$user` -> `sendError('User not found', 404)` — an IMMEDIATE early
 * `return`, NOT a thrown error. PHP's `sendResponse()` calls `exit;`
 * internally, so this branch NEVER reaches the enclosing
 * `catch (Exception $e)` — it is a structurally DIFFERENT code path from
 * the generic `'Failed to load CRM data: ...'` 400 catch-all below. Ported
 * as a plain `return` from inside the `try` block: a `return` (unlike a
 * `throw`) does not invoke a JS `catch` either, so this mirrors PHP's
 * `exit`-bypasses-`catch` behavior exactly.
 *
 * The 6 independent best-effort blocks (points+tier / stats / tags /
 * all_tags / notes / transactions) are `_lib/customerCrm.ts`'s
 * `getRestOfCrmData()` — see that module's doc for the full per-block
 * literal port and default-on-throw values. None of them can reach this
 * route's own outer `catch` (each swallows its own errors internally,
 * exactly like PHP's own per-block `try/catch`), so that catch is only
 * ever reachable via the `SELECT * FROM users` call itself throwing (or any
 * other genuinely unexpected failure) — matching PHP's own outer
 * `catch (Exception $e) { sendError('Failed to load CRM data: ' .
 * $e->getMessage()); }`.
 *
 * `lineAccountId` (used only by block (d), `all_tags`) resolves as
 * `session.currentBotId ?? 1` — the established convention across this
 * whole `api/inbox/actions/*` family (see e.g. `../drug-info/route.ts`,
 * `../get-admins/route.ts`).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six `TenantRole` values).
 */

/** PHP's `(int) $v` — loose int cast, non-numeric -> 0. */
function intval(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'string') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

async function handleCustomerCrm(userId: number): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const user = await getCrmUserById(db, userId);
    if (!user) {
      // Immediate early return — PHP's sendError()+exit() here bypasses the
      // enclosing try/catch entirely; a plain `return` (not a `throw`)
      // mirrors that exactly. See module doc.
      return NextResponse.json({ success: false, error: 'User not found' }, { status: 404 });
    }

    const rest = await getRestOfCrmData(db, userId, lineAccountId);

    return NextResponse.json({
      success: true,
      data: { user, ...rest },
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to load CRM data: ${message}` }, { status: 400 });
  }
}

export async function GET(request: NextRequest): Promise<NextResponse> {
  const userId = intval(request.nextUrl.searchParams.get('user_id') ?? '');
  return handleCustomerCrm(userId);
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const raw: unknown = await request.json().catch(() => ({}));
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};
  const userId = intval(body.user_id);
  return handleCustomerCrm(userId);
}
