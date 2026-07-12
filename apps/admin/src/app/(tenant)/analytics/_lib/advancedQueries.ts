import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * advancedQueries.ts — port of app/Models/Analytics/AnalyticsModel.php, the
 * data layer behind analytics.php's 'advanced' tab (includes/analytics/
 * advanced.php -> App\Controllers\AnalyticsController -> AnalyticsModel).
 *
 * IMPORTANT filter-semantics note (differs from overviewQueries.ts and from
 * users/queries.ts): every method here uses `$accountFilter = $this->
 * lineAccountId ? "AND line_account_id = ?" : "";` — when lineAccountId is
 * null/0, there is NO account filter fragment at all (not `OR line_account_id
 * IS NULL`), so the query spans every bot in the tenant. This is a genuinely
 * different filter shape than overview.php's `(line_account_id = ? OR
 * line_account_id IS NULL)` pattern one tab over — both are replicated
 * literally, not reconciled, per this batch's "replicate literally, do not
 * silently fix" instruction.
 */

function acctFilterUsers(lineAccountId: number | null) {
  return lineAccountId ? sql`AND line_account_id = ${lineAccountId}` : sql``;
}
function acctFilterM1(lineAccountId: number | null) {
  return lineAccountId ? sql`AND m1.line_account_id = ${lineAccountId}` : sql``;
}
function acctFilterO(lineAccountId: number | null) {
  return lineAccountId ? sql`AND o.line_account_id = ${lineAccountId}` : sql``;
}
function acctFilterBc(lineAccountId: number | null) {
  return lineAccountId ? sql`AND bc.line_account_id = ${lineAccountId}` : sql``;
}
function acctFilterTl(lineAccountId: number | null) {
  return lineAccountId ? sql`AND tl.line_account_id = ${lineAccountId}` : sql``;
}

export interface DateRange {
  start: string;
  end: string;
}

/** Ported from AnalyticsModel::getDateRange(). */
export function getDateRange(period: string, now: Date = new Date()): DateRange {
  const end = bangkokDateTime(now, '23:59:59');
  let start: string;
  switch (period) {
    case '24h':
      start = new Date(now.getTime() - 24 * 60 * 60 * 1000).toISOString().slice(0, 19).replace('T', ' ');
      break;
    case '7d':
      start = bangkokDateTime(addDays(now, -7), '00:00:00');
      break;
    case '30d':
      start = bangkokDateTime(addDays(now, -30), '00:00:00');
      break;
    case '90d':
      start = bangkokDateTime(addDays(now, -90), '00:00:00');
      break;
    case 'year':
      start = `${bangkokYear(now)}-01-01 00:00:00`;
      break;
    default:
      start = bangkokDateTime(addDays(now, -7), '00:00:00');
  }
  return { start, end };
}

/** Ported from AnalyticsModel::getPreviousDateRange(). */
export function getPreviousDateRange(current: DateRange): DateRange {
  const diffMs = Date.parse(current.end.replace(' ', 'T') + 'Z') - Date.parse(current.start.replace(' ', 'T') + 'Z');
  const startMs = Date.parse(current.start.replace(' ', 'T') + 'Z') - diffMs;
  const endMs = Date.parse(current.start.replace(' ', 'T') + 'Z') - 1000;
  return {
    start: new Date(startMs).toISOString().slice(0, 19).replace('T', ' '),
    end: new Date(endMs).toISOString().slice(0, 19).replace('T', ' '),
  };
}

function addDays(d: Date, days: number): Date {
  return new Date(d.getTime() + days * 24 * 60 * 60 * 1000);
}
function bangkokYear(d: Date): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bangkok', year: 'numeric' }).format(d);
}
function bangkokDateTime(d: Date, time: string): string {
  const datePart = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Bangkok',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(d);
  return `${datePart} ${time}`;
}

export interface UserStats {
  total: number;
  new: number;
  active: number;
  growthRate: number;
  daily: { date: string; count: number }[];
}

export async function getUserStats(db: Kysely<TenantDB>, lineAccountId: number | null, range: DateRange): Promise<UserStats> {
  const totalResult = await sql<{ total: number }>`
    SELECT COUNT(*) AS total FROM users WHERE 1=1 ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const total = Number(totalResult.rows[0]?.total ?? 0);

  const newResult = await sql<{ total: number }>`
    SELECT COUNT(*) AS total FROM users WHERE created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const newUsers = Number(newResult.rows[0]?.total ?? 0);

  const activeResult = await sql<{ total: number }>`
    SELECT COUNT(DISTINCT user_id) AS total FROM messages
    WHERE direction = 'incoming' AND created_at BETWEEN ${range.start} AND ${range.end}
    ${lineAccountId ? sql`AND line_account_id = ${lineAccountId}` : sql``}
  `.execute(db);
  const active = Number(activeResult.rows[0]?.total ?? 0);

  const dailyResult = await sql<{ date: string; count: number }>`
    SELECT DATE(created_at) AS date, COUNT(*) AS count
    FROM users WHERE created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
    GROUP BY DATE(created_at) ORDER BY date
  `.execute(db);

  return {
    total,
    new: newUsers,
    active,
    growthRate: total > 0 ? Math.round((newUsers / total) * 1000) / 10 : 0,
    daily: dailyResult.rows,
  };
}

export interface MessageStats {
  total: number;
  incoming: number;
  outgoing: number;
  byType: { message_type: string | null; count: number }[];
  hourly: { hour: number; count: number }[];
  responseRate: number;
}

export async function getMessageStats(db: Kysely<TenantDB>, lineAccountId: number | null, range: DateRange): Promise<MessageStats> {
  const statsResult = await sql<{ total: number; incoming: number; outgoing: number }>`
    SELECT COUNT(*) AS total,
      SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) AS incoming,
      SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) AS outgoing
    FROM messages WHERE created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const stats = statsResult.rows[0] ?? { total: 0, incoming: 0, outgoing: 0 };

  const byTypeResult = await sql<{ message_type: string | null; count: number }>`
    SELECT message_type, COUNT(*) AS count
    FROM messages WHERE created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
    GROUP BY message_type ORDER BY count DESC
  `.execute(db);

  const hourlyResult = await sql<{ hour: number; count: number }>`
    SELECT HOUR(created_at) AS hour, COUNT(*) AS count
    FROM messages WHERE direction = 'incoming' AND created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
    GROUP BY HOUR(created_at) ORDER BY hour
  `.execute(db);

  const respondedResult = await sql<{ responded: number }>`
    SELECT COUNT(DISTINCT m1.user_id) AS responded
    FROM messages m1
    INNER JOIN messages m2 ON m1.user_id = m2.user_id
      AND m2.direction = 'outgoing'
      AND m2.created_at BETWEEN m1.created_at AND DATE_ADD(m1.created_at, INTERVAL 5 MINUTE)
    WHERE m1.direction = 'incoming' AND m1.created_at BETWEEN ${range.start} AND ${range.end}
    ${acctFilterM1(lineAccountId)}
  `.execute(db);
  const responded = Number(respondedResult.rows[0]?.responded ?? 0);

  return {
    total: Number(stats.total ?? 0),
    incoming: Number(stats.incoming ?? 0),
    outgoing: Number(stats.outgoing ?? 0),
    byType: byTypeResult.rows,
    hourly: hourlyResult.rows,
    responseRate: Number(stats.incoming ?? 0) > 0 ? Math.round((responded / Number(stats.incoming)) * 1000) / 10 : 0,
  };
}

