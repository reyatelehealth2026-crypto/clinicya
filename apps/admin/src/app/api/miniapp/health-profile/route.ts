import type { NextRequest } from 'next/server';
import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { HEALTH_PROFILE_GET_STATUS } from '@reya/contracts';
import { handleMiniappOptions, miniappJson } from '@/lib/miniapp/cors';
import { resolveMiniappTenantContext, TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS } from '@/lib/miniapp/tenant';
import { getHealthProfileAction } from './_lib/query';
import {
  addAllergyAction,
  addMedicationAction,
  removeAllergyAction,
  removeMedicationAction,
  updateMedicalHistoryAction,
  updatePersonalAction,
  type ActionResult,
} from './_lib/mutations';

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
 *
 * POST /api/miniapp/health-profile — added by mig-api (Phase 3 batch 2,
 * wt-phase3b2): the SIX write actions with real line-mini-app callers
 * (line-mini-app/src/lib/health-api.ts) — `update_personal`,
 * `update_medical_history`, `add_allergy`, `remove_allergy`,
 * `add_medication`, `remove_medication`. `update_medication` has zero
 * callers and is NOT ported (see _lib/mutations.ts's doc comment). The GET
 * export above (and _lib/query.ts) is left functionally untouched by this
 * addition.
 */

export const OPTIONS = handleMiniappOptions;

const WRITE_ACTIONS = new Set([
  'update_personal',
  'update_medical_history',
  'add_allergy',
  'remove_allergy',
  'add_medication',
  'remove_medication',
]);

async function parseJsonBody(request: NextRequest): Promise<Record<string, unknown>> {
  try {
    const raw = await request.text();
    if (!raw) return {};
    const parsed: unknown = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

async function dispatchWrite(db: Kysely<TenantDB>, action: string, input: Record<string, unknown>): Promise<ActionResult> {
  switch (action) {
    case 'update_personal':
      return updatePersonalAction(db, input);
    case 'update_medical_history':
      return updateMedicalHistoryAction(db, input);
    case 'add_allergy':
      return addAllergyAction(db, input);
    case 'remove_allergy':
      return removeAllergyAction(db, input);
    case 'add_medication':
      return addMedicationAction(db, input);
    case 'remove_medication':
      return removeMedicationAction(db, input);
    default:
      // Unreachable — POST() below already 400s any action not in WRITE_ACTIONS before calling this.
      return { status: 400, body: { success: false, error: 'Invalid action' } };
  }
}

export async function POST(request: NextRequest): Promise<Response> {
  const input = await parseJsonBody(request);
  const action = typeof input.action === 'string' ? input.action : '';

  if (!WRITE_ACTIONS.has(action)) {
    return miniappJson({ success: false, error: 'Invalid action' }, { status: 400 });
  }

  const outcome = await resolveMiniappTenantContext(request, { method: 'POST', jsonBody: input });
  if (!outcome.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }

  const result = await dispatchWrite(outcome.context.db, action, input);
  return miniappJson(result.body, { status: result.status });
}

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
