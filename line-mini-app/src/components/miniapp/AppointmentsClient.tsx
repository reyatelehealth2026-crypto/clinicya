'use client'

import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Calendar, ChevronRight, Clock, Star, X } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import {
  APPOINTMENT_STATUS_COLOR,
  APPOINTMENT_STATUS_LABEL,
  bookAppointment,
  cancelAppointment,
  getMyAppointments,
  getPharmacists
} from '@/lib/appointments-api'
import type { Pharmacist } from '@/lib/appointments-api'

const APPOINTMENT_TYPES = [
  { key: 'consultation', label: 'ปรึกษายาและสุขภาพ' },
  { key: 'review', label: 'ทบทวนยา' },
  { key: 'chronic', label: 'โรคเรื้อรัง' },
  { key: 'other', label: 'อื่นๆ' }
]

function PharmacistCard({ p, onSelect }: { p: Pharmacist; onSelect: () => void }) {
  return (
    <button
      type="button"
      onClick={onSelect}
      className="flex w-full items-center gap-3 rounded-2xl bg-white p-3 shadow-soft transition-colors hover:bg-slate-50"
    >
      {p.image_url ? (
        <img src={p.image_url} alt={p.name} className="h-14 w-14 rounded-xl object-cover" />
      ) : (
        <div className="flex h-14 w-14 items-center justify-center rounded-xl bg-line-soft text-xl">👨‍⚕️</div>
      )}
      <div className="min-w-0 flex-1 text-left">
        <p className="truncate text-sm font-bold text-slate-900">{p.name}</p>
        <p className="text-xs text-slate-500">{p.specialty ?? 'เภสัชกร'}</p>
        <div className="mt-1 flex items-center gap-2">
          {p.rating != null && (
            <span className="flex items-center gap-0.5 text-xs font-semibold text-amber-500">
              <Star size={11} fill="currentColor" /> {Number(p.rating).toFixed(1)}
            </span>
          )}
          {p.consultation_fee != null && (
            <span className="text-xs text-slate-400">
              {Number(p.consultation_fee) === 0 ? 'ฟรี' : `฿${Number(p.consultation_fee).toLocaleString()}`}
            </span>
          )}
          {p.is_available === 0 && (
            <span className="badge badge-red">ไม่ว่าง</span>
          )}
        </div>
      </div>
      <ChevronRight size={16} className="shrink-0 text-slate-300" />
    </button>
  )
}

