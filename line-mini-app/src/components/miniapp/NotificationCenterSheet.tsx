'use client'

import Link from 'next/link'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Bell,
  CalendarCheck,
  Coins,
  HeartPulse,
  Package,
  PackageOpen,
  Pill,
  Tag,
  X,
} from 'lucide-react'
import { useToast } from '@/lib/toast'
import {
  NOTIFICATION_CATEGORIES,
  getNotificationPreferences,
  setNotificationPreference,
  type NotificationCategory,
  type NotificationPreferences,
} from '@/lib/service-messages'

interface NotificationCenterSheetProps {
  lineUserId: string
  onClose: () => void
}

type CategoryMeta = {
  key: NotificationCategory
  icon: typeof Bell
  title: string
  description: string
}

const CATEGORY_META: CategoryMeta[] = [
  { key: 'order_updates',         icon: Package,       title: 'อัพเดทออเดอร์',  description: 'แจ้งยืนยันออเดอร์ การจัดส่ง และการรับสินค้า' },
  { key: 'promotions',            icon: Tag,           title: 'โปรโมชั่น',       description: 'ข่าวสารโปรโมชั่นและส่วนลดพิเศษ' },
  { key: 'appointment_reminders', icon: CalendarCheck, title: 'เตือนนัดหมาย',    description: 'แจ้งเตือน 24 ชม. และ 30 นาทีก่อนนัดหมาย' },
  { key: 'med_reminders',         icon: Pill,          title: 'เตือนทานยา',      description: 'แจ้งเตือนเวลาทานยาตามที่ตั้งไว้' },
  { key: 'health_tips',           icon: HeartPulse,    title: 'เคล็ดลับสุขภาพ',  description: 'บทความและเคล็ดลับดูแลสุขภาพ' },
  { key: 'price_alerts',          icon: Bell,          title: 'แจ้งเตือนราคา',   description: 'แจ้งเมื่อสินค้าในรายการโปรดลดราคา' },
  { key: 'restock_alerts',        icon: PackageOpen,   title: 'แจ้งสินค้าเข้า',  description: 'แจ้งเมื่อสินค้าที่หมดกลับมามีสต๊อก' },
]

const DEFAULT_PREFS: NotificationPreferences = NOTIFICATION_CATEGORIES.reduce<NotificationPreferences>((acc, key) => {
  acc[key] = true
  return acc
}, {} as NotificationPreferences)

export function NotificationCenterSheet({ lineUserId, onClose }: NotificationCenterSheetProps) {
  const qc = useQueryClient()
  const { toast } = useToast()

  const prefsQuery = useQuery({
    queryKey: ['notification-preferences', lineUserId],
    queryFn: () => getNotificationPreferences(lineUserId),
    enabled: Boolean(lineUserId),
  })

  const prefs: NotificationPreferences = {
    ...DEFAULT_PREFS,
    ...(prefsQuery.data?.preferences ?? {}),
  }

  const toggleMutation = useMutation({
    mutationFn: ({ category, enabled }: { category: NotificationCategory; enabled: boolean }) =>
      setNotificationPreference(lineUserId, category, enabled),
    onMutate: async ({ category, enabled }) => {
      await qc.cancelQueries({ queryKey: ['notification-preferences', lineUserId] })
      const snapshot = qc.getQueryData(['notification-preferences', lineUserId])
      qc.setQueryData(['notification-preferences', lineUserId], (old: unknown) => {
        const oldData = (old as { preferences?: NotificationPreferences } | undefined) ?? {}
        return {
          ...oldData,
          preferences: { ...prefs, [category]: enabled },
        }
      })
      return { snapshot }
    },
    onError: (err, _vars, ctx) => {
      if (ctx?.snapshot !== undefined) {
        qc.setQueryData(['notification-preferences', lineUserId], ctx.snapshot)
      }
      toast.error(err instanceof Error ? err.message : 'บันทึกไม่สำเร็จ')
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['notification-preferences', lineUserId] }),
  })

  const allOff = NOTIFICATION_CATEGORIES.every(k => !prefs[k])

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center" onClick={onClose}>
      <div className="absolute inset-0 bg-black/50" />
      <div
        className="relative w-full max-w-md rounded-t-3xl bg-white p-5 pb-safe-bottom max-h-[90vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <h3 className="text-base font-bold text-slate-900">การแจ้งเตือน</h3>
          <button onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
            <X size={20} />
          </button>
        </div>

        {prefsQuery.isLoading ? (
          <div className="space-y-2">
            {[1, 2, 3, 4, 5, 6, 7].map(i => (
              <div key={i} className="skeleton h-14 w-full rounded-xl" />
            ))}
          </div>
        ) : (
          <div className="space-y-2">
            {CATEGORY_META.map(({ key, icon: Icon, title, description }) => {
              const on = prefs[key]
              return (
                <div
                  key={key}
                  className="flex items-center gap-3 rounded-xl bg-white px-3 py-2.5 ring-1 ring-slate-100"
                >
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-line-soft">
                    <Icon size={17} className="text-line" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-slate-900">{title}</p>
                    <p className="mt-0.5 text-[11px] text-slate-500 leading-snug">{description}</p>
                  </div>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={on}
                    onClick={() => toggleMutation.mutate({ category: key, enabled: !on })}
                    disabled={toggleMutation.isPending}
                    className={`relative h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-50 ${
                      on ? 'bg-line' : 'bg-slate-200'
                    }`}
                  >
                    <span
                      className={`absolute top-[2px] h-5 w-5 rounded-full bg-white shadow transition-transform ${
                        on ? 'translate-x-[22px]' : 'translate-x-[2px]'
                      }`}
                    />
                  </button>
                </div>
              )
            })}
          </div>
        )}

        {allOff && !prefsQuery.isLoading && (
          <div className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">
            ⚠️ การปิดการแจ้งเตือนทั้งหมดอาจทำให้คุณพลาดข้อมูลสำคัญ
          </div>
        )}

        <div className="mt-4 space-y-1.5">
          <p className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-400">เมนูเพิ่มเติม</p>
          <div className="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-100 divide-y divide-slate-100">
            <Link
              href="/notifications"
              onClick={onClose}
              className="flex items-center gap-3 px-3 py-2.5 hover:bg-slate-50"
            >
              <Pill size={17} className="text-line shrink-0" />
              <span className="flex-1 text-sm font-medium text-slate-900">เตือนทานยาที่ตั้งไว้</span>
            </Link>
            <Link
              href="/rewards/history"
              onClick={onClose}
              className="flex items-center gap-3 px-3 py-2.5 hover:bg-slate-50"
            >
              <Coins size={17} className="text-line shrink-0" />
              <span className="flex-1 text-sm font-medium text-slate-900">ประวัติแต้ม</span>
            </Link>
            <Link
              href="/appointments"
              onClick={onClose}
              className="flex items-center gap-3 px-3 py-2.5 hover:bg-slate-50"
            >
              <CalendarCheck size={17} className="text-line shrink-0" />
              <span className="flex-1 text-sm font-medium text-slate-900">นัดหมายของฉัน</span>
            </Link>
          </div>
        </div>

        <button
          type="button"
          onClick={onClose}
          className="mt-4 w-full rounded-xl bg-line py-3 text-sm font-semibold text-white"
        >
          ปิด
        </button>
      </div>
    </div>
  )
}
