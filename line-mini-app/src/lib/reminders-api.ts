import { phpGet, phpPost } from '@/lib/php-bridge'

export type MedicationReminder = {
  id: number
  medication_name: string
  dosage?: string | null
  frequency: string
  reminder_times: string[]
  start_date?: string | null
  end_date?: string | null
  notes?: string | null
  is_active: number
  adherence_percent: number
  taken_count_7d: number
  missed_count_7d: number
  created_at: string
}

export type RemindersResponse = {
  success: boolean
  reminders: MedicationReminder[]
}

export const FREQUENCY_LABELS: Record<string, string> = {
  daily: 'ทุกวัน',
  twice_daily: 'วันละ 2 ครั้ง',
  three_daily: 'วันละ 3 ครั้ง',
  weekly: 'ทุกสัปดาห์',
  as_needed: 'เมื่อจำเป็น',
  custom: 'กำหนดเอง'
}

export function getReminders(lineUserId: string) {
  return phpGet<RemindersResponse>('/api/medication-reminders.php', {
    action: 'list',
    line_user_id: lineUserId
  })
}

export function addReminder(lineUserId: string, data: {
  medication_name: string
  dosage?: string
  frequency: string
  reminder_times: string[]
  start_date?: string
  end_date?: string
  notes?: string
}) {
  return phpPost<{ success: boolean; reminder_id?: number; message?: string }>('/api/medication-reminders.php', {
    action: 'add',
    line_user_id: lineUserId,
    ...data
  })
}

export function deleteReminder(lineUserId: string, reminderId: number) {
  return phpPost<{ success: boolean; message?: string }>('/api/medication-reminders.php', {
    action: 'delete',
    line_user_id: lineUserId,
    reminder_id: reminderId
  })
}

export function markTaken(lineUserId: string, reminderId: number, scheduledTime?: string) {
  return phpPost<{ success: boolean; message?: string }>('/api/medication-reminders.php', {
    action: 'mark_taken',
    line_user_id: lineUserId,
    reminder_id: reminderId,
    scheduled_time: scheduledTime,
    status: 'taken'
  })
}