function BookingModal({ pharmacist, lineUserId, onClose }: {
  pharmacist: Pharmacist
  lineUserId: string
  onClose: () => void
}) {
  const qc = useQueryClient()
  const today = new Date().toISOString().split('T')[0]

  const [date, setDate] = useState(today)
  const [time, setTime] = useState('09:00')
  const [type, setType] = useState('consultation')
  const [notes, setNotes] = useState('')

  const bookMutation = useMutation({
    mutationFn: () => bookAppointment({
      line_user_id: lineUserId,
      pharmacist_id: pharmacist.id,
      appointment_date: date,
      appointment_time: time,
      notes,
      type
    }),
    onSuccess: (data) => {
      if (data.success) {
        qc.invalidateQueries({ queryKey: ['appointments', lineUserId] })
        onClose()
      }
    }
  })

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="relative w-full max-w-md rounded-t-3xl bg-white p-5 pb-safe-bottom">
        <div className="mb-4 flex items-center justify-between">
          <p className="text-base font-bold text-slate-900">นัดหมายกับ {pharmacist.name}</p>
          <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
            <X size={18} />
          </button>
        </div>
        <div className="space-y-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">วันที่</label>
            <input
              type="date"
              value={date}
              min={today}
              onChange={e => setDate(e.target.value)}
              className="input-field w-full"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">เวลา</label>
            <input
              type="time"
              value={time}
              onChange={e => setTime(e.target.value)}
              className="input-field w-full"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">ประเภทการปรึกษา</label>
            <div className="grid grid-cols-2 gap-2">
              {APPOINTMENT_TYPES.map(({ key, label }) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => setType(key)}
                  className={`rounded-xl py-2 text-xs font-medium transition-all ${
                    type === key ? 'bg-line text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-500">บันทึกเพิ่มเติม</label>
            <textarea
              value={notes}
              onChange={e => setNotes(e.target.value)}
              placeholder="อาการที่ต้องการปรึกษา..."
              rows={2}
              className="input-field w-full resize-none"
            />
          </div>
          {bookMutation.data && !bookMutation.data.success && (
            <p className="text-xs text-rose-500">{bookMutation.data.message ?? 'เกิดข้อผิดพลาด'}</p>
          )}
          <button
            type="button"
            onClick={() => bookMutation.mutate()}
            disabled={bookMutation.isPending}
            className="w-full rounded-xl bg-line py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            {bookMutation.isPending ? 'กำลังนัดหมาย...' : 'ยืนยันการนัดหมาย'}
          </button>
        </div>
      </div>
    </div>
  )
}

export function AppointmentsClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const qc = useQueryClient()

  const [selectedPharmacist, setSelectedPharmacist] = useState<Pharmacist | null>(null)
  const [tab, setTab] = useState<'upcoming' | 'book'>('upcoming')

  const appointmentsQuery = useQuery({
    queryKey: ['appointments', lineUserId],
    queryFn: () => getMyAppointments(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const pharmacistsQuery = useQuery({
    queryKey: ['pharmacists'],
    queryFn: getPharmacists,
    staleTime: 60_000
  })

  const cancelMutation = useMutation({
    mutationFn: (id: number) => cancelAppointment(lineUserId, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['appointments', lineUserId] })
  })

  const handleRefresh = useCallback(async () => {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['appointments', lineUserId] }),
      qc.invalidateQueries({ queryKey: ['pharmacists'] })
    ])
  }, [qc, lineUserId])

  const appointments = appointmentsQuery.data?.appointments ?? []
  const pharmacists = pharmacistsQuery.data?.pharmacists ?? []

  return (
    <AppShell title="นัดหมาย" subtitle="นัดปรึกษาเภสัชกร" onRefresh={handleRefresh}>
      {/* Tab switcher */}
      <div className="flex rounded-2xl bg-slate-100 p-1">
        {(['upcoming', 'book'] as const).map(t => (
          <button
            key={t}
            type="button"
            onClick={() => setTab(t)}
            className={`flex-1 rounded-xl py-2 text-xs font-semibold transition-all ${
              tab === t ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'
            }`}
          >
            {t === 'upcoming' ? `นัดหมายของฉัน (${appointments.length})` : 'นัดหมายใหม่'}
          </button>
        ))}
      </div>

      {tab === 'upcoming' ? (
        appointmentsQuery.isLoading ? (
          <div className="space-y-3">
            {[1, 2].map(i => <div key={i} className="skeleton h-24 w-full rounded-2xl" />)}
          </div>
        ) : appointments.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-blue-50">
              <Calendar size={28} className="text-blue-400" />
            </div>
            <div>
              <p className="text-sm font-semibold text-slate-700">ยังไม่มีนัดหมาย</p>
              <p className="mt-1 text-xs text-slate-400">กดแท็บ "นัดหมายใหม่" เพื่อเริ่มต้น</p>
            </div>
            <button
              type="button"
              onClick={() => setTab('book')}
              className="rounded-2xl bg-line px-5 py-2 text-sm font-semibold text-white"
            >
              นัดหมายใหม่
            </button>
          </div>
        ) : (
          <div className="space-y-3">
            {appointments.map(a => (
              <div key={a.id} className="rounded-2xl bg-white p-4 shadow-soft">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="text-sm font-bold text-slate-900">
                        {a.pharmacist_name ?? `เภสัชกร #${a.pharmacist_id}`}
                      </p>
                      <span className={`badge ${APPOINTMENT_STATUS_COLOR[a.status] ?? 'badge-slate'}`}>
                        {APPOINTMENT_STATUS_LABEL[a.status] ?? a.status}
                      </span>
                    </div>
                    <div className="mt-1.5 flex items-center gap-3 text-xs text-slate-500">
                      <span className="flex items-center gap-1">
                        <Calendar size={12} />
                        {new Date(a.appointment_date).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' })}
                      </span>
                      <span className="flex items-center gap-1">
                        <Clock size={12} />
                        {a.appointment_time}
                      </span>
                    </div>
                    {a.notes && <p className="mt-1 text-xs text-slate-400">{a.notes}</p>}
                  </div>
                  {(a.status === 'pending' || a.status === 'confirmed') && (
                    <button
                      type="button"
                      onClick={() => cancelMutation.mutate(a.id)}
                      disabled={cancelMutation.isPending && cancelMutation.variables === a.id}
                      className="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-500 hover:bg-rose-50 disabled:opacity-40"
                    >
                      ยกเลิก
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )
      ) : (
        pharmacistsQuery.isLoading ? (
          <div className="space-y-3">
            {[1, 2, 3].map(i => <div key={i} className="skeleton h-20 w-full rounded-2xl" />)}
          </div>
        ) : pharmacists.length === 0 ? (
          <div className="flex flex-col items-center gap-3 py-12 text-center">
            <p className="text-sm text-slate-500">ไม่พบเภสัชกรในขณะนี้</p>
          </div>
        ) : (
          <div className="space-y-3">
            <p className="px-1 text-xs font-semibold text-slate-400">เลือกเภสัชกร</p>
            {pharmacists.map(p => (
              <PharmacistCard key={p.id} p={p} onSelect={() => setSelectedPharmacist(p)} />
            ))}
          </div>
        )
      )}

      {selectedPharmacist && (
        <BookingModal
          pharmacist={selectedPharmacist}
          lineUserId={lineUserId}
          onClose={() => setSelectedPharmacist(null)}
        />
      )}
    </AppShell>
  )
}
