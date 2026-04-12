import type { Product } from '@/types/product'
import { LazyImage } from '@/components/ui/LazyImage'
import { useCartStore } from '@/stores/useCartStore'
import { formatCurrency } from '@/lib/format'
import { ShoppingCart } from 'lucide-react'

export function ProductCard({ product }: { product: Product }) {
  const addItem = useCartStore((s) => s.addItem)
  const hasDiscount = product.sale_price !== null && product.sale_price < product.price
  const displayPrice = hasDiscount ? product.sale_price! : product.price

  return (
    <div className="bg-white rounded-xl shadow-sm overflow-hidden">
      <LazyImage src={product.image_url || ''} alt={product.name} className="aspect-square" />
      <div className="p-2.5">
        <h3 className="text-xs font-medium text-gray-900 line-clamp-2 min-h-[2rem]">{product.name}</h3>
        <div className="flex items-center gap-1.5 mt-1">
          <span className="text-sm font-bold text-primary">{formatCurrency(displayPrice)}</span>
          {hasDiscount && <span className="text-[10px] text-gray-400 line-through">{formatCurrency(product.price)}</span>}
        </div>
        <button
          onClick={() => addItem(product)}
          disabled={!product.in_stock}
          className="mt-2 w-full flex items-center justify-center gap-1 bg-primary text-white text-xs py-1.5 rounded-lg font-medium disabled:bg-gray-200 disabled:text-gray-400 active:scale-95 transition-transform"
        >
          <ShoppingCart className="w-3.5 h-3.5" />
          {product.in_stock ? 'เพิ่มลงตะกร้า' : 'สินค้าหมด'}
        </button>
      </div>
    </div>
  )
}
