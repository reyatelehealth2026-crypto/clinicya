'use client'

import { useMemo, useState } from 'react'
import { Copy, Send, Sparkles, X } from 'lucide-react'
import { shareMessagesOnMiniApp } from '@/lib/line-miniapp'
import { buildRewardShareMessage, buildRewardShareText } from '@/lib/reward-share'
import type { RewardItem } from '@/types/rewards'

type RewardShareSheetProps = {
  reward: RewardItem | null
  onClose: () => void
}

function stockLabel(reward: RewardItem) {
  if (reward.stock === null || reward.stock === undefined || reward.stock < 0) {
    return 'พร้อมแลกในมินิแอป'
  }
  return reward.stock === 0 ? 'ของรางวัลหมดแล้ว' : `เหลือ ${reward.stock} รายการ`
}

export function RewardShareSheet({ reward, onClose }: RewardShareSheetProps) {
  const [sharing, setSharing] = useState(false)
  const [copying, setCopying] = useState(false)
  const [message, setMessage] = useState<string | null>(null)

  const shareText = useMemo(() => (reward ? buildRewardShareText(reward) : ''), [reward])
  const shareMessage = useMemo(() => (reward ? buildRewardShareMessage(reward) : null), [reward])

  if (!reward) {
    return null
  }

  async function handleShare() {
    if (!shareMessage) {
      return
    }
    setSharing(true)
    setMessage(null)
    try {
      const channel = await shareMessagesOnMiniApp([shareMessage], shareText)
      setMessage(channel === 'line' ? 'เปิดหน้าต่างแชร์ใน LINE แล้ว' : 'เปิดตัวเลือกแชร์ของอุปกรณ์แล้ว')
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
      setMessage('คัดลอกข้อความแนะนำของรางวัลแล้ว')
    } catch {
      setMessage('คัดลอกไม่สำเร็จ')
    } finally {
      setCopying(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/45 p-4 backdrop-blur-[2px] sm:items-center">
      <button type="button" aria-label="Close share sheet" className="absolute inset-0" onClick={onClose} />
      <div className="relative z-10 w-full max-w-md overflow-hidden rounded-[2rem] bg-white shadow-2xl">
        <div className="relative overflow-hidden border-b border-emerald-100 bg-[radial-gradient(circle_at_top_right,_rgba(16,185,129,0.24),_transparent_42%),linear-gradient(135deg,#0f172a_0%,#111827_40%,#064e3b_100%)] px-5 pb-5 pt-4 text-white">
          <button
            type="button"
            onClick={onClose}
            className="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full bg-white/12 text-white/90 transition-colors hover:bg-white/20"
          >
            <X size={16} />
          </button>
          <div className="inline-flex items-center gap-1 rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-100">
            <Sparkles size={12} />
            Reward Share
          </div>
          <h3 className="mt-4 text-xl font-bold leading-tight">แชร์ของรางวัลให้ดูน่าสนใจใน LINE</h3>
          <p className="mt-1.5 max-w-[22rem] text-sm text-emerald-50/80">
            ส่งเป็นการ์ดแนะนำของรางวัล พร้อมแต้มที่ใช้และรายละเอียดสำคัญได้ทันที
          </p>
        </div>

        <div className="space-y-4 px-5 py-5">
          <div className="overflow-hidden rounded-[1.75rem] border border-slate-100 bg-white shadow-[0_16px_50px_rgba(15,23,42,0.08)]">
            <div className="relative aspect-[5/3] overflow-hidden bg-slate-100">
              <img
                src={reward.image_url || 'https://placehold.co/600x360/f1f5f9/94a3b8?text=Reward'}
                alt={reward.name}
                className="h-full w-full object-cover"
              />
              <div className="absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-slate-950/70 to-transparent" />
              <div className="absolute left-3 top-3 rounded-full bg-white/92 px-3 py-1.5 text-xs font-bold text-emerald-600 shadow-sm">
                {reward.points_required.toLocaleString()} คะแนน
              </div>
              <div className="absolute bottom-3 left-3 right-3 flex items-end justify-between gap-3">
                <div className="rounded-2xl bg-white/14 px-3 py-2 backdrop-blur-md">
                  <p className="text-[11px] font-medium text-white/75">ไอเท็มแนะนำ</p>
                  <p className="mt-0.5 text-sm font-semibold text-white">{reward.name}</p>
                </div>
                <div className="rounded-full bg-slate-950/55 px-3 py-1 text-[11px] font-semibold text-white backdrop-blur-sm">
                  {stockLabel(reward)}
                </div>
              </div>
            </div>
            <div className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-500">Mini App Exclusive</p>
                  <h4 className="mt-1 text-base font-bold leading-snug text-slate-900">{reward.name}</h4>
                </div>
                <div className="rounded-2xl bg-emerald-50 px-3 py-2 text-right">
                  <p className="text-[11px] font-medium text-emerald-700">ใช้แต้ม</p>
                  <p className="text-sm font-bold text-emerald-600">{reward.points_required.toLocaleString()}</p>
                </div>
              </div>
              {reward.description ? <p className="text-sm leading-relaxed text-slate-600">{reward.description}</p> : null}
            </div>
          </div>

          <div className="rounded-2xl bg-slate-50 p-4">
            <p className="text-xs font-semibold text-slate-500">ข้อความแชร์สำรอง</p>
            <pre className="mt-2 whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-slate-700">{shareText}</pre>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <button type="button" onClick={handleShare} disabled={sharing} className="btn-primary w-full justify-center disabled:opacity-50">
              <Send size={16} />
              {sharing ? 'กำลังเปิดหน้าต่างแชร์...' : 'แชร์ให้เพื่อนดู'}
            </button>
            <button type="button" onClick={handleCopy} disabled={copying} className="btn-secondary w-full justify-center disabled:opacity-50">
              <Copy size={16} />
              {copying ? 'กำลังคัดลอก...' : 'คัดลอกข้อความ'}
            </button>
          </div>

          {message ? <p className="text-xs font-medium text-slate-500">{message}</p> : null}
        </div>
      </div>
    </div>
  )
}
