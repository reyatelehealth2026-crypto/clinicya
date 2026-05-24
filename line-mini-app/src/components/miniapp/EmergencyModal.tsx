'use client'

import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  AlertTriangle,
  AlertCircle,
  Phone,
  Bot,
  X,
  Activity
} from 'lucide-react'

/**
 * Query string for tabbable / focusable descendants inside the dialog. Used
 * by the focus-trap effect — keep this in sync with elements rendered below.
 */
const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Props for {@link EmergencyModal}.
 *
 * @property severity - `'critical'` shows red theme + 1669 ambulance button.
 *                      `'warning'` shows amber theme without 1669.
 * @property symptoms - Detected emergency symptoms shown as a bullet list.
 * @property recommendation - One-line Thai-language recommendation rendered
 *                            in the body of the modal.
 * @property onClose - Fired when the user dismisses the modal (close button or
 *                     backdrop tap). Parent owns visibility state.
 * @property onConsultPharmacist - Fired when the user taps "ปรึกษาเภสัชกรทันที".
 */
interface EmergencyModalProps {
  severity: 'critical' | 'warning'
  symptoms: string[]
  recommendation: string
  onClose: () => void
  onConsultPharmacist: () => void
}

/**
 * Fullscreen modal (rendered via React portal into `document.body`) that
 * surfaces a critical or warning-level red flag with one-tap access to Thai
 * emergency hotlines (1669 / 1323 / 1367) and a CTA to escalate to a human
 * pharmacist. Mirrors `ai-chat.js:1038-1132` visually, modernised to
 * Tailwind + lucide.
 *
 * Designed to be unconditionally mounted by the parent — when not visible,
 * the parent simply skips rendering it. The modal locks `<body>` scroll
 * while mounted and restores it on unmount.
 */
