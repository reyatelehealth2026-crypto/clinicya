import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getRevenueAnalytics, getPipelineData } from '../queries';
import { MiniLineChart, MiniBarChart, MiniDonut, STAGE_CHART_COLORS } from './MiniChart';

/**
 * AnalyticsStudioTab.tsx — Server Component port of analytics-studio.php
 * (`loadAnalyticsData()` -> `crmApi('analytics_revenue', {period})` +
 * `crmApi('pipeline')`). Two of the 4 charts are 100% hardcoded mock data in
 * the PHP source, with NO API call backing them at all
 * (`renderAnalyticsCustomerChart()`/`renderAnalyticsTicketChart()` — both
 * literal arrays in the JS, confirmed by reading the full file) — mirrored
 * as the exact same static mock series, not fabricated fresh or wired to a
 * real query that doesn't exist in the PHP source.
 *
 * "Export" (`exportReport()`) is a bare `alert('Export functionality - would
 * generate PDF/Excel report')` stub — a client component would be needed
 * just for that one alert; given it does nothing real in PHP either, it's
 * omitted here (flagged) rather than adding a client boundary for a no-op.
 */
export interface AnalyticsStudioTabProps {
  db: Kysely<TenantDB>;
  period: string;
}

const PERIOD_OPTIONS = ['7d', '30d', '90d', '1y'];
const MOCK_CUSTOMER_GROWTH = [
  { label: 'Week 1', value: 45 },
  { label: 'Week 2', value: 52 },
  { label: 'Week 3', value: 48 },
  { label: 'Week 4', value: 61 },
];
const MOCK_TICKET_RESOLUTION = [
  { label: 'Open', value: 12, color: '#3b82f6' },
  { label: 'Pending', value: 8, color: '#f59e0b' },
  { label: 'Resolved', value: 45, color: '#10b981' },
  { label: 'Closed', value: 23, color: '#94a3b8' },
];

export async function AnalyticsStudioTab({ db, period }: AnalyticsStudioTabProps) {
  const [revenue, pipeline] = await Promise.all([getRevenueAnalytics(db, period), getPipelineData(db)]);

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <form method="GET" className="flex items-center gap-2">
          <input type="hidden" name="tab" value="analytics" />
          <select name="period" defaultValue={period} className="border rounded-md text-sm px-2 py-1.5">
            {PERIOD_OPTIONS.map((p) => (
              <option key={p} value={p}>
                {p === '7d' ? 'Last 7 Days' : p === '30d' ? 'Last 30 Days' : p === '90d' ? 'Last 90 Days' : 'Last Year'}
              </option>
            ))}
          </select>
          <button type="submit" className="px-3 py-1.5 rounded-md bg-gray-100 text-gray-700 text-sm">
            Apply
          </button>
        </form>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
          <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Revenue Trend</div>
          <div className="p-3">
            {revenue.daily.length > 0 ? (
              <MiniLineChart labels={revenue.daily.map((d) => d.date)} values={revenue.daily.map((d) => d.revenue)} ariaLabel="Revenue trend" />
            ) : (
              <div className="text-center py-8 text-gray-400 text-sm">No data</div>
            )}
          </div>
        </div>

        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
          <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Deals by Stage</div>
          <div className="p-3">
            <MiniBarChart
              data={pipeline.stages.map((s, i) => ({ label: s.name, value: s.count, color: STAGE_CHART_COLORS[i] ?? '#94a3b8' }))}
              ariaLabel="Deals by stage"
            />
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
          <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Customer Growth</div>
          <div className="p-3">
            <MiniBarChart data={MOCK_CUSTOMER_GROWTH.map((d) => ({ ...d, color: '#10b981' }))} ariaLabel="Customer growth (mock, matches PHP)" />
          </div>
        </div>
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
          <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Ticket Resolution</div>
          <div className="p-3">
            <MiniDonut data={MOCK_TICKET_RESOLUTION} />
          </div>
        </div>
      </div>
    </div>
  );
}
