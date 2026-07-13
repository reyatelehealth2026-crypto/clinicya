import type { NextRequest } from 'next/server';
import { HEALTH_PROFILE_GET_STATUS } from '@reya/contracts';
import { handleMiniappOptions, miniappJson } from '@/lib/miniapp/cors';
import { resolveMiniappTenantContext, TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS } from '@/lib/miniapp/tenant';
import { getHealthProfileAction } from './_lib/query';

/**
 * GET /api/miniapp/health-profile — port of api/health-profile.php's
 * `action=get` ONLY (read api/health-profile.php in full — 782 lines —
 * before writing this file). `get_allergies`/`get_medications`/
 * `search_drugs` all have zero line-mini-app callers per the brief's grep
 * verification (`get` already nests allergies+medications) — out of scope.
 * Every POST write action (update_personal, update_medical_history,
 * add/remove_allergy, add/update/remove_medication) is out of this
 * (reads-lane) batch's scope entirely; an explicit unsupported `action`
 * 400s, matching api/health-profile.php's own `default:
 * jsonResponse(['success'=>false,'error'=>'Invalid action'], 400)` branch.
 *
 * RESPONSE ENVELOPE — `{success, ...}` per handler, WITH real HTTP status
 * codes (400/500), via HEALTH_PROFILE_GET_STATUS — NOT the flat
 * always-200 member.php/rewards.php shape.
 */

export const OPTIONS = handleMiniappOptions;

export async function GET(request: NextRequest): Promise<Response> {
  const query = Object.fromEntries(request.nextUrl.searchParams.entries());
  const action = query.action;

  if (action !== 'get') {
    return miniappJson({ success: false, error: 'Invalid action' }, { status: 400 });
  }

  const outcome = await resolveMiniappTenantContext(request, { method: 'GET', query });
  if (!outcome.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }

  const lineUserId = typeof query.line_user_id === 'string' && query.line_user_id !== '' ? query.line_user_id : null;
  const lineAccountId = query.line_account_id !== undefined ? Number.parseInt(query.line_account_id, 10) || 0 : 0;

  const result = await getHealthProfileAction(outcome.context.db, lineUserId, lineAccountId);
  const status = !result.success ? HEALTH_PROFILE_GET_STATUS[result.error] : HEALTH_PROFILE_GET_STATUS.ok;
  return miniappJson(result, { status });
}
