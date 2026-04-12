'use client'

import { useMemo, useState } from 'react'
import { Copy, Send, Sparkles } from 'lucide-react'
import { appConfig } from '@/lib/config'
import { shareTextOnMiniApp } from '@/lib/line-miniapp'
import type { MemberProfile, TierInfo } from '@/types/member'

type MiniAppPerksCardProps = {
  member: MemberProfile
  tier: TierInfo
}

function buildShareText(member: MemberProfile, tier: TierInfo) {
  const displayName = member.display_name || [member.first_name, member.last_name].filter(Boolean).join(' ') || 'สมาชิก Re-Ya'
  const points = member.points.toLocaleString()
  return [
    `ฉันกำลังใช้งาน ${appConfig.miniAppName}`,
    `โปรไฟล์สมาชิก: ${displayName}`,
    `ระดับสมาชิก: ${tier.tier_name}`,
    `แต้มสะสม: ${points} คะแนน`,
    'เข้ามาดูสิทธิประโยชน์และติดตามออเดอร์ได้ใน LINE Mini App'
  ].join('\n')
}

export function MiniAppPerksCard({ member, tier }: MiniAppPerksCardProps) {
  const [sharing, setSharing] = useState(false)
  const [copying, setCopying] = useState(false)
  const [message, setMessage] = useState<string | null>(null)

  const shareText = useMemo(() => buildShareText(member, tier), [member, tier])

  async function handleShare() {
    setSharing(true)
    setMessage(null)
    try {
      const channel = await shareTextOnMiniApp(shareText)
      setMessage(channel === 'line' ? 'เปิดตัวเลือกแชร์ใน LINE แล้ว' : 'เปิดตัวเลือกแชร์ของอุปกรณ์แล้ว')
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'แชร์ไม่สำเร็จ')
    } finally {
      setSharing(false)
    }
  }

  async function handleCopy() {
    setCopying(true)
    setMessage(null)
    try {
      await navigator.clipboard.writeText(shareText)
      setMessage('คัดลอกข้อความแชร์แล้ว')
    } catch {
      setMessage('คัดลอกไม่สำเร็จ')
    } finally {
      setCopying(false)
    }
  }

  return (
    <section className="animate-slide-up overflow-hidden rounded-3xl bg-white shadow-soft">
      <div className="flex items-center gap-2 border-b border-slate-100 px-5 py-4">
        <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-line-soft">
          <Sparkles size={14} className="text-line" />
        </div>
        <div>
          <h3 className="text-sm font-bold text-slate-900">ลูกเล่นเฉพาะมินิแอป</h3>
          <p className="text-xs text-slate-500">แชร์โปรไฟล์สมาชิกผ่าน LINE หรือคัดลอกข้อความได้ทันที</p>
        </div>
      </div>

      <div className="space-y-4 px-5 py-5">
        <div className="rounded-2xl bg-slate-50 p-4">
          <p className="text-xs font-medium text-slate-500">ตัวอย่างข้อความแชร์</p>
          <pre className="mt-2 whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-slate-700">{shareText}</pre>
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <button type="button" onClick={handleShare} disabled={sharing} className="btn-primary w-full justify-center disabled:opacity-50">
            <Send size={16} />
            {sharing ? 'กำลังเปิดตัวเลือกแชร์...' : 'แชร์โปรไฟล์'}
          </button>
          <button type="button" onClick={handleCopy} disabled={copying} className="btn-secondary w-full justify-center disabled:opacity-50">
            <Copy size={16} />
            {copying ? 'กำลังคัดลอก...' : 'คัดลอกข้อความแชร์'}
          </button>
        </div>

        {message ? <p className="text-xs font-medium text-slate-500">{message}</p> : null}
      </div>
    </section>
  )
}
