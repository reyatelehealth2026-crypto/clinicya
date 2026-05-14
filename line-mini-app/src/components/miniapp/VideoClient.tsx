'use client'

import { useEffect, useMemo, useState } from 'react'
import { Video, CalendarDays, Clock, Stethoscope, CheckCircle2, AlertCircle } from 'lucide-react'
import { AppShell } from '@/components/miniapp/AppShell'
import { useLineContext } from '@/components/providers'
import { appConfig, apiUrl } from '@/lib/config'

// ─── Types ──────────────────────────────────────────────────────────
interface Pharmacist {
  id: number
  name: string
  title?: string
  specialty?: string
  image_url?: string
  rating?: number
  review_count?: number
  consultation_fee?: number
  consultation_duration?: number
  is_available?: number
}

interface Slot {
  time: string
  available: boolean
}

interface BookingResult {
  appointment_id: string
  id: number
  date: string
  time: string
  duration: number
  pharmacist_name: string
}

const CONSULTATION_TYPES = [
  { id: 'general',  label: 'ปรึกษายาและสุขภาพ' },
  { id: 'review',   label: 'ทบทวนยา' },
  { id: 'chronic',  label: 'โรคเรื้อรัง' },
  { id: 'other',    label: 'อื่นๆ' }
] as const

// ─── Helpers ────────────────────────────────────────────────────────
function nextNDays(n: number): { iso: string; label: string; sub: string }[] {
  const days: { iso: string; label: string; sub: string }[] = []
  const dayNames = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.']
  const today = new Date()
  for (let i = 0; i < n; i++) {
    const d = new Date(today)
    d.setDate(today.getDate() + i)
    const iso = d.toISOString().slice(0, 10)
    days.push({
      iso,
      label: `${d.getDate()}`,
      sub: dayNames[d.getDay()]
    })
  }
  return days
}

function formatThaiDate(iso: string): string {
  const [y, m, d] = iso.split('-')
  return `${d}/${m}/${y}`
}

