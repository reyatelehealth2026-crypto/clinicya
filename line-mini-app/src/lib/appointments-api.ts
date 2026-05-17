import { appConfig } from '@/lib/config'
import { phpGet, phpPost } from '@/lib/php-bridge'

export type Pharmacist = {
  id: number
  name: string
  title?: string | null
  specialty?: string | null
  image_url?: string | null
  rating?: number | null
  review_count?: number | null
  consultation_fee?: number | null
  consultation_duration?: number | null
  is_available?: number | null
  case_count?: number
}

export type AppointmentSlot = {
  date: string
  slots: string[]
}

export type Appointment = {
  id: number
  pharmacist_id: number
  pharmacist_name?: string | null
  pharmacist_image?: string | null
  appointment_date: string
  appointment_time: string
  status: 'pending' | 'confirmed' | 'completed' | 'cancelled'
  notes?: string | null
  type?: string | null
  created_at: string
}

export type PharmacistsResponse = {
  success: boolean
  message?: string
  pharmacists: Pharmacist[]
}

export type AppointmentsResponse = {
  success: boolean
  // API ตอบแบบ {upcoming, past, all} — เก็บทุก field; getMyAppointments() จะ normalize เป็น appointments[]
  upcoming?: Appointment[]
  past?: Appointment[]
  all?: Appointment[]
  appointments?: Appointment[]
  message?: string
}

export type SlotsResponse = {
  success: boolean
  slots?: AppointmentSlot[]
  message?: string
}

export function getPharmacists() {
  return phpGet<PharmacistsResponse>('/api/appointments.php', {
    action: 'pharmacists',
    line_account_id: appConfig.lineAccountId
  })
}

export async function getMyAppointments(lineUserId: string): Promise<AppointmentsResponse> {
  const res = await phpGet<AppointmentsResponse>('/api/appointments.php', {
    action: 'my_appointments',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId
  })
  // Normalize: API ตอบ {upcoming, past, all} แต่ UI อ่าน .appointments — รวมเป็น list เดียวเรียงล่าสุดก่อน
  const merged = res.appointments ?? res.all ?? [...(res.upcoming ?? []), ...(res.past ?? [])]
  return { ...res, appointments: merged }
}

export function getAvailableSlots(pharmacistId: number, date?: string) {
  return phpGet<SlotsResponse>('/api/appointments.php', {
    action: 'available_slots',
    pharmacist_id: pharmacistId,
    date: date ?? new Date().toISOString().split('T')[0]
  })
}

export function bookAppointment(data: {
  line_user_id: string
  pharmacist_id: number
  appointment_date: string
  appointment_time: string
  notes?: string
  type?: string
}) {
  // API คาดหวัง field ชื่อ date / time / symptoms (ไม่ใช่ appointment_date / appointment_time / notes)
  const typeLabels: Record<string, string> = {
    consultation: 'ปรึกษายาและสุขภาพ',
    review: 'ทบทวนยา',
    chronic: 'โรคเรื้อรัง',
    other: 'อื่นๆ'
  }
  const typeLabel = data.type ? typeLabels[data.type] ?? data.type : ''
  const symptoms = [
    typeLabel ? `ประเภท: ${typeLabel}` : '',
    data.notes ? `บันทึก: ${data.notes}` : ''
  ].filter(Boolean).join('\n')

  return phpPost<{ success: boolean; appointment_id?: number; message?: string }>('/api/appointments.php', {
    action: 'book',
    line_account_id: appConfig.lineAccountId,
    line_user_id: data.line_user_id,
    pharmacist_id: data.pharmacist_id,
    date: data.appointment_date,
    time: data.appointment_time,
    type: 'scheduled',
    symptoms
  })
}

export function cancelAppointment(lineUserId: string, appointmentId: number) {
  return phpPost<{ success: boolean; message?: string }>('/api/appointments.php', {
    action: 'cancel',
    line_user_id: lineUserId,
    appointment_id: appointmentId
  })
}

export const APPOINTMENT_STATUS_LABEL: Record<string, string> = {
  pending: 'รอยืนยัน',
  confirmed: 'ยืนยันแล้ว',
  completed: 'เสร็จสิ้น',
  cancelled: 'ยกเลิก'
}

export const APPOINTMENT_STATUS_COLOR: Record<string, string> = {
  pending: 'badge-amber',
  confirmed: 'badge-blue',
  completed: 'badge-green',
  cancelled: 'badge-red'
}
