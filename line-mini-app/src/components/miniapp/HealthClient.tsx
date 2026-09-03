'use client'

import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Activity, AlertTriangle, ChevronDown, ChevronUp, Pill, Plus, Trash2, User } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { useToast } from '@/lib/toast'
import { AppShell } from '@/components/miniapp/AppShell'
import { closeMiniApp, sendTextToChat } from '@/lib/line-miniapp'
import {
  MEDICAL_CONDITIONS,
  addAllergy,
  addMedication,
  getHealthProfile,
  removeAllergy,
  removeMedication,
  updateMedicalHistory,
  updatePersonalInfo
} from '@/lib/health-api'
import type { HealthPersonalInfo } from '@/lib/health-api'

const BLOOD_TYPES = ['A', 'B', 'AB', 'O', 'unknown'] as const
const BLOOD_TYPE_LABEL: Record<string, string> = {
  A: 'A', B: 'B', AB: 'AB', O: 'O', unknown: 'ไม่ทราบ'
}
const GENDER_LABEL: Record<string, string> = {
  male: 'ชาย', female: 'หญิง', other: 'อื่นๆ'
}
const SEVERITY_LABEL: Record<string, string> = {
  mild: 'เล็กน้อย', moderate: 'ปานกลาง', severe: 'รุนแรง'
}
const REACTION_LABEL: Record<string, string> = {
  rash: 'ผื่นคัน', breathing: 'หายใจลำบาก', swelling: 'บวม', other: 'อื่นๆ'
}

function SectionCard({ title, icon: Icon, children }: {
  title: string
  icon: typeof User
  children: React.ReactNode
}) {
  const [open, setOpen] = useState(true)
  return (
    <div className="overflow-hidden rounded-2xl bg-white shadow-soft">
      <button
        type="button"
        onClick={() => setOpen(v => !v)}
        className="flex w-full items-center justify-between px-4 py-3.5"
      >
        <div className="flex items-center gap-2">
          <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-line-soft">
            <Icon size={16} className="text-line" />
          </div>
          <span className="text-sm font-bold text-slate-900">{title}</span>
        </div>
        {open ? <ChevronUp size={16} className="text-slate-400" /> : <ChevronDown size={16} className="text-slate-400" />}
      </button>
      {open && <div className="border-t border-slate-50 px-4 pb-4 pt-3">{children}</div>}
    </div>
  )
}

