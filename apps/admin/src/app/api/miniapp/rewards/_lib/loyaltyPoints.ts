import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';
import { calculateTier, getUserTierPoints } from './tierService';

/**
 * packages/db's mysql2 pool has no `dateStrings: true`, so DATE/DATETIME/TIMESTAMP columns hydrate as
 * JS `Date` objects, not PHP PDO's raw `YYYY-MM-DD`/`YYYY-MM-DD HH:MM:SS` strings — left unformatted
 * these serialize to `Z`-suffixed ISO strings via `JSON.stringify`, which is NOT what
 * classes/LoyaltyPoints.php actually returns. Same fix already applied in points-history's/
 * health-profile's query.ts (`formatPhpDate()`/`asDateTimeString()`) — mirrored here rather than
 * imported, per this batch's allowed-paths boundary (each miniapp route folder is self-contained).
 */
function asDateTimeString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

/** Same rationale as `asDateTimeString()`, for DATE columns (`rewards.start_date`/`end_date`, `reward_redemptions.expires_at`) — `YYYY-MM-DD` only. */
function asDateString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
}

/**
 * loyaltyPoints.ts — port of classes/LoyaltyPoints.php (667 lines, read in full) for the methods
 * api/rewards.php's `list`/`redeem`/`my_redemptions` actions actually call: getActiveRewards,
 * getReward, redeemReward (+ its deductPoints/updateUserTier/generateUniqueRedemptionCode internals),
 * getUserRedemptions, getMemberByUserId (+ its getUserTier wrapper), getUserPoints.
 */

// ---------------------------------------------------------------------------
// getUserPoints — same port as member/_lib/loyaltyPoints.ts (see that file's doc comment for why this
// is duplicated rather than shared across the member/rewards folder boundary).
// ---------------------------------------------------------------------------

export interface UserPointsResult {
  total_points: number;
  available_points: number;
  used_points: number;
}

export async function getUserPoints(db: Kysely<TenantDB>, userId: number): Promise<UserPointsResult> {
  const txResult = await sql<{
    total_points: number | string;
    available_points: number | string;
    used_points: number | string;
  }>`
    SELECT
      COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as total_points,
      COALESCE(SUM(points), 0) as available_points,
      COALESCE(SUM(CASE WHEN points < 0 THEN ABS(points) ELSE 0 END), 0) as used_points
    FROM points_transactions
    WHERE user_id = ${userId}
  `.execute(db);
  const result = txResult.rows[0];

  if (!result || Number(result.available_points) === 0) {
    const userResult = await sql<{
      total_points: number | string | null;
      available_points: number | string | null;
      used_points: number | string | null;
      points: number | string | null;
    }>`
      SELECT total_points, available_points, used_points, points FROM users WHERE id = ${userId}
    `.execute(db);
    const userRow = userResult.rows[0];
    if (userRow) {
      let availablePoints = userRow.available_points;
      let totalPoints = userRow.total_points;
      const hasAvailable = availablePoints !== null && availablePoints !== undefined && Number(availablePoints) !== 0;
      const hasLegacyPoints = userRow.points !== null && userRow.points !== undefined && Number(userRow.points) !== 0;
      if (!hasAvailable && hasLegacyPoints) {
        availablePoints = userRow.points;
        totalPoints = userRow.points;
      }
      if (Number(availablePoints ?? 0) > 0) {
        return {
          total_points: Number(totalPoints ?? 0),
          available_points: Number(availablePoints ?? 0),
          used_points: Number(userRow.used_points ?? 0),
        };
      }
    }
  }

  if (!result) {
    return { total_points: 0, available_points: 0, used_points: 0 };
  }

  return {
    total_points: Number(result.total_points),
    available_points: Math.max(0, Number(result.available_points)),
    used_points: Number(result.used_points),
  };
}

// ---------------------------------------------------------------------------
// Rewards catalogue
// ---------------------------------------------------------------------------