export interface OrderStats {
  total: number;
  pending: number;
  paid: number;
  delivered: number;
  cancelled: number;
  conversionRate: number;
  avgOrderValue: number;
  daily: { date: string; orders: number; revenue: number }[];
}

export async function getOrderStats(db: Kysely<TenantDB>, lineAccountId: number | null, range: DateRange): Promise<OrderStats> {
  const statsResult = await sql<{
    total: number;
    pending: number;
    paid: number;
    delivered: number;
    cancelled: number;
    total_revenue: number | null;
    avg_order_value: number | null;
  }>`
    SELECT COUNT(*) AS total,
      SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
      SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
      SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
      SUM(grand_total) AS total_revenue,
      AVG(grand_total) AS avg_order_value
    FROM orders WHERE created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const stats = statsResult.rows[0] ?? {
    total: 0,
    pending: 0,
    paid: 0,
    delivered: 0,
    cancelled: 0,
    total_revenue: 0,
    avg_order_value: 0,
  };

  const dailyResult = await sql<{ date: string; orders: number; revenue: number }>`
    SELECT DATE(created_at) AS date, COUNT(*) AS orders, SUM(grand_total) AS revenue
    FROM orders WHERE created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
    GROUP BY DATE(created_at) ORDER BY date
  `.execute(db);

  const total = Number(stats.total ?? 0);
  const paid = Number(stats.paid ?? 0);
  const delivered = Number(stats.delivered ?? 0);

  return {
    total,
    pending: Number(stats.pending ?? 0),
    paid,
    delivered,
    cancelled: Number(stats.cancelled ?? 0),
    conversionRate: total > 0 ? Math.round(((paid + delivered) / total) * 1000) / 10 : 0,
    avgOrderValue: Math.round(Number(stats.avg_order_value ?? 0) * 100) / 100,
    daily: dailyResult.rows,
  };
}

export interface RevenueStats {
  total: number;
  previous: number;
  growth: number;
  topProducts: { product_name: string; qty: number; revenue: number }[];
}

export async function getRevenueStats(db: Kysely<TenantDB>, lineAccountId: number | null, range: DateRange): Promise<RevenueStats> {
  const totalResult = await sql<{ total: number | null }>`
    SELECT SUM(grand_total) AS total FROM orders
    WHERE payment_status = 'paid' AND created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const total = Number(totalResult.rows[0]?.total ?? 0);

  const prevRange = getPreviousDateRange(range);
  const prevTotalResult = await sql<{ total: number | null }>`
    SELECT SUM(grand_total) AS total FROM orders
    WHERE payment_status = 'paid' AND created_at BETWEEN ${prevRange.start} AND ${prevRange.end} ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const prevTotal = Number(prevTotalResult.rows[0]?.total ?? 0);

  const growth = prevTotal > 0 ? Math.round(((total - prevTotal) / prevTotal) * 1000) / 10 : 0;

  const topProductsResult = await sql<{ product_name: string; qty: number; revenue: number }>`
    SELECT oi.product_name AS product_name, SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS revenue
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    WHERE o.payment_status = 'paid' AND o.created_at BETWEEN ${range.start} AND ${range.end}
    ${acctFilterO(lineAccountId)}
    GROUP BY oi.product_name ORDER BY revenue DESC LIMIT 10
  `.execute(db);

  return {
    total: Math.round(total * 100) / 100,
    previous: Math.round(prevTotal * 100) / 100,
    growth,
    topProducts: topProductsResult.rows,
  };
}

export interface EngagementStats {
  broadcasts: { name: string; sent_count: number; unique_clicks: number; ctr: number | null }[];
  links: { title: string; click_count: number; unique_clicks: number }[];
}

export async function getEngagementStats(db: Kysely<TenantDB>, lineAccountId: number | null, range: DateRange): Promise<EngagementStats> {
  const broadcastsResult = await sql<{ name: string; sent_count: number; unique_clicks: number; ctr: number | null }>`
    SELECT bc.name AS name, bc.sent_count AS sent_count,
      COUNT(DISTINCT bcl.user_id) AS unique_clicks,
      ROUND(COUNT(DISTINCT bcl.user_id) / NULLIF(bc.sent_count, 0) * 100, 1) AS ctr
    FROM broadcast_campaigns bc
    LEFT JOIN broadcast_clicks bcl ON bc.id = bcl.broadcast_id
    WHERE bc.sent_at BETWEEN ${range.start} AND ${range.end}
    ${acctFilterBc(lineAccountId)}
    GROUP BY bc.id ORDER BY bc.sent_at DESC LIMIT 10
  `.execute(db);

  const linksResult = await sql<{ title: string; click_count: number; unique_clicks: number }>`
    SELECT tl.title AS title, tl.click_count AS click_count, tl.unique_clicks AS unique_clicks
    FROM tracked_links tl
    WHERE tl.created_at BETWEEN ${range.start} AND ${range.end}
    ${acctFilterTl(lineAccountId)}
    ORDER BY tl.click_count DESC LIMIT 10
  `.execute(db);

  return { broadcasts: broadcastsResult.rows, links: linksResult.rows };
}

export interface DashboardStats {
  users: UserStats;
  messages: MessageStats;
  orders: OrderStats;
  revenue: RevenueStats;
  engagement: EngagementStats;
}

/** Ported from AnalyticsModel::getDashboardStats(). */
export async function getDashboardStats(db: Kysely<TenantDB>, lineAccountId: number | null, period: string): Promise<DashboardStats> {
  const range = getDateRange(period);
  const [users, messages, orders, revenue, engagement] = await Promise.all([
    getUserStats(db, lineAccountId, range),
    getMessageStats(db, lineAccountId, range),
    getOrderStats(db, lineAccountId, range),
    getRevenueStats(db, lineAccountId, range),
    getEngagementStats(db, lineAccountId, range),
  ]);
  return { users, messages, orders, revenue, engagement };
}

export interface RealTimeStats {
  activeUsers: number;
  messagesPerHour: number;
  ordersToday: number;
  revenueToday: number;
  timestamp: string;
}

/** Ported from AnalyticsModel::getRealTimeStats() (last 1 hour / today). */
export async function getRealTimeStats(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<RealTimeStats> {
  const activeResult = await sql<{ count: number }>`
    SELECT COUNT(DISTINCT user_id) AS count FROM messages
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const activeNow = Number(activeResult.rows[0]?.count ?? 0);

  const messagesResult = await sql<{ count: number }>`
    SELECT COUNT(*) AS count FROM messages
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const messagesNow = Number(messagesResult.rows[0]?.count ?? 0);

  const ordersResult = await sql<{ count: number; total: number | null }>`
    SELECT COUNT(*) AS count, SUM(grand_total) AS total FROM orders
    WHERE DATE(created_at) = CURDATE() ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const ordersToday = Number(ordersResult.rows[0]?.count ?? 0);
  const revenueToday = Math.round(Number(ordersResult.rows[0]?.total ?? 0) * 100) / 100;

  return {
    activeUsers: activeNow,
    messagesPerHour: messagesNow,
    ordersToday,
    revenueToday,
    timestamp: new Date().toISOString().slice(0, 19).replace('T', ' '),
  };
}

