import type { Metadata } from 'next';
import { PageHeader } from '@/components/PageHeader';
import { Pagination } from '@/components/Pagination';
import { requireTenantPageContext } from '../users/_lib/session';
import { getActivityLogsPage, parseActivityLogsFilters, type RawSearchParams } from './queries';
import { logTypeLabel, actionLabel, logTypeBadgeClasses, LOG_TYPE_LABELS, ACTION_LABELS } from './_lib/labels';
import { formatLogTimestamp } from './_lib/format';

/**
 * (tenant)/activity-logs/page.tsx — Server Component port of
 * activity-logs.php. Read-only audit trail viewer: OFFSET pagination
 * (page/perPage=50/offset — NOT a cursor, see queries.ts's module doc),
 * 5 GET filters (type, action, date_from/date_to, search), 6 display
 * columns (เวลา/ประเภท/การกระทำ/รายละเอียด/ผู้ดำเนินการ/IP). Serves at the
 * same clean URL PHP does — `/activity-logs`.
 *
 * Access gate: activity-logs.php has NO page-specific role check beyond
 * `includes/auth_check.php`'s generic "must be logged in" requirement (no
 * `isAdmin()`/`isSuperAdmin()` gate in the PHP source, confirmed by reading
 * the full file) — reuses users/_lib/session's requireTenantPageContext()
 * verbatim, the same cross-route-import convention user-detail/queries.ts
 * and user-detail/actions.ts already establish for this exact helper.
 *
 * Confirmed zero mutations in this page (re-verified by reading the full
 * 401-line source, not just the orchestrator's grep): the filter form and
 * pagination links are plain `<form method="GET">`/`<a href="?...">`, no
 * `$_POST`/`REQUEST_METHOD` handling anywhere in the file. No Server Action
 * needed for this route.
 */
export interface ActivityLogsPageProps {
  searchParams: Promise<RawSearchParams>;
}

export const metadata: Metadata = { title: 'Activity Logs' };

function first(searchParams: RawSearchParams, key: string): string {
  const v = searchParams[key];
  return (Array.isArray(v) ? v[0] : v) ?? '';
}

export default async function ActivityLogsPage({ searchParams }: ActivityLogsPageProps) {
  const params = await searchParams;
  const { db } = await requireTenantPageContext();

  const filters = parseActivityLogsFilters(params);
  const { logs, totalLogs, totalPages, page, perPage } = await getActivityLogsPage(db, filters);

  const rangeStart = totalLogs > 0 ? (page - 1) * perPage + 1 : 0;
  const rangeEnd = Math.min(page * perPage, totalLogs);

  const preserveParams: Record<string, string | undefined> = {
    type: filters.type || undefined,
    action: filters.action || undefined,
    search: filters.search || undefined,
    date_from: filters.dateFrom || undefined,
    date_to: filters.dateTo || undefined,
  };

  return (
    <div>
      <PageHeader title="Activity Logs" subtitle={`${totalLogs.toLocaleString()} รายการ`} />

      <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        {totalLogs > 0 ? (
          <div className="px-5 py-2.5 text-xs text-slate-500 border-b border-slate-200 bg-white">
            แสดงรายการที่ {rangeStart.toLocaleString()}-{rangeEnd.toLocaleString()} จาก {totalLogs.toLocaleString()}
          </div>
        ) : null}

        <div className="px-5 py-4 bg-slate-50 border-b border-slate-200">
          <form method="GET" className="flex flex-wrap gap-3 items-end">
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-slate-500">ประเภท</label>
              <select name="type" defaultValue={filters.type} className="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white min-w-[140px]">
                <option value="">ทั้งหมด</option>
                {Object.entries(LOG_TYPE_LABELS).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-slate-500">การกระทำ</label>
              <select name="action" defaultValue={filters.action} className="px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white min-w-[140px]">
                <option value="">ทั้งหมด</option>
                {Object.entries(ACTION_LABELS).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-slate-500">จากวันที่</label>
              <input type="date" name="date_from" defaultValue={filters.dateFrom} className="px-3 py-2 border border-slate-300 rounded-lg text-sm" />
            </div>
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-slate-500">ถึงวันที่</label>
              <input type="date" name="date_to" defaultValue={filters.dateTo} className="px-3 py-2 border border-slate-300 rounded-lg text-sm" />
            </div>
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-slate-500">ค้นหา</label>
              <input
                type="text"
                name="search"
                defaultValue={filters.search}
                placeholder="คำอธิบาย, ชื่อผู้ใช้..."
                className="px-3 py-2 border border-slate-300 rounded-lg text-sm min-w-[200px]"
              />
            </div>
            <button type="submit" className="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700">
              ค้นหา
            </button>
          </form>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-200">
                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500">เวลา</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500">ประเภท</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500">การกระทำ</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500">รายละเอียด</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500">ผู้ดำเนินการ</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500">IP</th>
              </tr>
            </thead>
            <tbody>
              {logs.length === 0 ? (
                <tr>
                  <td colSpan={6}>
                    <div className="text-center py-14 text-slate-400">
                      <div>ไม่พบข้อมูล</div>
                    </div>
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr key={log.id} className="border-b border-slate-100 hover:bg-slate-50 align-top">
                    <td className="px-4 py-3 text-xs text-slate-400 whitespace-nowrap">{formatLogTimestamp(log.created_at)}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-semibold ${logTypeBadgeClasses(log.log_type)}`}>
                        {logTypeLabel(log.log_type)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <span className="inline-block px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-700 border border-slate-200">
                        {actionLabel(log.action)}
                      </span>
                    </td>
                    <td className="px-4 py-3 max-w-[300px]">
                      <div className="text-slate-800">{log.description}</div>
                      {log.entity_type ? (
                        <div className="text-[11px] text-slate-400 mt-0.5">
                          {log.entity_type} #{log.entity_id}
                        </div>
                      ) : null}
                      {log.user_name ? <div className="text-[11px] text-slate-400 mt-0.5">ลูกค้า: {log.user_name}</div> : null}
                    </td>
                    <td className="px-4 py-3">
                      {log.admin_name ? <span className="text-primary-600 font-medium">{log.admin_name}</span> : <span className="text-slate-400">-</span>}
                    </td>
                    <td className="px-4 py-3 text-xs text-slate-400 font-mono">{log.ip_address ?? '-'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {totalPages > 1 ? (
          <Pagination currentPage={page} totalPages={totalPages} perPage={perPage} basePath="/activity-logs" queryParams={preserveParams} total={totalLogs} />
        ) : null}
      </div>
    </div>
  );
}
