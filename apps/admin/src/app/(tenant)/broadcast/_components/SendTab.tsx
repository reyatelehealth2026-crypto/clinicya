import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { cancelScheduledAction } from '../_lib/send-actions';
import {
  getBroadcastGroups,
  getBroadcastHistory,
  getBroadcastTags,
  getBroadcastTemplates,
  getSegments,
  getTotalUsers,
  type BroadcastHistoryItem,
} from '../_lib/send-queries';
import { SendComposeForm } from './SendComposeForm';

/**
 * SendTab.tsx — Server Component port of includes/broadcast/send.php's "send" tab (the
 * default tab, `broadcast.php`'s `case 'send': default:`). Fetches every read this tab needs
 * (templates, groups, segments, tags, totalUsers, paginated history) via `../_lib/
 * send-queries.ts`, server-renders the success/scheduled/cancelled banners + history sidebar
 * (including the `action=cancel_scheduled` form, a plain Server Action binding — no client JS
 * needed for that one), and delegates the interactive compose form + WS-2 confirm modal to
 * `./SendComposeForm.tsx` ('use client' — see that file's module doc for why the split).
 */

function first(searchParams: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = searchParams[key];
  return Array.isArray(v) ? v[0] : v;
}

const STATUS_COLORS: Record<string, string> = {
  sent: 'bg-green-100 text-green-700',
  scheduled: 'bg-blue-100 text-blue-700',
  sending: 'bg-yellow-100 text-yellow-700',
  failed: 'bg-red-100 text-red-600',
  draft: 'bg-gray-100 text-gray-600',
};
const STATUS_LABELS: Record<string, string> = {
  sent: 'ส่งแล้ว',
  scheduled: '⏰ ตั้งเวลา',
  sending: 'กำลังส่ง',
  failed: 'ยกเลิก',
  draft: 'ร่าง',
};

/** `date('d/m H:i', strtotime(...))` — same Bangkok/Gregorian approach as
 * ../../activity-logs/_lib/format.ts's formatLogTimestamp(). */
function formatHistoryDate(value: Date | string): string {
  const date = typeof value === 'string' ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return '-';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok',
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  return `${get('day')}/${get('month')} ${get('hour')}:${get('minute')}`;
}

function HistoryCard({ item }: { item: BroadcastHistoryItem }) {
  const statusColor = STATUS_COLORS[item.status ?? ''] ?? 'bg-gray-100 text-gray-600';
  const statusLabel = STATUS_LABELS[item.status ?? ''] ?? item.status;

  return (
    <div className="p-3 bg-gray-50 rounded-lg">
      <div className="flex justify-between items-start mb-1">
        <h4 className="font-medium text-sm truncate flex-1 mr-2">{item.title}</h4>
        <span className={`px-2 py-0.5 text-xs rounded shrink-0 ${statusColor}`}>{statusLabel}</span>
      </div>
      <div className="flex justify-between text-xs text-gray-400 mb-1">
        <span>
          <i className="fas fa-comment mr-1" aria-hidden="true" />
          {item.messageType}
        </span>
        {item.status === 'scheduled' && item.scheduledAt ? (
          <span className="text-blue-500">
            <i className="fas fa-clock mr-1" aria-hidden="true" />
            {formatHistoryDate(item.scheduledAt)}
          </span>
        ) : item.sentAt ? (
          <span>{formatHistoryDate(item.sentAt)}</span>
        ) : null}
      </div>
      {item.status === 'sent' ? (
        <p className="text-xs text-gray-400">
          <i className="fas fa-users mr-1" aria-hidden="true" />
          {item.sentCount.toLocaleString('en-US')} คน
        </p>
      ) : null}
      {item.status === 'scheduled' ? (
        <form action={cancelScheduledAction} className="mt-1">
          <input type="hidden" name="action" value="cancel_scheduled" />
          <input type="hidden" name="broadcast_id" value={item.id} />
          <button type="submit" className="text-xs text-red-500 hover:text-red-700">
            <i className="fas fa-times-circle mr-1" aria-hidden="true" />
            ยกเลิก
          </button>
        </form>
      ) : null}
    </div>
  );
}

