import { NextResponse, type NextRequest } from 'next/server';
import { handleMiniappOptions, miniappJson } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleAddToCart, handleClearCart, handleGetCart, handleRemoveFromCart, handleUpdateCart, type ActionResult } from './_lib/handlers';

/**
 * /api/miniapp/checkout/cart — port of api/checkout.php's cart CRUD actions (read in full, 2794 lines),
 * mig-api's checkout batch (docs/plans/2026-07-12-nextjs-full-migration-plan.md Phase 3):
 *
 *   - action=cart             (GET)  — handleGetCart()        L1105-1283
 *   - action=add_to_cart      (POST) — handleAddToCart()      L845-971
 *   - action=update_cart      (POST) — handleUpdateCart()     L976-1035
 *   - action=remove_from_cart (POST) — handleRemoveFromCart() L1040-1076
 *   - action=clear_cart       (POST) — handleClearCart()      L1081-1100
 *
 * OUT OF SCOPE this batch (explicitly deferred, do not add here): action=get_order/order/
 * update_payment_method/my_orders/shop_info/last_address/promptpay_qr (a later batch),
 * action=products/product_detail/categories (already shipped — see shop-products/route.ts),
 * action=create_order/upload_slip (orderCreation's own route directory,
 * apps/admin/src/app/api/miniapp/checkout/order/**).
 *
 * IDENTITY MODEL: no session/auth gating — line_user_id (and, for action=cart, an optional raw
 * `user_id` override) trusted as given, exactly like the PHP original (no server-side LIFF token
 * verification anywhere in this surface). TENANCY: resolveMiniappTenantContext() two-phase pin
 * (x-tenant-id header, else routeByLineAccount() via line_account_id/la/account) — see
 * lib/miniapp/tenant.ts's doc comment. None of these 5 handlers read `line_account_id` from the
 * request body/query directly (PHP doesn't either) — each derives its own tenant-scoped
 * `line_account_id` from the resolved `users` row (see getUserIdFromLineUserId() in
 * _lib/cartProductSource.ts); the tenant DB itself is still selected via the shared two-phase pin above.
 */

function queryRecord(url: URL): Record<string, string> {
  return Object.fromEntries(url.searchParams.entries());
}

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

/**
 * Port of api/checkout.php's outer `try { switch(...) } catch (Exception $e) { jsonResponse(false,
 * $e->getMessage()); }` — wraps the whole action dispatch so an unexpected exception still surfaces as a
 * flat, HTTP-200 `{success:false, message}` body instead of an unhandled 500. Unlike rewards.php's own
 * catch clause, checkout.php's does NOT add an `error_details` field — kept minimal here to match.
 */
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

export async function GET(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = queryRecord(url);
  const action = query.action ?? '';

  const resolved = await withMiniappTenant(request, { method: 'GET', query }, async ({ db }) => {
    return runAction(async () => {
      switch (action) {
        case 'cart':
          return handleGetCart(db, query);
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

export async function POST(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = queryRecord(url);
  const jsonBody = await parseJsonBody(request);
  const action = (query.action || jsonBody.action || '') as string;

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, async ({ db }) => {
    return runAction(async () => {
      switch (action) {
        case 'add_to_cart':
          return handleAddToCart(db, jsonBody);
        case 'update_cart':
          return handleUpdateCart(db, jsonBody);
        case 'remove_from_cart':
          return handleRemoveFromCart(db, jsonBody);
        case 'clear_cart':
          return handleClearCart(db, jsonBody);
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
