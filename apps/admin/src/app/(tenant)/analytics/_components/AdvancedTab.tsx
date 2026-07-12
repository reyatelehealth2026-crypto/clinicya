import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getDashboardStats, getRealTimeStats } from '../_lib/advancedQueries';
import { MiniLineChart, MiniStackedBarChart } from './MiniBarChart';
import { RealtimeBar } from './RealtimeBar';
import { FunnelChart } from './FunnelChart';
import { AdvancedControls } from './AdvancedControls';

/**
 * AdvancedTab.tsx — Server Component port of includes/analytics/advanced.php
 * -> App\Controllers\AnalyticsController::dashboard() ->
 * app/Views/analytics/dashboard.php. `period` defaults to '7d' (NOT the
 * overview tab's '30'), matching AnalyticsController::dashboard()'s own
 * `$_GET['period'] ?? '7d'` exactly.
 */
export interface AdvancedTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
  period: string;
}

function num(n: number): string {
  return new Intl.NumberFormat('en-US').format(n);
}

export async function AdvancedTab({ db, lineAccountId, period }: AdvancedTabProps) {
  const [stats, realtime] = await Promise.all([getDashboardStats(db, lineAccountId, period), getRealTimeStats(db, lineAccountId)]);

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h2 className="text-2xl font-bold text-gray-800">Advanced Analytics</h2>
          <p className="text-gray-500 text-sm">ข้อมูลเชิงลึกและสถิติการใช้งาน</p>
        </div>
        <AdvancedControls period={period} />
      </div>

      <RealtimeBar initial={realtime} />

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <div className="flex items-center justify-between mb-3">
            <span className={`text-xs px-2 py-1 rounded-full ${stats.users.growthRate >= 0 ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'}`}>
              {stats.users.growthRate >= 0 ? '+' : ''}
              {stats.users.growthRate}%
            </span>
          </div>
          <div className="text-2xl font-bold text-gray-800">{num(stats.users.total)}</div>
          <div className="text-sm text-gray-500">ผู้ใช้ทั้งหมด</div>
          <div className="mt-2 text-xs text-gray-400">
            ใหม่ {num(stats.users.new)} | Active {num(stats.users.active)}
          </div>
        </div>

        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs px-2 py-1 rounded-full bg-blue-100 text-blue-600">{stats.messages.responseRate}% ตอบกลับ</span>
          </div>
          <div className="text-2xl font-bold text-gray-800">{num(stats.messages.total)}</div>
          <div className="text-sm text-gray-500">ข้อความทั้งหมด</div>
          <div className="mt-2 text-xs text-gray-400">
            เข้า {num(stats.messages.incoming)} | ออก {num(stats.messages.outgoing)}
          </div>
        </div>

        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs px-2 py-1 rounded-full bg-green-100 text-green-600">{stats.orders.conversionRate}% สำเร็จ</span>
          </div>
          <div className="text-2xl font-bold text-gray-800">{num(stats.orders.total)}</div>
          <div className="text-sm text-gray-500">คำสั่งซื้อ</div>
          <div className="mt-2 text-xs text-gray-400">
            รอ {stats.orders.pending} | ชำระแล้ว {stats.orders.paid}
          </div>
        </div>

        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <div className="flex items-center justify-between mb-3">
            <span className={`text-xs px-2 py-1 rounded-full ${stats.revenue.growth >= 0 ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'}`}>
              {stats.revenue.growth >= 0 ? '+' : ''}
              {stats.revenue.growth}%
            </span>
          </div>
          <div className="text-2xl font-bold text-gray-800">฿{num(Math.round(stats.revenue.total))}</div>
          <div className="text-sm text-gray-500">รายได้</div>
          <div className="mt-2 text-xs text-gray-400">เฉลี่ย/ออเดอร์ ฿{num(Math.round(stats.orders.avgOrderValue))}</div>
        </div>
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <h3 className="font-semibold text-gray-800 mb-4">ผู้ใช้ใหม่รายวัน</h3>
          <MiniLineChart values={stats.users.daily.map((d) => Number(d.count))} color="#06C755" ariaLabel="ผู้ใช้ใหม่รายวัน" />
        </div>
        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <h3 className="font-semibold text-gray-800 mb-4">รายได้รายวัน</h3>
          <MiniStackedBarChart
            labels={stats.orders.daily.map((d) => d.date)}
            series={[{ label: 'รายได้', color: '#06C755', values: stats.orders.daily.map((d) => Number(d.revenue)) }]}
          />
        </div>
      </div>

      {/* Second Row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <h3 className="font-semibold text-gray-800 mb-4">ประเภทข้อความ</h3>
          {stats.messages.byType.length > 0 ? (
            <ul className="space-y-1.5 text-sm">
              {stats.messages.byType.map((t) => (
                <li key={t.message_type ?? 'text'} className="flex justify-between">
                  <span className="text-gray-600">{t.message_type ?? 'text'}</span>
                  <span className="font-medium text-gray-800">{num(t.count)}</span>
                </li>
              ))}
            </ul>
          ) : (
            <div className="text-center text-gray-400 py-8">ไม่มีข้อมูล</div>
          )}
        </div>

        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <h3 className="font-semibold text-gray-800 mb-4">ช่วงเวลาที่มีการสนทนา</h3>
          <MiniStackedBarChart
            labels={Array.from({ length: 24 }, (_, h) => `${h}:00`)}
            series={[
              {
                label: 'ข้อความ',
                color: '#3B82F6',
                values: Array.from({ length: 24 }, (_, h) => Number(stats.messages.hourly.find((d) => Number(d.hour) === h)?.count ?? 0)),
              },
            ]}
          />
        </div>

        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <h3 className="font-semibold text-gray-800 mb-4">Customer Funnel</h3>
          <FunnelChart period={period} />
        </div>
      </div>

      {/* Top Products & Engagement */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <h3 className="font-semibold text-gray-800 mb-4">สินค้าขายดี</h3>
          {stats.revenue.topProducts.length > 0 ? (
            <div className="space-y-3">
              {stats.revenue.topProducts.map((product, i) => (
                <div key={product.product_name} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                  <div className="flex items-center gap-3">
                    <span className="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs font-bold">{i + 1}</span>
                    <span className="text-sm font-medium text-gray-700">{product.product_name}</span>
                  </div>
                  <div className="text-right">
                    <div className="text-sm font-semibold text-gray-800">฿{num(Math.round(product.revenue))}</div>
                    <div className="text-xs text-gray-400">{num(product.qty)} ชิ้น</div>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className="text-center text-gray-400 py-8">ไม่มีข้อมูล</div>
          )}
        </div>

        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <h3 className="font-semibold text-gray-800 mb-4">ประสิทธิภาพ Broadcast</h3>
          {stats.engagement.broadcasts.length > 0 ? (
            <div className="space-y-3">
              {stats.engagement.broadcasts.map((broadcast) => (
                <div key={broadcast.name} className="p-3 bg-gray-50 rounded-lg">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm font-medium text-gray-700">{broadcast.name}</span>
                    <span className="text-xs px-2 py-1 bg-blue-100 text-blue-600 rounded-full">{broadcast.ctr ?? 0}% CTR</span>
                  </div>
                  <div className="flex items-center gap-4 text-xs text-gray-400">
                    <span>{num(broadcast.sent_count)} sent</span>
                    <span>{num(broadcast.unique_clicks)} clicks</span>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className="text-center text-gray-400 py-8">ไม่มีข้อมูล</div>
          )}
        </div>
      </div>
    </div>
  );
}
