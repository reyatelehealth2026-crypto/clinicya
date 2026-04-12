'use client'

import { useState } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { UserPlus } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { registerMember } from '@/lib/member-api'
import { useToast } from '@/lib/toast'

const inputClass =
  'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-line/30'

export function RegisterClient() {
  const line = useLineContext()
  const { toast } = useToast()
  const router = useRouter()
  const queryClient = useQueryClient()
  const lineUserId = line.profile?.userId || ''

  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [birthday, setBirthday] = useState('')
  const [gender, setGender] = useState('')

  const mutation = useMutation({
    mutationFn: () =>
      registerMember({
        line_user_id: lineUserId,
        first_name: firstName.trim(),
        last_name: lastName.trim() || undefined,
        phone: phone.trim() || undefined,
        email: email.trim() || undefined,
        birthday: birthday.trim(),
        gender: gender.trim(),
        display_name: line.profile?.displayName,
        picture_url: line.profile?.pictureUrl
      }),
    onSuccess: (data) => {
      if (!data.success) {
        toast.error(data.message || 'สมัครไม่สำเร็จ')
        return
      }
      toast.success(data.message || 'สมัครสมาชิกสำเร็จ')
      queryClient.invalidateQueries({ queryKey: ['member-check'] })
      queryClient.invalidateQueries({ queryKey: ['member-card'] })
      router.push('/profile')
    },
    onError: (e: Error) => {
      toast.error(e.message || 'เกิดข้อผิดพลาด')
    }
  })

  const onSubmit = () => {
    if (!firstName.trim()) {
      toast.warning('กรุณากรอกชื่อ')
      return
    }
    if (!birthday.trim()) {
      toast.warning('กรุณาเลือกวันเกิด')
      return
    }
    if (!gender) {
      toast.warning('กรุณาเลือกเพศ')
      return
    }
    mutation.mutate()
  }

  if (!lineUserId) {
    return (
      <AppShell title="สมัครสมาชิก" subtitle="สะสมแต้มและสิทธิพิเศษ">
        <VerifiedOnlyNotice title="ต้องเข้าสู่ระบบ LINE" description="เปิดจาก LINE เพื่อสมัครสมาชิก" />
      </AppShell>
    )
  }

  return (
    <AppShell title="สมัครสมาชิก" subtitle="กรอกข้อมูลให้ครบเพื่อรับสิทธิ์สมาชิก">
      <div className="mx-auto max-w-md space-y-4">
        <div className="flex flex-col items-center py-2 text-center">
          <div className="mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-line-soft">
            <UserPlus className="text-line" size={32} />
          </div>
          <h2 className="text-lg font-bold text-slate-900">สมัครสมาชิก</h2>
          <p className="mt-1 text-sm text-slate-500">ข้อมูลใช้เพื่อบริการและสะสมแต้มตามนโยบายร้าน</p>
        </div>

        {line.profile?.pictureUrl ? (
          <div className="flex items-center gap-3 rounded-2xl bg-white p-3 shadow-soft">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={line.profile.pictureUrl} alt="" className="h-12 w-12 rounded-full" />
            <div className="text-left">
              <p className="text-sm font-medium text-slate-900">{line.profile.displayName}</p>
              <p className="text-xs text-line">เชื่อมต่อ LINE แล้ว</p>
            </div>
          </div>
        ) : null}

        <div className="space-y-3 rounded-3xl bg-white p-4 shadow-soft">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">ชื่อจริง *</label>
            <input className={inputClass} value={firstName} onChange={(e) => setFirstName(e.target.value)} placeholder="ชื่อ" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">นามสกุล</label>
            <input className={inputClass} value={lastName} onChange={(e) => setLastName(e.target.value)} placeholder="นามสกุล" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">วันเกิด *</label>
            <input className={inputClass} type="date" value={birthday} onChange={(e) => setBirthday(e.target.value)} />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">เพศ *</label>
            <select className={inputClass} value={gender} onChange={(e) => setGender(e.target.value)}>
              <option value="">เลือก</option>
              <option value="male">ชาย</option>
              <option value="female">หญิง</option>
              <option value="other">ไม่ระบุ</option>
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">เบอร์โทร</label>
            <input className={inputClass} type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="0812345678" />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">อีเมล</label>
            <input className={inputClass} type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="email@example.com" />
          </div>
        </div>

        <button
          type="button"
          disabled={mutation.isPending}
          onClick={onSubmit}
          className="flex w-full items-center justify-center rounded-2xl bg-line py-3.5 text-sm font-semibold text-white disabled:opacity-60"
        >
          {mutation.isPending ? 'กำลังส่ง…' : 'ยืนยันสมัครสมาชิก'}
        </button>

        <Link href="/shop" className="block text-center text-sm text-slate-500 underline">
          ข้ามไปช้อปก่อน
        </Link>
      </div>
    </AppShell>
  )
}
