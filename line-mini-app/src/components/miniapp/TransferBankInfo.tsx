'use client'

import { useCallback } from 'react'
import { Copy } from 'lucide-react'
import type { TransferInfo } from '@/lib/shop-api'
import { useToast } from '@/lib/toast'

type Props = {
  info: TransferInfo | undefined
  className?: string
}

export function TransferBankInfo({ info, className = '' }: Props) {
  const { toast } = useToast()

  const copy = useCallback(
    async (label: string, text: string) => {
      const t = text.trim()
      if (!t) return
      try {
        await navigator.clipboard.writeText(t)
        toast.success(`คัดลอก${label}แล้ว`)
      } catch {
        toast.error('คัดลอกไม่สำเร็จ')
      }
    },
    [toast]
  )

  if (!info) return null

  const hasBanks = info.banks && info.banks.some((b) => b.bank_name || b.account_number || b.account_holder)
  const hasPromptPay = Boolean(info.promptpay_number?.trim())

  if (!hasBanks && !hasPromptPay) return null

  return (
    <div className={`rounded-2xl border border-slate-100 bg-slate-50/90 p-3 text-sm text-slate-800 ${className}`}>
      <p className="mb-2 text-xs font-semibold text-slate-600">โอนเข้าบัญชี / พร้อมเพย์</p>
      <div className="space-y-2">
        {info.banks.map((b, i) => {
          if (!b.bank_name && !b.account_number && !b.account_holder) return null
          return (
            <div key={i} className="rounded-xl bg-white p-3 shadow-sm">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-slate-900">{b.bank_name || 'ธนาคาร'}</p>
                  {b.account_holder ? <p className="text-xs text-slate-500">{b.account_holder}</p> : null}
                  {b.account_number ? (
                    <p className="mt-1 font-mono text-base tracking-wide text-slate-900">{b.account_number}</p>
                  ) : null}
                </div>
                {b.account_number ? (
                  <button
                    type="button"
                    onClick={() => copy('เลขบัญชี', b.account_number)}
                    className="shrink-0 rounded-lg bg-line/10 p-2 text-line hover:bg-line/20"
                    aria-label="คัดลอกเลขบัญชี"
                  >
                    <Copy size={16} />
                  </button>
                ) : null}
              </div>
            </div>
          )
        })}
        {hasPromptPay ? (
          <div className="flex items-center justify-between gap-2 rounded-xl bg-white p-3 shadow-sm">
            <div>
              <p className="text-xs text-slate-500">พร้อมเพย์</p>
              <p className="font-mono text-sm text-slate-900">{info.promptpay_number}</p>
            </div>
            <button
              type="button"
              onClick={() => copy('พร้อมเพย์', info.promptpay_number)}
              className="shrink-0 rounded-lg bg-line/10 p-2 text-line hover:bg-line/20"
              aria-label="คัดลอกพร้อมเพย์"
            >
              <Copy size={16} />
            </button>
          </div>
        ) : null}
      </div>
      <p className="mt-2 text-[11px] leading-snug text-slate-500">
        ตรวจสอบยอดให้ตรงก่อนโอน · หลังโอนอัปโหลดสลิปในรายละเอียดออเดอร์
      </p>
    </div>
  )
}