export interface FunnelStage {
  stage: string;
  count: number;
  rate: number;
}

/** Ported from AnalyticsModel::getCustomerFunnel(). Note: the "added to cart" stage is intentionally NOT scoped by lineAccountId — matches the PHP source exactly (cart_items query has no account filter at all). */
export async function getCustomerFunnel(db: Kysely<TenantDB>, lineAccountId: number | null, range: DateRange): Promise<FunnelStage[]> {
  const visitorsResult = await sql<{ count: number }>`
    SELECT COUNT(*) AS count FROM users WHERE created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const visitors = Number(visitorsResult.rows[0]?.count ?? 0);

  const engagedResult = await sql<{ count: number }>`
    SELECT COUNT(DISTINCT u.id) AS count FROM users u
    INNER JOIN messages m ON u.id = m.user_id AND m.direction = 'incoming'
    WHERE u.created_at BETWEEN ${range.start} AND ${range.end}
    ${lineAccountId ? sql`AND u.line_account_id = ${lineAccountId}` : sql``}
  `.execute(db);
  const engaged = Number(engagedResult.rows[0]?.count ?? 0);

  const cartResult = await sql<{ count: number }>`
    SELECT COUNT(DISTINCT user_id) AS count FROM cart_items WHERE created_at BETWEEN ${range.start} AND ${range.end}
  `.execute(db);
  const addedToCart = Number(cartResult.rows[0]?.count ?? 0);

  const purchasedResult = await sql<{ count: number }>`
    SELECT COUNT(DISTINCT user_id) AS count FROM orders WHERE created_at BETWEEN ${range.start} AND ${range.end} ${acctFilterUsers(lineAccountId)}
  `.execute(db);
  const purchased = Number(purchasedResult.rows[0]?.count ?? 0);

  const rate = (n: number) => (visitors > 0 ? Math.round((n / visitors) * 1000) / 10 : 0);

  return [
    { stage: 'ผู้ติดตามใหม่', count: visitors, rate: 100 },
    { stage: 'มีการสนทนา', count: engaged, rate: rate(engaged) },
    { stage: 'เพิ่มตะกร้า', count: addedToCart, rate: rate(addedToCart) },
    { stage: 'สั่งซื้อ', count: purchased, rate: rate(purchased) },
  ];
}
