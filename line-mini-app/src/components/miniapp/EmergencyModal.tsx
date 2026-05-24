'use client'

import { useEffect, useState } from 'react'
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

  // SSR-safe portal: only render after mount on the client.
  useEffect(() => {
    setMounted(true)
  }, [])

  // Lock body scroll while the modal is open + restore on unmount.
  useEffect(() => {
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = previous
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

  if (!mounted) return null

  const isCritical = severity === 'critical'

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
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="emergency-modal-title"
      className="fixed inset-0 z-[100] flex items-end sm:items-center justify-center"
    >
      {/* Backdrop */}
      <button
        type="button"
        aria-label="ปิดการแจ้งเตือนฉุกเฉิน"
        onClick={onClose}
        className="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in"
      />

      {/* Card */}
      <div
        className={`relative w-full sm:max-w-md max-h-[92vh] overflow-y-auto rounded-t-3xl sm:rounded-3xl border-2 shadow-card animate-slide-up ${contentBgClass}`}
      >
        {/* Close (X) */}
        <button
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