export interface SendTabProps {
  db: Kysely<TenantDB>;
  lineAccountId: number | null;
  searchParams: Record<string, string | string[] | undefined>;
}

export async function SendTab({ db, lineAccountId, searchParams }: SendTabProps) {
  const histPageRaw = Number.parseInt(first(searchParams, 'hist_page') ?? '1', 10) || 1;

  const [groups, segments, tags, templates, totalUsers, history] = await Promise.all([
    getBroadcastGroups(db),
    getSegments(db, lineAccountId),
    getBroadcastTags(db, lineAccountId),
    getBroadcastTemplates(db, lineAccountId),
    getTotalUsers(db, lineAccountId),
    getBroadcastHistory(db, lineAccountId, histPageRaw),
  ]);

  const sent = first(searchParams, 'sent');
  const scheduled = first(searchParams, 'scheduled');
  const cancelled = first(searchParams, 'cancelled');

  return (
    <div>
      {sent !== undefined ? (
        <div className="mb-4 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
          <i className="fas fa-check-circle text-2xl mr-3" aria-hidden="true" />
          <div>
            <p className="font-medium">ส่ง Broadcast สำเร็จ!</p>
            <p className="text-sm">ส่งถึงผู้รับ {Number(sent).toLocaleString('en-US')} คน</p>
          </div>
        </div>
      ) : null}
      {scheduled !== undefined ? (
        <div className="mb-4 p-4 bg-blue-100 text-blue-700 rounded-lg flex items-center">
          <i className="fas fa-clock text-2xl mr-3" aria-hidden="true" />
          <div>
            <p className="font-medium">ตั้งเวลา Broadcast สำเร็จ!</p>
            <p className="text-sm">ระบบจะส่งข้อความตามเวลาที่กำหนด</p>
          </div>
        </div>
      ) : null}
      {cancelled !== undefined ? (
        <div className="mb-4 p-4 bg-yellow-100 text-yellow-700 rounded-lg flex items-center">
          <i className="fas fa-ban text-2xl mr-3" aria-hidden="true" />
          <div>
            <p className="font-medium">ยกเลิก Broadcast ที่ตั้งเวลาแล้ว</p>
          </div>
        </div>
      ) : null}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <SendComposeForm templates={templates} groups={groups} segments={segments} tags={tags} totalUsers={totalUsers} />

        <div className="lg:col-span-1">
          <div className="bg-white rounded-xl shadow p-6 sticky top-6">
            <h3 className="text-lg font-semibold mb-4">ประวัติการส่ง</h3>
            <div className="space-y-3 max-h-[640px] overflow-y-auto">
              {history.items.map((item) => (
                <HistoryCard key={item.id} item={item} />
              ))}
              {history.items.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-10 text-center">
                  <div className="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-3">
                    <i className="fas fa-paper-plane text-gray-300 text-2xl" aria-hidden="true" />
                  </div>
                  <p className="text-sm font-medium text-gray-500">ยังไม่มีประวัติการส่ง</p>
                  <p className="text-xs text-gray-400 mt-1">เริ่มส่ง Broadcast แรกของคุณได้เลย!</p>
                </div>
              ) : null}
            </div>
            {history.hasMore ? (
              <div className="mt-3 pt-3 border-t border-gray-100">
                <a
                  href={`/broadcast?tab=send&hist_page=${history.page + 1}`}
                  className="w-full flex items-center justify-center gap-2 py-2 text-sm text-green-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition"
                >
                  <i className="fas fa-chevron-down text-xs" aria-hidden="true" />
                  โหลดเพิ่ม
                </a>
              </div>
            ) : null}
            {history.page > 1 ? (
              <div className="mt-1">
                <a
                  href={`/broadcast?tab=send&hist_page=${history.page - 1}`}
                  className="w-full flex items-center justify-center gap-2 py-2 text-sm text-gray-400 hover:text-gray-600 hover:bg-gray-50 rounded-lg transition"
                >
                  <i className="fas fa-chevron-up text-xs" aria-hidden="true" />
                  ย้อนกลับ
                </a>
              </div>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  );
}
