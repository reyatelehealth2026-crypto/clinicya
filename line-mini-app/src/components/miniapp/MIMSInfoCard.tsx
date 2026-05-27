'use client'

import { BookOpen, AlertTriangle } from 'lucide-react'
import type { MimsDisease, MimsRedFlag } from '@/types/ai-chat'

/**
 * Props for {@link MIMSInfoCard}.
 *
 * @property disease - Disease entry from the MIMS knowledge base. `name_th`
 *                     is the primary display label.
 * @property redFlags - Optional list of red-flag tags rendered as chips below
 *                      the advice lists.
 */
interface MIMSInfoCardProps {
  disease: MimsDisease
  redFlags?: MimsRedFlag[]
}

const MAX_ADVICE = 3
const MAX_REFERRAL = 2

/**
 * Educational card surfaced after AI tokens — disease name + self-care list +
 * when-to-see-a-doctor list + optional red-flag tags. Mirrors the visual
 * structure of `ai-chat.js:1737-1790`.
 */
export function MIMSInfoCard({ disease, redFlags }: MIMSInfoCardProps) {
  const advice = (disease.non_drug_advice ?? []).slice(0, MAX_ADVICE)
  const referral = (disease.referral_criteria ?? []).slice(0, MAX_REFERRAL)
  const flags = (redFlags ?? []).filter(
    (f) => f.flag?.message || f.matched_keyword
  )

  return (
    <div className="mt-3 rounded-2xl border border-blue-200 bg-white shadow-soft overflow-hidden">
      <div className="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-blue-50 to-indigo-50 border-b border-blue-100">
        <BookOpen className="w-4 h-4 text-blue-600" aria-hidden="true" />
        <span className="text-xs font-semibold text-blue-700">ข้อมูลจาก MIMS</span>
      </div>

      <div className="p-4 space-y-3">
        <h4 className="text-sm font-bold text-gray-900">
          {disease.name_th}
          {disease.name_en ? (
            <span className="ml-1 text-xs font-normal text-gray-500">({disease.name_en})</span>
          ) : null}
        </h4>

        {advice.length > 0 ? (
          <div>
            <p className="text-xs font-semibold text-gray-700 mb-1">🏠 การดูแลตัวเอง</p>
            <ul className="text-xs text-gray-700 space-y-1 list-disc list-inside leading-relaxed">
              {advice.map((a, i) => (
                <li key={i}>{a}</li>
              ))}
            </ul>
          </div>
        ) : null}

        {referral.length > 0 ? (
          <div className="rounded-lg bg-rose-50 border border-rose-100 p-3">
            <p className="text-xs font-semibold text-rose-700 mb-1">🏥 ควรพบแพทย์เมื่อ</p>
            <ul className="text-xs text-rose-800 space-y-1 list-disc list-inside leading-relaxed">
              {referral.map((c, i) => (
                <li key={i}>{c}</li>
              ))}
            </ul>
          </div>
        ) : null}

        {flags.length > 0 ? (
          <div>
            <p className="text-xs font-semibold text-amber-700 mb-1.5 flex items-center gap-1">
              <AlertTriangle className="w-3 h-3" aria-hidden="true" />
              อาการที่ต้องระวัง
            </p>
            <div className="flex flex-wrap gap-1.5">
              {flags.map((f, i) => (
                <span
                  key={i}
                  className="inline-flex items-center px-2 py-0.5 text-[11px] bg-amber-100 text-amber-800 border border-amber-200 rounded-full"
                >
                  {f.flag?.message || f.matched_keyword}
                </span>
              ))}
            </div>
          </div>
        ) : null}
      </div>
    </div>
  )
}
