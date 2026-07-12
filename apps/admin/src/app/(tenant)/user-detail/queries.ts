import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getAllTags, type UserTagOption } from '../users/queries';
import { buildHealthProfileDisplay, type HealthProfileDisplay } from './_lib/health';
import {
  getPointsHistory,
  getUserPoints,
  getUserTier,
  type PointsHistoryRow,
  type TierCalcResult,
  type UserPoints,
} from './_lib/loyalty';

/**
 * queries.ts — data assembly for /user-detail, ported from user-detail.php
 * lines 79-336 (user row, tags, transactions/order stats, message count,
 * points/tier, shop name), 668-684 (health fallback — see ./_lib/health.ts),
 * and 990-1004 (per-order transaction_items, LIMIT 3).
 *
 * Every real-table query is a raw `sql` fragment, not Kysely's typed
 * builder — see ../users/queries.ts's `getUsersListPage()` doc comment.
 *
 * OUT OF SCOPE (Phase 8 follow-up, not silently dropped): the entire Odoo
 * ERP card (user-detail.php lines 191-335 data assembly, 1022-1541 markup) —
 * `OdooCustomerDashboardService`, `odoo_line_users` link lookup, invoice/
 * order/webhook timeline data. See page.tsx's module doc for the render-time
 * gate (ODOO_INTEGRATION_ENABLED) this respects.
 */

export { getAllTags, type UserTagOption };

// ---------------------------------------------------------------------------
// User row
// ---------------------------------------------------------------------------

export interface UserDetailRow {
  id: number;
  lineUserId: string;
  displayName: string | null;
  realName: string | null;
  memberId: string | null;
  phone: string | null;
  email: string | null;
  birthday: Date | null;
  gender: 'male' | 'female' | 'other' | null;
  address: string | null;
  province: string | null;
  postalCode: string | null;
  note: string | null;
  pictureUrl: string | null;
  statusMessage: string | null;
  isBlocked: number | null;
  createdAt: Date;
  weight: string | null;
  height: string | null;
  bloodType: string | null;
  medicalConditions: string | null;
  drugAllergies: string | null;
  lineAccountId: number | null;
}

export async function getUserRow(db: Kysely<TenantDB>, userId: number): Promise<UserDetailRow | null> {
  const result = await sql<UserDetailRow>`
    SELECT
      id, line_user_id AS lineUserId, display_name AS displayName, real_name AS realName,
      member_id AS memberId, phone, email, birthday, gender, address, province,
      postal_code AS postalCode, note, picture_url AS pictureUrl, status_message AS statusMessage,
      is_blocked AS isBlocked, created_at AS createdAt, weight, height, blood_type AS bloodType,
      medical_conditions AS medicalConditions, drug_allergies AS drugAllergies, line_account_id AS lineAccountId
    FROM users WHERE id = ${userId}
  `.execute(db);
  return result.rows[0] ?? null;
}

// ---------------------------------------------------------------------------
// user_tags for this user (user-detail.php lines 89-109)
// ---------------------------------------------------------------------------

export async function getUserTagsForUser(db: Kysely<TenantDB>, userId: number): Promise<UserTagOption[]> {
  const result = await sql<UserTagOption>`
    SELECT ut.id, ut.name, ut.color
    FROM user_tags ut
    JOIN user_tag_assignments uta ON ut.id = uta.tag_id
    WHERE uta.user_id = ${userId}
  `.execute(db);
  return result.rows;
}

// ---------------------------------------------------------------------------
// transactions (order history) + per-order transaction_items
// ---------------------------------------------------------------------------

export interface TransactionItemRow {
  productName: string;
  quantity: number;
}

export interface TransactionRow {
  id: number;
  orderNumber: string;
  createdAt: Date;
  status: string | null;
  grandTotal: string;
  shippingName: string | null;
  items: TransactionItemRow[];
}

export async function getTransactionItems(db: Kysely<TenantDB>, transactionId: number): Promise<TransactionItemRow[]> {
  const result = await sql<TransactionItemRow>`
    SELECT COALESCE(p.name, ti.product_name) AS productName, ti.quantity
    FROM transaction_items ti
    LEFT JOIN business_items p ON ti.product_id = p.id
    WHERE ti.transaction_id = ${transactionId}
    LIMIT 3
  `.execute(db);
  return result.rows;
}

