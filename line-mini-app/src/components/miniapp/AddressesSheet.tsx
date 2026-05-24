'use client'

import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, ChevronUp, Plus, Trash2, X } from 'lucide-react'
import { useToast } from '@/lib/toast'
import {
  ADDRESS_LABEL_TH,
  ADDRESS_LABELS,
  addressHasData,
  deleteAddress,
  listAddresses,
  upsertAddress,
  type AddressLabel,
  type UserAddress
} from '@/lib/addresses-api'

type Draft = {
  name: string
  phone: string
  address: string
  subdistrict: string
  district: string
  province: string
  postcode: string
}

const EMPTY_DRAFT: Draft = {
  name: '',
  phone: '',
  address: '',
  subdistrict: '',
  district: '',
  province: '',
  postcode: ''
}

function rowToDraft(a?: UserAddress | null): Draft {
  return {
    name: a?.name ?? '',
    phone: a?.phone ?? '',
    address: a?.address ?? '',
    subdistrict: a?.subdistrict ?? '',
    district: a?.district ?? '',
    province: a?.province ?? '',
    postcode: a?.postcode ?? ''
  }
}

function draftEquals(a: Draft, b: Draft): boolean {
  return a.name === b.name &&
    a.phone === b.phone &&
    a.address === b.address &&
    a.subdistrict === b.subdistrict &&
    a.district === b.district &&
    a.province === b.province &&
    a.postcode === b.postcode
}

function draftIsEmpty(d: Draft): boolean {
  return !d.name.trim() && !d.phone.trim() && !d.address.trim()
}

interface AddressSectionProps {
  label: AddressLabel
  serverRow: UserAddress | undefined
  lineUserId: string
  initiallyOpen: boolean
}

