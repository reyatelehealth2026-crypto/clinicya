import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * catalogSource.ts — TypeScript port of includes/shop-storefront-catalog.php's
 * `useShopProductCatalog()` + includes/shop-data-source.php's
 * `getShopOrderDataSource()`/`normalizeShopOrderDataSource()`. Used ONLY by
 * the `categories` action (shop-products.php's own standalone branch) — the
 * `products`/`product_detail` actions (checkout.php) always read
 * `business_items` unconditionally since the 2026-05-15 unification, no
 * Odoo/shop_products branch left in either of those two.
 *
 * SIMPLIFICATION (same established precedent as query.ts's module doc):
 * `shop_products` (table) and `shop_settings.order_data_source` (column)
 * both exist unconditionally on a tenant DB created from the committed
 * template (packages/db's generated schema confirms both) — PHP's
 * `try { $db->query('SELECT 1 FROM shop_products LIMIT 1'); } catch {
 * return false; }` and `ensureShopOrderDataSourceColumn()`'s `ALTER TABLE ...
 * ADD COLUMN` guard are schema-drift compatibility shims with no equivalent
 * need here, so this port skips straight to the underlying decision.
 */

function normalizeShopOrderDataSource(value: string | null | undefined): 'shop' | 'odoo' {
  return (value ?? '').trim().toLowerCase() === 'odoo' ? 'odoo' : 'shop';
}

async function getShopOrderDataSource(db: Kysely<TenantDB>, lineAccountId: number): Promise<'shop' | 'odoo'> {
  try {
    if (lineAccountId > 0) {
      const scoped = await sql<{ order_data_source: string | null }>`
        SELECT order_data_source FROM shop_settings WHERE line_account_id = ${lineAccountId} LIMIT 1
      `.execute(db);
      const value = scoped.rows[0]?.order_data_source;
      if (value !== undefined && value !== null && value !== '') {
        return normalizeShopOrderDataSource(value);
      }
    }
    const fallback = await sql<{ order_data_source: string | null }>`
      SELECT order_data_source FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1
    `.execute(db);
    return normalizeShopOrderDataSource(fallback.rows[0]?.order_data_source);
  } catch {
    return 'shop';
  }
}

/** Mirrors `useShopProductCatalog()`: true iff the Odoo/shop_products storefront catalog is active for this line_account_id. */
export async function useShopProductCatalog(db: Kysely<TenantDB>, lineAccountId: number): Promise<boolean> {
  if (lineAccountId <= 0) {
    return false;
  }
  return (await getShopOrderDataSource(db, lineAccountId)) === 'odoo';
}