export interface RewardRow {
  id: number;
  line_account_id: number | null;
  name: string;
  description: string | null;
  image_url: string | null;
  points_required: number;
  reward_type: string | null;
  reward_value: string | null;
  stock: number | null;
  max_per_user: number | null;
  is_active: number | null;
  sort_order: number | null;
  start_date: string | Date | null;
  end_date: string | Date | null;
  terms: string | null;
  created_at: string | Date;
  // `valid_until`/`validity_days` are NOT real `rewards` columns (verified against the CREATE TABLE —
  // `SELECT *` never actually populates these), so unlike the other date fields on this interface they
  // stay string-typed rather than `string | Date`: this branch of redeemReward() is dead in practice on
  // the current schema, preserved verbatim from classes/LoyaltyPoints.php's own `??`/`!empty()` guards
  // rather than "fixed" into something the real table can't produce.
  valid_until?: string | null;
  validity_days?: number | null;
}

/** Formats every DATE/TIMESTAMP column `SELECT * FROM rewards` can return — see `asDateTimeString()`'s doc comment. */
function normalizeRewardRowDates(reward: RewardRow): RewardRow {
  return {
    ...reward,
    created_at: asDateTimeString(reward.created_at) ?? reward.created_at,
    start_date: asDateString(reward.start_date),
    end_date: asDateString(reward.end_date),
  };
}

async function columnExists(db: Kysely<TenantDB>, table: string, column: string): Promise<boolean> {
  try {
    const result = await sql<{ Field: string }>`SHOW COLUMNS FROM ${sql.table(table)} LIKE ${column}`.execute(db);
    return result.rows.length > 0;
  } catch {
    return false;
  }
}

/** Port of LoyaltyPoints::getRewards(true) / getActiveRewards() — SELECT * with stock normalized (NULL -> -1). */
export async function getActiveRewards(db: Kysely<TenantDB>, lineAccountId: number): Promise<RewardRow[]> {
  try {
    const hasLineAccountId = await columnExists(db, 'rewards', 'line_account_id');
    const hasIsActive = await columnExists(db, 'rewards', 'is_active');

    const filters: ReturnType<typeof sql>[] = [];
    if (hasLineAccountId) filters.push(sql`(line_account_id = ${lineAccountId} OR line_account_id IS NULL)`);
    if (hasIsActive) filters.push(sql`is_active = 1`);
    const whereSql = filters.length > 0 ? sql`WHERE ${sql.join(filters, sql` AND `)}` : sql``;

    const result = await sql<RewardRow>`SELECT * FROM rewards ${whereSql} ORDER BY points_required ASC`.execute(db);
    return result.rows.map((reward) =>
      normalizeRewardRowDates({
        ...reward,
        stock: reward.stock === null || reward.stock === undefined ? -1 : Number(reward.stock),
      })
    );
  } catch {
    return [];
  }
}

async function getReward(db: Kysely<TenantDB>, rewardId: number): Promise<RewardRow | null> {
  const result = await sql<RewardRow>`SELECT * FROM rewards WHERE id = ${rewardId}`.execute(db);
  const reward = result.rows[0];
  // NOTE: unlike getActiveRewards(), PHP's LoyaltyPoints::getReward() does NOT normalize `stock`
  // (NULL -> -1) — preserved verbatim (this reward is only ever consumed internally by redeemReward(),
  // whose stock checks already treat `stock === null` as "unlimited" the same as `stock === -1`).
  return reward ? normalizeRewardRowDates(reward) : null;
}

// ---------------------------------------------------------------------------
// Redeem
// ---------------------------------------------------------------------------

async function updateUserTierColumn(db: Kysely<TenantDB>, lineAccountId: number, userId: number, points: number): Promise<void> {
  try {
    const tierInfo = await calculateTier(db, lineAccountId, points);
    await sql`UPDATE users SET member_tier = ${tierInfo.tier_code} WHERE id = ${userId}`.execute(db);
  } catch {
    // best-effort, matches PHP's catch (Exception $e) { error_log(...) }.
  }
}

async function deductPoints(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  userId: number,
  points: number,
  referenceType: string,
  referenceId: number,
  description: string
): Promise<boolean> {
  if (points <= 0) return false;
  const current = await getUserPoints(db, userId);
  if (current.available_points < points) return false;
  const newBalance = current.available_points - points;

  await sql`UPDATE users SET available_points = available_points - ${points}, used_points = used_points + ${points} WHERE id = ${userId}`.execute(
    db
  );

  await updateUserTierColumn(db, lineAccountId, userId, newBalance);

  await sql`
    INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description)
    VALUES (${userId}, ${lineAccountId}, 'redeem', ${-points}, ${newBalance}, ${referenceType}, ${referenceId}, ${description})
  `.execute(db);

  return true;
}

