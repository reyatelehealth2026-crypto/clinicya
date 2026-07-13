import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getActiveRewards, getMemberByUserId, getUserRedemptions, redeemReward } from './loyaltyPoints';

/**
 * handlers.ts — the three action handlers ported from api/rewards.php (189 lines, read in full):
 * `list`/`rewards` (GET), `redeem` (POST), `my_redemptions` (GET). Business logic (rewards catalogue,
 * point deduction, redemption codes, tier lookups) lives in classes/LoyaltyPoints.php's port
 * (./loyaltyPoints.ts) — this file only reproduces api/rewards.php's own request/response glue.
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

/** Port of api/rewards.php's local `jsonResponse($success, $message, $data)` — always HTTP 200. */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): ActionResult {
  return { status: 200, body: { success, message, ...data } };
}

export async function handleGetRewards(db: Kysely<TenantDB>, lineAccountId: number): Promise<ActionResult> {
  let rewards = await getActiveRewards(db, lineAccountId);

  // Fallback (contractNote): a tenant's own line_account_id has zero active rewards -> fall back to
  // the is_default=1 line_accounts row's rewards, unless that IS the account we already tried.
  if (rewards.length === 0) {
    const defaultAccountResult = await sql<{ id: number }>`SELECT id FROM line_accounts WHERE is_default = 1 LIMIT 1`.execute(db);
    const defaultAccount = defaultAccountResult.rows[0];
    if (defaultAccount && Number(defaultAccount.id) !== lineAccountId) {
      rewards = await getActiveRewards(db, Number(defaultAccount.id));
    }
  }

  return ok(true, 'OK', { rewards });
}

export async function handleRedeem(db: Kysely<TenantDB>, lineAccountId: number, data: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = typeof data.line_user_id === 'string' ? data.line_user_id : '';
  const rewardId = parseInt(String(data.reward_id ?? '0'), 10) || 0;

  if (!lineUserId) return ok(false, 'กรุณาเข้าสู่ระบบ');
  if (!rewardId) return ok(false, 'กรุณาเลือกของรางวัล');

  const userResult = await sql<{ id: number; display_name: string | null }>`
    SELECT id, display_name FROM users WHERE line_user_id = ${lineUserId}
  `.execute(db);
  const user = userResult.rows[0];
  if (!user) return ok(false, 'ไม่พบข้อมูลผู้ใช้');

  const result = await redeemReward(db, lineAccountId, user.id, rewardId);

  if (!result.success) {
    return ok(false, result.message);
  }

  const member = await getMemberByUserId(db, lineAccountId, user.id);

  return ok(true, result.message, {
    redemption_code: result.redemption_code,
    reward: result.reward,
    redemption_id: result.redemption_id,
    expires_at: result.expires_at ?? null,
    new_balance: member?.available_points ?? 0,
    member,
  });
}

export async function handleMyRedemptions(db: Kysely<TenantDB>, query: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = typeof query.line_user_id === 'string' ? query.line_user_id : '';
  const limitRaw = parseInt(String(query.limit ?? '20'), 10);
  const limit = Math.min(Number.isFinite(limitRaw) ? limitRaw : 20, 100);

  if (!lineUserId) return ok(false, 'Missing line_user_id');

  const userResult = await sql<{ id: number }>`SELECT id FROM users WHERE line_user_id = ${lineUserId}`.execute(db);
  const user = userResult.rows[0];
  if (!user) return ok(false, 'ไม่พบข้อมูลผู้ใช้');

  const redemptions = await getUserRedemptions(db, user.id, limit);
  return ok(true, 'OK', { redemptions });
}
