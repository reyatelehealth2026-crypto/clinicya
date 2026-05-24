/**
 * Client-side emergency keyword scanner for the AI chat input.
 *
 * Ported from `liff/assets/js/components/ai-chat.js:1211-1279`
 * (`checkEmergencySymptoms`). Runs synchronously before the SSE
 * request so the UI can surface the EmergencyModal immediately even
 * if the network is slow. The server (`RedFlagDetector`) is still the
 * source of truth and may emit its own `emergency` SSE event with
 * additional context.
 *
 * All Thai keyword lists are preserved verbatim from the source.
 */

export type EmergencySeverity = 'critical' | 'warning'

export interface EmergencyPayload {
  severity: EmergencySeverity
  symptoms: string[]
  recommendation: string
}

interface EmergencyRule {
  keywords: string[]
  symptom: string
}

/** Critical red flags — require immediate medical attention (Requirement 3.1). */
const CRITICAL_RULES: readonly EmergencyRule[] = [
  {
    keywords: ['หายใจไม่ออก', 'หายใจลำบาก', 'หอบหนัก', 'แน่นหน้าอกมาก'],
    symptom: 'หายใจลำบาก/แน่นหน้าอก'
  },
  {
    keywords: ['เจ็บหน้าอก', 'แน่นหน้าอก', 'เจ็บอกร้าวไปแขน'],
    symptom: 'เจ็บหน้าอก'
  },
  {
    keywords: ['ชัก', 'หมดสติ', 'เป็นลม', 'ไม่รู้สึกตัว', 'ไม่ตอบสนอง'],
    symptom: 'หมดสติ/ชัก'
  },
  {
    keywords: ['เลือดออกมาก', 'เลือดไหลไม่หยุด', 'ตกเลือด', 'อาเจียนเป็นเลือด'],
    symptom: 'เลือดออกมาก'
  },
  {
    keywords: [
      'อัมพาต',
      'แขนขาอ่อนแรงทันที',
      'พูดไม่ชัดทันที',
      'หน้าเบี้ยว',
      'ปากเบี้ยว'
    ],
    symptom: 'อาการคล้ายโรคหลอดเลือดสมอง'
  },
  {
    keywords: [
      'แพ้ยารุนแรง',
      'บวมทั้งตัว',
      'ผื่นขึ้นทั้งตัว',
      'หายใจไม่ออกหลังกินยา',
      'ลิ้นบวม',
      'คอบวม'
    ],
    symptom: 'แพ้ยารุนแรง (Anaphylaxis)'
  },
  {
    keywords: ['กินยาเกินขนาด', 'กินยาผิด', 'overdose', 'กินยาฆ่าตัวตาย'],
    symptom: 'กินยาเกินขนาด'
  },
  {
    keywords: ['ฆ่าตัวตาย', 'ทำร้ายตัวเอง', 'ไม่อยากมีชีวิต', 'อยากตาย'],
    symptom: 'ความคิดทำร้ายตัวเอง'
  }
]

/** Warning red flags — should see doctor soon (Requirement 3.3). */
const WARNING_RULES: readonly EmergencyRule[] = [
  {
    keywords: ['ไข้สูงมาก', 'ไข้ 40', 'ไข้สูง 3 วัน', 'ไข้ไม่ลด'],
    symptom: 'ไข้สูง'
  },
  {
    keywords: ['ปวดหัวรุนแรง', 'ปวดหัวมาก', 'ปวดหัวแบบไม่เคยเป็น'],
    symptom: 'ปวดหัวรุนแรง'
  },
  {
    keywords: [
      'ท้องเสียมาก',
      'ท้องเสียหลายวัน',
      'ถ่ายเป็นเลือด',
      'อุจจาระเป็นเลือด'
    ],
    symptom: 'ท้องเสียรุนแรง/ถ่ายเป็นเลือด'
  },
  {
    keywords: ['ปวดท้องรุนแรง', 'ปวดท้องมาก', 'ปวดท้องน้อยข้างเดียว'],
    symptom: 'ปวดท้องรุนแรง'
  },
  {
    keywords: ['ตาเหลือง', 'ตัวเหลือง', 'ปัสสาวะสีเข้ม'],
    symptom: 'อาการตัวเหลือง'
  },
  {
    keywords: ['หายใจเหนื่อย', 'หอบเหนื่อย', 'เหนื่อยง่าย'],
    symptom: 'หายใจเหนื่อย'
  },
  {
    keywords: ['บวมขา', 'บวมเท้า', 'บวมทั้งสองข้าง'],
    symptom: 'อาการบวม'
  },
  {
    keywords: ['น้ำหนักลดมาก', 'ผอมลงเร็ว', 'เบื่ออาหารมาก'],
    symptom: 'น้ำหนักลดผิดปกติ'
  }
]

function matchRules(message: string, rules: readonly EmergencyRule[]): string[] {
  const detected: string[] = []
  for (const rule of rules) {
    for (const keyword of rule.keywords) {
      if (message.includes(keyword)) {
        detected.push(rule.symptom)
        break
      }
    }
  }
  return Array.from(new Set(detected))
}

/**
 * Scan a user message for emergency keywords.
 *
 * Returns the highest-severity match, or `null` if nothing matched.
 * Critical findings take precedence over warning findings.
 */
export function scanEmergency(message: string): EmergencyPayload | null {
  if (!message) return null
  const lowered = message.toLowerCase()

  const critical = matchRules(lowered, CRITICAL_RULES)
  if (critical.length > 0) {
    return {
      severity: 'critical',
      symptoms: critical,
      recommendation:
        '🚨 อาการเหล่านี้เป็นอันตรายร้ายแรง กรุณาโทรเรียกรถพยาบาลหรือไปห้องฉุกเฉินทันที!'
    }
  }

  const warning = matchRules(lowered, WARNING_RULES)
  if (warning.length > 0) {
    return {
      severity: 'warning',
      symptoms: warning,
      recommendation:
        'อาการเหล่านี้ควรได้รับการตรวจจากแพทย์ หากอาการไม่ดีขึ้นหรือรุนแรงขึ้น ควรพบแพทย์โดยเร็ว'
    }
  }

  return null
}
