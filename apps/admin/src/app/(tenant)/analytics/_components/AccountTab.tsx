import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getAllAccounts, getAccountById, getAccountTabData } from '../_lib/accountQueries';
import { AccountSelect } from './AccountSelect';

/**
 * AccountTab.tsx — Server Component port of includes/analytics/account.php.
 */
export interface AccountTabProps {
  db: Kysely<TenantDB>;
  selectedAccountId: number | null;
  dateFrom: string;
  dateTo: string;
}

const EVENT_ICONS: Record<string, string> = {
  follow: '➕',
  unfollow: '➖',
  message: '💬',
  postback: '🔘',
  beacon: '📡',
};

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

function fmtDateTime(d: Date | string | null, withSeconds = false): string {
  if (!d) return '-';
  const date = typeof d === 'string' ? new Date(d) : d;
  if (Number.isNaN(date.getTime())) return '-';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: BANGKOK_TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: withSeconds ? '2-digit' : undefined,
    hour12: false,
  }).formatToParts(date);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  const time = withSeconds ? `${get('hour')}:${get('minute')}:${get('second')}` : `${get('hour')}:${get('minute')}`;
  return `${get('day')}/${get('month')}/${get('year')} ${time}`;
}

function fmtDate(d: Date | string): string {
  const date = typeof d === 'string' ? new Date(d) : d;
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone: BANGKOK_TIME_ZONE, day: '2-digit', month: '2-digit', year: 'numeric' }).formatToParts(date);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  return `${get('day')}/${get('month')}/${get('year')}`;
}

function num(n: number): string {
  return new Intl.NumberFormat('en-US').format(n);
}

