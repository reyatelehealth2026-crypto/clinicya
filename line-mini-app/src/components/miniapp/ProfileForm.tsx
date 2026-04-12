'use client'

import { type ChangeEvent, type FormEvent, useMemo, useState } from 'react'
import { Save, Sparkles, User, Phone, Mail, Calendar, MapPin } from 'lucide-react'
import type { MemberProfile, MemberUpdatePayload } from '@/types/member'

type ProfileFormState = {
  first_name: string
  last_name: string
  phone: string
  email: string
  birthday: string
  gender: string
  address: string
  district: string
  province: string
  postal_code: string
}

type ProfileFormProps = {
  member: MemberProfile
  lineUserId: string
  onSubmit: (payload: MemberUpdatePayload) => Promise<void>
  onQuickFill?: () => Promise<void>
  quickFillDisabled?: boolean
}

export function ProfileForm({ member, lineUserId, onSubmit, onQuickFill, quickFillDisabled }: ProfileFormProps) {
  const initialState = useMemo<ProfileFormState>(
    () => ({
      first_name: member.first_name || '',
      last_name: member.last_name || '',
      phone: member.phone || '',
      email: member.email || '',
      birthday: member.birthday || '',
      gender: member.gender || '',
      address: member.address || '',
      district: member.district || '',
      province: member.province || '',
      postal_code: member.postal_code || ''
    }),
    [member]
  )

  const [form, setForm] = useState(initialState)
  const [saving, setSaving] = useState(false)

  function setField(field: keyof ProfileFormState) {
    return (event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
      const value = event.target.value
      setForm((prev: ProfileFormState) => ({ ...prev, [field]: value }))
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    try {
      await onSubmit({ line_user_id: lineUserId, ...form })
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="animate-slide-up rounded-3xl bg-white shadow-soft">
      <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <h3 className="text-base font-bold text-slate-900">แก้ไขข้อมูลส่วนตัว</h3>
        {onQuickFill ? (
          <button
            type="button"
            onClick={() => void onQuickFill()}
            disabled={quickFillDisabled}
            className="flex items-center gap-1.5 rounded-full bg-line-soft px-3 py-1.5 text-xs font-semibold text-line transition-colors hover:bg-line-muted disabled:cursor-not-allowed disabled:opacity-50"
          >
            <Sparkles size={12} />
            Auto-fill
          </button>
        ) : null}
      </div>

      <div className="space-y-5 px-5 py-5">
        <fieldset>
          <legend className="section-title mb-3 flex items-center gap-1.5"><User size={12} /> ข้อมูลทั่วไป</legend>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">ชื่อ</label>
              <input value={form.first_name} onChange={setField('first_name')} placeholder="ชื่อจริง" className="input-field" />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">นามสกุล</label>
              <input value={form.last_name} onChange={setField('last_name')} placeholder="นามสกุล" className="input-field" />
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend className="section-title mb-3 flex items-center gap-1.5"><Phone size={12} /> ติดต่อ</legend>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">เบอร์โทร</label>
              <input value={form.phone} onChange={setField('phone')} placeholder="08x-xxx-xxxx" type="tel" className="input-field" />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">อีเมล</label>
              <input value={form.email} onChange={setField('email')} placeholder="email@example.com" type="email" className="input-field" />
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend className="section-title mb-3 flex items-center gap-1.5"><Calendar size={12} /> ข้อมูลเพิ่มเติม</legend>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">วันเกิด</label>
              <input type="date" value={form.birthday} onChange={setField('birthday')} className="input-field" />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">เพศ</label>
              <select value={form.gender} onChange={setField('gender')} className="input-field">
                <option value="">เลือกเพศ</option>
                <option value="male">ชาย</option>
                <option value="female">หญิง</option>
                <option value="other">อื่น ๆ</option>
              </select>
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend className="section-title mb-3 flex items-center gap-1.5"><MapPin size={12} /> ที่อยู่</legend>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <label className="mb-1.5 block text-xs font-medium text-slate-500">ที่อยู่</label>
              <input value={form.address} onChange={setField('address')} placeholder="บ้านเลขที่ ซอย ถนน" className="input-field" />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">อำเภอ/เขต</label>
              <input value={form.district} onChange={setField('district')} placeholder="อำเภอ/เขต" className="input-field" />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">จังหวัด</label>
              <input value={form.province} onChange={setField('province')} placeholder="จังหวัด" className="input-field" />
            </div>
            <div className="sm:col-span-2">
              <label className="mb-1.5 block text-xs font-medium text-slate-500">รหัสไปรษณีย์</label>
              <input value={form.postal_code} onChange={setField('postal_code')} placeholder="10xxx" className="input-field" />
            </div>
          </div>
        </fieldset>
      </div>

      <div className="border-t border-slate-100 px-5 py-4">
        <button type="submit" disabled={saving} className="btn-primary w-full">
          <Save size={16} />
          {saving ? 'กำลังบันทึก...' : 'บันทึกข้อมูล'}
        </button>
      </div>
    </form>
  )
}
