'use client'

import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { ShieldCheck, Loader2 } from 'lucide-react'

/**
 * Props for {@link ConsentModal}.
 *
 * @property onAccept  - User grants `health_data` consent (PDPA มาตรา 26).
 * @property onDecline - User declines / dismisses. Parent should stop
 *                       re-prompting for the rest of the session.
 * @property submitting - True while the accept request is in flight.
 */
interface ConsentModalProps {
  onAccept: () => void
  onDecline: () => void
  submitting?: boolean
}

/**
 * PDPA health-data consent prompt (issue #15), shown when the backend signals
 * `consent_required` on a triage payload. Rendered via a React portal into
 * `document.body`, locks body scroll, and closes on Escape (= decline).
 *
 * Advisory: declining does not block the chat — it just records the decision
 * and stops the nudge for this session.
 */
export function ConsentModal({ onAccept, onDecline, submitting = false }: ConsentModalProps) {
  const [mounted, setMounted] = useState(false)
  const acceptBtnRef = useRef<HTMLButtonElement | null>(null)

  // SSR-safe portal: only render after mount on the client.
  useEffect(() => {
    setMounted(true)
  }, [])

  // Lock body scroll while open (iOS Safari / LIFF safe: pin body position).
  useEffect(() => {
    if (typeof window === 'undefined') return
    const scrollY = window.scrollY
    const body = document.body
    const prev = {
      position: body.style.position,
      top: body.style.top,
      width: body.style.width,
      overflow: body.style.overflow
    }
    body.style.position = 'fixed'
    body.style.top = `-${scrollY}px`
    body.style.width = '100%'
    body.style.overflow = 'hidden'
    return () => {
      body.style.position = prev.position
      body.style.top = prev.top
      body.style.width = prev.width
      body.style.overflow = prev.overflow
      window.scrollTo(0, scrollY)
    }
  }, [])

  // Escape = decline (a11y).
  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !submitting) onDecline()
    }
    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
  }, [onDecline, submitting])

  // Move focus to the primary action on open.
  useEffect(() => {
    if (mounted) acceptBtnRef.current?.focus?.()
  }, [mounted])

  if (!mounted) return null

  return createPortal(
    <div
      className="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4"
      onClick={() => {
        if (!submitting) onDecline()
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="consent-title"
        className="w-full max-w-md rounded-t-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-5 shadow-xl sm:rounded-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-3 flex items-center gap-2 text-emerald-700">
          <ShieldCheck className="h-6 w-6 shrink-0" aria-hidden />
          <h2 id="consent-title" className="text-lg font-semibold">
            ขอความยินยอมใช้ข้อมูลสุขภาพ
          </h2>
        </div>

        <p className="mb-2 text-sm leading-relaxed text-gray-700">
          เพื่อให้เภสัชกร AI ซักถามอาการและแนะนำยาได้อย่างปลอดภัย
          ระบบจำเป็นต้องบันทึก
          <span className="font-medium"> ประวัติอาการ/สุขภาพ </span>
          ของคุณ ซึ่งถือเป็นข้อมูลส่วนบุคคลอ่อนไหวตาม PDPA (มาตรา 26)
        </p>
        <p className="mb-4 text-xs leading-relaxed text-gray-500">
          ข้อมูลจะถูกเก็บอย่างปลอดภัยและใช้เพื่อการให้คำปรึกษาเท่านั้น
          คุณสามารถถอนความยินยอมได้ภายหลังในหน้าตั้งค่า
        </p>

        <div className="flex flex-col gap-2">
          <button
            ref={acceptBtnRef}
            type="button"
            disabled={submitting}
            onClick={onAccept}
            className="flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"
          >
            {submitting ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> : null}
            ยินยอมและดำเนินการต่อ
          </button>
          <button
            type="button"
            disabled={submitting}
            onClick={onDecline}
            className="rounded-xl px-4 py-2 text-sm font-medium text-gray-500 transition hover:text-gray-700 disabled:opacity-60"
          >
            ไม่ในตอนนี้
          </button>
        </div>
      </div>
    </div>,
    document.body
  )
}
