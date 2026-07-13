import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * accountQueries.ts — port of includes/analytics/account.php + the two
 * LineAccountManager methods it calls (classes/LineAccountManager.php:
 * getAllAccounts(), getAccountById($id)).
 */

export interface LineAccountOption {
  id: number;
  name: string;
  is_default: number | null;
}

/** Ported from LineAccountManager::getAllAccounts(): `SELECT * FROM line_accounts ORDER BY is_default DESC, name ASC`. */
export async function getAllAccounts(db: Kysely<TenantDB>): Promise<LineAccountOption[]> {
  const result = await sql<LineAccountOption>`
    SELECT id, name, is_default FROM line_accounts ORDER BY is_default DESC, name ASC
  `.execute(db);
  return result.rows;
}

export interface LineAccountDetail {
  id: number;
  name: string;
  basic_id: string | null;
  picture_url: string | null;
}

/** Ported from LineAccountManager::getAccountById($id). */
export async function getAccountById(db: Kysely<TenantDB>, id: number): Promise<LineAccountDetail | null> {
  const result = await sql<LineAccountDetail>`
    SELECT id, name, basic_id, picture_url FROM line_accounts WHERE id = ${id}
  `.execute(db);
  return result.rows[0] ?? null;
}

export interface AccountFollowerRow {
  id: number;
  user_id: number | null;
  followed_at: Date | null;
  unfollowed_at: Date | null;
  is_following: number;
  total_messages: number;
  follow_count: number;
  picture_url: string | null;
  display_name: string | null;
  current_name: string | null;
  current_picture: string | null;
}

export interface AccountFollowerStats {
  total: number;
  active: number;
  unfollowed: number;
}

export interface AccountEventRow {
  id: number;
  event_type: string;
  line_user_id: string | null;
  display_name: string | null;
  created_at: Date;
}

export interface AccountDailyStatRow {
  stat_date: string;
  new_followers: number;
  unfollowers: number;
  incoming_messages: number;
  outgoing_messages: number;
  total_messages: number;
}

export interface AccountTabData {
  followers: AccountFollowerRow[];
  followerStats: AccountFollowerStats;
  recentEvents: AccountEventRow[];
  dailyStats: AccountDailyStatRow[];
}

/** Ported from account.php lines 32-76 (the block gated on `if ($selectedAccountId)`). */
export async function getAccountTabData(
  db: Kysely<TenantDB>,
  selectedAccountId: number,
  dateFrom: string,
  dateTo: string
): Promise<AccountTabData> {
  const followersResult = await sql<AccountFollowerRow>`
    SELECT af.*, u.display_name AS current_name, u.picture_url AS current_picture
    FROM account_followers af
    LEFT JOIN users u ON af.user_id = u.id
    WHERE af.line_account_id = ${selectedAccountId}
    ORDER BY af.followed_at DESC
    LIMIT 100
  `.execute(db);

  const totalResult = await sql<{ count: number }>`
    SELECT COUNT(*) AS count FROM account_followers WHERE line_account_id = ${selectedAccountId}
  `.execute(db);
  const total = Number(totalResult.rows[0]?.count ?? 0);

  const activeResult = await sql<{ count: number }>`
    SELECT COUNT(*) AS count FROM account_followers WHERE line_account_id = ${selectedAccountId} AND is_following = 1
  `.execute(db);
  const active = Number(activeResult.rows[0]?.count ?? 0);

  const recentEventsResult = await sql<AccountEventRow>`
    SELECT ae.*, u.display_name AS display_name
    FROM account_events ae
    LEFT JOIN users u ON ae.user_id = u.id
    WHERE ae.line_account_id = ${selectedAccountId}
    ORDER BY ae.created_at DESC
    LIMIT 50
  `.execute(db);

  const dailyStatsResult = await sql<AccountDailyStatRow>`
    SELECT * FROM account_daily_stats
    WHERE line_account_id = ${selectedAccountId} AND stat_date BETWEEN ${dateFrom} AND ${dateTo}
    ORDER BY stat_date DESC
  `.execute(db);

  return {
    followers: followersResult.rows,
    followerStats: { total, active, unfollowed: total - active },
    recentEvents: recentEventsResult.rows,
    dailyStats: dailyStatsResult.rows,
  };
}
