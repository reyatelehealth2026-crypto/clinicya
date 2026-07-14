import { NextResponse, type NextRequest } from 'next/server';
import { handleMiniappOptions, miniappJson } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleValidatePromo, type ActionResult } from './_lib/handlers';

/**
 * /api/miniapp/checkout/pricing — port of api/checkout.php's action=validate_promo
 * (handleValidatePromo(), L2202-2348, read in full), mig-api's checkout batch
 * (docs/plans/2026-07-12-nextjs-full-migration-plan.md Phase 3).
 *
 * POST-only: line-mini-app's validatePromo() (line-mini-app/src/lib/shop-api.ts) always POSTs
 * `{action:'validate_promo', code, line_user_id, line_account_id, subtotal}` — the PHP original's
 * generic `$_GET['action'] ?? $_POST['action']` dispatch technically also answers a GET, but no real
 * caller ever does, so only POST is wired here (same shape as this file's sibling `cart/route.ts`
 * having no GET branch for actions the client never GETs).
 *
 * IDENTITY MODEL / TENANCY: see cart/route.ts's module doc — identical two-phase tenant pin, no
 * session/auth gating.
 */

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

/** Same rationale as cart/route.ts's runAction() — checkout.php's outer catch has no `error_details` field. */
async function runAction(action: () => Promise<ActionResult>): Promise<ActionResult> {
  try {
    return await action();
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { status: 200, body: { success: false, message } };
  }
}

function respond(result: ActionResult): NextResponse {
  return miniappJson(result.body, { status: result.status });
}

export const OPTIONS = handleMiniappOptions;

export async function POST(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = Object.fromEntries(url.searchParams.entries());
  const jsonBody = await parseJsonBody(request);
  const action = (query.action || jsonBody.action || '') as string;

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, async ({ db }) => {
    return runAction(async () => {
      switch (action) {
        case 'validate_promo':
          return handleValidatePromo(db, jsonBody);
        default:
          return { status: 200, body: { success: false, message: 'Invalid action' } } satisfies ActionResult;
      }
    });
  });

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}
