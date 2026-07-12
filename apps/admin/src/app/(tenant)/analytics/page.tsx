import type { Metadata } from 'next';
import { Tabs } from '@/components/Tabs';
import { requireAnalyticsPageContext } from './_lib/session';
import { parseOverviewPeriod, todayInBangkok, daysAgoInBangkok, type OverviewPeriodFilter } from './_lib/period';
import { OverviewTab } from './_components/OverviewTab';
import { AdvancedTab } from './_components/AdvancedTab';
import { CrmTab } from './_components/CrmTab';
import { AccountTab } from './_components/AccountTab';

/**
 * (tenant)/analytics/page.tsx — Server Component port of analytics.php, the
 * consolidated "สถิติรวม" analytics hub (analytics.php's own doc comment:
 * "รวม: ภาพรวม + วิเคราะห์ขั้นสูง + CRM Analytics + สถิติแยกตามบอท"). Serves
 * at the SAME clean URL PHP does — `/analytics` (default tab=overview) and
 * `/analytics?tab={advanced|crm|account}` — matching
 * apps/admin/src/nav/manifest.ts's 'สถิติรวม'/'วิเคราะห์ข้อมูล' nav hrefs.
 *
 * All FOUR tabs are ported (none were dead/stub — confirmed by reading every
 * includes/analytics/*.php partial + app/Controllers/AnalyticsController.php
 * + app/Models/Analytics/AnalyticsModel.php + classes/AdvancedCRM.php +
 * classes/LineAccountManager.php in full, per this batch's brief):
 *   - overview: includes/analytics/overview.php -> OverviewTab
 *   - advanced: includes/analytics/advanced.php -> App\Controllers\
 *     AnalyticsController (MVC) -> AdvancedTab (+ RealtimeBar/FunnelChart/
 *     AdvancedControls client islands replacing its api_realtime/api_funnel/
 *     export AJAX endpoints with Server Actions — see actions.ts)
 *   - crm: includes/analytics/crm.php (classes/AdvancedCRM.php) -> CrmTab
 *   - account: includes/analytics/account.php (classes/LineAccountManager.php)
 *     -> AccountTab
 *
 * Access gate: analytics.php requires isAdmin()||isSuperAdmin() (role IN
 * ('admin','super_admin')), redirecting non-admins to '/' — see
 * _lib/session.ts's requireAnalyticsPageContext().
 */
export interface AnalyticsPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export const metadata: Metadata = { title: 'สถิติรวม' };

const TABS = [
  { key: 'overview', label: 'ภาพรวม' },
  { key: 'advanced', label: 'วิเคราะห์ขั้นสูง' },
  { key: 'crm', label: 'CRM' },
  { key: 'account', label: 'แยกตามบอท' },
] as const;
type TabKey = (typeof TABS)[number]['key'];

function resolveActiveTab(tabParam: string | string[] | undefined): TabKey {
  const value = Array.isArray(tabParam) ? tabParam[0] : tabParam;
  return (TABS.some((t) => t.key === value) ? value : 'overview') as TabKey;
}

function first(params: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = params[key];
  return Array.isArray(v) ? v[0] : v;
}

function OverviewPeriodSelector({ period, tab }: { period: OverviewPeriodFilter; tab: TabKey }) {
  const options: { key: string; label: string }[] = [
    { key: '7', label: '7 วัน' },
    { key: '30', label: '30 วัน' },
    { key: '90', label: '90 วัน' },
  ];
  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="inline-flex gap-1 p-1 bg-slate-100 rounded-lg border border-slate-200">
        {options.map((opt) => (
          <a
            key={opt.key}
            href={`/analytics?tab=${tab}&period=${opt.key}`}
            aria-current={period.period === opt.key ? 'true' : undefined}
            className={`inline-flex items-center px-4 py-1.5 rounded-md text-sm font-medium ${
              period.period === opt.key ? 'bg-primary-600 text-white' : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            {opt.label}
          </a>
        ))}
      </div>
      <form className="flex items-center gap-2">
        <input type="hidden" name="tab" value="overview" />
        <input type="date" name="start" defaultValue={period.startDate} className="px-3 py-2 border rounded-lg text-sm" />
        <span className="text-slate-400">-</span>
        <input type="date" name="end" defaultValue={period.endDate} className="px-3 py-2 border rounded-lg text-sm" />
        <button type="submit" className="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700">
          ค้นหา
        </button>
      </form>
    </div>
  );
}

export default async function AnalyticsPage({ searchParams }: AnalyticsPageProps) {
  const params = await searchParams;
  const { db, session } = await requireAnalyticsPageContext();
  const activeTab = resolveActiveTab(params.tab);
  const lineAccountId = session.currentBotId;

  const preserveParams: Record<string, string | undefined> = {
    period: first(params, 'period'),
    start: first(params, 'start'),
    end: first(params, 'end'),
    days: first(params, 'days'),
    account_id: first(params, 'account_id'),
    date_from: first(params, 'date_from'),
    date_to: first(params, 'date_to'),
  };

  const overviewPeriod = parseOverviewPeriod(params);

  // Resolved by direct invocation (`await Tab({...})`), not JSX, matching
  // dashboard/page.tsx's established convention — see that file's doc
  // comment for why (plain react-dom under @testing-library/react can't
  // await a nested async function component reached via JSX the way
  // Next.js's RSC renderer can).
  let tabContent: Awaited<ReturnType<typeof OverviewTab>>;
  if (activeTab === 'advanced') {
    tabContent = await AdvancedTab({ db, lineAccountId, period: first(params, 'period') ?? '7d' });
  } else if (activeTab === 'crm') {
    tabContent = await CrmTab({ db, lineAccountId, days: Number.parseInt(first(params, 'days') ?? '30', 10) || 30 });
  } else if (activeTab === 'account') {
    const rawAccountId = first(params, 'account_id');
    const parsedAccountId = rawAccountId ? Number.parseInt(rawAccountId, 10) : NaN;
    const selectedAccountId = Number.isFinite(parsedAccountId) && parsedAccountId > 0 ? parsedAccountId : null;
    tabContent = await AccountTab({
      db,
      selectedAccountId,
      // account.php: `$_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'))` / `$_GET['date_to'] ?? date('Y-m-d')`
      // — an independent 30-day default, NOT derived from the overview tab's own $_GET['period'].
      dateFrom: first(params, 'date_from') ?? daysAgoInBangkok(30),
      dateTo: first(params, 'date_to') ?? todayInBangkok(),
    });
  } else {
    tabContent = await OverviewTab({ db, lineAccountId, startDate: overviewPeriod.startDate, endDate: overviewPeriod.endDate });
  }

  return (
    <div>
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <div>
          <h2 className="text-xl font-bold text-slate-800">📊 สถิติรวม</h2>
          <p className="text-sm text-slate-500">ภาพรวมข้อมูลลูกค้า ข้อความ และการตลาด</p>
        </div>
        {activeTab === 'overview' ? <OverviewPeriodSelector period={overviewPeriod} tab={activeTab} /> : null}
      </div>

      <Tabs tabs={TABS.map((t) => ({ key: t.key, label: t.label }))} activeTab={activeTab} basePath="/analytics" preserveParams={preserveParams} />

      <div className="mt-6">{tabContent}</div>
    </div>
  );
}
