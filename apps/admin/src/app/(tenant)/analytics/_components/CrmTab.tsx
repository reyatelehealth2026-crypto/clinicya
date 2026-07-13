import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getUserAnalytics, getSegments, getTotalUsers } from '../_lib/crmQueries';

/**
 * CrmTab.tsx — Server Component port of includes/analytics/crm.php. `days`
 * is `(int)($_GET['days'] ?? 30)`, parsed by the page and passed in.
 */
export interface CrmTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
  days: number;
}

function num(n: number): string {
  return new Intl.NumberFormat('en-US').format(n);
}

const DAY_OPTIONS = [7, 30, 90] as const;

export async function CrmTab({ db, lineAccountId, days }: CrmTabProps) {
  const [analytics, totalUsers, segments] = await Promise.all([
    getUserAnalytics(db, lineAccountId, days),
    getTotalUsers(db, lineAccountId),
    getSegments(db, lineAccountId),
  ]);

  return (
    <div>
      <div className="flex justify-between items-center mb-6">
        <div>
          <h3 className="text-xl font-bold">📊 CRM Analytics</h3>
          <p className="text-gray-600">วิเคราะห์ข้อมูลลูกค้า</p>
        </div>
        <div className="flex gap-2">
          {DAY_OPTIONS.map((d) => (
            <a
              key={d}
              href={`/analytics?tab=crm&days=${d}`}
              className={`px-4 py-2 rounded-lg ${days === d ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200'}`}
            >
              {d} วัน
            </a>
          ))}
        </div>
      </div>

      {/* Main Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div className="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow p-5 text-white">
          <p className="text-blue-100 text-sm">Total Users</p>
          <p className="text-3xl font-bold">{num(totalUsers)}</p>
        </div>
        <div className="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow p-5 text-white">
          <p className="text-green-100 text-sm">Active Users ({days}d)</p>
          <p className="text-3xl font-bold">{num(analytics.activeUsers)}</p>
        </div>
        <div className="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow p-5 text-white">
          <p className="text-purple-100 text-sm">New Users ({days}d)</p>
          <p className="text-3xl font-bold">{num(analytics.newUsers)}</p>
        </div>
        <div className="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl shadow p-5 text-white">
          <p className="text-orange-100 text-sm">Segments</p>
          <p className="text-3xl font-bold">{segments.length}</p>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl shadow p-5">
          <div className="flex justify-between items-center mb-4">
            <h3 className="font-semibold">🏷️ Top Tags</h3>
            <a href="/user-tags" className="text-sm text-blue-600 hover:underline">
              จัดการ →
            </a>
          </div>
          {analytics.topTags.length > 0 ? (
            <div className="space-y-2">
              {analytics.topTags.map((tag) => (
                <div key={tag.name} className="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                  <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full" style={{ backgroundColor: tag.color ?? '#6b7280' }} />
                    <span className="text-sm">{tag.name}</span>
                  </div>
                  <span className="text-sm font-medium">{num(tag.count)}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-gray-500 text-center py-4">ยังไม่มี Tags</p>
          )}
        </div>

        <div className="bg-white rounded-xl shadow p-5">
          <div className="flex justify-between items-center mb-4">
            <h3 className="font-semibold">🎯 Customer Segments</h3>
            <a href="/customer-segments" className="text-sm text-blue-600 hover:underline">
              จัดการ →
            </a>
          </div>
          {segments.length > 0 ? (
            <div className="space-y-2">
              {segments.slice(0, 5).map((segment) => (
                <div key={segment.id} className="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                  <div>
                    <p className="font-medium text-sm">{segment.name}</p>
                    <p className="text-xs text-gray-500">{segment.segment_type}</p>
                  </div>
                  <span className="text-lg font-bold text-blue-600">{num(segment.user_count)}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-gray-500 text-center py-4">ยังไม่มี Segments</p>
          )}
        </div>
      </div>

      {/* Quick Actions */}
      <div className="mt-6 bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-xl p-5">
        <h4 className="font-semibold text-green-800 mb-3">🚀 Quick Actions</h4>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <a href="/customer-segments" className="p-3 bg-white rounded-lg hover:shadow-md transition text-center">
            <p className="text-sm font-medium">สร้าง Segment</p>
          </a>
          <a href="/link-tracking" className="p-3 bg-white rounded-lg hover:shadow-md transition text-center">
            <p className="text-sm font-medium">สร้าง Tracked Link</p>
          </a>
          <a href="/user-tags" className="p-3 bg-white rounded-lg hover:shadow-md transition text-center">
            <p className="text-sm font-medium">จัดการ Tags</p>
          </a>
          <a href="/broadcast" className="p-3 bg-white rounded-lg hover:shadow-md transition text-center">
            <p className="text-sm font-medium">ส่ง Broadcast</p>
          </a>
        </div>
      </div>
    </div>
  );
}
