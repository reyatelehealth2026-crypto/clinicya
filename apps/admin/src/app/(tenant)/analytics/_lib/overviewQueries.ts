import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * overviewQueries.ts — literal port of includes/analytics/overview.php's
 * query block (lines 13-121). Every query is bound `(startDate, endDate,
 * lineAccountId)` (or just `lineAccountId`) exactly as the PHP source does,
 * including the `(line_account_id = ? OR line_account_id IS NULL)` shared-bot
 * fallback pattern used throughout this codebase. Raw `sql` fragments, not
 * Kysely's typed builder — same rationale as users/queries.ts's module doc
 * (no CamelCasePlugin on the shared Kysely<TenantDB> instance).
 *
 * The three try/catch-guarded blocks in the PHP source (sales stats, top
 * tags, segments count, revenue-by-day, top keywords) are reproduced as
 * try/catch here too — each falls back to the PHP source's exact default
 * (0, [], or 0) on any query error, matching "tables that might not exist on
 * every tenant" tolerance the original author clearly intended.
 */

export interface OverviewStats {
  followers: number;
  newFollowers: number;
  messages: number;
  broadcasts: number;
  broadcastRecipients: number;
  orders: number;
  revenue: number;
  activeUsers: number;
}

export interface TopTagRow {
  name: string;
  color: string | null;
  count: number;
}

export interface TopKeywordRow {
  keyword: string;
  hitCount: number;
}

export interface DailyCountRow {
  date: string;
  count: number;
}

export interface DailyMessagesRow {
  date: string;
  incoming: number;
  outgoing: number;
}

export interface DailyRevenueRow {
  date: string;
  revenue: number;
}

export interface OverviewData {
  stats: OverviewStats;
  topTags: TopTagRow[];
  topKeywords: TopKeywordRow[];
  segmentsCount: number;
  messagesByDay: DailyMessagesRow[];
  followersByDay: DailyCountRow[];
  revenueByDay: DailyRevenueRow[];
}

export async function getOverviewData(
  db: Kysely<TenantDB>,
  lineAccountId: number | null,
  startDate: string,
  endDate: string
): Promise<OverviewData> {
  const followersResult = await sql<{ total: number }>`
    SELECT COUNT(*) AS total FROM users WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL) AND is_blocked = 0
  `.execute(db);
  const followers = Number(followersResult.rows[0]?.total ?? 0);

  const newFollowersResult = await sql<{ total: number }>`
    SELECT COUNT(*) AS total FROM users WHERE DATE(created_at) BETWEEN ${startDate} AND ${endDate} AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
  `.execute(db);
  const newFollowers = Number(newFollowersResult.rows[0]?.total ?? 0);

  const messagesResult = await sql<{ total: number }>`
    SELECT COUNT(*) AS total FROM messages WHERE DATE(created_at) BETWEEN ${startDate} AND ${endDate} AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
  `.execute(db);
  const messages = Number(messagesResult.rows[0]?.total ?? 0);

  const broadcastResult = await sql<{ total: number; recipients: number }>`
    SELECT COUNT(*) AS total, COALESCE(SUM(sent_count), 0) AS recipients
    FROM broadcasts WHERE status = 'sent' AND DATE(sent_at) BETWEEN ${startDate} AND ${endDate} AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
  `.execute(db);
  const broadcasts = Number(broadcastResult.rows[0]?.total ?? 0);
  const broadcastRecipients = Number(broadcastResult.rows[0]?.recipients ?? 0);

  let orders = 0;
  let revenue = 0;
  try {
    const salesResult = await sql<{ total_orders: number; revenue: number }>`
      SELECT COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) AS revenue
      FROM transactions
      WHERE DATE(created_at) BETWEEN ${startDate} AND ${endDate}
      AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
    `.execute(db);
    orders = Number(salesResult.rows[0]?.total_orders ?? 0);
    revenue = Number(salesResult.rows[0]?.revenue ?? 0);
  } catch {
    orders = 0;
    revenue = 0;
  }

  const activeUsersResult = await sql<{ count: number }>`
    SELECT COUNT(DISTINCT user_id) AS count FROM messages WHERE DATE(created_at) BETWEEN ${startDate} AND ${endDate} AND direction = 'incoming' AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
  `.execute(db);
  const activeUsers = Number(activeUsersResult.rows[0]?.count ?? 0);

  let topTags: TopTagRow[] = [];
  try {
    const topTagsResult = await sql<TopTagRow>`
      SELECT t.name AS name, t.color AS color, COUNT(uta.user_id) AS count
      FROM user_tags t
      LEFT JOIN user_tag_assignments uta ON t.id = uta.tag_id
      WHERE (t.line_account_id = ${lineAccountId} OR t.line_account_id IS NULL)
      GROUP BY t.id
      ORDER BY count DESC
      LIMIT 5
    `.execute(db);
    topTags = topTagsResult.rows;
  } catch {
    topTags = [];
  }

  let segmentsCount = 0;
  try {
    const segmentsResult = await sql<{ count: number }>`
      SELECT COUNT(*) AS count FROM customer_segments WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
    `.execute(db);
    segmentsCount = Number(segmentsResult.rows[0]?.count ?? 0);
  } catch {
    segmentsCount = 0;
  }

  const messagesByDayResult = await sql<DailyMessagesRow>`
    SELECT DATE(created_at) AS date,
      SUM(direction = 'incoming') AS incoming,
      SUM(direction = 'outgoing') AS outgoing
    FROM messages
    WHERE DATE(created_at) BETWEEN ${startDate} AND ${endDate}
    AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
    GROUP BY DATE(created_at) ORDER BY date
  `.execute(db);

  const followersByDayResult = await sql<DailyCountRow>`
    SELECT DATE(created_at) AS date, COUNT(*) AS count
    FROM users
    WHERE DATE(created_at) BETWEEN ${startDate} AND ${endDate}
    AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
    GROUP BY DATE(created_at) ORDER BY date
  `.execute(db);

  let revenueByDay: DailyRevenueRow[] = [];
  try {
    const revenueByDayResult = await sql<DailyRevenueRow>`
      SELECT DATE(created_at) AS date,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) AS revenue
      FROM transactions
      WHERE DATE(created_at) BETWEEN ${startDate} AND ${endDate}
      AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
      GROUP BY DATE(created_at) ORDER BY date
    `.execute(db);
    revenueByDay = revenueByDayResult.rows;
  } catch {
    revenueByDay = [];
  }

  let topKeywords: TopKeywordRow[] = [];
  try {
    const topKeywordsResult = await sql<TopKeywordRow>`
      SELECT keyword AS keyword, hit_count AS hitCount FROM auto_replies
      WHERE is_active = 1 AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
      ORDER BY hit_count DESC LIMIT 5
    `.execute(db);
    topKeywords = topKeywordsResult.rows;
  } catch {
    topKeywords = [];
  }

  return {
    stats: {
      followers,
      newFollowers,
      messages,
      broadcasts,
      broadcastRecipients,
      orders,
      revenue,
      activeUsers,
    },
    topTags,
    topKeywords,
    segmentsCount,
    messagesByDay: messagesByDayResult.rows,
    followersByDay: followersByDayResult.rows,
    revenueByDay,
  };
}