function AddressSection({ label, serverRow, lineUserId, initiallyOpen }: AddressSectionProps) {
  const qc = useQueryClient()
  const { toast } = useToast()

  const serverDraft = rowToDraft(serverRow)
  const [draft, setDraft] = useState<Draft>(serverDraft)
  const [open, setOpen] = useState(initiallyOpen)

  // Re-sync local draft when server data changes (and the user hasn't started
  // editing). Tracks via a snapshot of the last seen server draft.
  const [lastSyncedServer, setLastSyncedServer] = useState<Draft>(serverDraft)
  useEffect(() => {
    if (!draftEquals(serverDraft, lastSyncedServer)) {
      // Server changed — overwrite local draft only if user hasn't diverged.
      if (draftEquals(draft, lastSyncedServer)) {
        setDraft(serverDraft)
      }
      setLastSyncedServer(serverDraft)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serverDraft.name, serverDraft.phone, serverDraft.address, serverDraft.subdistrict, serverDraft.district, serverDraft.province, serverDraft.postcode])

  const saveMutation = useMutation({
    mutationFn: () => upsertAddress(lineUserId, label, {
      name: draft.name.trim() || null,
      phone: draft.phone.trim() || null,
      address: draft.address.trim() || null,
      subdistrict: draft.subdistrict.trim() || null,
      district: draft.district.trim() || null,
      province: draft.province.trim() || null,
      postcode: draft.postcode.trim() || null
    }),
    onSuccess: (data) => {
      if (data?.success === false) {
        toast.error(data.error || 'บันทึกไม่สำเร็จ')
        return
      }
      toast.success(data?.message || 'บันทึกที่อยู่แล้ว')
      qc.invalidateQueries({ queryKey: ['user-addresses', lineUserId] })
    },
    onError: (e: Error) => toast.error(e.message || 'บันทึกไม่สำเร็จ')
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteAddress(lineUserId, label),
    onSuccess: (data) => {
      if (data?.success === false) {
        toast.error(data.error || 'ลบไม่สำเร็จ')
        return
      }
      toast.success(data?.message || 'ลบที่อยู่สำรองแล้ว')
      setDraft(EMPTY_DRAFT)
      qc.invalidateQueries({ queryKey: ['user-addresses', lineUserId] })
    },
    onError: (e: Error) => toast.error(e.message || 'ลบไม่สำเร็จ')
  })

  const isDirty = !draftEquals(draft, serverDraft)
  const isPrimary = label === 'primary'
  const hasServerData = serverRow ? addressHasData(serverRow) : false

  return (
    <div className={`rounded-2xl border ${isPrimary ? 'border-line bg-emerald-50/30' : 'border-slate-100 bg-slate-50/50'}`}>
      <button
        type="button"
        onClick={() => setOpen(v => !v)}
        className="flex w-full items-center justify-between px-4 py-3"
      >
        <div className="flex items-center gap-2">
          <span className={`text-sm font-bold ${isPrimary ? 'text-line' : 'text-slate-700'}`}>
            {ADDRESS_LABEL_TH[label]}
          </span>
          {isPrimary && (
            <span className="rounded-full bg-line px-2 py-0.5 text-[10px] font-semibold text-white">
              จัดส่งหลัก
            </span>
          )}
          {!hasServerData && !isPrimary && (
            <span className="text-xs text-slate-400">(ยังไม่ได้กรอก)</span>
          )}
        </div>
        {open ? <ChevronUp size={16} className="text-slate-400" /> : <ChevronDown size={16} className="text-slate-400" />}
      </button>

      {open && (
        <div className="border-t border-slate-100 px-4 pb-4 pt-3">
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">ชื่อผู้รับ</label>
                <input
                  type="text"
                  value={draft.name}
                  onChange={e => setDraft({ ...draft, name: e.target.value })}
                  placeholder="ชื่อ-นามสกุล"
                  className="input-field w-full text-sm"
                  maxLength={255}
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">เบอร์โทร</label>
                <input
                  type="tel"
                  value={draft.phone}
                  onChange={e => setDraft({ ...draft, phone: e.target.value })}
                  placeholder="08x-xxx-xxxx"
                  className="input-field w-full text-sm"
                  maxLength={20}
                />
              </div>
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">ที่อยู่ (บ้านเลขที่, ถนน)</label>
              <textarea
                value={draft.address}
                onChange={e => setDraft({ ...draft, address: e.target.value })}
                placeholder="เช่น 123/45 ถ.รัชดาภิเษก"
                rows={2}
                className="input-field w-full text-sm"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">ตำบล/แขวง</label>
                <input
                  type="text"
                  value={draft.subdistrict}
                  onChange={e => setDraft({ ...draft, subdistrict: e.target.value })}
                  className="input-field w-full text-sm"
                  maxLength={100}
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">อำเภอ/เขต</label>
                <input
                  type="text"
                  value={draft.district}
                  onChange={e => setDraft({ ...draft, district: e.target.value })}
                  className="input-field w-full text-sm"
                  maxLength={100}
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">จังหวัด</label>
                <input
                  type="text"
                  value={draft.province}
                  onChange={e => setDraft({ ...draft, province: e.target.value })}
                  className="input-field w-full text-sm"
                  maxLength={100}
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">รหัสไปรษณีย์</label>
                <input
                  type="text"
                  value={draft.postcode}
                  onChange={e => setDraft({ ...draft, postcode: e.target.value })}
                  className="input-field w-full text-sm"
                  inputMode="numeric"
                  maxLength={10}
                />
              </div>
            </div>

            <div className="flex gap-2 pt-1">
              <button
                type="button"
                onClick={() => saveMutation.mutate()}
                disabled={saveMutation.isPending || !isDirty || draftIsEmpty(draft)}
                className="flex-1 rounded-xl bg-line py-2.5 text-sm font-semibold text-white transition-opacity disabled:opacity-50"
              >
                {saveMutation.isPending ? 'กำลังบันทึก...' : 'บันทึก'}
              </button>
              {!isPrimary && hasServerData && (
                <button
                  type="button"
                  onClick={() => {
                    if (window.confirm('ลบที่อยู่สำรองนี้?')) deleteMutation.mutate()
                  }}
                  disabled={deleteMutation.isPending}
                  className="rounded-xl border border-red-200 bg-white px-3 py-2.5 text-sm font-semibold text-red-500 transition-colors hover:bg-red-50 disabled:opacity-50"
                  title="ลบที่อยู่สำรอง"
                >
                  <Trash2 size={16} />
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

interface AddressesSheetProps {
  lineUserId: string
  onClose: () => void
}

export function AddressesSheet({ lineUserId, onClose }: AddressesSheetProps) {
  const addressesQuery = useQuery({
    queryKey: ['user-addresses', lineUserId],
    queryFn: () => listAddresses(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const rows = addressesQuery.data?.addresses ?? []
  const byLabel: Partial<Record<AddressLabel, UserAddress>> = {}
  rows.forEach(r => { byLabel[r.label] = r })

  // Decide which sections are open by default:
  //   primary: always open
  //   secondary_N: open if has data, otherwise closed (collapsed empty)
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center" onClick={onClose}>
      <div className="absolute inset-0 bg-black/50" />
      <div
        className="relative w-full max-w-md rounded-t-3xl bg-white p-5 pb-safe-bottom max-h-[90vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <h3 className="text-base font-bold text-slate-900">ที่อยู่จัดส่ง</h3>
          <button onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
            <X size={20} />
          </button>
        </div>

        {addressesQuery.isLoading ? (
          <div className="space-y-3">
            {[1, 2, 3, 4].map(i => <div key={i} className="skeleton h-20 w-full rounded-2xl" />)}
          </div>
        ) : (
          <div className="space-y-3">
            {ADDRESS_LABELS.map((label) => {
              const row = byLabel[label]
              const hasData = row ? addressHasData(row) : false
              const isPrimary = label === 'primary'
              const initiallyOpen = isPrimary || hasData
              return (
                <AddressSection
                  key={label}
                  label={label}
                  serverRow={row}
                  lineUserId={lineUserId}
                  initiallyOpen={initiallyOpen}
                />
              )
            })}
          </div>
        )}

        <p className="mt-4 text-center text-xs text-slate-400 flex items-center justify-center gap-1">
          <Plus size={12} />
          กรอกข้อมูลในส่วนที่ต้องการแล้วกดบันทึก
        </p>

        <button
          type="button"
          onClick={onClose}
          className="mt-3 w-full rounded-xl bg-line py-3 text-sm font-semibold text-white"
        >
          ปิด
        </button>
      </div>
    </div>
  )
}
