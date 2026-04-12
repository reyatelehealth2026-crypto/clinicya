'use client'

import { useEffect, useState } from 'react'
import { Clock, Zap } from 'lucide-react'

interface FlashSaleCountdownProps {
  endsAt: Date | string
  title: string
  discount: string
  onExpired?: () => void
}

function formatTime(totalSec: number) {
  const h = Math.floor(totalSec / 3600)
  const m = Math.floor((totalSec % 3600) / 60)
  const s = totalSec % 60
  return { h, m, s }
}

export function FlashSaleCountdown({ endsAt, title, discount, onExpired }: FlashSaleCountdownProps) {
  const [secondsLeft, setSecondsLeft] = useState(() =>
    Math.max(0, Math.floor((new Date(endsAt).getTime() - Date.now()) / 1000))
  )

  useEffect(() => {
    if (secondsLeft <= 0) { onExpired?.(); return }
    const id = setInterval(() => {
      setSecondsLeft(prev => {
        if (prev <= 1) { onExpired?.(); return 0 }
        return prev - 1
      })
    }, 1000)
    return () => clearInterval(id)
  }, [secondsLeft, onExpired])

  if (secondsLeft <= 0) return null

  const { h, m, s } = formatTime(secondsLeft)

  return (
    <section className="animate-fade-in overflow-hidden rounded-3xl bg-gradient-to-r from-orange-500 to-red-500 p-4 text-white shadow-card">
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/20">
          <Zap size={18} className="text-white" />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-bold uppercase tracking-widest text-white/70">Flash Sale</p>
          <p className="truncate text-sm font-bold leading-tight">{title}</p>
          <p className="text-base font-extrabold tracking-tight">{discount}</p>
        </div>
        <div className="flex shrink-0 items-center gap-1.5 rounded-2xl bg-white/15 px-3 py-2 backdrop-blur-sm">
          <Clock size={12} className="text-white/80" />
          <span className="font-mono text-sm font-bold tabular-nums">
            {h > 0 && `${String(h).padStart(2, '0')}:`}
            {String(m).padStart(2, '0')}:{String(s).padStart(2, '0')}
          </span>
        </div>
      </div>
    </section>
  )
}
