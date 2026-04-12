import { useNavigate } from 'react-router-dom'
import { Minus, Plus, Trash2, ShoppingCart } from 'lucide-react'
import { useCartStore } from '@/stores/useCartStore'
import { formatCurrency } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { LazyImage } from '@/components/ui/LazyImage'
import { PageHeader } from '@/components/layout/PageHeader'

export function CartPage() {
  const navigate = useNavigate()
  const items = useCartStore((s) => s.items)
  const updateQuantity = useCartStore((s) => s.updateQuantity)
  const removeItem = useCartStore((s) => s.removeItem)
  const clearCart = useCartStore((s) => s.clearCart)
  const subtotal = useCartStore((s) => s.getSubtotal())

  if (items.length === 0) {
    return (
      <div><PageHeader title="ตะกร้า" showBack={false} />
        <div className="flex flex-col items-center justify-center py-16 text-gray-400"><ShoppingCart className="w-12 h-12 mb-3" /><p className="text-sm font-medium">ตะกร้าว่าง</p><Button variant="outline" size="sm" className="mt-3" onClick={() => navigate('/shop')}>เลือกซื้อสินค้า</Button></div>
      </div>
    )
  }

  return (
    <div className="pb-32">
      <PageHeader title={`ตะกร้า (${items.length})`} showBack={false} rightAction={<button onClick={clearCart} className="text-xs text-red-500 font-medium">ล้างทั้งหมด</button>} />
      <div className="p-4 space-y-3">
        {items.map((item) => {
          const price = item.product.sale_price || item.product.price
          return (
            <div key={item.product.id} className="bg-white rounded-xl p-3 shadow-sm flex gap-3">
              <LazyImage src={item.product.image_url || ''} alt={item.product.name} className="w-20 h-20 rounded-lg shrink-0" />
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900 line-clamp-2">{item.product.name}</p>
                <p className="text-sm font-bold text-primary mt-1">{formatCurrency(price)}</p>
                <div className="flex items-center justify-between mt-2">
                  <div className="flex items-center gap-2">
                    <button onClick={() => updateQuantity(item.product.id, item.quantity - 1)} className="w-7 h-7 rounded-lg bg-gray-100 flex items-center justify-center"><Minus className="w-3.5 h-3.5" /></button>
                    <span className="text-sm font-medium w-6 text-center">{item.quantity}</span>
                    <button onClick={() => updateQuantity(item.product.id, item.quantity + 1)} className="w-7 h-7 rounded-lg bg-gray-100 flex items-center justify-center"><Plus className="w-3.5 h-3.5" /></button>
                  </div>
                  <button onClick={() => removeItem(item.product.id)} className="text-gray-400 hover:text-red-500"><Trash2 className="w-4 h-4" /></button>
                </div>
              </div>
            </div>
          )
        })}
      </div>
      <div className="fixed bottom-16 left-0 right-0 bg-white border-t border-gray-100 px-4 py-3 safe-bottom z-40">
        <div className="flex items-center justify-between mb-2"><span className="text-sm text-gray-500">รวม</span><span className="text-lg font-bold text-primary">{formatCurrency(subtotal)}</span></div>
        <Button className="w-full" size="lg" onClick={() => navigate('/checkout')}>ดำเนินการสั่งซื้อ</Button>
      </div>
    </div>
  )
}
