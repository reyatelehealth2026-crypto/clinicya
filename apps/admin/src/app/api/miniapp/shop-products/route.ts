import type { NextRequest } from 'next/server';
import { SHOP_PRODUCTS_STATUS } from '@reya/contracts';
import { handleMiniappOptions, miniappJson } from '@/lib/miniapp/cors';
import { resolveMiniappTenantContext, TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS } from '@/lib/miniapp/tenant';
import { useShopProductCatalog } from './_lib/catalogSource';
import { getCategoriesAction, getProductDetailAction, getProductsAction } from './_lib/query';

/**
 * GET /api/miniapp/shop-products — THREE read-only actions ported under one
 * Route Handler (see route module doc in _lib/query.ts for the full
 * scope-correction rationale):
 *
 *   - action=products        — api/checkout.php's handleGetProducts() (the
 *                               ACTUAL product/category source line-mini-app's
 *                               ShopClient.tsx calls, via shop-api.ts's
 *                               fetchProducts()). NOT api/shop-products.php's
 *                               own `products` branch (dead from the
 *                               mini-app's perspective).
 *   - action=product_detail  — api/checkout.php's handleGetProductDetail().
 *   - action=categories      — api/shop-products.php's OWN standalone
 *                               `categories` branch (genuinely public/
 *                               read-only, ported for completeness).
 *
 * api/checkout.php itself is otherwise UNTOUCHED this batch — its
 * cart/order/slip write actions stay 100% on PHP for a later, separately
 * briefed batch (the plan explicitly flips checkout last).
 *
 * Any other `action` value 400s — write actions (cart/add_to_cart/
 * create_order/…) are out of this (reads-lane) batch's scope entirely.
 */

export const OPTIONS = handleMiniappOptions;

export async function GET(request: NextRequest): Promise<Response> {
  const query = Object.fromEntries(request.nextUrl.searchParams.entries());
  const action = query.action;

  const outcome = await resolveMiniappTenantContext(request, { method: 'GET', query });
  if (!outcome.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  const { db } = outcome.context;

  if (action === 'products') {
    const limitRaw = Number.parseInt(query.limit ?? '', 10);
    const offsetRaw = Number.parseInt(query.offset ?? '', 10);
    const result = await getProductsAction(db, {
      lineAccountId: nonEmpty(query.line_account_id),
      categoryId: nonEmpty(query.category_id),
      search: (query.search ?? '').trim(),
      sort: query.sort,
      brand: (query.brand ?? '').trim(),
      lineUserId: nonEmpty(query.line_user_id),
      limit: Math.max(1, Math.min(24, Number.isFinite(limitRaw) ? limitRaw : 12)),
      offset: Math.max(0, Number.isFinite(offsetRaw) ? offsetRaw : 0),
    });
    return miniappJson(result, { status: SHOP_PRODUCTS_STATUS });
  }

  if (action === 'product_detail') {
    const productId = Number.parseInt(query.product_id ?? '', 10) || 0;
    const result = await getProductDetailAction(db, productId, nonEmpty(query.line_account_id), nonEmpty(query.line_user_id));
    return miniappJson(result, { status: SHOP_PRODUCTS_STATUS });
  }

  if (action === 'categories') {
    const lineAccountId = Number.parseInt(query.account ?? query.line_account_id ?? '', 10) || 0;
    const useOdoo = await useShopProductCatalog(db, lineAccountId);
    const result = await getCategoriesAction(db, { lineAccountId, useOdoo });
    return miniappJson(result, { status: SHOP_PRODUCTS_STATUS });
  }

  return miniappJson({ success: false, error: 'Invalid action' }, { status: 400 });
}

function nonEmpty(value: string | undefined): string | null {
  return value !== undefined && value !== '' ? value : null;
}