export function HealthClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const qc = useQueryClient()
  const { toast } = useToast()

  const profileQuery = useQuery({
    queryKey: ['health-profile', lineUserId],
    queryFn: () => getHealthProfile(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const profile = profileQuery.data?.profile

  const personalMutation = useMutation({
    mutationFn: (data: HealthPersonalInfo) => updatePersonalInfo(lineUserId, data),
    onSuccess: (data) => {
      if (data?.success === false) {
        toast.error(data.message || 'บันทึกข้อมูลไม่สำเร็จ')
        return
      }
      toast.success('บันทึกข้อมูลส่วนตัวแล้ว')
      hasUserEditedRef.current = false
      qc.invalidateQueries({ queryKey: ['health-profile', lineUserId] })
    },
    onError: (e: Error) => toast.error(e.message || 'บันทึกข้อมูลไม่สำเร็จ')
  })
  // Optimistic update for medical condition chips so the UI reacts instantly
  const conditionsMutation = useMutation({
    mutationFn: (conditions: string[]) => updateMedicalHistory(lineUserId, conditions),
    onMutate: async (next: string[]) => {
      await qc.cancelQueries({ queryKey: ['health-profile', lineUserId] })
      const snapshot = qc.getQueryData<{ success: boolean; profile?: { medical_conditions: string[] } }>(
        ['health-profile', lineUserId]
      )
      // Patch the cached profile in-place so chips toggle immediately
      qc.setQueryData(['health-profile', lineUserId], (old: any) => {
        if (!old?.profile) return old
        return { ...old, profile: { ...old.profile, medical_conditions: next } }
      })
      return { snapshot }
    },
    onError: (e: Error, _vars, ctx) => {
      if (ctx?.snapshot) qc.setQueryData(['health-profile', lineUserId], ctx.snapshot)
      toast.error(e.message || 'บันทึกโรคประจำตัวไม่สำเร็จ')
    },
    onSuccess: (data) => {
      if (data?.success === false) {
        toast.error(data.message || 'บันทึกโรคประจำตัวไม่สำเร็จ')
        return
      }
      toast.success('บันทึกโรคประจำตัวแล้ว')
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['health-profile', lineUserId] })
  })
  const removeAllergyMutation = useMutation({
    mutationFn: (id: number) => removeAllergy(lineUserId, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['health-profile', lineUserId] })
  })
  const addAllergyMutation = useMutation({
    mutationFn: (d: { drug_name: string; reaction_type: string; severity: string }) =>
      addAllergy(lineUserId, d),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['health-profile', lineUserId] })
  })
  const removeMedMutation = useMutation({
    mutationFn: (id: number) => removeMedication(lineUserId, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['health-profile', lineUserId] })
  })
  const addMedMutation = useMutation({
    mutationFn: (d: { medication_name: string; dosage?: string; frequency?: string }) =>
      addMedication(lineUserId, d),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['health-profile', lineUserId] })
  })

  /* Local form state */
  const [personal, setPersonal] = useState<HealthPersonalInfo | null>(null)
  const [newAllergy, setNewAllergy] = useState('')
  const [newAllergyReaction, setNewAllergyReaction] = useState<'rash' | 'breathing' | 'swelling' | 'other'>('other')
  const [newAllergySeverity, setNewAllergySeverity] = useState<'mild' | 'moderate' | 'severe'>('moderate')
  const [newMed, setNewMed] = useState('')
  const [newMedDosage, setNewMedDosage] = useState('')

  // Tracks whether the user has touched the personal form since the last
  // successful save. While false, we always re-sync the form with whatever
  // the server returned (covers Bug #3: data not appearing after refresh).
  const hasUserEditedRef = useRef(false)

  const handleRefresh = useCallback(async () => {
    await qc.invalidateQueries({ queryKey: ['health-profile', lineUserId] })
  }, [qc, lineUserId])

  /* Sync personal form with loaded data */
  const pi = profile?.personal_info

  // Normalize server fields (PHP returns DECIMAL columns as strings, JSON
  // returns ints) to numbers so isDirty equality works.
  const piNormalized: HealthPersonalInfo | null = pi ? {
    name: pi.name ?? null,
    age: pi.age != null ? Number(pi.age) || null : null,
    gender: pi.gender ?? null,
    weight: pi.weight != null ? Number(pi.weight) || null : null,
    height: pi.height != null ? Number(pi.height) || null : null,
    blood_type: pi.blood_type ?? 'unknown'
  } : null

  /* Re-sync local form with server data whenever the server data changes,
   * UNLESS the user has uncommitted edits in flight. This fixes the
   * "save succeeds but disappears after refresh" bug. */
  useEffect(() => {
    if (piNormalized && !hasUserEditedRef.current) {
      setPersonal(piNormalized)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [piNormalized?.name, piNormalized?.age, piNormalized?.gender, piNormalized?.weight, piNormalized?.height, piNormalized?.blood_type])

  const personalForm: HealthPersonalInfo = personal ?? piNormalized ?? {
    name: null,
    age: null,
    gender: null,
    weight: null,
    height: null,
    blood_type: 'unknown'
  }

  // Updater that also marks the form as user-edited.
  const updatePersonal = (patch: Partial<HealthPersonalInfo>) => {
    hasUserEditedRef.current = true
    setPersonal({ ...personalForm, ...patch })
  }

  /* isDirty — เทียบ personal กับ piNormalized (server state) */
  const isDirty =
    personal !== null &&
    piNormalized !== null &&
    (
      (personal.name ?? null) !== (piNormalized.name ?? null) ||
      (personal.age ?? null) !== (piNormalized.age ?? null) ||
      (personal.gender ?? null) !== (piNormalized.gender ?? null) ||
      (personal.weight ?? null) !== (piNormalized.weight ?? null) ||
      (personal.height ?? null) !== (piNormalized.height ?? null) ||
      (personal.blood_type ?? 'unknown') !== (piNormalized.blood_type ?? 'unknown')
    )

  const completion = profile?.completion_percent ?? 0

  return (
    <AppShell title="ข้อมูลสุขภาพ" subtitle="โปรไฟล์สุขภาพของคุณ" onRefresh={handleRefresh}>
      {/* Completion bar */}
      {profile && (
        <div className="rounded-2xl bg-white p-4 shadow-soft">
          <div className="flex items-center justify-between mb-2">
            <p className="text-sm font-semibold text-slate-700">ความครบถ้วนของข้อมูล</p>
            <span className="text-sm font-bold text-line">{completion}%</span>
          </div>
          <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
            <div
              className="h-full rounded-full bg-line transition-all duration-500"
              style={{ width: `${completion}%` }}
            />
          </div>
        </div>
      )}

      {profileQuery.isLoading && (
        <div className="space-y-3">
          {[1, 2, 3].map(i => <div key={i} className="skeleton h-24 w-full rounded-2xl" />)}
        </div>
      )}

      {profile && (
        <>
          {/* Personal info */}
          <SectionCard title="ข้อมูลส่วนตัว" icon={User}>
            <div className="mb-3">
              <label className="mb-1 block text-xs font-medium text-slate-500">ชื่อ-นามสกุล</label>
              <input
                type="text"
                value={personalForm.name ?? ''}
                onChange={e => updatePersonal({ name: e.target.value || null })}
                placeholder="เช่น สมชาย ใจดี"
                className="input-field w-full"
                maxLength={255}
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">อายุ</label>
                <input
                  type="number"
                  value={personalForm.age ?? ''}
                  onChange={e => updatePersonal({ age: e.target.value ? Number(e.target.value) : null })}
                  placeholder="ปี"
                  className="input-field w-full"
                  min={0} max={150}
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">เพศ</label>
                <select
                  value={personalForm.gender ?? ''}
                  onChange={e => updatePersonal({ gender: (e.target.value as 'male' | 'female' | 'other') || null })}
                  className="input-field w-full"
                >
                  <option value="">เลือก</option>
                  {Object.entries(GENDER_LABEL).map(([k, v]) => (
                    <option key={k} value={k}>{v}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">น้ำหนัก (กก.)</label>
                <input
                  type="number"
                  value={personalForm.weight ?? ''}
                  onChange={e => updatePersonal({ weight: e.target.value ? Number(e.target.value) : null })}
                  placeholder="กก."
                  className="input-field w-full"
                  min={0}
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">ส่วนสูง (ซม.)</label>
                <input
                  type="number"
                  value={personalForm.height ?? ''}
                  onChange={e => updatePersonal({ height: e.target.value ? Number(e.target.value) : null })}
                  placeholder="ซม."
                  className="input-field w-full"
                  min={0}
                />
              </div>
              <div className="col-span-2">
                <label className="mb-1 block text-xs font-medium text-slate-500">หมู่เลือด</label>
                <div className="flex gap-2">
                  {BLOOD_TYPES.map(bt => (
                    <button
                      key={bt}
                      type="button"
                      onClick={() => setPersonal({ ...personalForm, blood_type: bt })}
                      className={`flex-1 rounded-xl py-2 text-xs font-semibold transition-all ${
                        personalForm.blood_type === bt
                          ? 'bg-line text-white'
                          : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                      }`}
                    >
                      {BLOOD_TYPE_LABEL[bt]}
                    </button>
                  ))}
                </div>
              </div>
            </div>
            <button
              type="button"
              onClick={() => personalMutation.mutate(personalForm)}
              disabled={personalMutation.isPending || !isDirty}
              className="mt-3 w-full rounded-xl bg-line py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              {personalMutation.isPending ? 'กำลังบันทึก...' : isDirty ? 'บันทึกข้อมูล' : 'บันทึกแล้ว'}
            </button>
            {personalMutation.isSuccess && (
              <p className="mt-2 text-center text-xs font-medium text-emerald-600">บันทึกแล้ว</p>
            )}
          </SectionCard>

          {/* Medical conditions */}
          <SectionCard title="โรคประจำตัว" icon={Activity}>
            <div className="grid grid-cols-2 gap-2">
              {MEDICAL_CONDITIONS.map(({ key, label }) => {
                const selected = (profile.medical_conditions ?? []).includes(key)
                return (
                  <button
                    key={key}
                    type="button"
                    onClick={() => {
                      const curr = profile.medical_conditions ?? []
                      const next = selected ? curr.filter(c => c !== key) : [...curr, key]
                      conditionsMutation.mutate(next)
                    }}
                    className={`rounded-xl px-3 py-2.5 text-left text-xs font-medium transition-all ${
                      selected
                        ? 'bg-line text-white'
                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                    }`}
                  >
                    {selected ? '✓ ' : ''}{label}
                  </button>
                )
              })}
            </div>
          </SectionCard>

          {/* Drug allergies */}
          <SectionCard title="การแพ้ยา" icon={AlertTriangle}>
            {profile.allergies.length > 0 ? (
              <div className="mb-3 space-y-2">
                {profile.allergies.map(a => (
                  <div key={a.id} className="flex items-center justify-between rounded-xl bg-rose-50 px-3 py-2">
                    <div>
                      <p className="text-sm font-semibold text-rose-800">{a.drug_name}</p>
                      <p className="text-xs text-rose-500">
                        {REACTION_LABEL[a.reaction_type]} · {SEVERITY_LABEL[a.severity]}
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={() => removeAllergyMutation.mutate(a.id)}
                      disabled={removeAllergyMutation.isPending}
                      className="ml-2 rounded-lg p-1.5 text-rose-400 hover:bg-rose-100 disabled:opacity-40"
                    >
                      <Trash2 size={14} />
                    </button>
                  </div>
                ))}
              </div>
            ) : (
              <p className="mb-3 text-xs text-slate-400">ยังไม่มีข้อมูลการแพ้ยา</p>
            )}
            <div className="space-y-2">
              <input
                type="text"
                value={newAllergy}
                onChange={e => setNewAllergy(e.target.value)}
                placeholder="ชื่อยาที่แพ้"
                className="input-field w-full"
              />
              <div className="flex gap-2">
                <select
                  value={newAllergyReaction}
                  onChange={e => setNewAllergyReaction(e.target.value as typeof newAllergyReaction)}
                  className="input-field flex-1"
                >
                  {Object.entries(REACTION_LABEL).map(([k, v]) => (
                    <option key={k} value={k}>{v}</option>
                  ))}
                </select>
                <select
                  value={newAllergySeverity}
                  onChange={e => setNewAllergySeverity(e.target.value as typeof newAllergySeverity)}
                  className="input-field flex-1"
                >
                  {Object.entries(SEVERITY_LABEL).map(([k, v]) => (
                    <option key={k} value={k}>{v}</option>
                  ))}
                </select>
              </div>
              <button
                type="button"
                disabled={!newAllergy.trim() || addAllergyMutation.isPending}
                onClick={() => {
                  if (!newAllergy.trim()) return
                  addAllergyMutation.mutate(
                    { drug_name: newAllergy.trim(), reaction_type: newAllergyReaction, severity: newAllergySeverity },
                    { onSuccess: () => setNewAllergy('') }
                  )
                }}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-rose-50 py-2 text-sm font-semibold text-rose-600 transition-all hover:bg-rose-100 disabled:opacity-50"
              >
                <Plus size={16} /> เพิ่มรายการแพ้ยา
              </button>
            </div>
          </SectionCard>

          {/* Current medications */}
          <SectionCard title="ยาที่ใช้ประจำ" icon={Pill}>
            {profile.medications.length > 0 ? (
              <div className="mb-3 space-y-2">
                {profile.medications.map(m => (
                  <div key={m.id} className="flex items-center justify-between rounded-xl bg-blue-50 px-3 py-2">
                    <div>
                      <p className="text-sm font-semibold text-blue-900">{m.medication_name}</p>
                      {m.dosage && <p className="text-xs text-blue-500">{m.dosage}{m.frequency ? ` · ${m.frequency}` : ''}</p>}
                    </div>
                    <button
                      type="button"
                      onClick={() => removeMedMutation.mutate(m.id)}
                      disabled={removeMedMutation.isPending}
                      className="ml-2 rounded-lg p-1.5 text-blue-400 hover:bg-blue-100 disabled:opacity-40"
                    >
                      <Trash2 size={14} />
                    </button>
                  </div>
                ))}
              </div>
            ) : (
              <p className="mb-3 text-xs text-slate-400">ยังไม่มีข้อมูลยาที่ใช้ประจำ</p>
            )}
            <div className="space-y-2">
              <input
                type="text"
                value={newMed}
                onChange={e => setNewMed(e.target.value)}
                placeholder="ชื่อยา"
                className="input-field w-full"
              />
              <input
                type="text"
                value={newMedDosage}
                onChange={e => setNewMedDosage(e.target.value)}
                placeholder="ขนาด เช่น 1 เม็ด วันละ 2 ครั้ง"
                className="input-field w-full"
              />
              <button
                type="button"
                disabled={!newMed.trim() || addMedMutation.isPending}
                onClick={() => {
                  if (!newMed.trim()) return
                  addMedMutation.mutate(
                    { medication_name: newMed.trim(), dosage: newMedDosage.trim() || undefined },
                    { onSuccess: () => { setNewMed(''); setNewMedDosage('') } }
                  )
                }}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-50 py-2 text-sm font-semibold text-blue-600 transition-all hover:bg-blue-100 disabled:opacity-50"
              >
                <Plus size={16} /> เพิ่มยาที่ใช้ประจำ
              </button>
              {addMedMutation.data?.has_interactions && (
                <div className="rounded-xl bg-amber-50 p-3 text-xs text-amber-700">
                  ⚠️ พบปฏิกิริยาระหว่างยา กรุณาปรึกษาเภสัชกร
                </div>
              )}
              {/* ขาย้อนกลับของ hybrid gateway — ส่งคำสั่งเข้าแชทแล้วปิดหน้าต่าง
                  ให้บอทตอบด้วยการ์ด "ขอรับยาเดิม" ที่มีปุ่มสั่งซ้ำรายตัว */}
              <button
                type="button"
                onClick={async () => {
                  const sent = await sendTextToChat('ขอรับยาเดิม')
                  if (sent) {
                    closeMiniApp()
                    return
                  }
                  toast.error('เปิดจากแชท LINE เพื่อใช้ปุ่มนี้')
                }}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-line-soft py-2 text-sm font-semibold text-line transition-all hover:opacity-90"
              >
                <Pill size={16} /> ขอรับยาเดิมในแชท
              </button>
            </div>
          </SectionCard>
        </>
      )}
    </AppShell>
  )
}
