import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { toNumber } from './numeric';
import { PROBLEM_KEYWORDS } from './executiveLogic';

/**
 * executiveData.ts — the DB-touching half of includes/dashboard/executive.php's
 * "STATS" section (lines 12-217). Every query below is a deliberately literal
 * transcription of the PHP source's raw SQL (same column list, same JOIN/WHERE/
 * GROUP BY/ORDER BY/LIMIT), issued via Kysely's `sql` tagged template — the
 * house style already established by @reya/auth (rbac.ts/impersonation.ts/
 * session.ts all query this way, never via the structured `.selectFrom()`
 * builder) — rather than the typed query builder, so the emitted SQL text is
 * byte-for-byte diffable against the PHP source for the parity harness.
 *
 * Two probes present in the PHP source are intentionally NOT replicated here
 * (flagged in the build report, per the brief):
 *   - `$ordersTable = 'transactions'; try { SELECT 1 FROM transactions } catch
 *     { $ordersTable = 'orders'; }` — this Next app targets a fixed, committed
 *     schema that always has `transactions`, so the fallback branch is dead
 *     code here. Escalate to mig-verify/mig-orchestrator if that assumption
 *     turns out to be wrong for some tenant.
 *   - `try { SELECT sent_by FROM messages } catch { $hasSentBy = false }` —
 *     same reasoning: packages/db/src/generated/tenant-db.d.ts's `Messages`
 *     interface already has `sentBy`, confirming the column exists in the
 *     generated/committed schema this snapshot was introspected from.
 *
 * Every PHP query block is independently try/caught (logs to `dev_logs` on
 * failure, keeps the pre-declared zero-value default) so one bad query
 * degrades gracefully instead of taking the whole page down. `dev_logs` is
 * NOT present in packages/db/src/generated/tenant-db.d.ts's generated schema
 * (flagged separately in the build report) — this port uses `console.error`
 * in its place, preserving the "one query's failure never 500s the page"
 * behaviour without writing to a table that doesn't exist in the typed
 * schema.
 */

function logQueryFailure(queryName: string, error: unknown): void {
  // eslint-disable-next-line no-console
  console.error(`[dashboard/executive] query '${queryName}' failed`, error);
}

async function safely<T>(queryName: string, fallback: T, run: () => Promise<T>): Promise<T> {
  try {
    return await run();
  } catch (error) {
    logQueryFailure(queryName, error);
    return fallback;
  }
}

export interface ExecutiveMessageStats {
  total: number;
  incoming: number;
  outgoing: number;
  unread: number;
}

export interface ExecutiveOrderStats {
  total: number;
  pending: number;
  completed: number;
  revenue: number;
}

export interface ExecutiveVideoStats {
  total: number;
  completed: number;
  /** Seconds — `video_calls.duration` is stored in seconds (matches PHP: `round($avg_duration / 60, 1)` at render time). */
  avgDuration: number;
}

export interface ProblemMessageRow {
  id: number;
  userId: number | null;
  content: string | null;
  /** Pre-formatted 'HH:MM' (Asia/Bangkok, via the session's `SET time_zone='+07:00'`) — avoids trusting mysql2's local-tz Date decoding for display. */
  timeHm: string;
  displayName: string | null;
  pictureUrl: string | null;
}

export interface AdminPerformanceRow {
  adminName: string;
  messagesSent: number;
  customersHandled: number;
}

export interface RecentConversationRow {
  id: number;
  displayName: string | null;
  pictureUrl: string | null;
  lineUserId: string | null;
  messageCount: number;
  lastMessageHm: string;
  lastMessage: string | null;
}

export interface ExecutiveData {
  messageStats: ExecutiveMessageStats;
  customersToday: number;
  newCustomers: number;
  orderStats: ExecutiveOrderStats;
  /** Rounded minutes, matches PHP's `round($stmt->fetchColumn() ?: 0)`. */
  avgResponseTime: number;
  videoStats: ExecutiveVideoStats;
  problemMessages: ProblemMessageRow[];
  adminPerformance: AdminPerformanceRow[];
  recentConversations: RecentConversationRow[];
  /** 24 buckets, index = hour-of-day (0-23), matches PHP's `array_fill(0, 24, 0)` + HOUR() fill. */
  hourlyActivity: number[];
  /** Raw incoming message bodies for the date window — feed into computeTopIssues() (executiveLogic.ts). */
  topIssueSourceMessages: string[];
}

const ZERO_MESSAGE_STATS: ExecutiveMessageStats = { total: 0, incoming: 0, outgoing: 0, unread: 0 };
const ZERO_ORDER_STATS: ExecutiveOrderStats = { total: 0, pending: 0, completed: 0, revenue: 0 };
const ZERO_VIDEO_STATS: ExecutiveVideoStats = { total: 0, completed: 0, avgDuration: 0 };

