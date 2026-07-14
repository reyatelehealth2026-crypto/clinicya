import { NextResponse, type NextRequest } from 'next/server';
import { handleMiniappOptions, miniappJson } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleCreateOrder } from './_lib/createOrder';
import { handleUploadSlip } from './_lib/uploadSlip';

/**
 * /api/miniapp/checkout/order — port of api/checkout.php's order-creation + payment-slip-upload flow
 * (mig-api's checkout/order batch, docs/plans/2026-07-12-nextjs-full-migration-plan.md Phase 3), read in
 * full before writing this file:
 *
 *   - action=create_order (POST, JSON body)             — handleCreateOrder()  L1288-1656
 *   - action=upload_slip  (POST, multipart/form-data)    — handleUploadSlip()   L1733-1863
 *
 * sendOrderConfirmation() (L1661-1728) is dead code (grep-verified zero call sites in checkout.php) — not
 * ported. reyaFixSlipUrl() (L2034-2039) is used only by the out-of-scope handleGetOrder() — not ported
 * this round.
 *
 * Own sub-path, per this round's "avoid file collision" instruction: cartAndPricing's builder owns
 * checkout/cart/** and checkout/pricing/** (separate route directories); this route owns checkout/order/**
 * exclusively (create_order, slip upload — payment verification/get_order are a later round).
 *
 * DISPATCH: branches on Content-Type FIRST (this is the first multipart endpoint in the whole Phase-3
 * effort) — `multipart/form-data` -> action=upload_slip (via `request.formData()`), everything else ->
 * JSON body -> action=create_order. Both ultimately mirror api/checkout.php's own
 * `$_GET['action'] ?? $_POST['action'] ?? null; if ($jsonInput) { $action = $jsonInput['action'] ?? $action; }`
 * dispatch, scoped to the two actions this route owns.
 *
 * IDENTITY MODEL / TENANCY: see checkout/cart/route.ts's module doc — identical two-phase tenant pin
 * (`x-tenant-id` header, else routeByLineAccount() via line_account_id/la/account), no session/auth
 * gating (line_user_id trusted as given, matching the PHP original). For the multipart branch, the
 * line_account_id signal line-mini-app sends as a plain form field (see
 * line-mini-app/src/lib/shop-api.ts's uploadPaymentSlip()) is extracted into a plain object and passed as
 * this batch's `jsonBody` tenant-routing signal — routeByLineAccount() only inspects it for the
 * line_account_id/la/account keys, so a FormData-sourced object works identically to a real JSON body for
 * routing purposes.
 */

interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

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

/** String-valued form fields only (excludes the uploaded File itself) — used both as the tenant-routing
 *  `jsonBody` signal and (indirectly, via handleUploadSlip's own `form.get()` calls) the action payload. */
function stringFormFields(form: FormData): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [key, value] of form.entries()) {
    if (typeof value === 'string') {
      out[key] = value;
    }
  }
  return out;
}

/**
 * Port of api/checkout.php's outer `try { switch(...) } catch (Exception $e) { jsonResponse(false,
 * $e->getMessage()); }` — wraps the whole action dispatch so an unexpected exception still surfaces as a
 * flat, HTTP-200 `{success:false, message}` body instead of an unhandled 500. checkout.php's catch clause
 * does NOT add an `error_details` field — kept minimal here to match (same as cart/route.ts's runAction()).
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

export async function POST(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const origin = `${url.protocol}//${url.host}`;
  const contentType = request.headers.get('content-type') ?? '';

  if (contentType.toLowerCase().includes('multipart/form-data')) {
    const form = await request.formData();
    const query = queryRecord(url);
    const formFields = stringFormFields(form);
    const action = (query.action || (typeof formFields.action === 'string' ? formFields.action : '')) as string;

    const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody: formFields }, async ({ db }) => {
      return runAction(async () => {
        switch (action) {
          case 'upload_slip':
            return handleUploadSlip(db, form, origin);
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

  const query = queryRecord(url);
  const jsonBody = await parseJsonBody(request);
  const action = (query.action || jsonBody.action || '') as string;

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, async ({ db }) => {
    return runAction(async () => {
      switch (action) {
        case 'create_order':
          return handleCreateOrder(db, jsonBody);
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
