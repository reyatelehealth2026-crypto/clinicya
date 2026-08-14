import { sql, type Expression, type Kysely, type SqlBool } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — query-building + row assembly for /shop/orders, ported from
 * shop/orders.php's "Transactions mode" GET render path (lines 205-486) and
 * includes/shop-data-source.php's getShopOrderDataSource() (used to decide
 * whether that GET path even runs — see page.tsx for the Odoo-mode gate).
 *
 * SCOPE: only the "Transactions mode" branch (shop/orders.php lines
 * 205-486) is ported here. The Odoo-backed branch (lines 27-203 — its own
 * odoo_webhooks_log queries/status maps/DataTable render) is OUT OF SCOPE
 * this batch, same precedent as users.php's Odoo tab: page.tsx renders an
 * explicit stub linking back to the still-live PHP page instead of porting,
 * faking, or silently dropping that gate. getShopOrderDataSource() below IS
 * ported in full (it decides which branch a request takes), but nothing
 * downstream of "orderDataSource === 'odoo'" is.
 *
 * `_useTransactions`/`_ordersTable`/`_itemsTable`/`_itemsFk` (shop/orders.php
 * lines 206-209) are module-level constants hardcoded to `true`/
 * 'transactions'/'transaction_items'/'transaction_id' and never reassigned
 * anywhere in the file (`shop/products.php`'s sibling "orders mode" branch,
 * which USED to make these vary, was removed — this file is
 * transactions-only now). Every `{$_ordersTable}`/`{$_itemsTable}`
 * interpolation below is therefore just a literal 'transactions'/
 * 'transaction_items' table name, and `if ($typeFilter && $_useTransactions)`
 * collapses to a bare `if ($typeFilter)` — not reproduced as a separate
 * runtime flag, matching users/queries.ts's own precedent for dropping
 * always-true PHP guards.
 *
 * `tablesExist` (shop/orders.php lines 212-218, a defensive
 * `SELECT 1 FROM transactions LIMIT 1` try/catch gating the whole page
 * behind a "ระบบคำสั่งซื้อยังไม่พร้อมใช้งาน" banner) is NOT ported: the typed
 * `TenantDB` schema guarantees `transactions` exists on every tenant DB
 * created from the committed migration template, same "no equivalent need
 * here" reasoning users/queries.ts's own module doc gives for skipping
 * other legacy-schema-drift probes.
 */

export const ORDERS_PER_PAGE = 50;

export type RawSearchParams = Record<string, string | string[] | undefined>;

function first(searchParams: RawSearchParams, key: string): string | undefined {
  const value = searchParams[key];
  return Array.isArray(value) ? value[0] : value;
}

export interface OrdersListFilters {
  /** `$_GET['status'] ?? ''` (line 338). */
  status: string;
  /** `$_GET['type'] ?? ''` (line 339). */
  type: string;
  /** `isset($_GET['pending_slip']) && $_GET['pending_slip'] == '1'` (line 365). Every link this page itself generates sets it to the literal string "1" — PHP's loose `==` would also accept e.g. "1.0" as a numeric-equal string, an edge case with no real link ever producing it, not reproduced here. */
  pendingSlip: boolean;
  /** `max(1, (int)($_GET['page'] ?? 1))` (line 370). */
  page: number;
  /** `isset($_GET['view']) && $_GET['view'] === 'dispense'` (line 449) — strict `===`, so only the exact string 'dispense' matches. */
  viewDispense: boolean;
}

/** Mirrors shop/orders.php lines 338-339, 365, 370, 449's `$_GET` parsing exactly. */
export function parseOrdersListFilters(searchParams: RawSearchParams): OrdersListFilters {
  const pageRaw = first(searchParams, 'page');
  return {
    status: first(searchParams, 'status') ?? '',
    type: first(searchParams, 'type') ?? '',
    pendingSlip: first(searchParams, 'pending_slip') === '1',
    page: Math.max(1, (pageRaw !== undefined ? Number.parseInt(pageRaw, 10) : 1) || 1),
    viewDispense: first(searchParams, 'view') === 'dispense',
  };
}

