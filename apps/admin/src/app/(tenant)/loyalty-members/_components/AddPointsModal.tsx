'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { giveByPhoneAction } from '../actions';

/**
 * AddPointsModal.tsx — client port of loyalty-members.php's #lmAddModal +
 * `lmOpenAdd()`/`lmSubmitAdd()` inline-script pair (lines 153-189, 220-275).
 * Calls the giveByPhoneAction Server Action instead of `fetch('api/
 * points-claim.php', {action:'give_by_phone'})`; same field-level validation
 * order (`phone.length < 8` first, then "amount or points" check) and the
 * same "reload the list on success" net effect (the action itself calls
 * revalidatePath('/loyalty-members') — this component just closes itself).
 */
export interface AddPointsTarget {
  userId?: number;
  phone?: string;
  name?: string;
}

export function AddPointsModal({ target, onClose }: { target: AddPointsTarget | null; onClose: () => void }) {
  const router = useRouter();
  const [phone, setPhone] = useState('');
  const [name, setName] = useState('');
  const [amount, setAmount] = useState('');
  const [points, setPoints] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const isExisting = Boolean(target?.userId);

  useEffect(() => {
    if (!target) return;
    setPhone(target.phone ?? '');
    setName('');
    setAmount('');
    setPoints('');
    setError('');
  }, [target]);

  if (!target) return null;

  async function handleSubmit() {
    if (!target) return;
    setError('');
    const digits = phone.replace(/\D+/g, '');
    if (digits.length < 8) {
      setError('กรุณากรอกเบอร์ให้ถูกต้อง');
      return;
    }
    const amountNum = Number.parseFloat(amount || '0');
    const pointsNum = Number.parseInt(points || '0', 10);
    if (!(amountNum > 0) && !(pointsNum > 0)) {
      setError('กรุณากรอกยอดเงินหรือแต้มอย่างน้อยหนึ่งช่อง');
      return;
    }

    setSubmitting(true);
    try {
      const result = await giveByPhoneAction({
        phone: digits,
        userId: target.userId ? String(target.userId) : '',
        name,
        amount: amountNum > 0 ? String(amountNum) : '',
        points: pointsNum > 0 ? String(pointsNum) : '',
      });
      if (!result.success) {
        setError(result.message || 'ให้แต้มไม่สำเร็จ');
        setSubmitting(false);
        return;
      }
      onClose();
      // Mirrors loyalty-members.php's `location.reload()` — refresh the server-rendered
      // member list + overview stat cards (the Server Action already revalidated the path).
      router.refresh();
    } catch {
      setError('เกิดข้อผิดพลาดในการเชื่อมต่อ');
      setSubmitting(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-3" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div className="p-3 border-b flex justify-between items-center bg-emerald-50">
          <h3 className="font-bold text-sm text-emerald-700">
            {isExisting ? `เพิ่มแต้ม · ${target.name || target.phone || ''}` : 'เพิ่มแต้ม / ลูกค้าใหม่'}
          </h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600" aria-label="ปิด">
            ×
          </button>
        </div>
        <div className="p-4">
          <div className="mb-3">
            <label className="block text-xs font-medium text-gray-600 mb-1">เบอร์โทร</label>
            <input
              type="tel"
              inputMode="numeric"
              value={phone}
              readOnly={isExisting}
              onChange={(e) => setPhone(e.target.value)}
              className="w-full border rounded-lg px-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none"
              placeholder="08x-xxx-xxxx"
            />
          </div>
          {!isExisting ? (
            <div className="mb-3">
              <label className="block text-xs font-medium text-gray-600 mb-1">
                ชื่อลูกค้า <span className="text-gray-400">(ไม่บังคับ)</span>
              </label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full border rounded-lg px-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none"
                placeholder="ชื่อลูกค้า"
              />
            </div>
          ) : null}
          <div className="mb-3">
            <label className="block text-xs font-medium text-gray-600 mb-1">ยอดเงิน (฿)</label>
            <input
              type="number"
              min={0}
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className="w-full border rounded-lg px-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none"
              placeholder="เช่น 250"
            />
          </div>
          <div className="flex items-center gap-2 my-2">
            <div className="flex-1 border-t border-gray-200" />
            <span className="text-xs text-gray-400">หรือ</span>
            <div className="flex-1 border-t border-gray-200" />
          </div>
          <div className="mb-3">
            <label className="block text-xs font-medium text-gray-600 mb-1">แต้มที่จะให้ (ระบุเอง)</label>
            <input
              type="number"
              min={0}
              step="1"
              value={points}
              onChange={(e) => setPoints(e.target.value)}
              className="w-full border rounded-lg px-3 py-2 text-sm focus:ring-1 focus:ring-emerald-500 outline-none"
              placeholder="เช่น 10"
            />
          </div>
          {error ? <div className="text-xs text-red-600 mb-2">{error}</div> : null}
        </div>
        <div className="p-3 border-t bg-gray-50">
          <button
            type="button"
            disabled={submitting}
            onClick={handleSubmit}
            className="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-3 rounded-lg font-bold text-sm disabled:opacity-60"
          >
            {submitting ? 'กำลังให้แต้ม...' : 'ให้แต้ม'}
          </button>
        </div>
      </div>
    </div>
  );
}
