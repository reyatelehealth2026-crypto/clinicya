'use client'

import { useState } from 'react'
import { Copy, Check, Landmark } from 'lucide-react'
import type { BankAccount } from '@/lib/shop-api'

interface Props {
  bankAccounts: BankAccount[] | null | undefined
}

function CopyButton({ text }: { text: string }) {
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard API not available (non-HTTPS or restricted context) — silent fail
    }
  }

  return (
    <button
      type="button"
      onClick={handleCopy}
      aria-label={copied ? 'คัดลอกแล้ว' : 'คัดลอก'}
      className="ml-2 inline-flex shrink-0 items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs text-slate-600 transition-colors hover:bg-slate-50 active:scale-95"
    >
      {copied ? (
        <>
          <Check size={12} className="text-emerald-600" />
          <span className="text-emerald-600">คัดลอกแล้ว</span>
        </>
      ) : (
        <>
          <Copy size={12} />
          <span>คัดลอก</span>
        </>
      )}
    </button>
  )
}

/**
 * Renders bank transfer details with per-account copy buttons.
 * Returns null when `bankAccounts` is null, undefined, or empty.
 */
export function BankTransferInfo({ bankAccounts }: Props) {
  if (!bankAccounts || bankAccounts.length === 0) return null

  return (
    <section className="rounded-3xl bg-white p-4 shadow-soft">
      <div className="mb-3 flex items-center gap-2">
        <Landmark size={18} className="text-line" />
        <h3 className="text-sm font-semibold text-slate-900">โอนเงินเข้าบัญชี / Bank Transfer</h3>
      </div>
      <ul className="space-y-3">
        {bankAccounts.map((acc, idx) => (
          <li
            key={idx}
            className="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 text-sm"
          >
            <p className="font-medium text-slate-800">{acc.bank_name}</p>
            <p className="mt-0.5 text-xs text-slate-500">ชื่อบัญชี / Account Name</p>
            <p className="text-slate-700">{acc.account_name}</p>
            <div className="mt-1.5 flex items-center">
              <div className="min-w-0 flex-1">
                <p className="text-xs text-slate-500">เลขบัญชี / Account No.</p>
                <p className="font-mono text-base font-semibold tracking-wide text-slate-900">
                  {acc.account_number}
                </p>
              </div>
              <CopyButton text={acc.account_number} />
            </div>
          </li>
        ))}
      </ul>
    </section>
  )
}
