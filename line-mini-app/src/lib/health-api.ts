import { appConfig } from '@/lib/config'
import { phpGet, phpPost } from '@/lib/php-bridge'

export type HealthPersonalInfo = {
  name: string | null
  age: number | null
  gender: 'male' | 'female' | 'other' | null
  weight: number | null
  height: number | null
  blood_type: 'A' | 'B' | 'AB' | 'O' | 'unknown' | null
}

export type DrugAllergy = {
  id: number
  drug_name: string
  reaction_type: 'rash' | 'breathing' | 'swelling' | 'other'
  reaction_notes?: string | null
  severity: 'mild' | 'moderate' | 'severe'
  created_at: string
}

export type CurrentMedication = {
  id: number
  medication_name: string
  dosage?: string | null
  frequency?: string | null
  start_date?: string | null
  notes?: string | null
  is_active: number
}

export type HealthProfile = {
  personal_info: HealthPersonalInfo
  medical_conditions: string[]
  allergies: DrugAllergy[]
  medications: CurrentMedication[]
  completion_percent: number
  updated_at?: string | null
}

export type HealthProfileResponse = {
  success: boolean
  profile?: HealthProfile
  error?: string
}

export const MEDICAL_CONDITIONS: { key: string; label: string }[] = [
  { key: 'diabetes', label: 'เบาหวาน' },
  { key: 'hypertension', label: 'ความดันโลหิตสูง' },
  { key: 'heart_disease', label: 'โรคหัวใจ' },
  { key: 'kidney_disease', label: 'โรคไต' },
  { key: 'liver_disease', label: 'โรคตับ' },
  { key: 'pregnancy', label: 'ตั้งครรภ์' },
  { key: 'asthma', label: 'หอบหืด' },
  { key: 'thyroid', label: 'ไทรอยด์' },
  { key: 'cancer', label: 'มะเร็ง' },
  { key: 'other', label: 'อื่นๆ' }
]

export function getHealthProfile(lineUserId: string) {
  return phpGet<HealthProfileResponse>('/api/health-profile.php', {
    action: 'get',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId
  })
}

export function updatePersonalInfo(lineUserId: string, data: HealthPersonalInfo) {
  return phpPost<{ success: boolean; message?: string }>('/api/health-profile.php', {
    action: 'update_personal',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
    ...data
  })
}

export function updateMedicalHistory(lineUserId: string, conditions: string[]) {
  return phpPost<{ success: boolean; message?: string }>('/api/health-profile.php', {
    action: 'update_medical_history',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
    conditions
  })
}

export function addAllergy(lineUserId: string, data: {
  drug_name: string
  reaction_type: string
  severity: string
  reaction_notes?: string
}) {
  return phpPost<{ success: boolean; message?: string; allergy?: DrugAllergy }>('/api/health-profile.php', {
    action: 'add_allergy',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
    ...data
  })
}

export function removeAllergy(lineUserId: string, allergyId: number) {
  return phpPost<{ success: boolean; message?: string }>('/api/health-profile.php', {
    action: 'remove_allergy',
    line_user_id: lineUserId,
    allergy_id: allergyId
  })
}

export function addMedication(lineUserId: string, data: {
  medication_name: string
  dosage?: string
  frequency?: string
  start_date?: string
  notes?: string
}) {
  return phpPost<{ success: boolean; message?: string; has_interactions?: boolean; interactions?: unknown[] }>('/api/health-profile.php', {
    action: 'add_medication',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
    ...data
  })
}

export function removeMedication(lineUserId: string, medicationId: number) {
  return phpPost<{ success: boolean; message?: string }>('/api/health-profile.php', {
    action: 'remove_medication',
    line_user_id: lineUserId,
    medication_id: medicationId
  })
}
