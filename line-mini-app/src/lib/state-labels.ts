/**
 * Triage state labels for the AI chat header indicator.
 *
 * Ported from `liff/assets/js/components/ai-chat.js:857-870` (`stateLabels`).
 * Mirror the 12 triage states emitted by `/api/ai-chat.php` via the
 * `{ structured: { type: 'state', state, label_th } }` SSE event.
 */

export type TriageState =
  | 'greeting'
  | 'symptom'
  | 'duration'
  | 'severity'
  | 'associated'
  | 'allergy'
  | 'medical_history'
  | 'current_meds'
  | 'recommend'
  | 'confirm'
  | 'complete'
  | 'escalate'

export const STATE_LABELS: Record<TriageState, string> = {
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

/** Narrow an unknown string to a `TriageState`, falling back to `greeting`. */
export function toTriageState(value: string | null | undefined): TriageState {
  if (value && value in STATE_LABELS) {
    return value as TriageState
  }
  return 'greeting'
}

/** Look up the Thai label for a given triage state. */
export function getStateLabel(state: string | null | undefined): string {
  return STATE_LABELS[toTriageState(state)]
}
