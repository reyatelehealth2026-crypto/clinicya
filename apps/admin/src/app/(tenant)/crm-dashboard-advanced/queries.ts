import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — port of classes/CRMDashboardService.php's read methods that
 * are actually reachable from one of the 9 includes/dashboard/crm-advanced/
 * *.php section partials (or the crm-dashboard-advanced.php page shell's own
 * `<script>`, for `customer_360`). Methods with NO reachable caller in any of
 * the 9 partials — `campaign_stats`, `customer_timeline`, `customer_deals`,
 * `customer_tickets`, `segment_customers`, `quickSearch`,
 * `generateSalesReport`, `generateCustomerReport`,
 * `getSalesTeamAnalytics`/`analytics_sales_team`,
 * `getCustomerLifecycleAnalytics`/`analytics_customer_lifecycle` — are
 * intentionally NOT ported here (confirmed dead by grepping every `crmApi(`
 * call site across all 9 partials + the page shell; none of them invoke
 * these actions). reports.php itself makes ZERO `crmApi()` calls at all —
 * its "Generate Report" buttons are a pure client-side `setTimeout` fake,
 * calling neither `report_sales` nor `report_customers`.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * CRITICAL FINDING (per this batch's brief) + AUTHORIZED RESOLUTION
 * ══════════════════════════════════════════════════════════════════════════
 * `crm_deals`, `crm_tickets`, `crm_ticket_interactions` do not exist in
 * database/migration_2026-05-25_tenant_template.sql (zero CREATE TABLE
 * matches) or anywhere else in the committed schema/generated
 * packages/db/src/generated/tenant-db.d.ts. In real PHP,
 * crm-dashboard-advanced.php:28 calls `getExecutiveOverview()` UNGUARDED
 * before any HTML is emitted -> the page 500s on load on any tenant matching
 * the committed schema. This is a pre-existing PHP defect, out of this
 * batch's scope to fix at the schema layer (database/** is off-limits).
 *
 * AUTHORIZED RESOLUTION: every crm_deals/crm_tickets-touching read below is
 * wrapped in try/catch, falling back to documented empty defaults (0 deals,
 * ฿0 pipeline value, 0 tickets, empty alerts/activities/segments-with-deals-
 * or-tickets). This is a DELIBERATE, DOCUMENTED deviation from byte-parity
 * scoped ONLY to these three tables. Every other query below mirrors PHP's
 * real WHERE/JOIN/ORDER BY/LIMIT exactly.
 *
 * SAME TREATMENT for a fourth, separately-discovered column-level defect:
 * getRevenueAnalytics()'s SQL selects `created_at`/`amount_total` off
 * `odoo_webhooks_log`, neither of which exists on that table in the
 * committed template (real columns: `received_at`/`processed_at`, and the
 * generated `v_amount_total`) — also unguarded in real PHP, also a hard 500
 * on the committed schema. Wrapped in try/catch below with the same
 * empty-daily-series fallback shape; `summary` (an unconditional hardcoded
 * placeholder in PHP already) is untouched by the fallback.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * lineAccountId scoping — a SECOND, separate finding
 * ══════════════════════════════════════════════════════════════════════════
 * crm-dashboard-advanced.php:20 reads `$currentBotId = $_SESSION['line_account_id']
 * ?? null` — NOT `$_SESSION['current_bot_id']`, the session key every other
 * ported Phase 2 page uses. Grepping the ENTIRE repo for
 * `$_SESSION['line_account_id'] =` (an assignment to that exact top-level
 * key) returns ZERO hits — no PHP file ever sets it (the one near-miss,
 * auth/setup-account.php:75, sets the nested `$_SESSION['admin_user']
 * ['line_account_id']`, a different variable). This session key is dead:
 * `$currentBotId` is ALWAYS `null` on this page in production. Separately,
 * api/crm-dashboard-api.php:48 constructs `new CRMDashboardService($db)`
 * with NO second argument at all -> `$this->lineAccountId` is ALSO always
 * `null` for every AJAX-driven tab. Both code paths that ever construct this
 * service on this page therefore always pass `null` — a real, consistent
 * (if surely unintended) production behavior: this page never actually
 * scopes any query to the admin's current LINE OA, it always aggregates
 * across every LINE account in the tenant DB.
 *
 * Every function below still takes an explicit `lineAccountId: number | null`
 * parameter (for testability/documentation, matching the established
 * convention in apps/admin/src/app/(tenant)/dashboard/_lib/crmData.ts and
 * .../analytics/_lib/crmQueries.ts) — but page.tsx always calls with `null`,
 * mirroring the real, confirmed PHP behavior, NOT `session.currentBotId`.
 * Flagged in the build report as worth a product decision (same as
 * crmData.ts's own flagged executive/crm asymmetry), not silently
 * "corrected" to session-scoped in either direction.
 *
 * Two DIFFERENT null-handling shapes appear in the PHP source's WHERE
 * clauses and are reproduced literally, verbatim per query (do NOT
 * normalise them to a single pattern):
 *   - "double-bind" `(line_account_id = ? OR ? IS NULL)` bound to the SAME
 *     value twice -> when lineAccountId is null, ALWAYS TRUE (matches every
 *     row regardless of line_account_id). Used by: total_customers,
 *     getCustomers, getSegmentCount('new'|'inactive'),
 *     getCustomerLifecycleAnalytics (not ported, but noted for completeness).
 *   - "single-bind" `(line_account_id = ? OR line_account_id IS NULL)` bound
 *     ONCE -> when lineAccountId is null, `col = NULL` is never true, so
 *     this reduces to `line_account_id IS NULL` (matches ONLY rows with an
 *     actual NULL line_account_id — the OPPOSITE of "all rows"). Used by:
 *     active_campaigns (inside getExecutiveMetrics), getCampaigns.
 */

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function logQueryFailure(queryName: string, error: unknown): void {
  // eslint-disable-next-line no-console
  console.error(`[crm-dashboard-advanced] query '${queryName}' failed`, error);
}

function toNum(v: unknown): number {
  const n = Number(v ?? 0);
  return Number.isFinite(n) ? n : 0;
}

// ---------------------------------------------------------------------------
// Executive Overview — getExecutiveOverview() / getExecutiveMetrics()
// ---------------------------------------------------------------------------

export interface MetricWithChange {
  value: number;
  change: number;
}

export interface ExecutiveMetrics {
  totalCustomers: MetricWithChange;
  activeDeals: { value: number; pipelineValue: number; change: number };
  monthlyRevenue: MetricWithChange;
  openTickets: { value: number; urgent: number };
  conversionRate: { value: number; change: number };
  avgDealSize: { value: number; change: number };
  activeCampaigns: { value: number; change: number };
  satisfaction: { value: number; max: number; change: number };
}

export interface AlertRow {
  type: 'danger' | 'warning' | 'info';
  message: string;
  link: string;
}

export interface ActivityRow {
  type: 'deal' | 'ticket';
  created_at: string | Date;
  customer_name: string | null;
  title: string;
  value: number | null;
  stage: string;
}

export interface ExecutiveOverview {
  metrics: ExecutiveMetrics;
  alerts: AlertRow[];
  activities: ActivityRow[];
  charts: { revenueTrend: number[]; pipelineDistribution: number[] };
}

/** Ported from CRMDashboardService::getExecutiveMetrics() (private, inlined into getExecutiveOverview's shape here). */
async function getExecutiveMetrics(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<ExecutiveMetrics> {
  const totalCustomersResult = await sql<{ count: number | string | null }>`
    SELECT COUNT(*) as count FROM users WHERE (line_account_id = ${lineAccountId} OR ${lineAccountId} IS NULL) AND is_blocked = 0
  `.execute(db);
  const totalCustomers = toNum(totalCustomersResult.rows[0]?.count);

  let activeDealsCount = 0;
  let pipelineValue = 0;
  try {
    const result = await sql<{ deal_count: number | string | null; pipeline_value: number | string | null }>`
      SELECT COUNT(*) as deal_count, COALESCE(SUM(value), 0) as pipeline_value
      FROM crm_deals WHERE stage NOT IN ('closed_won', 'closed_lost')
    `.execute(db);
    activeDealsCount = toNum(result.rows[0]?.deal_count);
    pipelineValue = toNum(result.rows[0]?.pipeline_value);
  } catch (error) {
    logQueryFailure('executiveMetrics.activeDeals', error);
  }

  let openTicketsCount = 0;
  try {
    const result = await sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM crm_tickets WHERE status IN ('open', 'pending')
    `.execute(db);
    openTicketsCount = toNum(result.rows[0]?.count);
  } catch (error) {
    logQueryFailure('executiveMetrics.openTickets', error);
  }

  let avgDealSize = 0;
  try {
    const result = await sql<{ avg: number | string | null }>`
      SELECT AVG(value) as avg FROM crm_deals WHERE stage = 'closed_won' AND closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    `.execute(db);
    avgDealSize = Math.round(toNum(result.rows[0]?.avg) * 100) / 100;
  } catch (error) {
    logQueryFailure('executiveMetrics.avgDealSize', error);
  }

  // active_campaigns: single-bind pattern, see this file's module doc.
  const campaignsResult = await sql<{ count: number | string | null }>`
    SELECT COUNT(*) as count FROM drip_campaigns WHERE is_active = 1 AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
  `.execute(db);
  const activeCampaigns = toNum(campaignsResult.rows[0]?.count);

  return {
    totalCustomers: { value: totalCustomers, change: 5.2 }, // getCustomerGrowth() placeholder
    activeDeals: { value: activeDealsCount, pipelineValue, change: 12.5 }, // getDealsGrowth() placeholder
    monthlyRevenue: { value: 125000, change: 8.3 }, // getCurrentMonthRevenue()/getRevenueGrowth() placeholders
    openTickets: { value: openTicketsCount, urgent: 3 }, // getUrgentTicketsCount() placeholder
    conversionRate: { value: 24.5, change: 0 }, // calculateConversionRate() placeholder
    avgDealSize: { value: avgDealSize, change: 0 },
    activeCampaigns: { value: activeCampaigns, change: 0 },
    satisfaction: { value: 4.5, max: 5, change: 0.2 }, // placeholder
  };
}

/** Ported from CRMDashboardService::getActiveAlerts() (private). Both queries touch crm_tickets/crm_deals -> defensive empty array. */
async function getActiveAlerts(db: Kysely<TenantDB>): Promise<AlertRow[]> {
  const alerts: AlertRow[] = [];

  try {
    const result = await sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM crm_tickets WHERE status IN ('open', 'pending') AND sla_deadline < NOW()
    `.execute(db);
    const breached = toNum(result.rows[0]?.count);
    if (breached > 0) {
      alerts.push({ type: 'danger', message: `${breached} ticket(s) have breached SLA`, link: '#tickets' });
    }
  } catch (error) {
    logQueryFailure('activeAlerts.slaBreach', error);
  }

  try {
    const result = await sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM crm_deals WHERE stage = 'lead' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    `.execute(db);
    const newLeads = toNum(result.rows[0]?.count);
    if (newLeads > 0) {
      alerts.push({ type: 'info', message: `${newLeads} new lead(s) today`, link: '#pipeline' });
    }
  } catch (error) {
    logQueryFailure('activeAlerts.newLeads', error);
  }

  return alerts;
}

/**
 * Ported from CRMDashboardService::getRecentActivities($limit) — reachable
 * both via getExecutiveOverview() and directly (executive-overview.php's own
 * separate `crmApi('activities', {limit:10})` call). Both underlying queries
 * touch crm_deals/crm_tickets -> defensive empty array on either failing.
 */
export async function getRecentActivities(db: Kysely<TenantDB>, limit: number): Promise<ActivityRow[]> {
  let dealActivities: ActivityRow[] = [];
  try {
    const result = await sql<{
      type: 'deal';
      created_at: Date | string;
      customer_name: string | null;
      title: string;
      value: number | string | null;
      stage: string;
    }>`
      SELECT 'deal' as type, d.created_at, u.display_name as customer_name, d.title, d.value, d.stage
      FROM crm_deals d LEFT JOIN users u ON d.customer_id = u.id
      ORDER BY d.created_at DESC LIMIT ${limit}
    `.execute(db);
    dealActivities = result.rows.map((r) => ({ ...r, value: toNum(r.value) }));
  } catch (error) {
    logQueryFailure('recentActivities.deals', error);
  }

  let ticketActivities: ActivityRow[] = [];
  try {
    const result = await sql<{
      type: 'ticket';
      created_at: Date | string;
      customer_name: string | null;
      title: string;
      stage: string;
    }>`
      SELECT 'ticket' as type, t.created_at, u.display_name as customer_name, t.subject as title, t.status as stage
      FROM crm_tickets t LEFT JOIN users u ON t.customer_id = u.id
      ORDER BY t.created_at DESC LIMIT ${limit}
    `.execute(db);
    ticketActivities = result.rows.map((r) => ({ ...r, value: null }));
  } catch (error) {
    logQueryFailure('recentActivities.tickets', error);
  }

  const merged = [...dealActivities, ...ticketActivities];
  merged.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
  return merged.slice(0, limit);
}

/** getRevenueTrend($days) — hardcoded placeholder, portable regardless of $days (CRMDashboardService.php's own comment: "Placeholder methods"). */
function getRevenueTrendPlaceholder(): number[] {
  return [100, 120, 115, 140, 135, 160, 155];
}

/** getPipelineDistribution() — hardcoded placeholder. */
function getPipelineDistributionPlaceholder(): number[] {
  return [10, 8, 5, 3, 12, 7];
}

/** Ported from CRMDashboardService::getExecutiveOverview(). */
export async function getExecutiveOverview(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<ExecutiveOverview> {
  const [metrics, alerts, activities] = await Promise.all([
    getExecutiveMetrics(db, lineAccountId),
    getActiveAlerts(db),
    getRecentActivities(db, 10),
  ]);

  return {
    metrics,
    alerts,
    activities,
    charts: { revenueTrend: getRevenueTrendPlaceholder(), pipelineDistribution: getPipelineDistributionPlaceholder() },
  };
}

// ---------------------------------------------------------------------------
// Deals list — getDealsList() is an unconditional stub in real PHP
// ---------------------------------------------------------------------------

export interface DealsListResult {
  deals: unknown[];
  total: number;
}

/**
 * Ported from CRMDashboardService::getDealsList($filters) — an unimplemented
 * stub that ALWAYS returns `{deals: [], total: 0}` regardless of filters, in
 * real PHP TODAY. Used by executive-overview.php's "Recent Deals" table
 * (limit 5) and deals-list.php's "All Deals" tab — BOTH are always empty in
 * production right now. Mirrored literally: no query, no filters, no db arg.
 */
export function getDealsList(): DealsListResult {
  return { deals: [], total: 0 };
}

// ---------------------------------------------------------------------------
// Revenue analytics — getRevenueAnalytics($period)
// ---------------------------------------------------------------------------

export interface RevenueDailyRow {
  date: string;
  order_count: number;
  revenue: number;
}

export interface RevenueAnalytics {
  period: string;
  daily: RevenueDailyRow[];
  summary: { total: number; avg: number };
}

/**
 * Ported from CRMDashboardService::getRevenueAnalytics($period). Real PHP's
 * SQL (`SELECT DATE(created_at) ..., SUM(amount_total) ... FROM
 * odoo_webhooks_log WHERE ... created_at >= ...`) references `created_at`
 * and `amount_total` columns that do NOT exist on
 * database/migration_2026-05-25_tenant_template.sql's `odoo_webhooks_log`
 * (real columns are `received_at`/`processed_at` and the generated
 * `v_amount_total`) — same class of pre-existing PHP defect as the
 * crm_deals/crm_tickets finding documented at the top of this file, just
 * against a different table. Real PHP has NO try/catch here either, so on
 * the committed schema this 500s on load exactly like the crm_deals case.
 * AUTHORIZED RESOLUTION (same shape as crm_deals/crm_tickets above): wrap in
 * try/catch, falling back to an empty daily series. `summary` is untouched —
 * it's already the hardcoded getRevenueSummary($days) placeholder regardless
 * of query success.
 */
export async function getRevenueAnalytics(db: Kysely<TenantDB>, period: string): Promise<RevenueAnalytics> {
  const days = Number.parseInt(period.replace('d', ''), 10) || 0;

  let daily: RevenueDailyRow[] = [];
  try {
    const result = await sql<{ date: string; order_count: number | string | null; revenue: number | string | null }>`
      SELECT DATE(created_at) as date, COUNT(*) as order_count, SUM(amount_total) as revenue
      FROM odoo_webhooks_log
      WHERE event_type LIKE 'sale.order%' AND created_at >= DATE_SUB(NOW(), INTERVAL ${days} DAY)
      GROUP BY DATE(created_at) ORDER BY date
    `.execute(db);
    daily = result.rows.map((r) => ({ date: r.date, order_count: toNum(r.order_count), revenue: toNum(r.revenue) }));
  } catch (error) {
    logQueryFailure('getRevenueAnalytics.daily', error);
  }

  return {
    period,
    daily,
    summary: { total: 125000, avg: 17857 }, // getRevenueSummary($days) placeholder — ignores $days, matches PHP exactly
  };
}

// ---------------------------------------------------------------------------
// Sales Pipeline — getPipelineData()
// ---------------------------------------------------------------------------

export interface DealRow {
  id: number;
  customer_id: number;
  title: string;
  description: string | null;
  value: number;
  stage: string;
  probability: number;
  expected_close: string | null;
  assigned_to: number | null;
  source: string | null;
  created_at: string | Date;
  updated_at: string | Date;
  closed_at: string | Date | null;
  customer_name: string | null;
  customer_avatar: string | null;
}

export interface PipelineStage {
  id: string;
  name: string;
  count: number;
  value: number;
  deals: DealRow[];
}

export interface PipelineData {
  stages: PipelineStage[];
  totalValue: number;
  totalDeals: number;
  winRate: number;
}

const STAGE_ORDER = ['lead', 'qualified', 'proposal', 'negotiation', 'closed_won', 'closed_lost'] as const;
const STAGE_LABELS: Record<(typeof STAGE_ORDER)[number], string> = {
  lead: 'New Leads',
  qualified: 'Qualified',
  proposal: 'Proposal',
  negotiation: 'Negotiation',
  closed_won: 'Closed Won',
  closed_lost: 'Closed Lost',
};

/**
 * Ported from CRMDashboardService::getPipelineData(). Real PHP has NO
 * try/catch around the per-stage loop at all — the AUTHORIZED RESOLUTION
 * adds one here (not present in PHP) so a missing crm_deals table degrades
 * to "0 deals in every stage" instead of a 500, per this batch's brief.
 * win_rate (calculateWinRate() = 35.0, a hardcoded placeholder) is NOT
 * touched by the table-missing fallback — it's returned regardless.
 */
export async function getPipelineData(db: Kysely<TenantDB>): Promise<PipelineData> {
  const stages: PipelineStage[] = [];

  try {
    for (const stage of STAGE_ORDER) {
      const result = await sql<DealRow>`
        SELECT d.*, u.display_name as customer_name, u.picture_url as customer_avatar
        FROM crm_deals d LEFT JOIN users u ON d.customer_id = u.id
        WHERE d.stage = ${stage} ORDER BY d.updated_at DESC LIMIT 50
      `.execute(db);
      const deals = result.rows.map((r) => ({ ...r, value: toNum(r.value) }));
      const stageValue = deals.reduce((sum, d) => sum + d.value, 0);
      stages.push({ id: stage, name: STAGE_LABELS[stage], count: deals.length, value: stageValue, deals });
    }
  } catch (error) {
    logQueryFailure('pipelineData', error);
    stages.length = 0;
    for (const stage of STAGE_ORDER) {
      stages.push({ id: stage, name: STAGE_LABELS[stage], count: 0, value: 0, deals: [] });
    }
  }

  return {
    stages,
    totalValue: stages.reduce((sum, s) => sum + s.value, 0),
    totalDeals: stages.reduce((sum, s) => sum + s.count, 0),
    winRate: 35.0, // calculateWinRate() placeholder
  };
}

// ---------------------------------------------------------------------------
// Service Center — getTickets() / getTicketStats()
// ---------------------------------------------------------------------------

export interface TicketRow {
  id: number;
  customer_id: number;
  subject: string;
  description: string | null;
  status: string;
  priority: string;
  category: string | null;
  assigned_to: number | null;
  sla_deadline: string | Date | null;
  created_at: string | Date;
  resolved_at: string | Date | null;
  customer_name: string | null;
  customer_avatar: string | null;
  line_user_id: string | null;
}

export interface TicketsFilters {
  status?: string | null;
  priority?: string | null;
  assignedTo?: number | null;
  limit?: number;
  offset?: number;
}

export interface TicketsResult {
  tickets: TicketRow[];
  total: number;
  limit: number;
  offset: number;
}

/** Ported from CRMDashboardService::getTickets($filters). Touches crm_tickets only -> defensive empty result. */
export async function getTickets(db: Kysely<TenantDB>, filters: TicketsFilters = {}): Promise<TicketsResult> {
  const limit = filters.limit ?? 50;
  const offset = filters.offset ?? 0;

  try {
    const query = sql<TicketRow>`
      SELECT t.*, u.display_name as customer_name, u.picture_url as customer_avatar, u.line_user_id
      FROM crm_tickets t LEFT JOIN users u ON t.customer_id = u.id
      WHERE 1=1
      ${filters.status ? sql`AND t.status = ${filters.status}` : sql``}
      ${filters.priority ? sql`AND t.priority = ${filters.priority}` : sql``}
      ${filters.assignedTo ? sql`AND t.assigned_to = ${filters.assignedTo}` : sql``}
      ORDER BY CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END, t.created_at DESC
      LIMIT ${limit} OFFSET ${offset}
    `;
    const rows = (await query.execute(db)).rows;

    const countQuery = sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM crm_tickets t WHERE 1=1
      ${filters.status ? sql`AND t.status = ${filters.status}` : sql``}
      ${filters.priority ? sql`AND t.priority = ${filters.priority}` : sql``}
      ${filters.assignedTo ? sql`AND t.assigned_to = ${filters.assignedTo}` : sql``}
    `;
    const total = toNum((await countQuery.execute(db)).rows[0]?.count);

    return { tickets: rows, total, limit, offset };
  } catch (error) {
    logQueryFailure('getTickets', error);
    return { tickets: [], total: 0, limit, offset };
  }
}

export interface TicketStats {
  byStatus: Record<string, number>;
  byPriority: Record<string, number>;
  approachingSla: number;
  breachedSla: number;
}

/** Ported from CRMDashboardService::getTicketStats(). Touches crm_tickets only -> defensive empty stats. */
export async function getTicketStats(db: Kysely<TenantDB>): Promise<TicketStats> {
  try {
    const byStatusResult = await sql<{ status: string; count: number | string }>`
      SELECT status, COUNT(*) as count FROM crm_tickets GROUP BY status
    `.execute(db);
    const byPriorityResult = await sql<{ priority: string; count: number | string }>`
      SELECT priority, COUNT(*) as count FROM crm_tickets GROUP BY priority
    `.execute(db);
    const approachingResult = await sql<{ approaching_sla: number | string | null }>`
      SELECT COUNT(*) as approaching_sla FROM crm_tickets
      WHERE status IN ('open', 'pending') AND sla_deadline <= DATE_ADD(NOW(), INTERVAL 4 HOUR) AND sla_deadline > NOW()
    `.execute(db);
    const breachedResult = await sql<{ breached_sla: number | string | null }>`
      SELECT COUNT(*) as breached_sla FROM crm_tickets WHERE status IN ('open', 'pending') AND sla_deadline < NOW()
    `.execute(db);

    return {
      byStatus: Object.fromEntries(byStatusResult.rows.map((r) => [r.status, toNum(r.count)])),
      byPriority: Object.fromEntries(byPriorityResult.rows.map((r) => [r.priority, toNum(r.count)])),
      approachingSla: toNum(approachingResult.rows[0]?.approaching_sla),
      breachedSla: toNum(breachedResult.rows[0]?.breached_sla),
    };
  } catch (error) {
    logQueryFailure('getTicketStats', error);
    return { byStatus: {}, byPriority: {}, approachingSla: 0, breachedSla: 0 };
  }
}

// ---------------------------------------------------------------------------
// Customers — getCustomers()
// ---------------------------------------------------------------------------

export interface CustomerRow {
  id: number;
  line_user_id: string;
  display_name: string | null;
  picture_url: string | null;
  created_at: string | Date;
  last_message_at: string | Date | null;
  tags: string | null;
  deals_count: number;
  tickets_count: number;
}

export interface CustomersFilters {
  search?: string;
  tagId?: number | null;
  limit?: number;
  offset?: number;
}

export interface CustomersResult {
  customers: CustomerRow[];
  total: number;
  limit: number;
  offset: number;
}

/**
 * Ported from CRMDashboardService::getCustomers($filters) — reachable both
 * via the Customers tab AND the Add Deal/Create Ticket modals' customer
 * dropdown (`crmApi('customers', {limit:100})`). The row-listing query LEFT
 * JOINs crm_deals/crm_tickets inline (for deals_count/tickets_count) — on a
 * tenant DB matching the committed template this throws. AUTHORIZED
 * RESOLUTION: falls back to a variant query that drops those two LEFT JOINs
 * (deals_count/tickets_count hardcoded 0), so the page's real, portable data
 * (users/tags) still renders instead of losing the whole tab. The COUNT
 * query never touched crm_deals/crm_tickets in the first place (no defensive
 * wrap needed there).
 */
export async function getCustomers(db: Kysely<TenantDB>, lineAccountId: number | null, filters: CustomersFilters = {}): Promise<CustomersResult> {
  const limit = filters.limit ?? 50;
  const offset = filters.offset ?? 0;
  const search = filters.search?.trim();
  const like = search ? `%${search}%` : null;

  const searchClause = like ? sql`AND (u.display_name LIKE ${like} OR u.line_user_id LIKE ${like})` : sql``;
  const tagClause = filters.tagId
    ? sql`AND EXISTS (SELECT 1 FROM user_tag_assignments a2 WHERE a2.user_id = u.id AND a2.tag_id = ${filters.tagId})`
    : sql``;

  let rows: CustomerRow[];
  try {
    const result = await sql<CustomerRow>`
      SELECT u.id, u.line_user_id, u.display_name, u.picture_url, u.created_at, u.last_message_at,
        GROUP_CONCAT(DISTINCT t.name) as tags,
        COUNT(DISTINCT d.id) as deals_count,
        COUNT(DISTINCT tk.id) as tickets_count
      FROM users u
      LEFT JOIN user_tag_assignments a ON u.id = a.user_id
      LEFT JOIN user_tags t ON a.tag_id = t.id
      LEFT JOIN crm_deals d ON u.id = d.customer_id AND d.stage NOT IN ('closed_won', 'closed_lost')
      LEFT JOIN crm_tickets tk ON u.id = tk.customer_id AND tk.status IN ('open', 'pending')
      WHERE (u.line_account_id = ${lineAccountId} OR ${lineAccountId} IS NULL) AND u.is_blocked = 0
      ${searchClause} ${tagClause}
      GROUP BY u.id ORDER BY u.created_at DESC LIMIT ${limit} OFFSET ${offset}
    `.execute(db);
    rows = result.rows.map((r) => ({ ...r, deals_count: toNum(r.deals_count), tickets_count: toNum(r.tickets_count) }));
  } catch (error) {
    logQueryFailure('getCustomers.rows (falling back to no crm_deals/crm_tickets joins)', error);
    const fallback = await sql<Omit<CustomerRow, 'deals_count' | 'tickets_count'>>`
      SELECT u.id, u.line_user_id, u.display_name, u.picture_url, u.created_at, u.last_message_at,
        GROUP_CONCAT(DISTINCT t.name) as tags
      FROM users u
      LEFT JOIN user_tag_assignments a ON u.id = a.user_id
      LEFT JOIN user_tags t ON a.tag_id = t.id
      WHERE (u.line_account_id = ${lineAccountId} OR ${lineAccountId} IS NULL) AND u.is_blocked = 0
      ${searchClause} ${tagClause}
      GROUP BY u.id ORDER BY u.created_at DESC LIMIT ${limit} OFFSET ${offset}
    `.execute(db);
    rows = fallback.rows.map((r) => ({ ...r, deals_count: 0, tickets_count: 0 }));
  }

  const countResult = await sql<{ count: number | string | null }>`
    SELECT COUNT(DISTINCT u.id) as count FROM users u
    WHERE (u.line_account_id = ${lineAccountId} OR ${lineAccountId} IS NULL) AND u.is_blocked = 0
    ${searchClause} ${tagClause}
  `.execute(db);
  const total = toNum(countResult.rows[0]?.count);

  return { customers: rows, total, limit, offset };
}

// ---------------------------------------------------------------------------
// Marketing Hub — getCampaigns() / getSegments()
// ---------------------------------------------------------------------------

export interface CampaignRow {
  id: number;
  name: string;
  is_active: number;
  step_count: number;
  active_users: number;
  completed_users: number;
}

export interface CampaignsFilters {
  status?: 'active' | 'inactive' | null;
  limit?: number;
}

/** Ported from CRMDashboardService::getCampaigns($filters). Single-bind lineAccountId pattern — see module doc. Real, portable tables only. */
export async function getCampaigns(db: Kysely<TenantDB>, lineAccountId: number | null, filters: CampaignsFilters = {}): Promise<CampaignRow[]> {
  const limit = filters.limit ?? 20;
  const statusClause = filters.status ? sql`AND c.is_active = ${filters.status === 'active' ? 1 : 0}` : sql``;

  const result = await sql<CampaignRow>`
    SELECT c.id, c.name, c.is_active,
      (SELECT COUNT(*) FROM drip_campaign_steps WHERE campaign_id = c.id) as step_count,
      (SELECT COUNT(*) FROM drip_campaign_progress WHERE campaign_id = c.id AND status = 'active') as active_users,
      (SELECT COUNT(*) FROM drip_campaign_progress WHERE campaign_id = c.id AND status = 'completed') as completed_users
    FROM drip_campaigns c
    WHERE (c.line_account_id = ${lineAccountId} OR c.line_account_id IS NULL)
    ${statusClause}
    ORDER BY c.created_at DESC LIMIT ${limit}
  `.execute(db);

  return result.rows.map((r) => ({
    ...r,
    is_active: toNum(r.is_active),
    step_count: toNum(r.step_count),
    active_users: toNum(r.active_users),
    completed_users: toNum(r.completed_users),
  }));
}

export interface SegmentRow {
  id: string;
  name: string;
  description: string;
  count: number;
}

/** Ported from CRMDashboardService::getSegmentCount($segmentId) (private). */
async function getSegmentCount(db: Kysely<TenantDB>, lineAccountId: number | null, segmentId: string): Promise<number> {
  switch (segmentId) {
    case 'new': {
      const result = await sql<{ count: number | string | null }>`
        SELECT COUNT(*) as count FROM users
        WHERE (line_account_id = ${lineAccountId} OR ${lineAccountId} IS NULL) AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      `.execute(db);
      return toNum(result.rows[0]?.count);
    }
    case 'inactive': {
      const result = await sql<{ count: number | string | null }>`
        SELECT COUNT(*) as count FROM users u
        WHERE (u.line_account_id = ${lineAccountId} OR ${lineAccountId} IS NULL)
        AND NOT EXISTS (SELECT 1 FROM user_behaviors b WHERE b.user_id = u.id AND b.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY))
      `.execute(db);
      return toNum(result.rows[0]?.count);
    }
    case 'has_deals': {
      try {
        const result = await sql<{ count: number | string | null }>`
          SELECT COUNT(DISTINCT customer_id) as count FROM crm_deals WHERE stage NOT IN ('closed_won', 'closed_lost')
        `.execute(db);
        return toNum(result.rows[0]?.count);
      } catch (error) {
        logQueryFailure('segmentCount.has_deals', error);
        return 0;
      }
    }
    case 'has_tickets': {
      try {
        const result = await sql<{ count: number | string | null }>`
          SELECT COUNT(DISTINCT customer_id) as count FROM crm_tickets WHERE status IN ('open', 'pending')
        `.execute(db);
        return toNum(result.rows[0]?.count);
      } catch (error) {
        logQueryFailure('segmentCount.has_tickets', error);
        return 0;
      }
    }
    default:
      // 'vip' (and anything else) hits PHP's unhandled `default: return 0;` — always 0, no query.
      return 0;
  }
}

/** Ported from CRMDashboardService::getSegments(). */
export async function getSegments(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<SegmentRow[]> {
  const definitions: { id: string; name: string; description: string }[] = [
    { id: 'vip', name: 'VIP Customers', description: 'High value customers' },
    { id: 'new', name: 'New Customers', description: 'Joined in last 30 days' },
    { id: 'inactive', name: 'Inactive Users', description: 'No activity in 30 days' },
    { id: 'has_deals', name: 'Active Prospects', description: 'Have open deals' },
    { id: 'has_tickets', name: 'Support Active', description: 'Have open tickets' },
  ];

  return Promise.all(
    definitions.map(async (def) => ({ ...def, count: await getSegmentCount(db, lineAccountId, def.id) }))
  );
}

// ---------------------------------------------------------------------------
// Customer 360 — getCustomer360() (reachable via customers-list.php's row
// action -> the page shell's openCustomer360() -> `crmApi('customer_360')`)
// ---------------------------------------------------------------------------

export interface TagDetail {
  id: number;
  name: string;
  color: string | null;
}

export interface Customer360 {
  id: number;
  line_user_id: string;
  display_name: string | null;
  picture_url: string | null;
  phone: string | null;
  email: string | null;
  tags: TagDetail[];
  orders_count: number;
  total_spent: number;
  deals_count: number;
  tickets_count: number;
}

/**
 * Ported from CRMDashboardService::getCustomer360($customerId). The 4 count/
 * spend stats are ALL hardcoded placeholder constants in real PHP
 * (getCustomerOrdersCount=5, getCustomerTotalSpent=25000,
 * getCustomerDealsCount=2, getCustomerTicketsCount=1) — despite the
 * misleading names, NONE of them touch crm_deals/crm_tickets, so no
 * defensive wrap is needed for them.
 */
export async function getCustomer360(db: Kysely<TenantDB>, customerId: number): Promise<Customer360 | null> {
  const userResult = await sql<{
    id: number;
    line_user_id: string;
    display_name: string | null;
    picture_url: string | null;
    phone: string | null;
    email: string | null;
  }>`
    SELECT id, line_user_id, display_name, picture_url, phone, email FROM users WHERE id = ${customerId}
  `.execute(db);
  const customer = userResult.rows[0];
  if (!customer) {
    return null;
  }

  const tagsResult = await sql<TagDetail>`
    SELECT t.id, t.name, t.color FROM user_tags t
    JOIN user_tag_assignments a ON t.id = a.tag_id
    WHERE a.user_id = ${customerId}
  `.execute(db);

  return {
    ...customer,
    tags: tagsResult.rows,
    orders_count: 5,
    total_spent: 25000,
    deals_count: 2,
    tickets_count: 1,
  };
}
