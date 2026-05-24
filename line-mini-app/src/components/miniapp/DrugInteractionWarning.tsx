'use client'

import { AlertTriangle, AlertCircle, Pill, Bot } from 'lucide-react'
import type { DrugInteractionWarning as WarningItem } from '@/types/ai-chat'

/**
 * Props for {@link DrugInteractionWarning}.
 *
 * @property warnings - List of allergy + interaction warnings. The component
 *                      splits these into two sections internally.
 * @property onConsultPharmacist - Fired when the user taps the in-card
 *                                 "ปรึกษาเภสัชกร" button. Parent owns nav.
 */
interface DrugInteractionWarningProps {
  warnings: WarningItem[]
  onConsultPharmacist?: () => void
}

const REACTION_LABELS: Record<string, string> = {
  rash: 'ผื่นคัน',
  breathing: 'หายใจลำบาก',
  swelling: 'บวม',
  other: 'อื่นๆ'
}

function getReactionLabel(code?: string): string | null {
  if (!code || code === 'unknown') return null
  return REACTION_LABELS[code] ?? code
}

/**
 * Two-section warning card — allergies (red, high severity) above
 * known interactions (amber, medium). Visually mirrors
 * `ai-chat.js:1634-1716` but using Tailwind + lucide icons.
 */
export function DrugInteractionWarning({
  warnings,
  onConsultPharmacist
}: DrugInteractionWarningProps) {
  if (warnings.length === 0) return null

  // Partition strictly by `type` so a high-severity drug-drug interaction
  // stays in the interactions section (it's NOT an allergy). Sort each
  // section by severity: high → medium → low.
  const severityRank: Record<'high' | 'medium' | 'low', number> = {
    high: 0,
    medium: 1,
    low: 2
  }
  const bySeverity = (a: WarningItem, b: WarningItem): number => {
    const ra = severityRank[a.severity] ?? 3
    const rb = severityRank[b.severity] ?? 3
    return ra - rb
  }
  const allergyWarnings = warnings
    .filter((w) => w.type === 'allergy')
    .sort(bySeverity)
  const interactionWarnings = warnings
    .filter((w) => w.type === 'interaction')
    .sort(bySeverity)

  const hasAllergies = allergyWarnings.length > 0
  const hasInteractions = interactionWarnings.length > 0

  if (!hasAllergies && !hasInteractions) return null

  // Outer card tone keys off the worst-severity inside. Allergies always
  // dominate; otherwise a high-severity interaction also warrants rose.
  const hasAnyHigh =
    hasAllergies || interactionWarnings.some((w) => w.severity === 'high')
  const outerClass = hasAnyHigh
    ? 'border-rose-300 bg-rose-50'
    : 'border-amber-300 bg-amber-50'
  const headerClass = hasAnyHigh ? 'text-rose-700' : 'text-amber-800'
  const HeaderIcon = hasAnyHigh ? AlertTriangle : AlertCircle
  const headerText = hasAllergies ? '⛔ คำเตือนการแพ้ยา!' : '⚠️ ข้อควรระวังการใช้ยา'

  return (
    <div
      role="alert"
      aria-live="assertive"
      className={`mt-3 rounded-2xl border-2 ${outerClass} shadow-soft overflow-hidden`}
    >
      <div className={`flex items-center gap-2 px-4 py-2.5 border-b border-current/10 ${headerClass}`}>
        <HeaderIcon className="w-4 h-4" aria-hidden="true" />
        <span className="text-sm font-bold">{headerText}</span>
      </div>

      <div className="p-4 space-y-3">
        {hasAllergies ? (
          <section aria-label="ยาที่คุณแพ้">
            <p className="text-xs font-semibold text-rose-700 mb-2 flex items-center gap-1">
              <AlertTriangle className="w-3 h-3" aria-hidden="true" />
              ยาที่คุณแพ้
            </p>
            <ul className="space-y-2">
              {allergyWarnings.map((w, i) => {
                const reaction = getReactionLabel(w.reaction_type)
                return (
                  <li
                    key={`allergy-${i}`}
                    className="rounded-lg bg-white border border-rose-200 p-3"
                  >
                    <p className="text-xs text-rose-900 leading-relaxed">{w.message}</p>
                    {reaction ? (
                      <p className="text-[11px] text-rose-700 mt-1">
                        อาการแพ้: <span className="font-medium">{reaction}</span>
                      </p>
                    ) : null}
                    {w.allergy ? (
                      <p className="text-[11px] font-semibold text-rose-800 mt-1.5 bg-rose-100 rounded-md px-2 py-1 inline-block">
                        ⚠️ ห้ามใช้ยา {w.product} เด็ดขาด!
                      </p>
                    ) : null}
                  </li>
                )
              })}
            </ul>
          </section>
        ) : null}

        {hasInteractions ? (
          <section aria-label="ปฏิกิริยาระหว่างยา">
            <p className="text-xs font-semibold text-amber-800 mb-2 flex items-center gap-1">
              <Pill className="w-3 h-3" aria-hidden="true" />
              ปฏิกิริยาระหว่างยา
            </p>
            <ul className="space-y-2">
              {interactionWarnings.map((w, i) => (
                <li
                  key={`int-${i}`}
                  className="rounded-lg bg-white border border-amber-200 p-3"
                >
                  <p className="text-xs text-amber-900 leading-relaxed">{w.message}</p>
                  {w.interacts_with ? (
                    <p className="text-[11px] text-amber-700 mt-1">
                      ยาที่ตีกัน: <span className="font-medium">{w.interacts_with}</span>
                    </p>
                  ) : null}
                </li>
              ))}
            </ul>
          </section>
        ) : null}
      </div>

      <div className="px-4 py-3 bg-white/60 border-t border-current/10 flex flex-col gap-2">
        <p className="text-[11px] text-gray-700 leading-relaxed">
          💡 {hasAllergies
            ? 'กรุณาหลีกเลี่ยงยาที่แพ้และปรึกษาเภสัชกรเพื่อหายาทดแทน'
            : 'ควรปรึกษาเภสัชกรก่อนใช้ยาร่วมกัน'}
        </p>
        {onConsultPharmacist ? (
          <button
            type="button"
            onClick={onConsultPharmacist}
            className="self-start min-h-[44px] inline-flex items-center gap-1.5 px-3 py-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-full active:scale-95"
          >
            <Bot className="w-3.5 h-3.5" aria-hidden="true" />
            ปรึกษาเภสัชกร
          </button>
        ) : null}
      </div>
    </div>
  )
}
