import { useParams } from 'react-router-dom'
import { useOrder } from '@/hooks/useOrders'
import { formatCurrency, formatDateTime } from '@/lib/format'
import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import { LazyImage } from '@/components/ui/LazyImage'
import { CheckCircle, Circle, Truck, Package, Clock } from 'lucide-react'
import type { OrderStatus } from '@/types/product'

const statusLabel: Record<OrderStatus, string> = { pending: 'รอดำเนินการ', confirmed: 'ยืนยันแล้ว', processing: 'กำลังจัดเตรียม', shipped: 'จัดส่งแล้ว', delivered: 'สำเร็จ', cancelled: 'ยกเลิก' }
const statusBadgeVariant: Record<OrderStatus, 'default' | 'success' | 'warning' | 'danger' | 'info'> = { pending: 'warning', confirmed: 'info', processing: 'info', shipped: 'info', delivered: 'success', cancelled: 'danger' }
const timeline: { status: OrderStatus; icon: typeof Clock; label: string }[] = [
  { status: 'pending', icon: Clock, label: 'รอดำเนินการ' }, { status: 'confirmed', icon: CheckCircle, label: 'ยืนยันแล้ว' }, { status: 'processing', icon: Package, label: 'กำลังจัดเตรียม' }, { status: 'shipped', icon: Truck, label: 'จัดส่งแล้ว' }, { status: 'delivered', icon: CheckCircle, label: 'สำเร็จ' },
]
const statusOrder: OrderStatus[] = ['pending', 'confirmed', 'processing', 'shipped', 'delivered']

export function OrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { data: order, isLoading } = useOrder(id)

  if (isLoading) return <div><PageHeader title="รายละเอียดออเดอร์" /><div className="p-4 space-y-4"><Card><Skeleton height="80px" /></Card><Card><Skeleton height="120px" /></Card></div></div>
  if (!order) return <div><PageHeader title="รายละเอียดออเดอร์" /><div className="p-4 text-center py-16 text-gray-400"><p className="text-sm">ไม่พบออเดอร์</p></div></div>

  const currentIdx = statusOrder.indexOf(order.status)
  return (
    <div className="pb-4">
      <PageHeader title={`#${order.order_number}`} />
      <div className="p-4 space-y-4">
        <Card>
          <div className="flex items-center justify-between mb-4"><h3 className="text-sm font-semibold text-gray-900">สถานะออเดอร์</h3><Badge variant={statusBadgeVariant[order.status]}>{statusLabel[order.status]}</Badge></div>
          {order.status !== 'cancelled' && <div className="space-y-0">{timeline.map((step, i) => { const done = i <= currentIdx; const StepIcon = done ? step.icon : Circle; return (<div key={step.status} className="flex items-start gap-3"><div className="flex flex-col items-center"><StepIcon className={`w-5 h-5 ${done ? 'text-primary' : 'text-gray-300'}`} />{i < timeline.length - 1 && <div className={`w-0.5 h-6 ${i < currentIdx ? 'bg-primary' : 'bg-gray-200'}`} />}</div><p className={`text-xs pb-4 ${done ? 'text-gray-900 font-medium' : 'text-gray-400'}`}>{step.label}</p></div>) })}</div>}
        </Card>
        <Card>
          <h3 className="text-sm font-semibold text-gray-900 mb-3">รายการสินค้า</h3>
          <div className="space-y-3">{order.items.map((item, i) => (<div key={i} className="flex gap-3"><LazyImage src={item.image_url || ''} alt={item.product_name} className="w-14 h-14 rounded-lg object-cover shrink-0" /><div className="flex-1 min-w-0"><p className="text-sm text-gray-900 line-clamp-1">{item.product_name}</p><p className="text-xs text-gray-500 mt-0.5">x{item.quantity} · {formatCurrency(item.unit_price)}</p></div><span className="text-sm font-medium text-gray-900 shrink-0">{formatCurrency(item.total)}</span></div>))}</div>
        </Card>
        <Card>
          <h3 className="text-sm font-semibold text-gray-900 mb-3">สรุป</h3>
          <div className="space-y-1.5 text-sm">
            <div className="flex justify-between"><span className="text-gray-500">ค่าสินค้า</span><span>{formatCurrency(order.subtotal)}</span></div>
            {order.discount > 0 && <div className="flex justify-between"><span className="text-gray-500">ส่วนลด</span><span className="text-red-500">-{formatCurrency(order.discount)}</span></div>}
            <div className="flex justify-between"><span className="text-gray-500">ค่าจัดส่ง</span><span className="text-green-600">{order.shipping === 0 ? 'ฟรี' : formatCurrency(order.shipping)}</span></div>
            <div className="flex justify-between pt-2 border-t border-gray-100"><span className="font-bold">รวมทั้งหมด</span><span className="text-lg font-bold text-primary">{formatCurrency(order.total)}</span></div>
          </div>
        </Card>
        <Card><div className="space-y-2 text-xs text-gray-500"><div className="flex justify-between"><span>วันที่สั่ง</span><span>{formatDateTime(order.created_at)}</span></div><div className="flex justify-between"><span>อัปเดตล่าสุด</span><span>{formatDateTime(order.updated_at)}</span></div></div></Card>
      </div>
    </div>
  )
}
