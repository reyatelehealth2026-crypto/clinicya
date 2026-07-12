'use client';

import { useState, type ReactNode } from 'react';
import { AddPointsModal, type AddPointsTarget } from './AddPointsModal';
import { MemberDetailModal } from './MemberDetailModal';
import { lmName } from '../queries';
import type { LoyaltyMemberRow } from '../queries';

/**
 * MembersListClient.tsx — client island wrapping loyalty-members.php's
 * per-row "เพิ่มแต้ม"/"ดูประวัติแต้ม" buttons + header "เพิ่มแต้ม / ลูกค้าใหม่"
 * button and their two modals (lmOpenAdd()/lmOpenDetail() in the PHP
 * source). The row list itself is otherwise static markup (server-rendered
 * data passed down as props) — only the modal open/close state is client-side.
 *
 * `betweenHeaderAndList` renders the server-rendered stats cards + search
 * form (page.tsx) in between the header row and the list — those need no
 * client JS of their own (a plain GET `<form>`), so they're passed through
 * as a slot rather than needing this whole component to own their markup,
 * keeping the client bundle limited to what actually needs interactivity.
 */
export function MembersListClient({
  members,
  search,
  betweenHeaderAndList,
}: {
  members: LoyaltyMemberRow[];
  search: string;
  betweenHeaderAndList?: ReactNode;
}) {
  const [addTarget, setAddTarget] = useState<AddPointsTarget | null>(null);
  const [detailUserId, setDetailUserId] = useState<number | null>(null);

  function openAdd(userId?: number, phone?: string, name?: string) {
    setAddTarget({ userId, phone, name });
  }

  return (
    <>
      <div className="flex items-center justify-between gap-2 mb-4 flex-wrap">
        <div>
          <h1 className="text-lg font-bold text-gray-800">สมาชิกเบอร์ (สะสมแต้ม)</h1>
          <p className="text-xs text-gray-500 mt-0.5">ลูกค้าที่เพิ่มหน้าร้านด้วยเบอร์โทร ยังไม่ได้ผูก LINE</p>
        </div>
        <button
          type="button"
          onClick={() => openAdd()}
          className="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-bold shadow"
        >
          เพิ่มแต้ม / ลูกค้าใหม่
        </button>
      </div>

      {betweenHeaderAndList}

      <div className="bg-white rounded-xl border overflow-hidden">
        {members.length === 0 ? (
          <div className="text-center py-10 text-gray-400 text-sm">
            {search !== '' ? 'ไม่พบสมาชิกที่ค้นหา' : 'ยังไม่มีสมาชิกเบอร์ — กด "เพิ่มแต้ม / ลูกค้าใหม่" เพื่อเริ่ม'}
          </div>
        ) : (
          <div className="divide-y">
            {members.map((m) => {
              const name = lmName(m);
              const phone = m.phone ?? '';
              return (
                <div key={m.id} className="flex items-center gap-3 p-3 hover:bg-gray-50">
                  <div className="w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0">👤</div>
                  <div className="flex-1 min-w-0">
                    <div className="font-bold text-sm text-gray-800 truncate">{name}</div>
                    <div className="text-xs text-gray-500">
                      {phone} · {formatCreated(m.created_at)}
                    </div>
                  </div>
                  <div className="text-right flex-shrink-0">
                    <div className="text-emerald-700 font-extrabold text-sm">
                      {Number(m.available_points).toLocaleString()} <span className="text-[11px] font-normal text-gray-400">แต้ม</span>
                    </div>
                  </div>
                  <div className="flex gap-1 flex-shrink-0">
                    <button
                      type="button"
                      onClick={() => openAdd(m.id, phone, name)}
                      title="เพิ่มแต้ม"
                      className="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-medium"
                    >
                      ★
                    </button>
                    <button
                      type="button"
                      onClick={() => setDetailUserId(m.id)}
                      title="ดูประวัติแต้ม"
                      className="px-2.5 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-xs font-medium"
                    >
                      🧾
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
      {members.length >= 300 ? <p className="text-[11px] text-gray-400 text-center mt-2">แสดง 300 รายการล่าสุด — ใช้ค้นหาเพื่อกรองเพิ่ม</p> : null}

      <AddPointsModal target={addTarget} onClose={() => setAddTarget(null)} />
      <MemberDetailModal
        userId={detailUserId}
        onClose={() => setDetailUserId(null)}
        onAddPoints={(target) => {
          setDetailUserId(null);
          setAddTarget(target);
        }}
      />
    </>
  );
}

function formatCreated(value: Date | string | null): string {
  if (!value) return '-';
  const date = typeof value === 'string' ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return '-';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok',
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  return `${get('day')}/${get('month')}/${get('year')} ${get('hour')}:${get('minute')}`;
}