/**
 * Ported literally from shop/orders.php lines 347-367 (list query WHERE) /
 * 376-385 (count query WHERE) — the two are byte-for-byte the same clause
 * sequence in PHP (the count query re-derives it independently rather than
 * sharing a function), reproduced here as ONE builder both
 * getOrdersListPage()'s count and row queries share.
 *
 * `botIdForQuery` is shop/orders.php's own `$botIdForQuery` (line 340):
 * `$currentBotId ?? $_SESSION['current_bot_id'] ?? null`. IMPORTANT: because
 * `$currentBotId` itself is set at the very top of the file as
 * `$_SESSION['current_bot_id'] ?? 1` (line 21) — i.e. it is NEVER null by
 * the time line 340 runs — `$botIdForQuery` is therefore ALWAYS
 * `$currentBotId`, and the `?? $_SESSION[...] ?? null` fallbacks on line 340
 * are dead code. Callers (page.tsx/actions.ts) must pass
 * `session.currentBotId ?? 1`, not the raw nullable session value, to
 * reproduce this correctly — see page.tsx/actions.ts for where that
 * resolution happens; this function just takes the already-resolved value.
 */
export function buildOrdersWhereExpr(filters: OrdersListFilters, botIdForQuery: number | null): Expression<SqlBool> {
  const conditions: Expression<SqlBool>[] = [];

  if (botIdForQuery) {
    conditions.push(sql<SqlBool>`(o.line_account_id = ${botIdForQuery} OR o.line_account_id IS NULL)`);
  } else {
    conditions.push(sql<SqlBool>`1=1`);
  }

  if (filters.status) {
    conditions.push(sql<SqlBool>`o.status = ${filters.status}`);
  }

  if (filters.type) {
    conditions.push(sql<SqlBool>`o.transaction_type = ${filters.type}`);
  }

  if (filters.pendingSlip) {
    conditions.push(sql<SqlBool>`o.id IN (SELECT DISTINCT transaction_id FROM payment_slips WHERE status = 'pending')`);
  }

  return sql<SqlBool>`${sql.join(conditions, sql` AND `)}`;
}

export interface OrdersListRow {
  id: number;
  orderNumber: string;
  transactionType: string | null;
  status: string | null;
  /** Raw JSON text (shop/orders.php's `$order['delivery_info']`) — parsed at render time, matching PHP's own `json_decode(..., true)` call at render (line 636), not at query time. */
  deliveryInfo: string | null;
  createdAt: Date;
  /** Decimal column, MySQL/Kysely-typed as a string ("1234.50"). */
  grandTotal: string;
  itemCount: number;
  shippingTracking: string | null;
  displayName: string | null;
  pictureUrl: string | null;
}

export interface OrdersListResult {
  orders: OrdersListRow[];
  totalOrders: number;
  /** `max(1, (int)ceil($totalOrders / $perPage))` (line 389) — clamped to a minimum of 1, UNLIKE users.php's own list query (see users/queries.ts's getUsersListPage() doc: no such clamp there). Preserved as the difference it is. */
  totalPages: number;
  page: number;
  perPage: number;
  offset: number;
}

/**
 * Ported from shop/orders.php lines 342-394 (row query) and 374-389 (count
 * query), explicit-column-alias style — see users/queries.ts's
 * getUsersListPage() doc for why every real-table query in this codebase
 * uses raw `sql` + `AS camelAlias` rather than Kysely's typed
 * `.selectFrom()` builder (no CamelCasePlugin on the shared Kysely<TenantDB>
 * instance). `o.*` in PHP is narrowed here to only the columns the render
 * path (shop/orders.php lines 632-715) actually reads.
 */
