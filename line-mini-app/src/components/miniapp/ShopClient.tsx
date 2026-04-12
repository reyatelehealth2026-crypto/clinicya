'use client'

import { useState, useEffect } from 'react'
import Image from 'next/image'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ShoppingCart, Store, Search, X } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { fetchProducts, addToCart, type ShopProduct } from '@/lib/shop-api'

function priceLabel(p: ShopProduct) {
  const sale = p.sale_price != null && p.sale_price !== '' ? Number(p.sale_price) : null
  const base = p.price != null && p.price !== '' ? Number(p.price) : null
  if (sale != null && !Number.isNaN(sale)) return `฿${sale.toLocaleString()}`
  if (base != null && !Number.isNaN(base)) return `฿${base.toLocaleString()}`
  return '—'
}

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

  const products = productsQuery.data?.products ?? []
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
            <article key={p.id} className="overflow-hidden rounded-2xl bg-white shadow-soft">
              <div className="relative aspect-square w-full bg-slate-100">
                {p.image_url ? (
                  <Image src={p.image_url} alt="" fill className="object-cover" sizes="(max-width: 480px) 50vw, 200px" />
                ) : (
                  <div className="flex h-full items-center justify-center text-slate-300">
                    <Store size={32} />
                  </div>
                )}
              </div>
              <div className="p-3">
                <h3 className="line-clamp-2 text-sm font-semibold text-slate-900">{p.name}</h3>
                <p className="mt-1 text-sm font-bold text-line">{priceLabel(p)}</p>
                <button
                  type="button"
                  disabled={!lineUserId || addMutation.isPending}
                  onClick={() => addMutation.mutate({ id: p.id })}
                  className="mt-2 flex w-full items-center justify-center gap-1.5 rounded-xl bg-line py-2 text-xs font-semibold text-white transition-colors hover:bg-line-dark disabled:opacity-50"
                >
                  <ShoppingCart size={14} />
                  ใส่ตะกร้า
                </button>
              </div>
            </article>
          ))}
        </div>
      )}
    </AppShell>
  )
}
