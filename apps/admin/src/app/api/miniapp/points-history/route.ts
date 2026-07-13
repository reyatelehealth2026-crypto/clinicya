import type { NextRequest } from 'next/server';
import { POINTS_HISTORY_STATUS } from '@reya/contracts';
import { handleMiniappOptions, miniappJson } from '@/lib/miniapp/cors';
import { resolveMiniappTenantContext, TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS } from '@/lib/miniapp/tenant';
import { getPointsHistoryAction } from './_lib/query';

/**
 * GET /api/miniapp/points-history — port of api/points-history.php's
 * `action=history` ONLY (read api/points-history.php in full before writing
 * this file). `dashboard`/`full_history`/`rewards`/`redeem` all have zero
 * line-mini-app callers per the brief's grep verification and are out of
 * scope this batch — an explicit, unsupported `action` value 400s rather
 * than silently mapping to `history`, matching shop-products'/health-profile's
 * "400 on any other action value" convention for the other ported endpoints
 * in this batch.
 *
 * TENANT SCOPING (2026-05-27 PHP comment, preserved): this root-domain
 * Mini-App endpoint MUST route to the correct tenant DB by line_account_id —
 * otherwise a customer could see a different points balance/history than the
 * admin tenant view (split-brain). See `resolveMiniappTenantContext()`.
 *
 * Every branch responds HTTP 200 (even `Missing line_user_id`/`User not
 * found`) — matches api/points-history.php's always-200 error path exactly
 * (its shutdown-function 500 only fires on a genuine PHP fatal, which a
 * thrown exception here naturally reproduces as a Next 500 — no special
 * modeling needed).
 */

export const OPTIONS = handleMiniappOptions;

export async function GET(request: NextRequest): Promise<Response> {
  const query = Object.fromEntries(request.nextUrl.searchParams.entries());
  const action = query.action ?? 'history'; // `$_GET['action'] ?? $_POST['action'] ?? 'history'` — GET only ported here

  if (action !== 'history') {
    return miniappJson({ success: false, error: 'Invalid action' }, { status: 400 });
  }

  const lineUserId = typeof query.line_user_id === 'string' && query.line_user_id !== '' ? query.line_user_id : null;
  const lineAccountIdRaw = query.line_account_id;
  const lineAccountId =
    lineAccountIdRaw !== undefined && lineAccountIdRaw !== '' ? Number.parseInt(lineAccountIdRaw, 10) || 0 : 0;
  const limitRaw = query.limit;
  const limit = limitRaw !== undefined && limitRaw !== '' ? Number.parseInt(limitRaw, 10) || 20 : 20;

  const outcome = await resolveMiniappTenantContext(request, { method: 'GET', query });
  if (!outcome.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }

  const result = await getPointsHistoryAction(outcome.context.db, lineUserId, lineAccountId > 0 ? lineAccountId : null, limit);
  return miniappJson(result, { status: POINTS_HISTORY_STATUS });
}