export async function getOrdersListPage(
  db: Kysely<TenantDB>,
  filters: OrdersListFilters,
  botIdForQuery: number | null
): Promise<OrdersListResult> {
  const perPage = ORDERS_PER_PAGE;
  const offset = (filters.page - 1) * perPage;
  const whereExpr = buildOrdersWhereExpr(filters, botIdForQuery);

  const countResult = await sql<{ count: number }>`
    SELECT COUNT(*) AS count FROM transactions o JOIN users u ON o.user_id = u.id WHERE ${whereExpr}
  `.execute(db);
  const totalOrders = Number(countResult.rows[0]?.count ?? 0);
  const totalPages = Math.max(1, Math.ceil(totalOrders / perPage));

  const rowsResult = await sql<OrdersListRow>`
    SELECT
      o.id AS id,
      o.order_number AS orderNumber,
      o.transaction_type AS transactionType,
      o.status AS status,
      o.delivery_info AS deliveryInfo,
      o.created_at AS createdAt,
      o.grand_total AS grandTotal,
      o.shipping_tracking AS shippingTracking,
      u.display_name AS displayName,
      u.picture_url AS pictureUrl,
      (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = o.id) AS itemCount
    FROM transactions o
    JOIN users u ON o.user_id = u.id
    WHERE ${whereExpr}
    ORDER BY o.created_at DESC
    LIMIT ${perPage} OFFSET ${offset}
  `.execute(db);

  return {
    orders: rowsResult.rows,
    totalOrders,
    totalPages,
    page: filters.page,
    perPage,
    offset,
  };
}

/**
 * Ported from shop/orders.php lines 396-408. Best-effort (PHP wraps the
 * whole block in try/catch and just leaves `$statusCounts = []` on error) —
 * reproduced the same way, returning `{}` rather than throwing.
 */
export async function getStatusCounts(db: Kysely<TenantDB>, botIdForQuery: number | null): Promise<Record<string, number>> {
  try {
    const result = botIdForQuery
      ? await sql<{ status: string | null; c: number }>`
          SELECT status, COUNT(*) as c FROM transactions WHERE (line_account_id = ${botIdForQuery} OR line_account_id IS NULL) GROUP BY status
        `.execute(db)
      : await sql<{ status: string | null; c: number }>`
          SELECT status, COUNT(*) as c FROM transactions GROUP BY status
        `.execute(db);

    const counts: Record<string, number> = {};
    for (const row of result.rows) {
      // PHP: `$statusCounts[$row['status']] = $row['c'];` — a NULL status
      // coerces to the empty-string array key in PHP; reproduced literally.
      counts[row.status ?? ''] = Number(row.c);
    }
    return counts;
  } catch {
    return {};
  }
}

/**
 * Ported from shop/orders.php lines 410-427 (`$ordersWithPendingSlips`,
 * `$pendingSlipsCount = count($ordersWithPendingSlips)` — the count is
 * simply this array's length, not a separate query). Best-effort, same
 * try/catch-to-empty-array shape as getStatusCounts() above.
 *
 * PHP's `SELECT DISTINCT t.id, t.order_number` selects two columns but only
 * ever reads column 0 (`PDO::FETCH_COLUMN, 0`) — `order_number` is dead
 * selection. Reproduced faithfully (selected, unused) rather than trimmed,
 * per this batch's "replicate literally, don't silently simplify" brief.
 */
export async function getPendingSlipOrderIds(db: Kysely<TenantDB>, botIdForQuery: number | null): Promise<number[]> {
  try {
    const result = botIdForQuery
      ? await sql<{ id: number; order_number: string }>`
          SELECT DISTINCT t.id, t.order_number
          FROM transactions t
          INNER JOIN payment_slips ps ON ps.transaction_id = t.id
          WHERE ps.status = 'pending' AND (t.line_account_id = ${botIdForQuery} OR t.line_account_id IS NULL)
        `.execute(db)
      : await sql<{ id: number; order_number: string }>`
          SELECT DISTINCT t.id, t.order_number
          FROM transactions t
          INNER JOIN payment_slips ps ON ps.transaction_id = t.id
          WHERE ps.status = 'pending'
        `.execute(db);
    return result.rows.map((row) => row.id);
  } catch {
    return [];
  }
}

