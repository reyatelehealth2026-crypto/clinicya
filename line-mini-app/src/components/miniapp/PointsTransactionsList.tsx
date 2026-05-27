import { ArrowDownLeft, ArrowUpRight, Clock, Inbox } from 'lucide-react'
import type { PointsTransaction } from '@/lib/rewards-api'

const TYPE_LABEL: Record<string, string> = {
  earn: 'ได้รับแต้ม',
  redeem: 'ใช้แต้ม',
  expire: 'แต้มหมดอายุ',
  adjust: 'ปรับแต้ม'
}

function transactionIcon(type: string) {
  switch (type) {
    case 'earn':
      return <ArrowDownLeft size={20} className="text-line" />
    case 'redeem':
      return <ArrowUpRight size={20} className="text-rose-500" />
    case 'expire':
      return <Clock size={20} className="text-amber-500" />
    default:
      return <ArrowDownLeft size={20} className="text-slate-400" />
  }
}

function transactionAccent(type: string): string {
  switch (type) {
    case 'earn':    return 'bg-line-soft'
    case 'redeem':  return 'bg-rose-50'
    case 'expire':  return 'bg-amber-50'
    default:        return 'bg-slate-100'
  }
}

function formatPoints(p: number | string, type: string): string {
  const n = typeof p === 'string' ? Number(p) : p
  if (!Number.isFinite(n)) return '0'
  // earn = positive display; redeem/expire = explicit -N
  if (type === 'redeem' || type === 'expire') {
    return `-${Math.abs(n).toLocaleString()}`
  }
  return `+${n.toLocaleString()}`
}

interface SummaryProps {
  available: number
  totalEarned: number
  totalUsed: number
}

export function PointsBalanceSummary({ available, totalEarned, totalUsed }: SummaryProps) {
  return (
    <div className="gradient-card rounded-3xl p-5 text-white shadow-card">
      <p className="text-xs opacity-80">แต้มคงเหลือ</p>
      <p className="mt-1 text-3xl font-extrabold tabular-nums">{available.toLocaleString()}</p>
      <div className="mt-4 grid grid-cols-2 gap-3 text-xs">
        <div className="rounded-2xl bg-white/15 px-3 py-2 backdrop-blur-sm">
          <p className="opacity-80">รวมที่ได้รับ</p>
          <p className="mt-0.5 text-lg font-bold tabular-nums">{totalEarned.toLocaleString()}</p>
        </div>
        <div className="rounded-2xl bg-white/15 px-3 py-2 backdrop-blur-sm">
          <p className="opacity-80">รวมที่ใช้ไป</p>
          <p className="mt-0.5 text-lg font-bold tabular-nums">{totalUsed.toLocaleString()}</p>
        </div>
      </div>
    </div>
  )
}

interface PointsTransactionsListProps {
  items: PointsTransaction[]
}

export function PointsTransactionsList({ items }: PointsTransactionsListProps) {
  if (!items.length) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-3xl bg-white py-12 shadow-soft">
        <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100">
          <Inbox size={28} className="text-slate-400" />
        </div>
        <p className="text-sm font-medium text-slate-500">ยังไม่มีรายการแต้ม</p>
      </div>
    )
  }

  return (
    <div className="space-y-2.5">
      {items.map((tx, i) => {
        const isRedeem = tx.type === 'redeem' || tx.type === 'expire'
        return (
          <article
            key={tx.id}
            className="animate-fade-in rounded-2xl bg-white p-4 shadow-soft"
            style={{ animationDelay: `${i * 40}ms` }}
          >
            <div className="flex items-start gap-3">
              <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ${transactionAccent(tx.type)}`}>
                {transactionIcon(tx.type)}
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                  <p className="text-sm font-semibold text-slate-900 line-clamp-2">
                    {tx.description || TYPE_LABEL[tx.type] || tx.type}
                  </p>
                  <p className={`shrink-0 text-sm font-bold tabular-nums ${isRedeem ? 'text-rose-500' : 'text-line'}`}>
                    {formatPoints(tx.points, tx.type)}
                  </p>
                </div>
                <div className="mt-1 flex items-center justify-between text-xs text-slate-400">
                  <span>{TYPE_LABEL[tx.type] || tx.type}</span>
                  <span>{tx.formatted_date || tx.created_at}</span>
                </div>
                <p className="mt-1 text-[11px] text-slate-400">
                  คงเหลือ {Number(tx.balance_after).toLocaleString()} แต้ม
                </p>
              </div>
            </div>
          </article>
        )
      })}
    </div>
  )
}