/** Ported from user-detail.php lines 121-128 + the per-order items loop at lines 988-1007. Runs one items query per order (LIMIT 10 orders), mirroring the PHP page's own N+1 loop exactly — not a bug, a literal port. */
export async function getUserTransactions(db: Kysely<TenantDB>, userId: number): Promise<TransactionRow[]> {
  const result = await sql<Omit<TransactionRow, 'items'>>`
    SELECT id, order_number AS orderNumber, created_at AS createdAt, status, grand_total AS grandTotal,
           shipping_name AS shippingName
    FROM transactions
    WHERE user_id = ${userId}
    ORDER BY created_at DESC
    LIMIT 10
  `.execute(db);

  const withItems = await Promise.all(
    result.rows.map(async (row) => ({ ...row, items: await getTransactionItems(db, row.id) }))
  );
  return withItems;
}

export interface OrderStats {
  orderCount: number;
  totalSpent: number;
}

/** Ported from user-detail.php lines 140-149 — status NOT IN ('cancelled','pending'), off `transactions` (not `orders`). */
export async function getOrderStats(db: Kysely<TenantDB>, userId: number): Promise<OrderStats> {
  const result = await sql<{ cnt: number; total: number }>`
    SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS total
    FROM transactions
    WHERE user_id = ${userId} AND status NOT IN ('cancelled', 'pending')
  `.execute(db);
  const row = result.rows[0];
  return { orderCount: Number(row?.cnt ?? 0), totalSpent: Number(row?.total ?? 0) };
}

// ---------------------------------------------------------------------------
// message count, shop name
// ---------------------------------------------------------------------------

export async function getMessageCount(db: Kysely<TenantDB>, userId: number): Promise<number> {
  const result = await sql<{ count: number }>`SELECT COUNT(*) AS count FROM messages WHERE user_id = ${userId}`.execute(db);
  return Number(result.rows[0]?.count ?? 0);
}

export async function getShopName(db: Kysely<TenantDB>): Promise<string> {
  const result = await sql<{ shopName: string | null }>`SELECT shop_name AS shopName FROM shop_settings WHERE id = 1`.execute(db);
  return result.rows[0]?.shopName ?? 'LINE Shop';
}

// ---------------------------------------------------------------------------
// Composed page data
// ---------------------------------------------------------------------------

export interface UserDetailPageData {
  user: UserDetailRow;
  userTags: UserTagOption[];
  allTags: UserTagOption[];
  transactions: TransactionRow[];
  orderCount: number;
  totalSpent: number;
  messageCount: number;
  points: UserPoints;
  pointsHistory: PointsHistoryRow[];
  tier: TierCalcResult;
  shopName: string;
  health: HealthProfileDisplay;
}

/**
 * Assembles the whole /user-detail?id=N page's data in one call, mirroring
 * user-detail.php's top-to-bottom query sequence. Returns null when the
 * user id doesn't resolve to a row (mirrors `header('Location: users.php');
 * exit;` at line 84-87 — page.tsx redirects on a null return).
 */
export async function getUserDetailPageData(
  db: Kysely<TenantDB>,
  userId: number,
  currentBotId: number | null
): Promise<UserDetailPageData | null> {
  const user = await getUserRow(db, userId);
  if (!user) {
    return null;
  }

  const [userTags, allTags, transactions, orderStats, messageCount, points, pointsHistory, tier, shopName] =
    await Promise.all([
      getUserTagsForUser(db, userId),
      getAllTags(db, currentBotId),
      getUserTransactions(db, userId),
      getOrderStats(db, userId),
      getMessageCount(db, userId),
      getUserPoints(db, userId),
      getPointsHistory(db, userId, 5),
      getUserTier(db, userId, currentBotId),
      getShopName(db),
    ]);

  return {
    user,
    userTags,
    allTags,
    transactions,
    orderCount: orderStats.orderCount,
    totalSpent: orderStats.totalSpent,
    messageCount,
    points,
    pointsHistory,
    tier,
    shopName,
    health: buildHealthProfileDisplay(user),
  };
}