/** Ported from shop/orders.php lines 436-446 — the 💊 จ่ายยา type-chip badge count, fetched regardless of `$viewDispense`. Best-effort, defaults to 0 on error. */
export async function getDispenseCount(db: Kysely<TenantDB>, botIdForQuery: number | null): Promise<number> {
  try {
    const result = botIdForQuery
      ? await sql<{ c: number }>`SELECT COUNT(*) AS c FROM dispensing_records WHERE line_account_id = ${botIdForQuery}`.execute(db)
      : await sql<{ c: number }>`SELECT COUNT(*) AS c FROM dispensing_records`.execute(db);
    return Number(result.rows[0]?.c ?? 0);
  } catch {
    return 0;
  }
}

export interface DispenseRecordRow {
  id: number;
  orderNumber: string;
  userId: number;
  /** Raw JSON text (shop/orders.php's `$record['items']`) — parsed at render time (line 758), matching PHP. */
  items: string | null;
  totalAmount: string;
  paymentMethod: string | null;
  paymentStatus: string | null;
  createdAt: Date;
  displayName: string | null;
  pictureUrl: string | null;
}

/**
 * Ported from shop/orders.php lines 452-469 — only ever queried by page.tsx
 * when `filters.viewDispense` is true (mirrors PHP's own `if ($viewDispense)
 * { ... }` gate at line 453; this function itself has no such gate — the
 * caller decides). Best-effort: returns `[]` on error, matching PHP's own
 * try/catch leaving `$dispenseRecords = []`.
 */
export async function getDispenseRecords(db: Kysely<TenantDB>, botIdForQuery: number | null): Promise<DispenseRecordRow[]> {
  const selectCols = sql`
    d.id AS id, d.order_number AS orderNumber, d.user_id AS userId, d.items AS items,
    d.total_amount AS totalAmount, d.payment_method AS paymentMethod, d.payment_status AS paymentStatus,
    d.created_at AS createdAt, u.display_name AS displayName, u.picture_url AS pictureUrl
  `;
  try {
    const result = botIdForQuery
      ? await sql<DispenseRecordRow>`
          SELECT ${selectCols}
          FROM dispensing_records d
          JOIN users u ON d.user_id = u.id
          WHERE d.line_account_id = ${botIdForQuery}
          ORDER BY d.created_at DESC
        `.execute(db)
      : await sql<DispenseRecordRow>`
          SELECT ${selectCols}
          FROM dispensing_records d
          JOIN users u ON d.user_id = u.id
          ORDER BY d.created_at DESC
        `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

export type ShopOrderDataSource = 'shop' | 'odoo';

/** Mirrors includes/shop-data-source.php's normalizeShopOrderDataSource(). */
function normalizeShopOrderDataSource(value: string | null | undefined): ShopOrderDataSource {
  const mode = String(value ?? '').trim().toLowerCase();
  return mode === 'odoo' ? 'odoo' : 'shop';
}

/**
 * Port of includes/shop-data-source.php's getShopOrderDataSource($db,
 * $lineAccountId) (lines 30-48). `ensureShopOrderDataSourceColumn()` (that
 * file's lines 15-25, a runtime `SHOW COLUMNS`+`ALTER TABLE ... ADD COLUMN`
 * shim) is deliberately NOT ported — `shop_settings.order_data_source`
 * already exists in the generated TenantDB schema for every tenant DB
 * created from the committed migration template (this batch's brief: "no
 * drift, no runtime ALTER").
 *
 * Two-step lookup, exactly as PHP: (1) a line_account_id-scoped row, if
 * `lineAccountId` is truthy AND that row's value is non-null/non-empty;
 * else (2) the tenant's global default row (`id = 1 OR line_account_id IS
 * NULL`). Best-effort — any DB error on either step returns 'shop', matching
 * PHP's outer try/catch.
 */
export async function getShopOrderDataSource(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<ShopOrderDataSource> {
  try {
    if (lineAccountId) {
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
