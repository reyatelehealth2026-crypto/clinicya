'use client';

import { useState } from 'react';
import { Modal } from '@/components/Modal';
import { addHolidayAction, deleteHolidayAction } from '../actions';
import { formatHolidayDateTh, todayBangkokISO } from '../_lib/format';
import type { PharmacistRow } from '../queries';

/**
 * HolidayModal.tsx — client port of pharmacists.php's `#holidayModal` +
 * `openHolidayModal()`/`closeHolidayModal()` inline script (lines 340-363,
 * 429-462). PHP only ever shows the pharmacist's own top-5-upcoming
 * `holidays` array (server query, line 123's `LIMIT 5`) — this component
 * does the same, reading straight off the `pharmacist.holidays` prop rather
 * than re-fetching.
 *
 * UNLIKE the PHP page — where every `<form method="POST">` submit is a real
 * browser navigation that reloads pharmacy.php and, incidentally, closes
 * every modal (there is no sessionStorage/URL-param logic anywhere in the
 * source to reopen it) — this modal stays open across add/delete so the
 * pending appointment guard and multi-holiday entry flow don't force a
 * jarring reload. `onChanged()` (parent calls `router.refresh()`) refreshes
 * the underlying `pharmacists` data; since the parent re-derives `pharmacist`
 * from that fresh array by id every render, the list here updates in place.
 * This is a client-UX improvement over an incidental artifact of PHP's old
 * plain-form-POST mechanism, not a change to persisted data/behavior.
 */
export function HolidayModal({
  pharmacist,
  onClose,
  onChanged,
}: {
  pharmacist: PharmacistRow | null;
  onClose: () => void;
  onChanged: () => void;
}) {
  const [holidayDate, setHolidayDate] = useState('');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);

  if (!pharmacist) {
    return null;
  }

  async function handleAdd(e: React.FormEvent) {
    e.preventDefault();
    if (!pharmacist || !holidayDate) return;
    setSubmitting(true);
    try {
      await addHolidayAction({ pharmacistId: pharmacist.id, holidayDate, reason });
      setHolidayDate('');
      setReason('');
      onChanged();
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete(holidayId: number) {
    if (!pharmacist) return;
    await deleteHolidayAction(holidayId, pharmacist.id);
    onChanged();
  }

  return (
    <Modal open onClose={onClose} title="จัดการวันหยุด" size="md" footer={<button type="button" onClick={onClose} className="flex-1 py-2 border rounded-lg hover:bg-gray-50 text-sm font-medium">ปิด</button>}>
      <p className="text-gray-600 mb-4">เภสัชกร: {pharmacist.name}</p>

      <form onSubmit={handleAdd} className="mb-4">
        <div className="flex gap-2 mb-2">
          <input
            type="date"
            required
            min={todayBangkokISO()}
            aria-label="วันที่หยุด"
            className="flex-1 px-4 py-2 border rounded-lg text-sm"
            value={holidayDate}
            onChange={(e) => setHolidayDate(e.target.value)}
          />
          <input
            type="text"
            placeholder="เหตุผล (ไม่บังคับ)"
            aria-label="เหตุผล"
            className="flex-1 px-4 py-2 border rounded-lg text-sm"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </div>
        <button
          type="submit"
          disabled={submitting}
          className="w-full py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 text-sm font-medium disabled:opacity-60"
        >
          + เพิ่มวันหยุด
        </button>
      </form>

      <div className="space-y-2 max-h-40 overflow-y-auto">
        {pharmacist.holidays.length === 0 ? (
          <p className="text-gray-500 text-center py-4">ไม่มีวันหยุดที่กำหนด</p>
        ) : (
          pharmacist.holidays.map((h) => (
            <div key={h.id} className="flex items-center justify-between p-2 bg-gray-50 rounded">
              <div>
                <span className="font-medium">{formatHolidayDateTh(h.holidayDate)}</span>
                {h.reason ? <span className="text-sm text-gray-500 ml-2">({h.reason})</span> : null}
              </div>
              <button type="button" onClick={() => handleDelete(h.id)} aria-label="ลบวันหยุด" className="text-red-500 hover:text-red-700">
                ×
              </button>
            </div>
          ))
        )}
      </div>
    </Modal>
  );
}
