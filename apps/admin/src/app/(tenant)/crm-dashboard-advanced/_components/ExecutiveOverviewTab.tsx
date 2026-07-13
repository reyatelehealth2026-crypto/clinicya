import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { EmptyState } from '@/components/EmptyState';
import { getExecutiveOverview, getRevenueAnalytics, getPipelineData, getCustomers, getDealsList } from '../queries';
import { formatMoney, formatCount } from '../_lib/format';
import { Sparkline, MiniLineChart, MiniDonut, STAGE_CHART_COLORS } from './MiniChart';
import { AddDealModal, type DealCustomerOption } from './AddDealModal';
import { CreateTicketModal } from './CreateTicketModal';

/**
 * ExecutiveOverviewTab.tsx — Server Component port of
 * executive-overview.php's `loadExecutiveOverview()` (fires 5
 * `crmApi(...)` calls on tab-show: overview, deals[limit:5], activities[limit:10],
 * analytics_revenue[period:30d], pipeline) — all resolved server-side here
 * instead, same "server-render into a client island's initial state"
 * pattern used by /analytics's advanced/crm tabs.
 *
 * Two PHP quirks reproduced literally (confirmed by reading the full
 * `renderOverview()` client-side renderer):
 *   - The "Total Customers" sparkline is fed `data.charts.pipeline_distribution`
 *     (the getPipelineDistribution() PLACEHOLDER, [10,8,5,3,12,7]) — NOT
 *     customer-growth data. Genuinely mismatched in the PHP source; mirrored
 *     as-is, not "fixed" to something more sensible.
 *   - `#metric-sla-breach` is hardcoded to the literal string `'0'`
 *     (`// Would come from ticket stats` — a TODO comment left in, never
 *     wired up) — mirrored as a literal 0, not computed from getTicketStats().
 *   - CSAT stars are 4-filled/1-empty in the static HTML and NEVER updated by
 *     any JS regardless of the real satisfaction.value — mirrored as static.
 *   - "Top Performers" (`renderTopPerformers()`) ignores its own
 *     `pipelineData` argument entirely and renders 2 hardcoded mock rows —
 *     mirrored as a literal static block, not derived from any query.
 *   - The "Revenue by Source" chart card (`<canvas id="sourceChart">`) has
 *     NO render function anywhere in the PHP source — the canvas is declared
 *     but never populated, i.e. permanently blank in production. Rendered
 *     here as an honest "not implemented" placeholder rather than a blank
 *     canvas or fabricated data.
 *
 * Odoo kill-switch: the PHP source's "System Status" card unconditionally
 * shows "Odoo Sync: Active" only `<?php if (defined('ODOO_INTEGRATION_ENABLED')
 * && ODOO_INTEGRATION_ENABLED === true): ?>`. This batch's allowed paths have
 * no access to that flag/settings source, so — per the kill-switch principle
 * ("non-Odoo tenants must never see Odoo widgets") — this line is simply
 * omitted (the safe default), rather than guessed at. Flagged in the build
 * report.
 */
export interface ExecutiveOverviewTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
  period: string;
}

const MOCK_TOP_PERFORMERS = [
  { name: 'Sales Team', metric: '12 deals', revenue: 450000, rate: '35% win' },
  { name: 'Marketing', metric: '5 campaigns · 150 leads', revenue: null, rate: '12% conversion' },
];

