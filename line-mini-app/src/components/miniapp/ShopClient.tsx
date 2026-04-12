'use client'

import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Store, Search, X } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { ShopProductCard } from '@/components/miniapp/ShopProductCard'
import { fetchProducts, addToCart } from '@/lib/shop-api'
import { enrichShopProduct } from '@/lib/shop-product-utils'

export function ShopClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const queryClient = useQueryClient()

  const [inputValue, setInputValue] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [activeCategoryId, setActiveCategoryId] = useState<string | null>(null)

  // 300ms debounce: update searchTerm after user stops typing
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearchTerm(inputValue.trim())
    }, 300)
    return () => clearTimeout(timer)
  }, [inputValue])

  const productsQuery = useQuery({
    queryKey: ['shop-products', activeCategoryId ?? null, searchTerm],
    queryFn: () => fetchProducts(activeCategoryId ?? undefined, searchTerm)
  })

  const addMutation = useMutation({
    mutationFn: ({ id }: { id: number }) => addToCart(lineUserId, id, 1),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shop-cart', lineUserId] })
    }
  })

  const products = (productsQuery.data?.products ?? []).map((p) => enrichShopProduct(p))
  const categories = productsQuery.data?.categories ?? []

  function handleCategoryClick(id: string) {
    setActiveCategoryId((prev) => (prev === id ? null : id))
  }

  function handleClearSearch() {
    setInputValue('')
    setSearchTerm('')
  }

  return (
    <AppShell title="ร้านค้า" subtitle="เลือกสินค้าและเพิ่มลงตะกร้า">
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      {!lineUserId ? (
        <p className="text-center text-sm text-slate-500">กรุณาเข้าสู่ระบบ LINE เพื่อสั่งซื้อ</p>
      ) : null}

      {/* Search input */}
      <div className="relative">
        <Search
          size={16}
          className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
        />
        <input
          type="search"
          value={inputValue}
          onChange={(e) => setInputValue(e.target.value)}
          placeholder="ค้นหาสินค้า..."
          className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-9 pr-9 text-sm text-slate-800 placeholder-slate-400 outline-none focus:border-line focus:ring-1 focus:ring-line"
        />
        {inputValue ? (
          <button
            type="button"
            onClick={handleClearSearch}
            className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
            aria-label="ล้างคำค้นหา"
          >
            <X size={16} />
          </button>
        ) : null}
      </div>

      {/* Category badges */}
      {categories.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {categories.map((c) => {
            const isActive = activeCategoryId === String(c.id)
            return (
              <button
                key={c.id}
                type="button"
                onClick={() => handleCategoryClick(String(c.id))}
                className={[
                  'rounded-full px-3 py-1 text-xs font-medium transition-colors',
                  isActive
                    ? 'bg-line text-white'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                ].join(' ')}
              >
                {c.name}
              </button>
            )
          })}
        </div>
      ) : null}

      {productsQuery.isLoading ? (
        <div className="grid grid-cols-2 gap-3">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="skeleton aspect-[3/4] w-full rounded-2xl" />
          ))}
        </div>
      ) : products.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-3xl bg-white py-12 text-center shadow-soft">
          <Store className="text-slate-300" size={40} />
          <p className="text-sm text-slate-500">
            {searchTerm || activeCategoryId ? 'ไม่พบสินค้าที่ตรงกัน' : 'ยังไม่มีสินค้า'}
          </p>
          {(searchTerm || activeCategoryId) ? (
            <button
              type="button"
              onClick={() => {
                handleClearSearch()
                setActiveCategoryId(null)
              }}
              className="mt-1 text-xs text-line underline"
            >
              ล้างตัวกรอง
            </button>
          ) : null}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3">
          {products.map((p) => (
            <ShopProductCard
              key={p.id}
              product={p}
              lineUserId={lineUserId}
              disabledAdd={!lineUserId || addMutation.isPending}
              onAdd={() => addMutation.mutate({ id: p.id })}
            />
          ))}
        </div>
      )}
    </AppShell>
  )
}