function randomHex(byteLength: number): string {
  const bytes = new Uint8Array(byteLength);
  crypto.getRandomValues(bytes);
  return Array.from(bytes)
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

async function generateUniqueRedemptionCode(db: Kysely<TenantDB>): Promise<string> {
  const maxAttempts = 10;
  let code = '';
  let exists = true;

  for (let attempt = 0; attempt < maxAttempts && exists; attempt++) {
    const timestamp = Math.floor(Date.now() / 1000).toString(36);
    const random = randomHex(4).toUpperCase().slice(0, 6);
    code = 'RW' + timestamp.slice(-4).toUpperCase() + random;

    const result = await sql<{ count: number }>`SELECT COUNT(*) as count FROM reward_redemptions WHERE redemption_code = ${code}`.execute(db);
    exists = Number(result.rows[0]?.count ?? 0) > 0;
  }

  if (exists) {
    code = 'RW' + randomHex(6).toUpperCase().slice(0, 10);
  }
  return code;
}

export interface RedeemSuccess {
  success: true;
  message: string;
  redemption_code: string;
  reward: RewardRow;
  redemption_id: number;
  expires_at: string | null;
}
export interface RedeemFailure {
  success: false;
  message: string;
}

/** Port of LoyaltyPoints::redeemReward(). */
export async function redeemReward(db: Kysely<TenantDB>, lineAccountId: number, userId: number, rewardId: number): Promise<RedeemSuccess | RedeemFailure> {
  const reward = await getReward(db, rewardId);
  if (!reward) return { success: false, message: 'ไม่พบรางวัล' };

  if (reward.is_active !== null && reward.is_active !== undefined && Number(reward.is_active) === 0) {
    return { success: false, message: 'รางวัลนี้ไม่พร้อมให้บริการ' };
  }

  if (reward.stock !== null && reward.stock !== undefined && reward.stock !== -1 && reward.stock <= 0) {
    return { success: false, message: 'รางวัลหมดแล้ว' };
  }

  const userPoints = await getUserPoints(db, userId);
  if (userPoints.available_points < reward.points_required) {
    return { success: false, message: 'แต้มไม่เพียงพอ' };
  }

  const deducted = await deductPoints(db, lineAccountId, userId, reward.points_required, 'reward', rewardId, `แลกรางวัล: ${reward.name}`);
  if (!deducted) {
    return { success: false, message: 'ไม่สามารถหักแต้มได้' };
  }

  if (reward.stock !== null && reward.stock !== undefined && reward.stock > 0 && reward.stock !== -1) {
    await sql`UPDATE rewards SET stock = stock - 1 WHERE id = ${rewardId} AND stock > 0`.execute(db);
  }

  const code = await generateUniqueRedemptionCode(db);

  let expiresAt: string | null = null;
  if (reward.valid_until) {
    expiresAt = reward.valid_until;
  } else if (reward.validity_days) {
    const expiry = new Date(Date.now() + Number(reward.validity_days) * 24 * 60 * 60 * 1000);
    expiresAt = expiry.toISOString().slice(0, 19).replace('T', ' ');
  }

  const insertResult = await sql<never>`
    INSERT INTO reward_redemptions (user_id, reward_id, line_account_id, points_used, redemption_code, expires_at)
    VALUES (${userId}, ${rewardId}, ${lineAccountId}, ${reward.points_required}, ${code}, ${expiresAt})
  `.execute(db);

  return {
    success: true,
    message: 'แลกรางวัลสำเร็จ!',
    redemption_code: code,
    reward,
    redemption_id: Number(insertResult.insertId ?? 0),
    expires_at: expiresAt,
  };
}

// ---------------------------------------------------------------------------
// Redemption history
// ---------------------------------------------------------------------------

export interface RedemptionRow {
  id: number;
  user_id: number;
  reward_id: number;
  line_account_id: number | null;
  points_used: number;
  redemption_code: string | null;
  status: string | null;
  expires_at: string | Date | null;
  created_at: string | Date;
  approved_at: string | Date | null;
  approved_by: number | null;
  delivered_at: string | Date | null;
  notes: string | null;
  reward_name: string;
  reward_image: string | null;
}

export async function getUserRedemptions(db: Kysely<TenantDB>, userId: number, limit: number): Promise<RedemptionRow[]> {
  const result = await sql<RedemptionRow>`
    SELECT rr.*, r.name as reward_name, r.image_url as reward_image
    FROM reward_redemptions rr JOIN rewards r ON rr.reward_id = r.id
    WHERE rr.user_id = ${userId}
    ORDER BY rr.created_at DESC LIMIT ${limit}
  `.execute(db);
  // Formats every DATE/TIMESTAMP column `rr.*` can return — see `asDateTimeString()`'s doc comment.
  return result.rows.map((row) => ({
    ...row,
    created_at: asDateTimeString(row.created_at) ?? row.created_at,
    approved_at: asDateTimeString(row.approved_at),
    delivered_at: asDateTimeString(row.delivered_at),
    expires_at: asDateString(row.expires_at),
  }));
}

// ---------------------------------------------------------------------------
// Member lookup for the redeem response's `member` field
// ---------------------------------------------------------------------------

/**
 * Port of classes/LoyaltyPoints.php::getUserTier()'s OWN return array — NOT the full TierService
 * TierInfo shape (that one is used by member/_lib/tierService.ts's calculateTier() consumers, e.g.
 * `member:get_card`/`member:check`). LoyaltyPoints::getUserTier() re-maps TierService's wider array into
 * a narrower field set (`name`/`tier_code`/`color`/`icon`/`current_points`/`min_points`/`next_tier_name`/
 * `next_tier_points`/`points_to_next`/`progress_percent`/`discount_percent` — 11 fields, no `tier_name`
 * or `next_tier_code`), and that narrower shape is exactly what ends up embedded in a redeem response's
 * `member.tier` field. packages/contracts/src/rewards.ts's `RedeemMemberSchema.tier` already declares
 * this narrow shape; this type is what makes the handler match it (see classes/LoyaltyPoints.php's
 * getUserTier(), read in full, for the field list this mirrors verbatim).
 */
export interface MemberTierSummary {
  name: string;
  tier_code: string;
  color: string;
  icon: string;
  current_points: number;
  min_points: number;
  next_tier_name: string;
  next_tier_points: number | null;
  points_to_next: number;
  progress_percent: number;
  discount_percent: number;
}

export interface MemberSummary {
  id: number;
  display_name: string | null;
  picture_url: string | null;
  total_points: number | null;
  available_points: number | null;
  used_points: number | null;
  line_user_id: string;
  tier: MemberTierSummary;
  points: number;
}

async function getUserTier(db: Kysely<TenantDB>, lineAccountId: number, userId: number): Promise<MemberTierSummary> {
  const points = await getUserTierPoints(db, userId);
  const tierInfo = await calculateTier(db, lineAccountId, points);
  return {
    name: tierInfo.tier_name,
    tier_code: tierInfo.tier_code,
    color: tierInfo.color,
    icon: tierInfo.icon,
    current_points: tierInfo.current_points,
    min_points: tierInfo.min_points,
    next_tier_name: tierInfo.next_tier_name,
    next_tier_points: tierInfo.next_tier_points,
    points_to_next: tierInfo.points_to_next,
    progress_percent: tierInfo.progress_percent,
    discount_percent: tierInfo.discount_percent,
  };
}

/** Port of LoyaltyPoints::getMemberByUserId(). */
export async function getMemberByUserId(db: Kysely<TenantDB>, lineAccountId: number, userId: number): Promise<MemberSummary | null> {
  const result = await sql<{
    id: number;
    display_name: string | null;
    picture_url: string | null;
    total_points: number | string | null;
    available_points: number | string | null;
    used_points: number | string | null;
    line_user_id: string;
  }>`
    SELECT id, display_name, picture_url, total_points, available_points, used_points, line_user_id
    FROM users WHERE id = ${userId} LIMIT 1
  `.execute(db);
  const member = result.rows[0];
  if (!member) return null;

  const tier = await getUserTier(db, lineAccountId, userId);

  return {
    id: member.id,
    display_name: member.display_name,
    picture_url: member.picture_url,
    total_points: member.total_points === null || member.total_points === undefined ? null : Number(member.total_points),
    available_points: member.available_points === null || member.available_points === undefined ? null : Number(member.available_points),
    used_points: member.used_points === null || member.used_points === undefined ? null : Number(member.used_points),
    line_user_id: member.line_user_id,
    tier,
    points: member.available_points === null || member.available_points === undefined ? 0 : Number(member.available_points),
  };
}
