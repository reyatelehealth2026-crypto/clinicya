'use client';

import { useEffect, useState } from 'react';
import { Modal } from '@/components/Modal';
import { savePharmacistAction, type PharmacistSchedulesForm, type SavePharmacistInput } from '../actions';
import { DAY_NAMES_TH, toHHMM } from '../_lib/format';
import type { PharmacistRow } from '../queries';

/**
 * PharmacistFormModal.tsx — client port of pharmacists.php's
 * `#pharmacistModal` + `openPharmacistModal()`/`editPharmacist()` inline
 * script (lines 244-338, 369-422). One shared Modal (apps/admin's
 * components/Modal.tsx) handles both "เพิ่มเภสัชกร" (create) and
 * "แก้ไขเภสัชกร" (edit), matching the PHP source's single `<form
 * method="POST">` whose hidden `action`/`id` fields get swapped by JS
 * depending on which button opened it — including the 7-day schedule input
 * grid (lines 317-330).
 */
export interface PharmacistModalTarget {
  mode: 'create' | 'edit';
  pharmacist?: PharmacistRow;
}

interface FormState {
  title: string;
  name: string;
  specialty: string;
  licenseNo: string;
  hospital: string;
  bio: string;
  imageUrl: string;
  consultationFee: string;
  consultationDuration: string;
  isAvailable: boolean;
  isActive: boolean;
  schedules: PharmacistSchedulesForm;
}

function emptySchedules(): PharmacistSchedulesForm {
  const schedules: PharmacistSchedulesForm = {};
  for (let i = 0; i < 7; i++) {
    schedules[i] = { start: '', end: '' };
  }
  return schedules;
}

const EMPTY_FORM: FormState = {
  title: 'ภก.',
  name: '',
  specialty: '',
  licenseNo: '',
  hospital: '',
  bio: '',
  imageUrl: '',
  consultationFee: '0',
  consultationDuration: '15',
  isAvailable: true,
  isActive: true,
  schedules: emptySchedules(),
};

function formFromPharmacist(p: PharmacistRow): FormState {
  const schedules = emptySchedules();
  for (const s of p.schedules) {
    schedules[s.dayOfWeek] = { start: toHHMM(s.startTime), end: toHHMM(s.endTime) };
  }
  return {
    title: p.title || 'ภก.',
    name: p.name,
    specialty: p.specialty || '',
    licenseNo: p.licenseNo || '',
    hospital: p.hospital || '',
    bio: p.bio || '',
    imageUrl: p.imageUrl || '',
    consultationFee: String(p.consultationFee || 0),
    consultationDuration: String(p.consultationDuration || 15),
    isAvailable: p.isAvailable === 1,
    isActive: p.isActive === 1,
    schedules,
  };
}

const FIELD_LABEL = 'block text-sm font-medium text-gray-800 mb-1';
const FIELD_INPUT = 'w-full px-4 py-2 border rounded-lg text-sm';

