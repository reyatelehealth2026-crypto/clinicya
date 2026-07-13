'use client';

import { useEffect, useState } from 'react';
import { memberDetailAction } from '../actions';
import type { MemberDetailResult } from '../_lib/pointsClaim';

/**
 * MemberDetailModal.tsx — client port of loyalty-members.php's
 * #lmDetailModal + `lmOpenDetail()` (lines 191-212, 277-320). Calls
 * memberDetailAction() (read-only Server Action) instead of `fetch('api/
 * points-claim.php', {action:'member_detail'})`.
 */
export function MemberDetailModal({
  userId,
  onClose,
  onAddPoints,
}: {
  userId: number | null;
  onClose: () => void;
  onAddPoints: (target: { userId: number; phone: string; name: string }) => void;
}) {
  const [data, setData] = useState<MemberDetailResult | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (userId === null) {
      setData(null);
      return;
    }
    setLoading(true);
    setData(null);
    memberDetailAction(userId)
      .then(setData)
      .finally(() => setLoading(false));
  }, [userId]);

  if (userId === null) return null;

  const customer = data?.customer;

  return (
    <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-3" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden max-h-[90vh] flex flex-col">
        <div className="p-3 border-b flex justify-between items-center bg-emerald-50 flex-shrink-0">
          <h3 className="font-bold text-sm text-emerald-700">ประวัติแต้ม</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600" aria-label="ปิด">
            ×
          </button>
        </div>
        <div className="p-4 overflow-y-auto flex-1">
          <div className="mb-3 p-3 rounded-lg bg-emerald-50 text-center">
            <div className="font-bold text-gray-800">{loading ? '...' : (customer?.name ?? '-')}</div>
            <div className="text-xs text-gray-500">
              {customer ? `📞 ${customer.phone}${customer.hasLine ? ' · มี LINE' : ' · ไม่มี LINE'}` : ''}
            </div>
            <div className="text-2xl font-extrabold text-emerald-600 mt-1">
              {(customer?.availablePoints ?? 0).toLocaleString()} <span className="text-sm font-normal text-gray-400">แต้ม</span>
            </div>
          </div>
          <div className="space-y-1.5 text-xs">
            {loading ? (
              <div className="text-center text-gray-400 py-3">กำลังโหลด...</div>
            ) : !data?.success ? (
              <div className="text-center text-red-500 py-3">{data?.message || 'โหลดไม่สำเร็จ'}</div>
            ) : !data.transactions || data.transactions.length === 0 ? (
              <div className="text-center text-gray-400 py-3">ยังไม่มีรายการแต้ม</div>
            ) : (
              data.transactions.map((t, i) => {
                const earn = t.type === 'earn' || t.points >= 0;
                const sign = earn ? '+' : '';
                const color = earn ? 'text-emerald-600' : 'text-red-500';
                const when = t.createdAt ? t.createdAt.slice(0, 16).replace('T', ' ') : '';
                return (
                  <div key={`${t.createdAt}-${i}`} className="flex items-center justify-between gap-2 p-2 rounded border border-gray-100">
                    <div className="min-w-0">
                      <div className="text-gray-700 truncate">{t.description || t.type}</div>
                      <div className="text-[10px] text-gray-400">{when}</div>
                    </div>
                    <div className="text-right whitespace-nowrap">
                      <div className={`font-bold ${color}`}>
                        {sign}
                        {t.points.toLocaleString()}
                      </div>
                      <div className="text-[10px] text-gray-400">คงเหลือ {t.balanceAfter.toLocaleString()}</div>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>
        <div className="p-3 border-t bg-gray-50 flex-shrink-0">
          <button
            type="button"
            disabled={!customer}
            onClick={() => customer && onAddPoints({ userId: customer.userId, phone: customer.phone, name: customer.name })}
            className="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-2.5 rounded-lg font-medium text-sm disabled:opacity-60"
          >
            เพิ่มแต้มให้ลูกค้านี้
          </button>
        </div>
      </div>
    </div>
  );
}
