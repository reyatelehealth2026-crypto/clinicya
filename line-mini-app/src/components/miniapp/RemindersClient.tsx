'use client'

import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCircle2, Clock, Plus, Trash2, X } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import {
  FREQUENCY_LABELS,
  addReminder,
  deleteReminder,
  getReminders,
  markTaken
} from '@/lib/reminders-api'

const FREQUENCIES = Object.entries(FREQUENCY_LABELS)
const DEFAULT_TIMES = ['08:00']

function AdherenceBadge({ percent }: { percent: number }) {
  const color = percent >= 80 ? 'text-emerald-600 bg-emerald-50' : percent >= 50 ? 'text-amber-600 bg-amber-50' : 'text-rose-600 bg-rose-50'
  return (
    <span className={`inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-xs font-semibold ${color}`}>
      {percent}% ตรงเวลา
    </span>
  )
}

export function RemindersClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const qc = useQueryClient()

  const remindersQuery = useQuery({
    queryKey: ['reminders', lineUserId],
    queryFn: () => getReminders(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteReminder(lineUserId, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['reminders', lineUserId] })
  })

  const takenMutation = useMutation({
    mutationFn: ({ id, time }: { id: number; time?: string }) => markTaken(lineUserId, id, time),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['reminders', lineUserId] })
  })

  const addMutation = useMutation({
    mutationFn: (d: Parameters<typeof addReminder>[1]) => addReminder(lineUserId, d),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['reminders', lineUserId] })
      setShowForm(false)
      setMedName('')
      setDosage('')
      setFrequency('daily')
      setTimes(['08:00'])
    }
  })

  const handleRefresh = useCallback(async () => {
    await qc.invalidateQueries({ queryKey: ['reminders', lineUserId] })
  }, [qc, lineUserId])

  /* Form state */
  const [showForm, setShowForm] = useState(false)
  const [medName, setMedName] = useState('')
  const [dosage, setDosage] = useState('')
  const [frequency, setFrequency] = useState('daily')
  const [times, setTimes] = useState<string[]>(DEFAULT_TIMES)

  const reminders = remindersQuery.data?.reminders ?? []

  function updateTime(i: number, val: string) {
    setTimes(prev => prev.map((t, idx) => idx === i ? val : t))
  }
  function addTime() {
    if (times.length < 4) setTimes(prev => [...prev, '08:00'])
  }
  function removeTime(i: number) {
    if (times.length > 1) setTimes(prev => prev.filter((_, idx) => idx !== i))
  }

  return (
    <AppShell title="เตือนทานยา" subtitle="ตั้งการแจ้งเตือนยา" onRefresh={handleRefresh}>
      {/* Add button */}
      {!showForm && (
        <button
          type="button"
          onClick={() => setShowForm(true)}
          className="flex w-full items-center justify-center gap-2 rounded-2xl bg-line py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
        >
          <Plus size={18} /> เพิ่มการเตือนยา
        </button>
      )}

      {/* Add form */}
      {showForm && (
        <div className="rounded-2xl bg-white p-4 shadow-soft">
          <div className="mb-3 flex items-center justify-between">
            <p className="text-sm font-bold text-slate-900">เพิ่มการเตือนยา</p>
            <button
              type="button"
              onClick={() => setShowForm(false)}
              className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"
            >
              <X size={16} />
            </button>
          </div>
          <div className="space-y-3">
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">ชื่อยา *</label>
              <input
                type="text"
                value={medName}
                onChange={e => setMedName(e.target.value)}
                placeholder="เช่น พาราเซตามอล"
                className="input-field w-full"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">ขนาดยา</label>
              <input
                type="text"
                value={dosage}
                onChange={e => setDosage(e.target.value)}
                placeholder="เช่น 1 เม็ด"
                className="input-field w-full"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">ความถี่</label>
              <select
                value={frequency}
                onChange={e => setFrequency(e.target.value)}
                className="input-field w-full"
              >
                {FREQUENCIES.map(([k, v]) => (
                  <option key={k} value={k}>{v}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">เวลาเตือน</label>
              <p className="mb-2 text-[11px] text-slate-400">(เวลาประเทศไทย)</p>
              <div className="space-y-2">
                {times.map((t, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <input
                      type="time"
                      value={t}
                      onChange={e => updateTime(i, e.target.value)}
                      className="input-field flex-1"
                    />
                    {times.length > 1 && (
                      <button
                        type="button"
                        onClick={() => removeTime(i)}
                        className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100"
                      >
                        <X size={14} />
                      </button>
                    )}
                  </div>
                ))}
                {times.length < 4 && (
                  <button
                    type="button"
                    onClick={addTime}
                    className="flex items-center gap-1 text-xs font-medium text-line"
                  >
                    <Plus size={14} /> เพิ่มเวลา
                  </button>
                )}
              </div>
            </div>
            <button
              type="button"
              disabled={!medName.trim() || addMutation.isPending}
              onClick={() =>
                addMutation.mutate({
                  medication_name: medName.trim(),
                  dosage: dosage.trim() || undefined,
                  frequency,
                  reminder_times: times
                })
              }
              className="w-full rounded-xl bg-line py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              {addMutation.isPending ? 'กำลังบันทึก...' : 'บันทึก'}
            </button>
          </div>
        </div>
      )}

      {/* Reminders list */}
      {remindersQuery.isLoading ? (
        <div className="space-y-3">
          {[1, 2].map(i => <div key={i} className="skeleton h-28 w-full rounded-2xl" />)}
        </div>
      ) : reminders.length === 0 ? (
        <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
          <div className="flex h-16 w-16 items-center justify-center rounded-full bg-line-soft">
            <Bell size={28} className="text-line" />
          </div>
          <div>
            <p className="text-sm font-semibold text-slate-700">ยังไม่มีการเตือนยา</p>
            <p className="mt-1 text-xs text-slate-400">กดปุ่มด้านบนเพื่อเพิ่มการเตือน</p>
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          {reminders.map(r => (
            <div key={r.id} className="rounded-2xl bg-white p-4 shadow-soft">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <p className="truncate text-sm font-bold text-slate-900">{r.medication_name}</p>
                    <AdherenceBadge percent={r.adherence_percent} />
                  </div>
                  {r.dosage && (
                    <p className="mt-0.5 text-xs text-slate-500">{r.dosage}</p>
                  )}
                  <p className="mt-0.5 text-xs text-slate-400">
                    {FREQUENCY_LABELS[r.frequency] ?? r.frequency}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => deleteMutation.mutate(r.id)}
                  disabled={deleteMutation.isPending && deleteMutation.variables === r.id}
                  className="rounded-lg p-1.5 text-slate-300 hover:bg-rose-50 hover:text-rose-400 disabled:opacity-40"
                >
                  <Trash2 size={16} />
                </button>
              </div>

              {/* Time slots */}
              <div className="mt-3 flex flex-wrap gap-2">
                {r.reminder_times.map((t) => (
                  <div key={t} className="flex items-center gap-1.5 rounded-xl bg-slate-50 px-2.5 py-1.5">
                    <Clock size={12} className="text-slate-400" />
                    <span className="text-xs font-semibold tabular-nums text-slate-700">{t}</span>
                    <button
                      type="button"
                      onClick={() => takenMutation.mutate({ id: r.id, time: t })}
                      disabled={takenMutation.isPending}
                      className="ml-1 text-emerald-500 hover:text-emerald-700 disabled:opacity-40"
                      title="บันทึกทานยาแล้ว"
                    >
                      <CheckCircle2 size={14} />
                    </button>
                  </div>
                ))}
              </div>

              {/* 7-day stats */}
              {(r.taken_count_7d > 0 || r.missed_count_7d > 0) && (
                <p className="mt-2 text-xs text-slate-400">
                  7 วัน: ทาน {r.taken_count_7d} ครั้ง · พลาด {r.missed_count_7d} ครั้ง
                </p>
              )}
            </div>
          ))}
        </div>
      )}
    </AppShell>
  )
}
