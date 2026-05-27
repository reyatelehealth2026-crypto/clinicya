'use client'

import { Activity } from 'lucide-react'
import type { TriageState } from '@/types/ai-chat'

/**
 * Props for {@link StateHeader}.
 *
 * @property state - Current triage_sessions.current_state (12 valid codes —
 *                   see `TriageState`). Falls back to `greeting` if absent.
 */
interface StateHeaderProps {
  state?: TriageState | null
}

/** Thai labels mirrored from `liff/assets/js/components/ai-chat.js:857-870` */
const STATE_LABELS: Record<TriageState, string> = {
  greeting: 'พร้อมให้บริการ',
  symptom: 'กำลังซักประวัติ...',
  duration: 'กำลังซักประวัติ...',
  severity: 'กำลังประเมินอาการ...',
  associated: 'กำลังซักประวัติ...',
  allergy: 'ตรวจสอบการแพ้ยา...',
  medical_history: 'ตรวจสอบประวัติ...',
  current_meds: 'ตรวจสอบยาที่ใช้...',
  recommend: 'กำลังแนะนำยา...',
  confirm: 'รอยืนยัน...',
  complete: 'เสร็จสิ้น',
  escalate: 'ส่งต่อเภสัชกร'
}

/**
 * Small chip-style header showing the active triage state with a pulsing dot
 * when work is in progress. Pure presentational — does not poll or fetch.
 */
export function StateHeader({ state }: StateHeaderProps) {
  const resolved: TriageState = state ?? 'greeting'
  const label = STATE_LABELS[resolved] ?? 'พร้อมให้บริการ'
  const isActive = resolved !== 'greeting' && resolved !== 'complete'
  const isEscalate = resolved === 'escalate'
  const isComplete = resolved === 'complete'

  const dotColor = isEscalate
    ? 'bg-rose-500'
    : isComplete
      ? 'bg-emerald-500'
      : isActive
        ? 'bg-purple-500'
        : 'bg-gray-400'

  const textColor = isEscalate
    ? 'text-rose-700'
    : isComplete
      ? 'text-emerald-700'
      : isActive
        ? 'text-purple-700'
        : 'text-gray-600'

  const bgColor = isEscalate
    ? 'bg-rose-50 border-rose-200'
    : isComplete
      ? 'bg-emerald-50 border-emerald-200'
      : isActive
        ? 'bg-purple-50 border-purple-200'
        : 'bg-gray-50 border-gray-200'

  return (
    <div
      role="status"
      aria-live="polite"
      className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium border ${bgColor} ${textColor}`}
    >
      <span className="relative inline-flex w-2 h-2 shrink-0">
        {isActive ? (
          <span className={`absolute inline-flex h-full w-full rounded-full opacity-60 animate-ping ${dotColor}`} />
        ) : null}
        <span className={`relative inline-flex rounded-full w-2 h-2 ${dotColor}`} />
      </span>
      <Activity className="w-3 h-3 opacity-70" aria-hidden="true" />
      <span>{label}</span>
    </div>
  )
}
