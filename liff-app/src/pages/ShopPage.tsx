import { useState, useMemo } from 'react'
import { Search } from 'lucide-react'
import { useProducts } from '@/hooks/useProducts'
import { ProductCard } from '@/components/shared/ProductCard'
import { CartSummaryBar } from '@/components/shared/CartSummaryBar'
import { Skeleton } from '@/components/ui/Skeleton'

export function ShopPage() {
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState<string | undefined>()
  const { data: products, isLoading } = useProducts(category)

  const categories = useMemo(() => {
    const cats = new Set(products?.map((p) => p.category).filter(Boolean) as string[])
    return ['ทั้งหมด', ...cats]
  }, [products])

  const filtered = useMemo(() => {
    if (!search) return products || []
    const q = search.toLowerCase()
    return (products || []).filter((p) => p.name.toLowerCase().includes(q))
  }, [products, search])

  return (
    <div className="pb-20">
      <div className="bg-white px-4 pt-3 pb-3 safe-top sticky top-0 z-30 border-b border-gray-100">
        <div className="relative mb-3">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ค้นหาสินค้า..." className="w-full pl-10 pr-4 py-2.5 bg-gray-100 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/30" />
        </div>
        <div className="flex gap-2 overflow-x-auto scrollbar-hide -mx-4 px-4">
          {categories.map((cat) => (
            <button key={cat} onClick={() => setCategory(cat === 'ทั้งหมด' ? undefined : cat)} className={`shrink-0 px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${(!category && cat === 'ทั้งหมด') || category === cat ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600'}`}>
              {cat}
            </button>
          ))}
        </div>
      </div>
      <div className="p-4">
        {isLoading ? (
          <div className="grid grid-cols-2 gap-3">{[1, 2, 3, 4].map((i) => <div key={i} className="bg-white rounded-xl overflow-hidden shadow-sm"><Skeleton className="aspect-square w-full" /><div className="p-2.5 space-y-2"><Skeleton height="12px" /><Skeleton height="14px" className="w-1/2" /></div></div>)}</div>
        ) : filtered.length > 0 ? (
          <div className="grid grid-cols-2 gap-3">{filtered.map((p) => <ProductCard key={p.id} product={p} />)}</div>
        ) : (
          <div className="text-center py-16 text-gray-400"><p className="text-sm">ไม่พบสินค้า</p></div>
        )}
      </div>
      <CartSummaryBar />
    </div>
  )
}
