import { Clock, Gift, Inbox } from 'lucide-react'
import type { RedemptionItem } from '@/types/rewards'

function statusBadge(status: string) {
  switch (status.toLowerCase()) {
    case 'approved':
    case 'completed':
      return 'badge badge-green'
    case 'pending':
      return 'badge badge-amber'
    case 'rejected':
    case 'cancelled':
      return 'badge badge-red'
    default:
      return 'badge badge-slate'
  }
}

function statusLabel(status: string) {
  switch (status.toLowerCase()) {
    case 'approved': return 'อนุมัติแล้ว'
    case 'completed': return 'เสร็จสิ้น'
    case 'pending': return 'รอดำเนินการ'
    case 'rejected': return 'ไม่อนุมัติ'
    case 'cancelled': return 'ยกเลิก'
    default: return status
  }
}

export function RedemptionHistoryList({ items }: { items: RedemptionItem[] }) {
  if (!items.length) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-3xl bg-white py-12 shadow-soft">
        <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100">
          <Inbox size={28} className="text-slate-400" />
        </div>
        <p className="text-sm font-medium text-slate-500">ยังไม่มีประวัติการแลกรางวัล</p>
      </div>
    )
  }

  return (
    <div className="space-y-3">
      {items.map((item, i) => (
        <article
          key={item.id}
          className="animate-fade-in rounded-3xl bg-white p-4 shadow-soft"
          style={{ animationDelay: `${i * 60}ms` }}
        >
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-line-soft">
              <Gift size={18} className="text-line" />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-start justify-between gap-2">
                <h3 className="font-semibold text-slate-900 leading-snug">{item.reward_name}</h3>
                <span className={statusBadge(item.status)}>{statusLabel(item.status)}</span>
              </div>
              <p className="mt-1 font-mono text-xs text-slate-400">{item.redemption_code}</p>
              <div className="mt-2 flex items-center gap-3 text-xs text-slate-500">
                <span className="font-semibold text-line">-{item.points_used.toLocaleString()} แต้ม</span>
                <span className="flex items-center gap-1">
                  <Clock size={11} />
                  {new Date(item.created_at).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' })}
                </span>
              </div>
            </div>
          </div>
        </article>
      ))}
    </div>
  )
}
