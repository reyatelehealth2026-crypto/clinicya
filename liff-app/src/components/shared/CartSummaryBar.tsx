import { useNavigate } from 'react-router-dom'
import { useCartStore } from '@/stores/useCartStore'
import { formatCurrency } from '@/lib/format'
import { ShoppingCart } from 'lucide-react'

export function CartSummaryBar() {
  const navigate = useNavigate()
  const itemCount = useCartStore((s) => s.getItemCount())
  const subtotal = useCartStore((s) => s.getSubtotal())

  if (itemCount === 0) return null

  return (
    <div className="fixed bottom-16 left-0 right-0 bg-white border-t border-gray-100 px-4 py-2.5 z-40 safe-bottom">
      <button onClick={() => navigate('/cart')} className="w-full flex items-center justify-between bg-primary text-white rounded-xl px-4 py-2.5 active:scale-[0.98] transition-transform">
        <div className="flex items-center gap-2">
          <ShoppingCart className="w-4 h-4" />
          <span className="text-sm font-medium">{itemCount} รายการ</span>
        </div>
        <span className="text-sm font-bold">{formatCurrency(subtotal)}</span>
      </button>
    </div>
  )
}