export async function AccountTab({ db, selectedAccountId, dateFrom, dateTo }: AccountTabProps) {
  const accounts = await getAllAccounts(db);
  const selectedAccount = selectedAccountId ? await getAccountById(db, selectedAccountId) : null;
  const tabData = selectedAccountId ? await getAccountTabData(db, selectedAccountId, dateFrom, dateTo) : null;

  return (
    <div>
      <h3 className="text-xl font-bold mb-6">📊 สถิติแยกตามบอท</h3>

      {/* Account Selector */}
      <div className="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" className="flex flex-wrap gap-4 items-end">
          <input type="hidden" name="tab" value="account" />
          <div className="flex-1 min-w-[200px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">เลือกบอท</label>
            <AccountSelect accounts={accounts} selectedAccountId={selectedAccountId} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">จากวันที่</label>
            <input type="date" name="date_from" defaultValue={dateFrom} className="border rounded-lg px-3 py-2" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">ถึงวันที่</label>
            <input type="date" name="date_to" defaultValue={dateTo} className="border rounded-lg px-3 py-2" />
          </div>
          <button type="submit" className="bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600">
            🔍 ดูข้อมูล
          </button>
        </form>
      </div>

      {selectedAccount && tabData ? (
        <>
          {/* Account Info */}
          <div className="bg-white rounded-lg shadow p-4 mb-6">
            <div className="flex items-center gap-4">
              {selectedAccount.picture_url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={selectedAccount.picture_url} alt="" className="w-16 h-16 rounded-full" />
              ) : (
                <div className="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center text-2xl">🤖</div>
              )}
              <div>
                <h2 className="text-xl font-bold">{selectedAccount.name}</h2>
                <p className="text-gray-500">{selectedAccount.basic_id ?? '-'}</p>
              </div>
            </div>
          </div>

          {/* Stats Cards */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-3xl font-bold text-green-500">{num(tabData.followerStats.active)}</div>
              <div className="text-gray-500">ผู้ติดตามปัจจุบัน</div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-3xl font-bold text-blue-500">{num(tabData.followerStats.total)}</div>
              <div className="text-gray-500">ผู้ติดตามทั้งหมด</div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-3xl font-bold text-red-500">{num(tabData.followerStats.unfollowed)}</div>
              <div className="text-gray-500">ยกเลิกติดตาม</div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-3xl font-bold text-purple-500">{tabData.recentEvents.length}</div>
              <div className="text-gray-500">Events ล่าสุด</div>
            </div>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Recent Followers */}
            <div className="bg-white rounded-lg shadow">
              <div className="p-4 border-b">
                <h3 className="font-bold">👥 ผู้ติดตามล่าสุด</h3>
              </div>
              <div className="p-4 max-h-96 overflow-y-auto">
                {tabData.followers.length === 0 ? (
                  <p className="text-gray-500 text-center py-4">ยังไม่มีข้อมูล</p>
                ) : (
                  <div className="space-y-3">
                    {tabData.followers.map((f) => (
                      <div key={f.id} className={`flex items-center gap-3 p-2 rounded-lg ${f.is_following ? 'bg-green-50' : 'bg-red-50'}`}>
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={f.current_picture ?? f.picture_url ?? 'https://via.placeholder.com/40'} alt="" className="w-10 h-10 rounded-full" />
                        <div className="flex-1">
                          <div className="font-medium">{f.current_name ?? f.display_name ?? 'Unknown'}</div>
                          <div className="text-xs text-gray-500">
                            Follow: {fmtDateTime(f.followed_at)}
                            {!f.is_following ? <span className="text-red-500"> | Unfollow: {fmtDateTime(f.unfollowed_at)}</span> : null}
                          </div>
                          <div className="text-xs text-gray-400">
                            ข้อความ: {num(f.total_messages)} | Follow ครั้งที่: {f.follow_count}
                          </div>
                        </div>
                        <span className={`px-2 py-1 rounded text-xs ${f.is_following ? 'bg-green-500 text-white' : 'bg-red-500 text-white'}`}>
                          {f.is_following ? 'Active' : 'Left'}
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>

            {/* Recent Events */}
            <div className="bg-white rounded-lg shadow">
              <div className="p-4 border-b">
                <h3 className="font-bold">📋 Events ล่าสุด</h3>
              </div>
              <div className="p-4 max-h-96 overflow-y-auto">
                {tabData.recentEvents.length === 0 ? (
                  <p className="text-gray-500 text-center py-4">ยังไม่มีข้อมูล</p>
                ) : (
                  <div className="space-y-2">
                    {tabData.recentEvents.map((ev) => (
                      <div key={ev.id} className="flex items-center gap-3 p-2 border-b">
                        <span className="text-xl">{EVENT_ICONS[ev.event_type] ?? '📌'}</span>
                        <div className="flex-1">
                          <div className="font-medium">{ev.display_name ?? ev.line_user_id}</div>
                          <div className="text-xs text-gray-500">
                            {ev.event_type.charAt(0).toUpperCase() + ev.event_type.slice(1)} | {fmtDateTime(ev.created_at, true)}
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Daily Stats Table */}
          {tabData.dailyStats.length > 0 ? (
            <div className="bg-white rounded-lg shadow mt-6">
              <div className="p-4 border-b">
                <h3 className="font-bold">📈 สถิติรายวัน</h3>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-2 text-left">วันที่</th>
                      <th className="px-4 py-2 text-center">ผู้ติดตามใหม่</th>
                      <th className="px-4 py-2 text-center">ยกเลิกติดตาม</th>
                      <th className="px-4 py-2 text-center">ข้อความขาเข้า</th>
                      <th className="px-4 py-2 text-center">ข้อความขาออก</th>
                      <th className="px-4 py-2 text-center">รวมข้อความ</th>
                    </tr>
                  </thead>
                  <tbody>
                    {tabData.dailyStats.map((stat) => (
                      <tr key={stat.stat_date.toString()} className="border-b hover:bg-gray-50">
                        <td className="px-4 py-2">{fmtDate(stat.stat_date)}</td>
                        <td className="px-4 py-2 text-center text-green-600">+{stat.new_followers}</td>
                        <td className="px-4 py-2 text-center text-red-600">-{stat.unfollowers}</td>
                        <td className="px-4 py-2 text-center">{stat.incoming_messages}</td>
                        <td className="px-4 py-2 text-center">{stat.outgoing_messages}</td>
                        <td className="px-4 py-2 text-center font-bold">{stat.total_messages}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : null}
        </>
      ) : (
        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
          <p className="text-yellow-700">กรุณาเลือกบอทเพื่อดูสถิติ</p>
        </div>
      )}
    </div>
  );
}
