'use client'

import { Bot, Video } from 'lucide-react'

/**
 * Props for {@link PharmacistConsultCTA}.
 *
 * @property reason - Short Thai-language explanation of why the AI suggests a
 *                    human pharmacist. Shown under the title.
 * @property videoCallUrl - Optional deep link the parent may navigate to (e.g.
 *                          `/miniapp/video?session=…`). The component does not
 *                          navigate on its own — it always defers to `onClick`.
 * @property onClick - Fired when the user taps the "ปรึกษาผ่าน Video Call"
 *                     button. Parent owns navigation logic.
 */
interface PharmacistConsultCTAProps {
  reason: string
  videoCallUrl?: string
  onClick: () => void
}

/**
 * Card surfaced after the AI decides a human pharmacist would help. Ported
 * visually from `ai-chat.js:932-973` but using Tailwind + lucide icons.
 */
export function PharmacistConsultCTA({ reason, videoCallUrl, onClick }: PharmacistConsultCTAProps) {
  return (
    <div className="mt-3 rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 to-emerald-50 p-4 shadow-soft">
      <div className="flex items-start gap-3">
        <div className="w-10 h-10 rounded-full bg-brand-500 flex items-center justify-center shrink-0 shadow-glow">
          <Bot className="w-5 h-5 text-white" aria-hidden="true" />
        </div>
        <div className="flex-1 min-w-0">
          <h4 className="text-sm font-bold text-brand-700">ต้องการปรึกษาเภสัชกร?</h4>
          <p className="text-xs text-brand-600 mt-1 leading-relaxed">{reason}</p>
        </div>
      </div>

      <button
        type="button"
        onClick={onClick}
        data-video-url={videoCallUrl ?? ''}
        className="mt-3 w-full min-h-[44px] inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-full active:scale-95 transition-transform"
      >
        <Video className="w-4 h-4" aria-hidden="true" />
        ปรึกษาผ่าน Video Call
      </button>
    </div>
  )
}
