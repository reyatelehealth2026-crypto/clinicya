'use client'

import { useEffect, useState } from 'react'
import { Video } from 'lucide-react'
import { AppShell } from '@/components/miniapp/AppShell'

const LEGACY_VIDEO_CALL_URL = 'https://re-ya.com/liff/index.php?page=app#/video-call'

/**
 * Mini app `/video` page — เปิด video call room (legacy LIFF)
 * แทนที่ booking form เดิม เพื่อให้กดปุ่ม "ปรึกษาเภสัชกร" แล้วเข้า video room ทันที
 */
export function VideoRedirect() {
  const [target, setTarget] = useState<string>(LEGACY_VIDEO_CALL_URL)

  useEffect(() => {
    if (typeof window === 'undefined') return
    // ส่งต่อ appointment_id ถ้ามีใน query (กรณี deep link จาก booking)
    const params = new URLSearchParams(window.location.search)
    const appointmentId = params.get('appointment_id') || params.get('id')
    const url = appointmentId
      ? `https://re-ya.com/liff/index.php?page=app&appointment_id=${encodeURIComponent(appointmentId)}#/video-call`
      : LEGACY_VIDEO_CALL_URL
    setTarget(url)
    // Auto redirect หลัง 800ms (ให้ user เห็น loading state สั้นๆ)
    const timer = setTimeout(() => {
      window.location.href = url
    }, 800)
    return () => clearTimeout(timer)
  }, [])

  return (
    <AppShell title="ปรึกษาเภสัชกร" subtitle="กำลังเปิดวิดีโอคอล...">
      <div className="flex flex-col items-center justify-center gap-4 py-16 text-center">
        <div className="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50">
          <Video className="h-7 w-7 text-emerald-600" />
        </div>
        <div>
          <p className="text-sm font-semibold text-slate-700">กำลังเปิดห้องวิดีโอคอล</p>
          <p className="mt-1 text-xs text-slate-400">โปรดรอสักครู่...</p>
        </div>
        <div className="mt-2 flex items-center gap-2 text-xs text-slate-400">
          <div className="h-3 w-3 animate-spin rounded-full border-2 border-line border-t-transparent" />
          <span>กำลังโหลด</span>
        </div>
        <a
          href={target}
          className="mt-4 rounded-full bg-line px-5 py-2 text-sm font-semibold text-white"
        >
          เปิดด้วยตนเอง
        </a>
      </div>
    </AppShell>
  )
}
