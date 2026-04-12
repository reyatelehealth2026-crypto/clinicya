'use client'

import { Share2, Users } from 'lucide-react'
import { useHaptic } from '@/lib/hooks'
import { useToast } from '@/lib/toast'
import { shareMessagesOnMiniApp } from '@/lib/line-miniapp'

interface ReferralCardProps {
  memberId: string
  displayName: string
}

export function ReferralCard({ memberId, displayName }: ReferralCardProps) {
  const haptic = useHaptic()
  const { toast } = useToast()
  const referralLink = typeof window !== 'undefined'
    ? `${window.location.origin}/?ref=${memberId}`
    : `/?ref=${memberId}`

  async function handleShare() {
    haptic('medium')
    const text = `สวัสดี! ${displayName} แนะนำให้รู้จัก Re-Ya\n\nใช้รหัส: ${memberId}\nลิงก์: ${referralLink}\n\nสมัครแล้วรับแต้มพิเศษ! 🎁`
    try {
      await shareMessagesOnMiniApp([{ type: 'text', text }], text)
      toast.success('แชร์รหัสสำเร็จ!')
    } catch {
      try {
        await navigator.clipboard.writeText(referralLink)
        toast.info('คัดลอกลิงก์แล้ว')
      } catch {
        toast.error('ไม่สามารถแชร์ได้')
      }
    }
  }

  return (
    <section className="animate-fade-in overflow-hidden rounded-3xl bg-white shadow-card">
      <div className="gradient-card-dark flex items-center gap-3 px-5 py-4">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/15">
          <Users size={20} className="text-white" />
        </div>
        <div>
          <p className="text-xs font-medium text-white/60">โปรแกรมแนะนำเพื่อน</p>
          <p className="text-sm font-bold text-white">แนะนำเพื่อน รับ 50 แต้ม</p>
        </div>
      </div>
      <div className="px-5 py-4">
        <p className="mb-2 text-xs font-medium text-slate-500">รหัสแนะนำของคุณ</p>
        <div className="flex items-center gap-3">
          <div className="flex-1 rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-3">
            <p className="font-mono text-base font-bold tracking-widest text-slate-800">{memberId}</p>
          </div>
          <button
            onClick={() => void handleShare()}
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-line text-white shadow-soft transition-transform active:scale-95"
          >
            <Share2 size={18} />
          </button>
        </div>
        <p className="mt-3 text-xs text-slate-400">
          ทุกคนที่สมัครด้วยรหัสของคุณ คุณได้รับ <span className="font-semibold text-line">50 แต้ม</span>
        </p>
      </div>
    </section>
  )
}