export async function ExecutiveOverviewTab({ db, lineAccountId, period }: ExecutiveOverviewTabProps) {
  const [overview, revenue, pipeline, customersForModals] = await Promise.all([
    getExecutiveOverview(db, lineAccountId),
    getRevenueAnalytics(db, period),
    getPipelineData(db),
    getCustomers(db, lineAccountId, { limit: 100 }),
  ]);
  const { metrics, alerts } = overview;
  const recentDeals = getDealsList(); // always [] — see queries.ts's module doc

  const customerOptions: DealCustomerOption[] = customersForModals.customers.map((c) => ({ id: c.id, display_name: c.display_name, line_user_id: c.line_user_id }));

  return (
    <div>
      {/* Metric cards row 1 */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] font-medium uppercase tracking-wide text-gray-500 mb-1">Total Customers</div>
          <div className="flex items-end justify-between">
            <div className="font-mono text-xl font-semibold">{formatCount(metrics.totalCustomers.value)}</div>
            <div className={`text-[11px] font-mono ${metrics.totalCustomers.change >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>
              {metrics.totalCustomers.change >= 0 ? '+' : ''}
              {metrics.totalCustomers.change}%
            </div>
          </div>
          <div className="mt-2">
            <Sparkline values={overview.charts.pipelineDistribution} color="#3b82f6" />
          </div>
        </div>

        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] font-medium uppercase tracking-wide text-gray-500 mb-1">Pipeline Value</div>
          <div className="flex items-end justify-between">
            <div className="font-mono text-xl font-semibold">฿{formatMoney(metrics.activeDeals.pipelineValue)}</div>
            <div className="text-[11px] font-mono text-emerald-600">+{metrics.activeDeals.change}%</div>
          </div>
          <div className="text-xs text-gray-500 mt-1">{metrics.activeDeals.value} active deals</div>
        </div>

        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] font-medium uppercase tracking-wide text-gray-500 mb-1">Monthly Revenue</div>
          <div className="flex items-end justify-between">
            <div className="font-mono text-xl font-semibold">฿{formatMoney(metrics.monthlyRevenue.value)}</div>
            <div className="text-[11px] font-mono text-emerald-600">+{metrics.monthlyRevenue.change}%</div>
          </div>
          <div className="mt-2">
            <Sparkline values={overview.charts.revenueTrend} color="#10b981" />
          </div>
        </div>

        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] font-medium uppercase tracking-wide text-gray-500 mb-1">Open Tickets</div>
          <div className="flex items-end justify-between">
            <div className="font-mono text-xl font-semibold">{formatCount(metrics.openTickets.value)}</div>
            <div className="text-[11px] px-2 py-0.5 rounded bg-red-100 text-red-700">{metrics.openTickets.urgent} urgent</div>
          </div>
          <div className="text-xs text-gray-500 mt-1">
            <span className="text-red-600 font-medium">0</span> SLA breach
          </div>
        </div>
      </div>

      {/* Metric cards row 2 */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] font-medium uppercase tracking-wide text-gray-500 mb-1">Conversion Rate</div>
          <div className="font-mono text-xl font-semibold">{metrics.conversionRate.value}%</div>
          <div className="w-full bg-gray-200 rounded-full h-1.5 mt-2">
            <div className="bg-blue-600 h-1.5 rounded-full" style={{ width: `${metrics.conversionRate.value}%` }} />
          </div>
        </div>
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] font-medium uppercase tracking-wide text-gray-500 mb-1">Avg Deal Size</div>
          <div className="font-mono text-xl font-semibold">฿{formatMoney(metrics.avgDealSize.value)}</div>
          <div className="text-xs text-gray-500 mt-1">Last 30 days</div>
        </div>
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] font-medium uppercase tracking-wide text-gray-500 mb-1">Active Campaigns</div>
          <div className="font-mono text-xl font-semibold">{metrics.activeCampaigns.value}</div>
          <div className="flex items-center gap-1 mt-1">
            <span className="text-xs px-2 py-0.5 rounded bg-green-100 text-green-700">{metrics.activeCampaigns.value}</span>
            <span className="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600">running</span>
          </div>
        </div>
        <div className="bg-white border border-gray-200 rounded-md p-3">
          <div className="text-[11px] font-medium uppercase tracking-wide text-gray-500 mb-1">CSAT Score</div>
          <div className="flex items-end justify-between">
            <div className="font-mono text-xl font-semibold">{metrics.satisfaction.value}</div>
            <div className="text-sm text-gray-500">/5.0</div>
          </div>
          <div className="mt-2 text-yellow-400 text-xs" aria-hidden="true">
            ★★★★<span className="text-gray-300">★</span>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Left column */}
        <div className="lg:col-span-2 space-y-4">
          {alerts.length > 0 ? (
            <div data-testid="alerts">
              {alerts.map((a, i) => (
                <div
                  key={i}
                  className={`border rounded-md p-3 mb-3 flex items-center justify-between ${
                    a.type === 'danger' ? 'bg-red-50 border-red-200 text-red-800' : a.type === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-800' : 'bg-blue-50 border-blue-200 text-blue-800'
                  }`}
                >
                  <span className="font-medium">{a.message}</span>
                  <a href={a.link} className="text-sm underline">
                    View
                  </a>
                </div>
              ))}
            </div>
          ) : null}

          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm flex items-center justify-between">
              <span>Revenue Trend</span>
              <div className="flex gap-2">
                {['7d', '30d', '90d'].map((p) => (
                  <a
                    key={p}
                    href={`/crm-dashboard-advanced?tab=overview&period=${p}`}
                    className={`px-2 py-1 rounded text-xs ${period === p ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-600'}`}
                  >
                    {p.toUpperCase()}
                  </a>
                ))}
              </div>
            </div>
            <div className="p-3">
              {revenue.daily.length > 0 ? (
                <MiniLineChart labels={revenue.daily.map((d) => d.date)} values={revenue.daily.map((d) => d.revenue)} color="#3b82f6" ariaLabel="Revenue trend" />
              ) : (
                <EmptyState heading="No revenue data yet" sub="odoo_webhooks_log has no sale.order events for this period" />
              )}
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
              <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Pipeline Distribution</div>
              <div className="p-3">
                <MiniDonut data={pipeline.stages.map((s, i) => ({ label: s.name, value: s.value, color: STAGE_CHART_COLORS[i] ?? '#94a3b8' }))} />
              </div>
            </div>
            <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
              <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Revenue by Source</div>
              <div className="p-3">
                <EmptyState heading="Not implemented" sub="sourceChart has no render function in the PHP source — permanently blank there too" />
              </div>
            </div>
          </div>

          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm flex items-center justify-between">
              <span>Recent Deals</span>
              <a href="/crm-dashboard-advanced?tab=deals" className="text-xs text-blue-600 hover:underline">
                View All
              </a>
            </div>
            <div className="p-3">
              {recentDeals.deals.length === 0 ? (
                <div className="text-center py-4 text-gray-400 text-sm">No deals found</div>
              ) : null}
            </div>
          </div>
        </div>

        {/* Right column */}
        <div className="space-y-4">
          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Quick Actions</div>
            <div className="p-3 grid grid-cols-2 gap-2">
              <AddDealModal customers={customerOptions} />
              <CreateTicketModal customers={customerOptions} />
              <a href="/crm-dashboard-advanced?tab=customers" className="text-center px-3 py-1.5 rounded-md bg-gray-100 text-gray-700 text-xs font-medium">
                + Add Customer
              </a>
              <a href="/crm-dashboard-advanced?tab=marketing" className="text-center px-3 py-1.5 rounded-md bg-gray-100 text-gray-700 text-xs font-medium">
                ➤ Campaign
              </a>
            </div>
          </div>

          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Activity Feed</div>
            <div className="p-3 max-h-80 overflow-y-auto">
              {overview.activities.length === 0 ? (
                <div className="text-center py-4 text-gray-400 text-sm">No recent activity</div>
              ) : (
                overview.activities.map((a, i) => (
                  <div key={i} className="flex gap-2.5 py-2.5 border-b border-gray-100 last:border-0">
                    <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs flex-shrink-0 ${a.type === 'deal' ? 'bg-blue-100 text-blue-600' : 'bg-yellow-100 text-yellow-600'}`}>
                      {a.type === 'deal' ? '💼' : '🎫'}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm">
                        <span className="font-medium">{a.customer_name || 'Unknown'}</span>{' '}
                        {a.type === 'deal' ? `created deal "${a.title}" worth ฿${formatMoney(a.value)}` : `opened ticket "${a.title}" (${a.stage})`}
                      </p>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Top Performers</div>
            <div className="p-3 space-y-2.5">
              {MOCK_TOP_PERFORMERS.map((p) => (
                <div key={p.name} className="flex items-center justify-between p-2 bg-gray-50 rounded-md">
                  <div>
                    <p className="font-medium text-sm">{p.name}</p>
                    <p className="text-xs text-gray-500">{p.metric}</p>
                  </div>
                  <div className="text-right">
                    {p.revenue !== null ? <p className="font-mono font-medium text-sm">฿{formatMoney(p.revenue)}</p> : null}
                    <p className="text-xs text-gray-500">{p.rate}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">System Status</div>
            <div className="p-3 space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-gray-500">API Status</span>
                <span className="text-xs px-2 py-0.5 rounded bg-green-100 text-green-700">Operational</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">Database</span>
                <span className="text-xs px-2 py-0.5 rounded bg-green-100 text-green-700">Connected</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">LINE OA</span>
                <span className="text-xs px-2 py-0.5 rounded bg-green-100 text-green-700">Online</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
