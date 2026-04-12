import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/Card'
import { Skeleton } from '@/components/ui/Skeleton'
import { useAuthStore } from '@/stores/useAuthStore'
import { usePointsHistory } from '@/hooks/usePointsHistory'
import { formatNumber } from '@/lib/format'
import { TrendingUp, TrendingDown, Clock, RefreshCw } from 'lucide-react'
import type { PointsHistoryItem } from '@/types/member'

const typeIcon: Record<PointsHistoryItem['type'], typeof TrendingUp> = { earn: TrendingUp, redeem: TrendingDown, expire: Clock, adjust: RefreshCw }
const typeColor: Record<PointsHistoryItem['type'], string> = { earn: 'text-green-600', redeem: 'text-red-500', expire: 'text-gray-400', adjust: 'text-blue-500' }

export function PointsPage() {
  const member = useAuthStore((s) => s.member)
  const { data: history = [], isLoading } = usePointsHistory()

  return (
    <div className="pb-4">
      <PageHeader title="ประวัติแต้ม" />
      <div className="bg-white px-4 py-5 text-center border-b border-gray-100"><p className="text-xs text-gray-500">คะแนนสะสมปัจจุบัน</p><p className="text-3xl font-bold text-primary mt-1">{formatNumber(member?.points || 0)}</p><p className="text-xs text-gray-400 mt-1">คะแนน</p></div>
      <div className="p-4">
        {isLoading ? <div className="space-y-2">{[1, 2, 3].map((i) => <Card key={i}><Skeleton height="40px" /></Card>)}</div>
        : history.length === 0 ? <div className="flex flex-col items-center justify-center py-16 text-gray-400"><Clock className="w-12 h-12 mb-3" /><p className="text-sm font-medium">ยังไม่มีประวัติแต้ม</p></div>
        : <div className="space-y-2">{history.map((item) => { const Icon = typeIcon[item.type]; return (
          <Card key={item.id} className="flex items-center gap-3"><div className={`w-9 h-9 rounded-full bg-gray-50 flex items-center justify-center shrink-0 ${typeColor[item.type]}`}><Icon className="w-4 h-4" /></div><div className="flex-1 min-w-0"><p className="text-sm font-medium text-gray-900">{item.description}</p><p className="text-[10px] text-gray-400 mt-0.5">{item.created_at}</p></div><span className={`text-sm font-bold shrink-0 ${typeColor[item.type]}`}>{item.type === 'earn' || item.type === 'adjust' ? '+' : '-'}{formatNumber(item.points)}</span></Card>
        ) })}</div>}
      </div>
    </div>
  )
}
