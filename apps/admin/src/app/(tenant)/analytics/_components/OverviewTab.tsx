import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getOverviewData } from '../_lib/overviewQueries';
import { MiniStackedBarChart, MiniLineChart } from './MiniBarChart';

/**
 * OverviewTab.tsx — Server Component port of includes/analytics/overview.php.
 * Stat cards + 3 daily charts + CRM section (top tags / top keywords / quick
 * actions) + export links, translated 1:1 from the PHP markup's Tailwind
 * utility classes (overview.php already authors raw Tailwind classNames, so
 * this mirrors them directly rather than reaching for design-tokens.css's
 * custom `primary-*` scale — both coexist in the same Tailwind build).
 */
export interface OverviewTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
  startDate: string;
  endDate: string;
}

function baht(n: number): string {
  return new Intl.NumberFormat('en-US').format(Math.round(n));
}
function num(n: number): string {
  return new Intl.NumberFormat('en-US').format(n);
}

export async function OverviewTab({ db, lineAccountId, startDate, endDate }: OverviewTabProps) {
  const data = await getOverviewData(db, lineAccountId, startDate, endDate);
  const { stats, topTags, topKeywords, segmentsCount, messagesByDay, followersByDay, revenueByDay } = data;

  return (
    <div>
      {/* Main Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
        <div className="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-4 text-white">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-blue-100 text-xs">ผู้ติดตาม</p>
              <p className="text-2xl font-bold">{num(stats.followers)}</p>
            </div>
          </div>
          <p className="text-xs text-blue-200 mt-2">+{num(stats.newFollowers)} ใหม่</p>
        </div>

        <div className="bg-gradient-to-br from-green-500 to-green-600 rounded-xl p-4 text-white">
          <div>
            <p className="text-green-100 text-xs">Active Users</p>
            <p className="text-2xl font-bold">{num(stats.activeUsers)}</p>
          </div>
        </div>

        <div className="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl p-4 text-white">
          <div>
            <p className="text-purple-100 text-xs">ข้อความ</p>
            <p className="text-2xl font-bold">{num(stats.messages)}</p>
          </div>
        </div>

        <div className="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl p-4 text-white">
          <div>
            <p className="text-orange-100 text-xs">Broadcast</p>
            <p className="text-2xl font-bold">{num(stats.broadcasts)}</p>
          </div>
          <p className="text-xs text-orange-200 mt-2">{num(stats.broadcastRecipients)} ผู้รับ</p>
        </div>

        <div className="bg-gradient-to-br from-cyan-500 to-cyan-600 rounded-xl p-4 text-white">
          <div>
            <p className="text-cyan-100 text-xs">ออเดอร์</p>
            <p className="text-2xl font-bold">{num(stats.orders)}</p>
          </div>
        </div>

        <div className="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl p-4 text-white">
          <div>
            <p className="text-emerald-100 text-xs">รายได้</p>
            <p className="text-xl font-bold">฿{baht(stats.revenue)}</p>
          </div>
        </div>
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div className="bg-white rounded-xl shadow-sm p-4">
          <h3 className="font-semibold text-gray-800 mb-4">💬 ข้อความรายวัน</h3>
          <MiniStackedBarChart
            labels={messagesByDay.map((d) => d.date)}
            series={[
              { label: 'รับ', color: '#3B82F6', values: messagesByDay.map((d) => Number(d.incoming)) },
              { label: 'ส่ง', color: '#10B981', values: messagesByDay.map((d) => Number(d.outgoing)) },
            ]}
          />
        </div>

        <div className="bg-white rounded-xl shadow-sm p-4">
          <h3 className="font-semibold text-gray-800 mb-4">👥 ผู้ติดตามใหม่</h3>
          <MiniLineChart values={followersByDay.map((d) => Number(d.count))} color="#8B5CF6" ariaLabel="กราฟผู้ติดตามใหม่รายวัน" />
        </div>

        <div className="bg-white rounded-xl shadow-sm p-4">
          <h3 className="font-semibold text-gray-800 mb-4">💰 รายได้รายวัน</h3>
          <MiniLineChart values={revenueByDay.map((d) => Number(d.revenue))} color="#10B981" ariaLabel="กราฟรายได้รายวัน" />
        </div>
      </div>

      {/* CRM Section */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div className="bg-white rounded-xl shadow-sm p-4">
          <div className="flex justify-between items-center mb-4">
            <h3 className="font-semibold text-gray-800">🏷️ Top Tags</h3>
            <a href="/user-tags" className="text-sm text-purple-600 hover:underline">
              จัดการ →
            </a>
          </div>
          {topTags.length > 0 ? (
            <div className="space-y-2">
              {topTags.map((tag) => (
                <div key={tag.name} className="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                  <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full" style={{ backgroundColor: tag.color ?? '#6b7280' }} />
                    <span className="text-sm">{tag.name}</span>
                  </div>
                  <span className="text-sm font-medium text-gray-600">{num(tag.count)}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-gray-400 text-center py-4">ยังไม่มี Tags</p>
          )}
        </div>

        <div className="bg-white rounded-xl shadow-sm p-4">
          <div className="flex justify-between items-center mb-4">
            <h3 className="font-semibold text-gray-800">🔑 Top Keywords</h3>
            <a href="/auto-reply" className="text-sm text-purple-600 hover:underline">
              จัดการ →
            </a>
          </div>
          {topKeywords.length > 0 ? (
            <div className="space-y-2">
              {topKeywords.map((kw, i) => (
                <div key={kw.keyword} className="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                  <div className="flex items-center gap-2">
                    <span className="w-5 h-5 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center text-xs">{i + 1}</span>
                    <span className="text-sm">{kw.keyword}</span>
                  </div>
                  <span className="text-sm font-medium text-gray-600">{num(kw.hitCount ?? 0)}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-gray-400 text-center py-4">ยังไม่มีข้อมูล</p>
          )}
        </div>

        <div className="bg-white rounded-xl shadow-sm p-4">
          <h3 className="font-semibold text-gray-800 mb-4">🚀 Quick Actions</h3>
          <div className="grid grid-cols-2 gap-2">
            <a href="/customer-segments" className="p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition text-center">
              <p className="text-xs font-medium">Segments ({segmentsCount})</p>
            </a>
            <a href="/broadcast" className="p-3 bg-orange-50 rounded-lg hover:bg-orange-100 transition text-center">
              <p className="text-xs font-medium">Broadcast</p>
            </a>
            <a href="/shop/reports" className="p-3 bg-green-50 rounded-lg hover:bg-green-100 transition text-center">
              <p className="text-xs font-medium">รายงานยอดขาย</p>
            </a>
            <a href="/users" className="p-3 bg-purple-50 rounded-lg hover:bg-purple-100 transition text-center">
              <p className="text-xs font-medium">ลูกค้า</p>
            </a>
          </div>
        </div>
      </div>

      {/* Export Section */}
      <div className="bg-white rounded-xl shadow-sm p-4">
        <h3 className="font-semibold text-gray-800 mb-4">📥 Export ข้อมูล</h3>
        <div className="flex flex-wrap gap-3">
          <a href={`/export?type=messages&start=${startDate}&end=${endDate}`} className="flex items-center gap-2 px-4 py-2 bg-gray-50 rounded-lg hover:bg-gray-100">
            <span className="text-sm">Export ข้อความ</span>
          </a>
          <a href={`/export?type=users&start=${startDate}&end=${endDate}`} className="flex items-center gap-2 px-4 py-2 bg-gray-50 rounded-lg hover:bg-gray-100">
            <span className="text-sm">Export ผู้ติดตาม</span>
          </a>
          <a href={`/export?type=orders&start=${startDate}&end=${endDate}`} className="flex items-center gap-2 px-4 py-2 bg-gray-50 rounded-lg hover:bg-gray-100">
            <span className="text-sm">Export ออเดอร์</span>
          </a>
        </div>
      </div>
    </div>
  );
}
