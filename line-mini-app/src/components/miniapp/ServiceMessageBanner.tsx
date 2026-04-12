'use client'

import { Bell, X } from 'lucide-react'
import { useState } from 'react'
import { useLineContext } from '@/components/providers'
import { useHaptic } from '@/lib/hooks'
import { useToast } from '@/lib/toast'
import { saveNotificationPreference } from '@/lib/service-messages'

export function ServiceMessageBanner() {
  const line = useLineContext()
  const haptic = useHaptic()
  const { toast } = useToast()
  const [dismissed, setDismissed] = useState(false)
  const [isLoading, setIsLoading] = useState(false)
  const [enabled, setEnabled] = useState(false)

  if (dismissed || !line.profile?.userId) return null

  async function handleOptIn() {
    haptic('medium')
    setIsLoading(true)
    try {
      await saveNotificationPreference(line.profile!.userId, true)
      setEnabled(true)
      toast.success('เปิดรับการแจ้งเตือนแล้ว!')
      setTimeout(() => setDismissed(true), 2000)
    } catch {
      toast.error('เกิดข้อผิดพลาด กรุณาลองใหม่')
    } finally {
      setIsLoading(false)
    }
  }

  if (enabled) {
    return (
      <div className="flex animate-fade-in items-center gap-3 rounded-2xl bg-line-soft px-4 py-3">
        <Bell size={15} className="shrink-0 text-line" />
        <p className="flex-1 text-sm font-semibold text-line-dark">เปิดรับการแจ้งเตือนแล้ว ✓</p>
      </div>
    )
  }

  return (
    <div className="animate-fade-in relative overflow-hidden rounded-2xl border border-line/20 bg-line-soft px-4 py-3.5">
      <button
        onClick={() => setDismissed(true)}
        className="absolute right-2 top-2 p-1 text-slate-400 hover:text-slate-600"
      >
        <X size={14} />
      </button>
      <div className="flex items-center gap-3 pr-6">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-line/15">
          <Bell size={17} className="text-line-dark" />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-slate-800">รับแจ้งเตือนออเดอร์ & แต้ม</p>
          <p className="text-xs text-slate-500">รับข่าวสารผ่าน LINE ทันที</p>
        </div>
        <button
          onClick={() => void handleOptIn()}
          disabled={isLoading}
          className="shrink-0 rounded-xl bg-line px-3 py-2 text-xs font-bold text-white shadow-soft transition-all active:scale-95 disabled:opacity-50"
        >
          {isLoading ? '...' : 'เปิด'}
        </button>
      </div>
    </div>
  )
}
