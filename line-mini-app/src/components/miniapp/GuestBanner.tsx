'use client'

import { LogIn } from 'lucide-react'
import { useLineContext } from '@/components/providers'

export function GuestBanner() {
  const line = useLineContext()

  if (line.isLoggedIn) return null

  function handleLogin() {
    import('@line/liff').then((liff) => {
      liff.default.login()
    })
  }

  return (
    <div className="shrink-0 border-b border-amber-200 bg-amber-50 px-4 py-2.5">
      <div className="mx-auto flex max-w-md items-center justify-between gap-3">
        <p className="text-xs text-amber-800">
          กำลังดูในฐานะ <span className="font-semibold">ผู้เยี่ยมชม</span> — บางฟีเจอร์ต้องเข้าสู่ระบบ
        </p>
        <button
          type="button"
          onClick={handleLogin}
          className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-amber-700 active:scale-[0.97]"
        >
          <LogIn size={12} />
          เข้าสู่ระบบ
        </button>
      </div>
    </div>
  )
}
