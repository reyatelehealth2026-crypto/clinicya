'use client'

import { AlertTriangle, Phone } from 'lucide-react'

interface EscalationBannerProps {
  message: string
  onContact?: () => void
}

/**
 * Banner สีแดงเด่น — แสดงเมื่อ AI ตรวจพบ red flag อาการฉุกเฉิน
 * ผู้ใช้เห็นปุ่ม "ติดต่อเภสัชกร" + เบอร์ฉุกเฉิน 1669
 */
export function EscalationBanner({ message, onContact }: EscalationBannerProps) {
  return (
    <div
      role="alert"
      aria-live="assertive"
      className="mt-3 rounded-xl border-2 border-rose-300 bg-rose-50 p-3 shadow-sm"
    >
      <div className="flex items-start gap-2">
        <AlertTriangle className="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
        <div className="flex-1 min-w-0">
          <h4 className="text-sm font-bold text-rose-700">สัญญาณเตือนทางการแพทย์</h4>
          <p className="text-sm text-rose-800 mt-1 whitespace-pre-line">{message}</p>

          <div className="flex flex-wrap gap-2 mt-3">
            <a
              href="tel:1669"
              className="min-h-[40px] inline-flex items-center gap-1.5 px-3 py-2 bg-rose-600 text-white text-xs font-medium rounded-full active:scale-95"
            >
              <Phone className="w-3.5 h-3.5" />
              โทร 1669 (ฉุกเฉิน)
            </a>
            {onContact ? (
              <button
                type="button"
                onClick={onContact}
                className="min-h-[40px] inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-rose-300 text-rose-700 text-xs font-medium rounded-full active:scale-95"
              >
                คุยกับเภสัชกร
              </button>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  )
}
