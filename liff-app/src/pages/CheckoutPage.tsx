import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { MapPin, CreditCard, Banknote } from 'lucide-react'
import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { useCartStore } from '@/stores/useCartStore'
import { formatCurrency } from '@/lib/format'
import { showToast } from '@/components/ui/Toast'
import { useCreateOrder, useLastAddress } from '@/hooks/useCheckout'

type PaymentMethod = 'transfer' | 'cod'

export function CheckoutPage() {
  const navigate = useNavigate()
  const items = useCartStore((s) => s.items)
  const subtotal = useCartStore((s) => s.getSubtotal())
  const clearCart = useCartStore((s) => s.clearCart)
  const [payment, setPayment] = useState<PaymentMethod>('transfer')
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [address, setAddress] = useState('')
  const createOrder = useCreateOrder()
  const { data: lastAddr } = useLastAddress()

  useEffect(() => {
    if (lastAddr) {
      if (lastAddr.name) setName(lastAddr.name)
      if (lastAddr.phone) setPhone(lastAddr.phone)
      if (lastAddr.address) setAddress(lastAddr.address)
    }
  }, [lastAddr])

  const shipping = 0
  const total = subtotal + shipping

  const handlePlaceOrder = () => {
    if (!name || !phone || !address) { showToast('กรุณากรอกข้อมูลให้ครบ', 'warning'); return }
    createOrder.mutate(
      { items: items.map((i) => ({ product_id: i.product.id, quantity: i.quantity })), shipping: { name, phone, address }, payment_method: payment },
      { onSuccess: () => { clearCart(); showToast('สั่งซื้อสำเร็จ!', 'success'); navigate('/orders') }, onError: (err) => showToast(err.message || 'เกิดข้อผิดพลาด', 'error') },
    )
  }

  if (items.length === 0) { navigate('/cart'); return null }

  const inputClass = 'w-full px-3 py-2.5 bg-gray-50 rounded-xl text-sm border border-gray-200 focus:outline-none focus:ring-2 focus:ring-primary/30'
  return (
    <div className="pb-32">
      <PageHeader title="ชำระเงิน" />
      <div className="p-4 space-y-4">
        <Card>
          <div className="flex items-center gap-2 mb-3"><MapPin className="w-4 h-4 text-primary" /><h3 className="text-sm font-semibold text-gray-900">ที่อยู่จัดส่ง</h3></div>
          <div className="space-y-2">
            <input type="text" value={name} onChange={(e) => setName(e.target.value)} placeholder="ชื่อผู้รับ" className={inputClass} />
            <input type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="เบอร์โทร" className={inputClass} />
            <textarea value={address} onChange={(e) => setAddress(e.target.value)} placeholder="ที่อยู่" rows={2} className={`${inputClass} resize-none`} />
          </div>
        </Card>
        <Card>
          <h3 className="text-sm font-semibold text-gray-900 mb-3">ช่องทางชำระเงิน</h3>
          <div className="space-y-2">
            {[{ key: 'transfer' as const, icon: CreditCard, label: 'โอนเงิน / พร้อมเพย์' }, { key: 'cod' as const, icon: Banknote, label: 'ชำระปลายทาง (COD)' }].map((m) => (
              <button key={m.key} onClick={() => setPayment(m.key)} className={`w-full flex items-center gap-3 p-3 rounded-xl border transition-colors ${payment === m.key ? 'border-primary bg-primary/5' : 'border-gray-200'}`}>
                <m.icon className={`w-5 h-5 ${payment === m.key ? 'text-primary' : 'text-gray-400'}`} />
                <span className={`text-sm ${payment === m.key ? 'text-primary font-medium' : 'text-gray-600'}`}>{m.label}</span>
              </button>
            ))}
          </div>
        </Card>
        <Card>
          <h3 className="text-sm font-semibold text-gray-900 mb-3">สรุปคำสั่งซื้อ</h3>
          <div className="space-y-1.5 text-sm">
            <div className="flex justify-between"><span className="text-gray-500">ค่าสินค้า ({items.length} รายการ)</span><span>{formatCurrency(subtotal)}</span></div>
            <div className="flex justify-between"><span className="text-gray-500">ค่าจัดส่ง</span><span className="text-green-600">ฟรี</span></div>
            <div className="flex justify-between pt-2 border-t border-gray-100"><span className="font-bold">รวมทั้งหมด</span><span className="text-lg font-bold text-primary">{formatCurrency(total)}</span></div>
          </div>
        </Card>
      </div>
      <div className="fixed bottom-16 left-0 right-0 bg-white border-t border-gray-100 px-4 py-3 safe-bottom z-40">
        <Button className="w-full" size="lg" loading={createOrder.isPending} onClick={handlePlaceOrder}>ยืนยันสั่งซื้อ {formatCurrency(total)}</Button>
      </div>
    </div>
  )
}
