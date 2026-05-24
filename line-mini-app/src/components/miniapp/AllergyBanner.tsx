'use client'

import { AlertCircle, X } from 'lucide-react'

/**
 * Props for {@link AllergyBanner}.
 *
 * @property allergies - Drug names the user is known to be allergic to. Empty
 *                       arrays cause the component to render nothing.
 * @property onClose - Fired when the user dismisses the banner (purely visual
 *                     — parent decides whether to remember the dismissal).
 */
interface AllergyBannerProps {
  allergies: string[]
  onClose?: () => void
}

/**
 * Thin sticky banner reminding the user (and the AI in the same DOM) about
 * known drug allergies. Mirrors `ai-chat.js:1796-1815` but ported to Tailwind.
 */
export function AllergyBanner({ allergies, onClose }: AllergyBannerProps) {
  if (allergies.length === 0) return null

  const display = allergies.join(', ')

  return (
    <div
      role="alert"
      aria-live="polite"
      className="sticky top-0 z-20 w-full bg-amber-50 border-b border-amber-200 px-3 py-2 flex items-start gap-2 shadow-sm"
    >
      <AlertCircle
        className="w-4 h-4 text-amber-600 shrink-0 mt-0.5"
        aria-hidden="true"
      />
      <div className="flex-1 min-w-0 text-xs leading-relaxed">
        <span className="font-semibold text-amber-800">ข้อมูลการแพ้ยาของคุณ: </span>
        <span className="text-amber-900 break-words">{display}</span>
      </div>
      {onClose ? (
        <button
          type="button"
          onClick={onClose}
          aria-label="ปิดข้อความเตือนการแพ้ยา"
          className="shrink-0 -mr-1 -mt-1 p-1 rounded-full text-amber-700 hover:bg-amber-100 active:scale-95"
        >
          <X className="w-3.5 h-3.5" aria-hidden="true" />
        </button>
      ) : null}
    </div>
  )
}