export function EmergencyModal({
  severity,
  symptoms,
  recommendation,
  onClose,
  onConsultPharmacist
}: EmergencyModalProps) {
  const [mounted, setMounted] = useState(false)
  const dialogRef = useRef<HTMLDivElement | null>(null)
  const closeBtnRef = useRef<HTMLButtonElement | null>(null)
  const firstHotlineRef = useRef<HTMLAnchorElement | null>(null)

  // SSR-safe portal: only render after mount on the client.
  useEffect(() => {
    setMounted(true)
  }, [])

  /**
   * Lock body scroll while the modal is open + restore on unmount.
   *
   * iOS Safari (LIFF runtime) ignores `body { overflow: hidden }` alone — the
   * page still rubber-band scrolls under the modal. The accepted fix is to
   * pin `<body>` with `position: fixed; top: -scrollY` and restore the
   * scroll position on unmount.
   */
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

  // Close on Escape key for keyboard a11y.
  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
  }, [onClose])

  const isCritical = severity === 'critical'

  /**
   * Focus management (WCAG 2.4.3, ARIA APG dialog pattern):
   *  - on open: remember the previously-focused element, then move focus
   *    into the dialog (first hotline link when `isCritical`, otherwise
   *    the close button).
   *  - while open: trap Tab / Shift+Tab inside the dialog's focusable
   *    descendants so keyboard users can't escape behind the backdrop.
   *  - on close: restore focus to the previously-focused element.
   */
  useEffect(() => {
    if (!mounted) return
    const previouslyActive = document.activeElement as HTMLElement | null

    // Defer initial focus by a frame so refs are attached and the
    // animation doesn't fight the focus ring.
    const focusTarget = isCritical
      ? firstHotlineRef.current ?? closeBtnRef.current
      : closeBtnRef.current ?? firstHotlineRef.current
    focusTarget?.focus?.()

    function handleTabKey(e: KeyboardEvent) {
      if (e.key !== 'Tab') return
      const root = dialogRef.current
      if (!root) return
      const focusables = Array.from(
        root.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)
      ).filter((el) => !el.hasAttribute('aria-hidden'))
      if (focusables.length === 0) {
        e.preventDefault()
        return
      }
      const first = focusables[0]
      const last = focusables[focusables.length - 1]
      const active = document.activeElement as HTMLElement | null
      if (e.shiftKey) {
        if (active === first || !root.contains(active)) {
          e.preventDefault()
          last.focus()
        }
      } else {
        if (active === last || !root.contains(active)) {
          e.preventDefault()
          first.focus()
        }
      }
    }
    window.addEventListener('keydown', handleTabKey)
    return () => {
      window.removeEventListener('keydown', handleTabKey)
      previouslyActive?.focus?.()
    }
  }, [mounted, isCritical])

  if (!mounted) return null

  const contentBgClass = isCritical
    ? 'bg-gradient-to-br from-rose-50 to-white border-rose-300'
    : 'bg-gradient-to-br from-amber-50 to-white border-amber-300'
  const titleColor = isCritical ? 'text-rose-700' : 'text-amber-800'
  const iconBg = isCritical ? 'bg-rose-600' : 'bg-amber-500'
  const symptomsBg = isCritical
    ? 'bg-rose-50 border-rose-200 text-rose-900'
    : 'bg-amber-50 border-amber-200 text-amber-900'

  const TitleIcon = isCritical ? AlertTriangle : AlertCircle
  const titleText = isCritical ? '🚨 พบอาการฉุกเฉิน!' : '⚠️ พบอาการที่ต้องระวัง'
  const disclaimer = isCritical
    ? 'หากมีอาการรุนแรง กรุณาไปพบแพทย์ที่โรงพยาบาลใกล้บ้านทันที'
    : 'หากอาการไม่ดีขึ้นหรือรุนแรงขึ้น ควรพบแพทย์โดยเร็ว'

  const modal = (
    <div className="fixed inset-0 z-[100] flex items-end sm:items-center justify-center">
      {/* Backdrop — sibling of the dialog, not a focusable child. Decorative
          click-to-close affordance only; the X button below provides the a11y
          close. aria-hidden keeps screen readers + Tab order out of it. */}
      <div
        aria-hidden="true"
        onClick={onClose}
        className="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in"
      />

      {/* Dialog */}
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="emergency-modal-title"
        className={`relative w-full sm:max-w-md max-h-[92vh] overflow-y-auto rounded-t-3xl sm:rounded-3xl border-2 shadow-card animate-slide-up ${contentBgClass}`}
      >
        {/* Close (X) */}
        <button
          ref={closeBtnRef}
          type="button"
          onClick={onClose}
          aria-label="ปิด"
          className="absolute top-3 right-3 z-10 p-2 rounded-full text-gray-600 hover:bg-black/5 active:scale-95"
        >
          <X className="w-4 h-4" aria-hidden="true" />
        </button>

        {/* Header */}
        <div className="px-5 pt-6 pb-3 flex items-center gap-3">
          <div
            className={`w-12 h-12 rounded-full flex items-center justify-center shrink-0 text-white shadow-glow animate-pulse-soft ${iconBg}`}
          >
            <TitleIcon className="w-6 h-6" aria-hidden="true" />
          </div>
          <h3
            id="emergency-modal-title"
            className={`text-lg font-bold leading-tight ${titleColor}`}
          >
            {titleText}
          </h3>
        </div>

        {/* Body */}
        <div className="px-5 pb-3 space-y-3">
          <p className="text-sm text-gray-800 leading-relaxed">{recommendation}</p>

          {symptoms.length > 0 ? (
            <div className={`rounded-xl border p-3 ${symptomsBg}`}>
              <p className="text-xs font-semibold mb-1.5">อาการที่ตรวจพบ</p>
              <ul className="text-xs space-y-1 list-disc list-inside leading-relaxed">
                {symptoms.map((s, i) => (
                  <li key={i}>{s}</li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>

        {/* Hotlines header */}
        <div className="px-5 pb-1">
          <p className="text-xs font-semibold text-gray-700 flex items-center gap-1">
            <Activity className="w-3 h-3" aria-hidden="true" />
            สายด่วนฉุกเฉิน
          </p>
        </div>

        {/* Actions (sticky on small screens via flex layout) */}
        <div className="px-5 pb-5 pt-2 grid gap-2">
          {isCritical ? (
            <a
              ref={firstHotlineRef}
              href="tel:1669"
              className="min-h-[48px] inline-flex items-center justify-between gap-2 px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold rounded-2xl active:scale-95"
            >
              <span className="inline-flex items-center gap-2">
                <Phone className="w-4 h-4" aria-hidden="true" />
                โทร 1669
              </span>
              <span className="text-[11px] font-normal opacity-90">ฉุกเฉินการแพทย์</span>
            </a>
          ) : null}

          <a
            ref={(el) => {
              if (!isCritical) firstHotlineRef.current = el
            }}
            href="tel:1323"
            className="min-h-[48px] inline-flex items-center justify-between gap-2 px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-2xl active:scale-95"
          >
            <span className="inline-flex items-center gap-2">
              <Phone className="w-4 h-4" aria-hidden="true" />
              โทร 1323
            </span>
            <span className="text-[11px] font-normal opacity-90">สายด่วนสุขภาพจิต</span>
          </a>

          <a
            href="tel:1367"
            className="min-h-[48px] inline-flex items-center justify-between gap-2 px-4 py-2.5 bg-indigo-500 hover:bg-indigo-600 text-white text-sm font-semibold rounded-2xl active:scale-95"
          >
            <span className="inline-flex items-center gap-2">
              <Phone className="w-4 h-4" aria-hidden="true" />
              โทร 1367
            </span>
            <span className="text-[11px] font-normal opacity-90">สายด่วนสุขภาพ</span>
          </a>

          <button
            type="button"
            onClick={onConsultPharmacist}
            className="min-h-[48px] inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-2xl active:scale-95"
          >
            <Bot className="w-4 h-4" aria-hidden="true" />
            ปรึกษาเภสัชกรทันที
          </button>

          <button
            type="button"
            onClick={onClose}
            className="min-h-[44px] inline-flex items-center justify-center px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm font-medium rounded-2xl active:scale-95"
          >
            ปิด
          </button>
        </div>

        <p className="px-5 pb-5 text-[11px] text-gray-500 leading-relaxed">
          {disclaimer}
        </p>
      </div>
    </div>
  )

  return createPortal(modal, document.body)
}