export function PharmacistFormModal({
  target,
  onClose,
  onSaved,
}: {
  target: PharmacistModalTarget | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!target) return;
    if (target.mode === 'edit' && target.pharmacist) {
      setForm(formFromPharmacist(target.pharmacist));
    } else {
      setForm(EMPTY_FORM);
    }
  }, [target]);

  if (!target) {
    return null;
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      const input: SavePharmacistInput = {
        id: target?.mode === 'edit' ? target.pharmacist?.id : undefined,
        title: form.title,
        name: form.name,
        specialty: form.specialty,
        licenseNo: form.licenseNo,
        hospital: form.hospital,
        bio: form.bio,
        imageUrl: form.imageUrl,
        consultationFee: form.consultationFee,
        consultationDuration: form.consultationDuration,
        isAvailable: form.isAvailable,
        isActive: form.isActive,
        schedules: form.schedules,
      };
      await savePharmacistAction(input);
      onSaved();
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={target.mode === 'edit' ? 'แก้ไขเภสัชกร' : 'เพิ่มเภสัชกร'}
      size="lg"
      footer={
        <>
          <button
            type="button"
            onClick={onClose}
            className="flex-1 py-2 border rounded-lg hover:bg-gray-50 text-sm font-medium"
          >
            ยกเลิก
          </button>
          <button
            type="submit"
            form="pharmacist-form"
            disabled={submitting}
            className="flex-1 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm font-medium disabled:opacity-60"
          >
            บันทึก
          </button>
        </>
      }
    >
      <form id="pharmacist-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label htmlFor="pharm-title" className={FIELD_LABEL}>
              คำนำหน้า
            </label>
            <select
              id="pharm-title"
              className={FIELD_INPUT}
              value={form.title}
              onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
            >
              <option value="ภก.">ภก. (ชาย)</option>
              <option value="ภญ.">ภญ. (หญิง)</option>
              <option value="ดร.">ดร.</option>
              <option value="">ไม่ระบุ</option>
            </select>
          </div>
          <div>
            <label htmlFor="pharm-name" className={FIELD_LABEL}>
              ชื่อ-นามสกุล *
            </label>
            <input
              id="pharm-name"
              type="text"
              required
              className={FIELD_INPUT}
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            />
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label htmlFor="pharm-specialty" className={FIELD_LABEL}>
              ความเชี่ยวชาญ
            </label>
            <input
              id="pharm-specialty"
              type="text"
              placeholder="เช่น เภสัชกรคลินิก"
              className={FIELD_INPUT}
              value={form.specialty}
              onChange={(e) => setForm((f) => ({ ...f, specialty: e.target.value }))}
            />
          </div>
          <div>
            <label htmlFor="pharm-license" className={FIELD_LABEL}>
              เลขใบอนุญาต
            </label>
            <input
              id="pharm-license"
              type="text"
              className={FIELD_INPUT}
              value={form.licenseNo}
              onChange={(e) => setForm((f) => ({ ...f, licenseNo: e.target.value }))}
            />
          </div>
        </div>

        <div>
          <label htmlFor="pharm-hospital" className={FIELD_LABEL}>
            สถานที่ทำงาน
          </label>
          <input
            id="pharm-hospital"
            type="text"
            placeholder="เช่น โรงพยาบาล, คลินิก, ร้านยา"
            className={FIELD_INPUT}
            value={form.hospital}
            onChange={(e) => setForm((f) => ({ ...f, hospital: e.target.value }))}
          />
        </div>

        <div>
          <label htmlFor="pharm-bio" className={FIELD_LABEL}>
            ประวัติย่อ
          </label>
          <textarea
            id="pharm-bio"
            rows={2}
            placeholder="ประสบการณ์ ความเชี่ยวชาญ..."
            className={FIELD_INPUT}
            value={form.bio}
            onChange={(e) => setForm((f) => ({ ...f, bio: e.target.value }))}
          />
        </div>

        <div>
          <label htmlFor="pharm-image" className={FIELD_LABEL}>
            URL รูปภาพ
          </label>
          <input
            id="pharm-image"
            type="url"
            placeholder="https://..."
            className={FIELD_INPUT}
            value={form.imageUrl}
            onChange={(e) => setForm((f) => ({ ...f, imageUrl: e.target.value }))}
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label htmlFor="pharm-fee" className={FIELD_LABEL}>
              ค่าปรึกษา (บาท)
            </label>
            <input
              id="pharm-fee"
              type="number"
              min={0}
              className={FIELD_INPUT}
              value={form.consultationFee}
              onChange={(e) => setForm((f) => ({ ...f, consultationFee: e.target.value }))}
            />
          </div>
          <div>
            <label htmlFor="pharm-duration" className={FIELD_LABEL}>
              ระยะเวลาต่อครั้ง (นาที)
            </label>
            <input
              id="pharm-duration"
              type="number"
              min={5}
              className={FIELD_INPUT}
              value={form.consultationDuration}
              onChange={(e) => setForm((f) => ({ ...f, consultationDuration: e.target.value }))}
            />
          </div>
        </div>

        <div className="flex gap-4">
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              className="w-4 h-4"
              checked={form.isAvailable}
              onChange={(e) => setForm((f) => ({ ...f, isAvailable: e.target.checked }))}
            />
            <span>พร้อมให้บริการ</span>
          </label>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              className="w-4 h-4"
              checked={form.isActive}
              onChange={(e) => setForm((f) => ({ ...f, isActive: e.target.checked }))}
            />
            <span>เปิดใช้งาน</span>
          </label>
        </div>

        <div>
          <label className="block text-sm font-medium mb-2">ตารางเวลาทำงาน</label>
          <div className="space-y-2">
            {DAY_NAMES_TH.map((day, i) => (
              <div key={i} className="flex items-center gap-2">
                <span className="w-20 text-sm">{day}</span>
                <input
                  type="time"
                  aria-label={`${day} เริ่ม`}
                  className="px-3 py-1 border rounded text-sm"
                  value={form.schedules[i]?.start ?? ''}
                  onChange={(e) =>
                    setForm((f) => ({ ...f, schedules: { ...f.schedules, [i]: { ...f.schedules[i]!, start: e.target.value } } }))
                  }
                />
                <span>-</span>
                <input
                  type="time"
                  aria-label={`${day} สิ้นสุด`}
                  className="px-3 py-1 border rounded text-sm"
                  value={form.schedules[i]?.end ?? ''}
                  onChange={(e) =>
                    setForm((f) => ({ ...f, schedules: { ...f.schedules, [i]: { ...f.schedules[i]!, end: e.target.value } } }))
                  }
                />
              </div>
            ))}
          </div>
          <p className="text-xs text-gray-500 mt-1">เว้นว่างถ้าไม่ทำงานวันนั้น</p>
        </div>
      </form>
    </Modal>
  );
}