// ─── Component ──────────────────────────────────────────────────────
export function VideoClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId

  const [pharmacists, setPharmacists] = useState<Pharmacist[]>([])
  const [pharmacistsLoading, setPharmacistsLoading] = useState(true)
  const [pharmacistsError, setPharmacistsError] = useState<string | null>(null)

  const [selectedPharmacist, setSelectedPharmacist] = useState<Pharmacist | null>(null)
  const [selectedDate, setSelectedDate] = useState<string>('')
  const [selectedTime, setSelectedTime] = useState<string>('')

  const [slots, setSlots] = useState<Slot[]>([])
  const [slotsLoading, setSlotsLoading] = useState(false)
  const [slotsMessage, setSlotsMessage] = useState<string>('')

  const [types, setTypes] = useState<string[]>([])
  const [notes, setNotes] = useState('')

  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [success, setSuccess] = useState<BookingResult | null>(null)

  const days = useMemo(() => nextNDays(30), [])

  // Load pharmacists once
  useEffect(() => {
    let alive = true
    setPharmacistsLoading(true)
    fetch(apiUrl(`/api/appointments.php?action=pharmacists&line_account_id=${appConfig.lineAccountId}`))
      .then((r) => r.json())
      .then((data) => {
        if (!alive) return
        if (!data.success) throw new Error(data.message || 'โหลดเภสัชกรไม่สำเร็จ')
        setPharmacists(data.pharmacists || [])
      })
      .catch((err: unknown) => {
        if (!alive) return
        setPharmacistsError(err instanceof Error ? err.message : 'เกิดข้อผิดพลาด')
      })
      .finally(() => {
        if (alive) setPharmacistsLoading(false)
      })
    return () => {
      alive = false
    }
  }, [])

  // Load slots when pharmacist + date selected
  useEffect(() => {
    if (!selectedPharmacist || !selectedDate) {
      setSlots([])
      return
    }
    let alive = true
    setSlotsLoading(true)
    setSlotsMessage('')
    setSelectedTime('')

    const url = apiUrl(
      `/api/appointments.php?action=available_slots&pharmacist_id=${selectedPharmacist.id}&date=${selectedDate}`
    )
    fetch(url)
      .then((r) => r.json())
      .then((data) => {
        if (!alive) return
        if (!data.success) {
          setSlots([])
          setSlotsMessage(data.message || 'ดึงช่วงเวลาไม่สำเร็จ')
          return
        }
        setSlots(data.slots || [])
        if (data.message && (!data.slots || data.slots.length === 0)) {
          setSlotsMessage(data.message)
        }
      })
      .catch(() => {
        if (alive) setSlotsMessage('เครือข่ายขัดข้อง')
      })
      .finally(() => {
        if (alive) setSlotsLoading(false)
      })

    return () => {
      alive = false
    }
  }, [selectedPharmacist, selectedDate])

  function toggleType(id: string) {
    setTypes((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  const canSubmit = Boolean(
    lineUserId && selectedPharmacist && selectedDate && selectedTime && !submitting
  )

  async function handleSubmit() {
    if (!canSubmit || !selectedPharmacist) return
    setSubmitting(true)
    setSubmitError(null)

    // Compose symptoms field: chosen types + free-text notes.
    // Backend stores this verbatim; pharmacist sees it in dashboard.
    const typeLabels = CONSULTATION_TYPES.filter((t) => types.includes(t.id)).map((t) => t.label)
    const symptoms = [
      typeLabels.length ? `ประเภท: ${typeLabels.join(', ')}` : '',
      notes ? `บันทึก: ${notes}` : ''
    ]
      .filter(Boolean)
      .join('\n')

    const payload = {
      action: 'book',
      line_user_id: lineUserId,
      line_account_id: appConfig.lineAccountId,
      pharmacist_id: selectedPharmacist.id,
      date: selectedDate,
      time: selectedTime,
      type: 'scheduled',
      symptoms
    }

    try {
      const res = await fetch(apiUrl('/api/appointments.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      const data = await res.json()
      if (!data.success) throw new Error(data.message || 'จองไม่สำเร็จ')
      setSuccess({
        appointment_id: data.appointment_id,
        id: data.id,
        date: data.date,
        time: data.time,
        duration: data.duration,
        pharmacist_name: selectedPharmacist.name
      })
    } catch (err: unknown) {
      setSubmitError(err instanceof Error ? err.message : 'เกิดข้อผิดพลาด')
    } finally {
      setSubmitting(false)
    }
  }

  function resetFlow() {
    setSuccess(null)
    setSelectedPharmacist(null)
    setSelectedDate('')
    setSelectedTime('')
    setTypes([])
    setNotes('')
  }

  // ── Success state ───────────────────────────────────────────────
  if (success) {
    return (
      <AppShell title="ปรึกษาเภสัชกร" subtitle="วิดีโอคอลกับเภสัชกร">
        <div className="rounded-3xl bg-white p-6 text-center shadow-soft">
          <CheckCircle2 className="mx-auto h-14 w-14 text-emerald-500" />
          <h3 className="mt-3 text-lg font-semibold text-slate-800">นัดหมายสำเร็จ</h3>
          <p className="mt-1 text-sm text-slate-500">เลขที่: {success.appointment_id}</p>
          <div className="mt-4 space-y-1 text-sm text-slate-700">
            <p><strong>เภสัชกร:</strong> {success.pharmacist_name}</p>
            <p><strong>วันที่:</strong> {formatThaiDate(success.date)}</p>
            <p><strong>เวลา:</strong> {success.time} น. ({success.duration} นาที)</p>
          </div>
          <button
            type="button"
            onClick={resetFlow}
            className="mt-5 rounded-full bg-line px-5 py-2 text-sm font-semibold text-white"
          >
            จองนัดเพิ่ม
          </button>
        </div>
      </AppShell>
    )
  }

  // ── Booking form ────────────────────────────────────────────────
  return (
    <AppShell title="ปรึกษาเภสัชกร" subtitle="วิดีโอคอลกับเภสัชกร">
      {/* Login gate */}
      {!lineUserId && (
        <div className="mb-4 flex items-start gap-2 rounded-2xl bg-amber-50 p-3 text-sm text-amber-800">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
          <span>กรุณาเข้าสู่ระบบ LINE ก่อนทำการนัดหมาย</span>
        </div>
      )}

      {/* Step 1: Pharmacist */}
      <section className="space-y-3">
        <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-700">
          <Stethoscope className="h-4 w-4 text-line" /> เลือกเภสัชกร
        </h3>

        {pharmacistsLoading && (
          <div className="rounded-2xl bg-white p-4 text-center text-sm text-slate-400 shadow-soft">
            กำลังโหลด…
          </div>
        )}

        {pharmacistsError && (
          <div className="rounded-2xl bg-rose-50 p-3 text-sm text-rose-700">{pharmacistsError}</div>
        )}

        {!pharmacistsLoading && !pharmacistsError && pharmacists.length === 0 && (
          <div className="rounded-2xl bg-white p-4 text-center text-sm text-slate-400 shadow-soft">
            ยังไม่มีเภสัชกรพร้อมให้บริการ
          </div>
        )}

        <div className="space-y-2">
          {pharmacists.map((p) => {
            const active = selectedPharmacist?.id === p.id
            return (
              <button
                key={p.id}
                type="button"
                onClick={() => setSelectedPharmacist(p)}
                className={`flex w-full items-center gap-3 rounded-2xl border p-3 text-left transition ${
                  active ? 'border-line bg-emerald-50' : 'border-transparent bg-white shadow-soft'
                }`}
              >
                {p.image_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={p.image_url} alt={p.name} className="h-12 w-12 rounded-full object-cover" />
                ) : (
                  <div className="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <Stethoscope className="h-5 w-5" />
                  </div>
                )}
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-slate-800">
                    {p.title ? `${p.title} ` : ''}
                    {p.name}
                  </p>
                  <p className="truncate text-xs text-slate-500">
                    {p.specialty || 'เภสัชกร'}
                    {p.consultation_duration ? ` · ${p.consultation_duration} นาที/ครั้ง` : ''}
                  </p>
                </div>
                {active && <CheckCircle2 className="h-5 w-5 text-line" />}
              </button>
            )
          })}
        </div>
      </section>

      {/* Step 2: Date */}
      {selectedPharmacist && (
        <section className="mt-6 space-y-3">
          <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-700">
            <CalendarDays className="h-4 w-4 text-line" /> เลือกวันที่
          </h3>
          <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            {days.map((d) => {
              const active = selectedDate === d.iso
              return (
                <button
                  key={d.iso}
                  type="button"
                  onClick={() => setSelectedDate(d.iso)}
                  className={`flex min-w-[60px] flex-col items-center rounded-2xl px-3 py-2 text-xs transition ${
                    active ? 'bg-line text-white' : 'bg-white text-slate-700 shadow-soft'
                  }`}
                >
                  <span className="opacity-80">{d.sub}</span>
                  <span className="text-base font-semibold">{d.label}</span>
                </button>
              )
            })}
          </div>
        </section>
      )}

      {/* Step 3: Time */}
      {selectedPharmacist && selectedDate && (
        <section className="mt-6 space-y-3">
          <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-700">
            <Clock className="h-4 w-4 text-line" /> เลือกเวลา
          </h3>

          {slotsLoading && <p className="text-xs text-slate-400">กำลังโหลด…</p>}

          {!slotsLoading && slots.length === 0 && (
            <p className="rounded-2xl bg-white p-3 text-center text-sm text-slate-500 shadow-soft">
              {slotsMessage || 'ไม่มีช่วงเวลาว่างในวันที่เลือก'}
            </p>
          )}

          {!slotsLoading && slots.length > 0 && (
            <div className="grid grid-cols-4 gap-2">
              {slots.map((s) => {
                const active = selectedTime === s.time
                return (
                  <button
                    key={s.time}
                    type="button"
                    disabled={!s.available}
                    onClick={() => setSelectedTime(s.time)}
                    className={`rounded-xl px-2 py-2 text-sm transition ${
                      !s.available
                        ? 'cursor-not-allowed bg-slate-100 text-slate-300 line-through'
                        : active
                        ? 'bg-line text-white'
                        : 'bg-white text-slate-700 shadow-soft'
                    }`}
                  >
                    {s.time}
                  </button>
                )
              })}
            </div>
          )}
        </section>
      )}

      {/* Step 4: Type + notes */}
      {selectedPharmacist && selectedDate && selectedTime && (
        <section className="mt-6 space-y-4">
          <div>
            <h3 className="text-sm font-semibold text-slate-700">ประเภทการปรึกษา</h3>
            <div className="mt-2 grid grid-cols-2 gap-2">
              {CONSULTATION_TYPES.map((t) => {
                const active = types.includes(t.id)
                return (
                  <button
                    key={t.id}
                    type="button"
                    onClick={() => toggleType(t.id)}
                    className={`rounded-xl px-3 py-2 text-sm transition ${
                      active ? 'bg-line text-white' : 'bg-white text-slate-700 shadow-soft'
                    }`}
                  >
                    {t.label}
                  </button>
                )
              })}
            </div>
          </div>

          <div>
            <label htmlFor="notes" className="text-sm font-semibold text-slate-700">
              บันทึกเพิ่มเติม
            </label>
            <textarea
              id="notes"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={3}
              maxLength={500}
              placeholder="อาการ ยาที่ใช้อยู่ หรือสิ่งที่อยากปรึกษา…"
              className="mt-2 w-full rounded-2xl border border-slate-200 bg-white p-3 text-sm focus:border-line focus:outline-none focus:ring-2 focus:ring-emerald-100"
            />
            <p className="mt-1 text-right text-xs text-slate-400">{notes.length}/500</p>
          </div>

          {submitError && (
            <div className="rounded-2xl bg-rose-50 p-3 text-sm text-rose-700">{submitError}</div>
          )}

          <button
            type="button"
            onClick={handleSubmit}
            disabled={!canSubmit}
            className="flex w-full items-center justify-center gap-2 rounded-full bg-line px-5 py-3 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50"
          >
            <Video className="h-4 w-4" />
            {submitting ? 'กำลังบันทึก…' : 'ยืนยันการนัดหมาย'}
          </button>

          <div className="rounded-2xl bg-slate-50 p-3 text-xs text-slate-500">
            สรุป: {selectedPharmacist.name} · {formatThaiDate(selectedDate)} เวลา {selectedTime} น.
          </div>
        </section>
      )}
    </AppShell>
  )
}
