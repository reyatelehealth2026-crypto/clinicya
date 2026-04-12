import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, ClipboardList } from 'lucide-react'
import { useOrders } from '@/hooks/useOrders'
import { formatCurrency, formatDate } from '@/lib/format'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import { Card } from '@/components/ui/Card'
import type { OrderStatus } from '@/types/product'

const statusFilters: { key: OrderStatus | 'all'; label: string }[] = [
  { key: 'all', label: 'ทั้งหมด' }, { key: 'pending', label: 'รอดำเนินการ' }, { key: 'confirmed', label: 'ยืนยันแล้ว' }, { key: 'shipped', label: 'จัดส่งแล้ว' }, { key: 'delivered', label: 'สำเร็จ' }, { key: 'cancelled', label: 'ยกเลิก' },
]
const statusBadgeVariant: Record<OrderStatus, 'default' | 'success' | 'warning' | 'danger' | 'info'> = { pending: 'warning', confirmed: 'info', processing: 'info', shipped: 'info', delivered: 'success', cancelled: 'danger' }
const statusLabel: Record<OrderStatus, string> = { pending: 'รอดำเนินการ', confirmed: 'ยืนยันแล้ว', processing: 'กำลังจัดเตรียม', shipped: 'จัดส่งแล้ว', delivered: 'สำเร็จ', cancelled: 'ยกเลิก' }

export function OrdersPage() {
  const navigate = useNavigate()
  const [filter, setFilter] = useState<OrderStatus | 'all'>('all')
  const { data: orders, isLoading } = useOrders()
  const filtered = orders?.filter((o) => filter === 'all' || o.status === filter)

  return (
    <div className="pb-4">
      <div className="bg-white px-4 pt-3 pb-3 safe-top sticky top-0 z-30 border-b border-gray-100">
        <h1 className="text-base font-semibold text-gray-900 mb-3">ออเดอร์ของฉัน</h1>
        <div className="flex gap-2 overflow-x-auto scrollbar-hide -mx-4 px-4">
          {statusFilters.map((f) => (<button key={f.key} onClick={() => setFilter(f.key)} className={`shrink-0 px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${filter === f.key ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600'}`}>{f.label}</button>))}
        </div>
      </div>
      <div className="p-4 space-y-3">
        {isLoading ? ([1, 2, 3].map((i) => <Card key={i}><Skeleton height="60px" /></Card>))
        : filtered && filtered.length > 0 ? filtered.map((order) => (
          <Card key={order.id} className="cursor-pointer active:scale-[0.99] transition-transform" onClick={() => navigate(`/order/${order.id}`)}>
            <div className="flex items-center justify-between mb-2"><span className="text-xs text-gray-500">#{order.order_number}</span><Badge variant={statusBadgeVariant[order.status]}>{statusLabel[order.status]}</Badge></div>
            <div className="flex items-center justify-between">
              <div><p className="text-sm font-medium text-gray-900">{order.items.length} รายการ</p><p className="text-xs text-gray-400 mt-0.5">{formatDate(order.created_at)}</p></div>
              <div className="flex items-center gap-2"><span className="text-sm font-bold text-gray-900">{formatCurrency(order.total)}</span><ChevronRight className="w-4 h-4 text-gray-300" /></div>
            </div>
          </Card>
        )) : (
          <div className="flex flex-col items-center justify-center py-16 text-gray-400"><ClipboardList className="w-12 h-12 mb-3" /><p className="text-sm font-medium">ไม่มีออเดอร์</p>
            {filter !== 'all' && <button onClick={() => setFilter('all')} className="mt-2 text-xs text-primary font-medium">ดูทั้งหมด</button>}
          </div>
        )}
      </div>
    </div>
  )
}