async function fetchMessageStats(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<ExecutiveMessageStats> {
  return safely('messageStats', ZERO_MESSAGE_STATS, async () => {
    const result = await sql<{ total: number | string | null; incoming: number | string | null; outgoing: number | string | null; unread: number | string | null }>`
      SELECT
        COUNT(*) as total,
        SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) as incoming,
        SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
        SUM(CASE WHEN direction = 'incoming' AND is_read = 0 THEN 1 ELSE 0 END) as unread
      FROM messages WHERE created_at BETWEEN ${dateStart} AND ${dateEnd}
    `.execute(db);
    const row = result.rows[0];
    if (!row) {
      return ZERO_MESSAGE_STATS;
    }
    return { total: toNumber(row.total), incoming: toNumber(row.incoming), outgoing: toNumber(row.outgoing), unread: toNumber(row.unread) };
  });
}

async function fetchCustomersToday(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<number> {
  return safely('customersToday', 0, async () => {
    const result = await sql<{ count: number | string | null }>`
      SELECT COUNT(DISTINCT user_id) as count FROM messages WHERE direction = 'incoming' AND created_at BETWEEN ${dateStart} AND ${dateEnd}
    `.execute(db);
    return toNumber(result.rows[0]?.count);
  });
}

async function fetchNewCustomers(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<number> {
  return safely('newCustomers', 0, async () => {
    const result = await sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM users WHERE created_at BETWEEN ${dateStart} AND ${dateEnd}
    `.execute(db);
    return toNumber(result.rows[0]?.count);
  });
}

async function fetchOrderStats(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<ExecutiveOrderStats> {
  return safely('orderStats', ZERO_ORDER_STATS, async () => {
    const result = await sql<{
      total: number | string | null;
      pending: number | string | null;
      completed: number | string | null;
      revenue: number | string | null;
    }>`
      SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status IN ('completed', 'delivered') THEN 1 ELSE 0 END) as completed,
        COALESCE(SUM(CASE WHEN status IN ('completed', 'delivered', 'paid') THEN grand_total ELSE 0 END), 0) as revenue
      FROM transactions WHERE created_at BETWEEN ${dateStart} AND ${dateEnd}
    `.execute(db);
    const row = result.rows[0];
    if (!row) {
      return ZERO_ORDER_STATS;
    }
    return { total: toNumber(row.total), pending: toNumber(row.pending), completed: toNumber(row.completed), revenue: toNumber(row.revenue) };
  });
}

async function fetchAvgResponseTime(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<number> {
  return safely('avgResponseTime', 0, async () => {
    const result = await sql<{ avg_time: number | string | null }>`
      SELECT AVG(TIMESTAMPDIFF(MINUTE, m1.created_at, m2.created_at)) as avg_time
      FROM messages m1
      JOIN messages m2 ON m1.user_id = m2.user_id
        AND m2.direction = 'outgoing'
        AND m2.created_at > m1.created_at
        AND m2.created_at < DATE_ADD(m1.created_at, INTERVAL 1 HOUR)
      WHERE m1.direction = 'incoming'
        AND m1.created_at BETWEEN ${dateStart} AND ${dateEnd}
    `.execute(db);
    return Math.round(toNumber(result.rows[0]?.avg_time));
  });
}

async function fetchVideoStats(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<ExecutiveVideoStats> {
  return safely('videoStats', ZERO_VIDEO_STATS, async () => {
    const result = await sql<{ total: number | string | null; completed: number | string | null; avg_duration: number | string | null }>`
      SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        AVG(CASE WHEN status = 'completed' THEN duration ELSE NULL END) as avg_duration
      FROM video_calls WHERE created_at BETWEEN ${dateStart} AND ${dateEnd}
    `.execute(db);
    const row = result.rows[0];
    if (!row) {
      return ZERO_VIDEO_STATS;
    }
    return { total: toNumber(row.total), completed: toNumber(row.completed), avgDuration: toNumber(row.avg_duration) };
  });
}

async function fetchProblemMessages(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<ProblemMessageRow[]> {
  return safely('problemMessages', [] as ProblemMessageRow[], async () => {
    const keywordClause = sql.join(
      PROBLEM_KEYWORDS.map((keyword) => sql`m.content LIKE ${`%${keyword}%`}`),
      sql` OR `
    );
    const result = await sql<{
      id: number;
      user_id: number | null;
      content: string | null;
      time_hm: string;
      display_name: string | null;
      picture_url: string | null;
    }>`
      SELECT m.id, m.user_id, m.content, DATE_FORMAT(m.created_at, '%H:%i') as time_hm, u.display_name, u.picture_url
      FROM messages m
      LEFT JOIN users u ON m.user_id = u.id
      WHERE m.direction = 'incoming'
      AND m.created_at BETWEEN ${dateStart} AND ${dateEnd}
      AND (${keywordClause})
      ORDER BY m.created_at DESC LIMIT 20
    `.execute(db);
    return result.rows.map((row) => ({
      id: row.id,
      userId: row.user_id,
      content: row.content,
      timeHm: row.time_hm,
      displayName: row.display_name,
      pictureUrl: row.picture_url,
    }));
  });
}

async function fetchAdminPerformance(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<AdminPerformanceRow[]> {
  return safely('adminPerformance', [] as AdminPerformanceRow[], async () => {
    const result = await sql<{ admin_name: string; messages_sent: number | string | null; customers_handled: number | string | null }>`
      SELECT
        COALESCE(m.sent_by, 'System/Bot') as admin_name,
        COUNT(*) as messages_sent,
        COUNT(DISTINCT m.user_id) as customers_handled
      FROM messages m
      WHERE m.direction = 'outgoing'
      AND m.created_at BETWEEN ${dateStart} AND ${dateEnd}
      GROUP BY m.sent_by
      ORDER BY messages_sent DESC
    `.execute(db);
    return result.rows.map((row) => ({
      adminName: row.admin_name,
      messagesSent: toNumber(row.messages_sent),
      customersHandled: toNumber(row.customers_handled),
    }));
  });
}

async function fetchRecentConversations(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<RecentConversationRow[]> {
  return safely('recentConversations', [] as RecentConversationRow[], async () => {
    const result = await sql<{
      id: number;
      display_name: string | null;
      picture_url: string | null;
      line_user_id: string | null;
      message_count: number | string | null;
      last_message_hm: string;
      last_message: string | null;
    }>`
      SELECT u.id, u.display_name, u.picture_url, u.line_user_id,
             COUNT(m.id) as message_count,
             DATE_FORMAT(MAX(m.created_at), '%H:%i') as last_message_hm,
             (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_message
      FROM users u
      JOIN messages m ON u.id = m.user_id
      WHERE m.created_at BETWEEN ${dateStart} AND ${dateEnd}
      GROUP BY u.id
      ORDER BY MAX(m.created_at) DESC
      LIMIT 15
    `.execute(db);
    return result.rows.map((row) => ({
      id: row.id,
      displayName: row.display_name,
      pictureUrl: row.picture_url,
      lineUserId: row.line_user_id,
      messageCount: toNumber(row.message_count),
      lastMessageHm: row.last_message_hm,
      lastMessage: row.last_message,
    }));
  });
}

async function fetchHourlyActivity(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<number[]> {
  return safely('hourlyActivity', new Array(24).fill(0) as number[], async () => {
    const result = await sql<{ hour: number; count: number | string | null }>`
      SELECT HOUR(created_at) as hour, COUNT(*) as count
      FROM messages
      WHERE created_at BETWEEN ${dateStart} AND ${dateEnd}
      GROUP BY HOUR(created_at)
    `.execute(db);
    const buckets = new Array(24).fill(0) as number[];
    for (const row of result.rows) {
      if (row.hour >= 0 && row.hour <= 23) {
        buckets[row.hour] = toNumber(row.count);
      }
    }
    return buckets;
  });
}

async function fetchTopIssueSourceMessages(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<string[]> {
  return safely('topIssueSourceMessages', [] as string[], async () => {
    const result = await sql<{ content: string | null }>`
      SELECT content FROM messages WHERE direction = 'incoming' AND created_at BETWEEN ${dateStart} AND ${dateEnd}
    `.execute(db);
    return result.rows.map((row) => row.content).filter((content): content is string => content !== null);
  });
}

/**
 * fetchExecutiveData — runs every independent query block from
 * executive.php's STATS section concurrently (each already independently
 * fail-soft via `safely()`, mirroring PHP's per-block try/catch — there is
 * no cross-query transaction/consistency requirement in the PHP source
 * either, so concurrent reads are a faithful, faster port of the same
 * sequential-but-independent PHP code).
 */
export async function fetchExecutiveData(db: Kysely<TenantDB>, dateStart: string, dateEnd: string): Promise<ExecutiveData> {
  const [
    messageStats,
    customersToday,
    newCustomers,
    orderStats,
    avgResponseTime,
    videoStats,
    problemMessages,
    adminPerformance,
    recentConversations,
    hourlyActivity,
    topIssueSourceMessages,
  ] = await Promise.all([
    fetchMessageStats(db, dateStart, dateEnd),
    fetchCustomersToday(db, dateStart, dateEnd),
    fetchNewCustomers(db, dateStart, dateEnd),
    fetchOrderStats(db, dateStart, dateEnd),
    fetchAvgResponseTime(db, dateStart, dateEnd),
    fetchVideoStats(db, dateStart, dateEnd),
    fetchProblemMessages(db, dateStart, dateEnd),
    fetchAdminPerformance(db, dateStart, dateEnd),
    fetchRecentConversations(db, dateStart, dateEnd),
    fetchHourlyActivity(db, dateStart, dateEnd),
    fetchTopIssueSourceMessages(db, dateStart, dateEnd),
  ]);

  return {
    messageStats,
    customersToday,
    newCustomers,
    orderStats,
    avgResponseTime,
    videoStats,
    problemMessages,
    adminPerformance,
    recentConversations,
    hourlyActivity,
    topIssueSourceMessages,
  };
}
