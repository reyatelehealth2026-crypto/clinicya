'use client'

import type { TriageOption } from '@/types/ai-chat'

interface TriageOptionsProps {
  options: TriageOption[]
  disabled?: boolean
  onSelect: (value: string, label: string) => void
}

/**
 * ปุ่มตอบ Yes/No หรือ multi-choice — render ใต้ message ของ AI
 * ขั้นต่ำ 44x44 px ตาม a11y mobile tap target.
 */
export function TriageOptions({ options, disabled, onSelect }: TriageOptionsProps) {
  if (options.length === 0) return null

  const isYesNo =
    options.length === 2 &&
    options.some((o) => o.value === 'yes') &&
    options.some((o) => o.value === 'no')

  return (
    <div className={`flex flex-wrap gap-2 mt-2 ${isYesNo ? '' : 'flex-col'}`}>
      {options.map((opt) => {
        const isYes = opt.value === 'yes'
        const isNo = opt.value === 'no'
        return (
          <button
            key={opt.value}
            type="button"
            disabled={disabled}
            onClick={() => onSelect(opt.value, opt.label)}
            className={[
              'min-h-[44px] px-4 py-2 rounded-full text-sm font-medium transition-all',
              'border active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed',
              isYes
                ? 'bg-green-50 border-green-300 text-green-700 hover:bg-green-100'
                : isNo
                  ? 'bg-rose-50 border-rose-300 text-rose-700 hover:bg-rose-100'
                  : 'bg-purple-50 border-purple-200 text-purple-700 hover:bg-purple-100'
            ].join(' ')}
            aria-label={opt.label}
          >
            {opt.label}
          </button>
        )
      })}
    </div>
  )
}
