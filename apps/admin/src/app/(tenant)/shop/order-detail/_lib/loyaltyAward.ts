import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * loyaltyAward.ts — port of shop/order-detail.php's `approve_payment` action's
 * OWN inline "Award loyalty points (unified system)" block (PHP lines
 * 384-429).
 *
 * DELIBERATELY NOT a call into `(tenant)/user-detail/_lib/loyalty.ts`'s
 * `addPoints()` — that is a *different* PHP code path
 * (`classes/LoyaltyPoints.php::addPoints()`, called from user-detail.php's own
 * `add_points` POST handler) with a different default rate and different
 * write targets:
 *
 *   | | this file (order-detail.php inline block) | user-detail's addPoints() (LoyaltyPoints::addPoints()) |
 *   |---|---|---|
 *   | default `points_per_baht` when no `points_settings` row | **1** (PHP: `$pointsPerBaht = 1; // Default: 1 แต้มต่อ 1 บาท`) | **0.001** (`DEFAULT_POINTS_SETTINGS.pointsPerBaht`) |
 *   | balance column updated on `users` | `points` (`UPDATE users SET points = points + ?`) | `total_points`/`available_points` |
 *   | tables written | **BOTH** `points_history` AND `points_transactions` | only `points_transactions` |
 *
 *   Also note: `points_settings.line_account_id` lookup ordering, table
 *   shape, and rounding (`(int) floor($order['grand_total'] * $pointsPerBaht)`)
 *   are otherwise structurally similar between the two PHP sources, but they
 *   are genuinely two independent code paths in the PHP codebase — not one
 *   shared helper — and this port preserves that split rather than
 *   consolidating them. Do not "simplify" this into a shared points-award
 *   helper across the two page directories (also forbidden by this batch's
 *   allowed-paths boundary: order-detail may not import from
 *   user-detail/_lib).
 */

interface PointsSettingsRow {
  points_per_baht: string | number;
}

interface OrderForPointsRow {
  user_id: number | null;
  grand_total: string | number;
  order_number: string;
  current_points: number | null;
}

/**
 * Awards loyalty points for a just-approved order. Port of PHP lines 384-429
 * verbatim, including both nested try/catch blocks and their differing
 * error-handling (points_history logs via error_log/console.error on
 * failure; points_transactions swallows silently, matching PHP's own empty
 * `catch (Exception $e) {}`).
 */
export async function awardOrderLoyaltyPoints(db: Kysely<TenantDB>, orderId: number, currentBotId: number): Promise<void> {
  // PHP: SELECT o.*, u.line_user_id, u.points as current_points FROM {$ordersTable} o JOIN users u ON o.user_id = u.id WHERE o.id = ?
  const orderRows = await sql<OrderForPointsRow>`
    SELECT o.user_id, o.grand_total, o.order_number, u.points AS current_points
    FROM transactions o JOIN users u ON o.user_id = u.id WHERE o.id = ${orderId}
  `.execute(db);
  const order = orderRows.rows[0];

  if (!order || !order.user_id) {
    return;
  }

  // Get points settings. Default: 1 แต้มต่อ 1 บาท (PHP line 392) — NOT
  // user-detail's 0.001 default, see module doc above.
  let pointsPerBaht = 1;
  try {
    const settingsRows = await sql<PointsSettingsRow>`
      SELECT points_per_baht FROM points_settings
      WHERE line_account_id = ${currentBotId} OR line_account_id IS NULL
      ORDER BY line_account_id DESC LIMIT 1
    `.execute(db);
    const settings = settingsRows.rows[0];
    if (settings) {
      pointsPerBaht = Number(settings.points_per_baht);
    }
  } catch {
    // PHP: catch (Exception $e) {} — silent, pointsPerBaht stays at the default.
  }

  // Calculate points — PHP: (int) floor($order['grand_total'] * $pointsPerBaht).
  const earnedPoints = Math.floor(Number(order.grand_total) * pointsPerBaht);

  if (earnedPoints <= 0) {
    return;
  }

  const newBalance = (order.current_points ?? 0) + earnedPoints;

  // Update users.points (for LIFF system).
  await db
    .updateTable('users')
    .set({ points: sql`points + ${earnedPoints}` })
    .where('id', '=', order.user_id)
    .execute();

  // Log to points_history (for LIFF system).
  try {
    await db
      .insertInto('points_history')
      .values({
        line_account_id: currentBotId,
        user_id: order.user_id,
        points: earnedPoints,
        type: 'earn',
        description: `แต้มจากออเดอร์ #${order.order_number}`,
        reference_type: 'order',
        reference_id: orderId,
        balance_after: newBalance,
      })
      .execute();
  } catch (err) {
    // PHP: catch (Exception $e) { error_log('points_history insert error: ' . $e->getMessage()); }
    console.error('points_history insert error:', err);
  }

  // Also log to points_transactions (for legacy LoyaltyPoints system).
  try {
    await db
      .insertInto('points_transactions')
      .values({
        user_id: order.user_id,
        line_account_id: currentBotId,
        type: 'earn',
        points: earnedPoints,
        balance_after: newBalance,
        reference_type: 'order',
        reference_id: orderId,
        description: `Points from order #${order.order_number}`,
      })
      .execute();
  } catch {
    // PHP: catch (Exception $e) {} — silent, no error_log call (unlike points_history above).
  }

  // ⚠️ ไม่ส่งแจ้งเตือนแต้มแยก - จะรวมในข้อความสถานะออเดอร์ด้านบน (PHP line 424 comment).
}
