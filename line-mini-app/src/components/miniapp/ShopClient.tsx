'use client'

import { useEffect, useMemo, useState } from 'react'
import Link from 'next/link'
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Search, ShoppingCart, Sparkles, Store, X } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { BannerSlider } from '@/components/miniapp/BannerSlider'
import { ShopMerchSectionRail } from '@/components/miniapp/ShopMerchSectionRail'
import { ShopProductCard } from '@/components/miniapp/ShopProductCard'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { getShopMerch } from '@/lib/shop-merch-api'
import {
  addToCart,
  fetchCart,
  fetchProducts,
  type ProductSort,
  type ShopCategory,
} from '@/lib/shop-api'
import { enrichShopProduct } from '@/lib/shop-product-utils'
import { toggleWishlist } from '@/lib/wishlist-api'
import { cn } from '@/lib/utils'

const sortOptions: Array<{ value: ProductSort; label: string }> = [
  { value: 'latest', label: 'ล่าสุด' },
  { value: 'discount', label: 'โปรแรง' },
  { value: 'price_asc', label: 'ราคาต่ำ' },
]

function ShopHeader({ name, avatar, cartCount }: { name: string; avatar?: string | null; cartCount: number }) {
  return (
    <header className="safe-top shrink-0 border-b border-slate-100 bg-white/95 backdrop-blur-sm">
      <div className="mx-auto max-w-md px-4 pb-4 pt-3">
        <div className="flex items-center gap-3">
          {avatar ? (
            <img src={avatar} alt="" className="h-11 w-11 rounded-2xl object-cover shadow-soft" />
          ) : (
            <div className="gradient-card flex h-11 w-11 items-center justify-center rounded-2xl text-sm font-bold text-white shadow-soft">
              {name.charAt(0)}
            </div>
          )}

          <div className="min-w-0 flex-1">
            <p className="text-xs font-semibold uppercase tracking-[0.22em] text-line">Reya Shop</p>
            <p className="truncate text-base font-bold text-slate-900">{name}</p>
          </div>

          <Link
            href="/cart"
            className="relative flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 shadow-soft transition hover:border-slate-300 hover:text-line"
            aria-label="ไปยังตะกร้า"
          >
            <ShoppingCart size={18} />
            {cartCount > 0 ? (
              <span className="absolute -right-1 -top-1 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-line px-1 text-[10px] font-bold text-white">
                {cartCount > 99 ? '99+' : cartCount}
              </span>
            ) : null}
          </Link>
        </div>
      </div>
    </header>
  )
}

function CategoryShortcut({
  category,
  active,
  onClick,
}: {
  category: ShopCategory
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'flex min-w-[5rem] shrink-0 flex-col items-center gap-2 rounded-[1.4rem] border px-3 py-3 text-center transition',
        active ? 'border-line bg-line-soft text-line shadow-soft' : 'border-slate-200 bg-white text-slate-600'
      )}
    >
      <div
        className={cn(
          'flex h-12 w-12 items-center justify-center overflow-hidden rounded-full',
          active ? 'bg-white text-line' : 'bg-slate-50 text-slate-500'
        )}
      >
        {category.icon_url ? (
          <img src={category.icon_url} alt="" className="h-full w-full object-cover" />
        ) : (
          <span className="text-xs font-bold">{category.name.slice(0, 2)}</span>
        )}
      </div>
      <span className="line-clamp-2 text-[11px] font-semibold leading-tight">{category.name}</span>
    </button>
  )
}

export function ShopClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const queryClient = useQueryClient()
  const [inputValue, setInputValue] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [activeCategoryId, setActiveCategoryId] = useState<string | null>(null)
  const [activeBrand, setActiveBrand] = useState<string | null>(null)
  const [sort, setSort] = useState<ProductSort>('latest')
  const [addingId, setAddingId] = useState<number | null>(null)
  const [favoriteId, setFavoriteId] = useState<number | null>(null)

  useEffect(() => {
    const timer = setTimeout(() => {
      setSearchTerm(inputValue.trim())
    }, 250)
    return () => clearTimeout(timer)
  }, [inputValue])

  const cartQuery = useQuery({
    queryKey: ['shop-cart', lineUserId],
    queryFn: () => fetchCart(lineUserId),
    enabled: Boolean(lineUserId),
    staleTime: 30_000,
  })

  const merchQuery = useQuery({
    queryKey: ['shop-merch'],
    queryFn: getShopMerch,
    staleTime: 60_000,
  })

  const catalogQuery = useInfiniteQuery({
    queryKey: ['shop-products', activeCategoryId, searchTerm, sort, activeBrand, lineUserId],
    initialPageParam: 0,
    queryFn: ({ pageParam }) =>
      fetchProducts({
        categoryId: activeCategoryId ?? undefined,
        search: searchTerm,
        sort,
        brand: activeBrand ?? undefined,
        offset: pageParam,
        limit: 12,
        lineUserId,
      }),
    getNextPageParam: (lastPage) => {
      if (!lastPage.has_more) return undefined
      return (lastPage.offset ?? 0) + (lastPage.limit ?? 12)
    },
  })

  const addMutation = useMutation({
    mutationFn: ({ productId }: { productId: number }) => addToCart(lineUserId, productId, 1),
    onMutate: ({ productId }) => setAddingId(productId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shop-cart', lineUserId] })
    },
    onSettled: () => setAddingId(null),
  })

  const favoriteMutation = useMutation({
    mutationFn: (productId: number) => toggleWishlist(lineUserId, productId),
    onMutate: (productId) => setFavoriteId(productId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shop-products'] })
      queryClient.invalidateQueries({ queryKey: ['wishlist', lineUserId] })
    },
    onSettled: () => setFavoriteId(null),
  })

  const pages = catalogQuery.data?.pages ?? []
  const products = useMemo(
    () => pages.flatMap((page) => (page.products ?? []).map((product) => enrichShopProduct(product))),
    [pages]
  )
  const firstPage = pages[0]
  const categories = firstPage?.categories ?? []
  const brands = firstPage?.brands ?? []
  const banners = merchQuery.data?.data?.banners ?? []
  const merchSections = merchQuery.data?.data?.sections?.filter((section) => section.products.length > 0) ?? []
  const cartCount = cartQuery.data?.item_count ?? cartQuery.data?.items?.length ?? 0

  const hasActiveFilters =
    Boolean(searchTerm) || Boolean(activeCategoryId) || Boolean(activeBrand) || sort !== 'latest'

  return (
    <AppShell
      header={<ShopHeader name={line.profile?.displayName || 'Member'} avatar={line.profile?.pictureUrl} cartCount={cartCount} />}
      contentClassName="gap-5"
    >
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      <div className="sticky top-0 z-20 -mx-4 bg-surface-secondary/95 px-4 pb-4 pt-1 backdrop-blur-md">
        <div className="retail-surface p-4">
          <div className="flex items-center gap-2 rounded-[1.4rem] border border-slate-200 bg-white px-4 py-3">
            <Search size={18} className="text-slate-400" />
            <input
              type="search"
              value={inputValue}
              onChange={(event) => setInputValue(event.target.value)}
              placeholder="ค้นหาสินค้า แบรนด์ หรือสรรพคุณ"
              className="min-w-0 flex-1 bg-transparent text-sm text-slate-800 outline-none placeholder:text-slate-400"
            />
            {inputValue ? (
              <button type="button" onClick={() => setInputValue('')} className="text-slate-400 hover:text-slate-600">
                <X size={16} />
              </button>
            ) : null}
          </div>

          <div className="mt-3 flex flex-wrap gap-2">
            {sortOptions.map((option) => (
              <button
                key={option.value}
                type="button"
                onClick={() => setSort(option.value)}
                className={cn(
                  'rounded-full px-3 py-1.5 text-xs font-semibold transition',
                  sort === option.value ? 'bg-line text-white' : 'bg-slate-100 text-slate-500'
                )}
              >
                {option.label}
              </button>
            ))}
            {hasActiveFilters ? (
              <button
                type="button"
                onClick={() => {
                  setInputValue('')
                  setSearchTerm('')
                  setActiveCategoryId(null)
                  setActiveBrand(null)
                  setSort('latest')
                }}
                className="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-slate-500 ring-1 ring-slate-200"
              >
                ล้างตัวกรอง
              </button>
            ) : null}
          </div>
        </div>
      </div>

      {categories.length > 0 ? (
        <section className="space-y-3">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">หมวดสินค้า</p>
              <h2 className="text-base font-bold text-slate-900">เลือกจากหมวดที่ใช้งานบ่อย</h2>
            </div>
          </div>
          <div className="hide-scrollbar flex gap-3 overflow-x-auto pb-1">
            {categories.map((category) => (
              <CategoryShortcut
                key={category.id}
                category={category}
                active={activeCategoryId === String(category.id)}
                onClick={() => setActiveCategoryId((prev) => (prev === String(category.id) ? null : String(category.id)))}
              />
            ))}
          </div>
        </section>
      ) : null}

      {brands.length > 0 ? (
        <section className="space-y-3">
          <div className="flex items-center gap-2 text-slate-900">
            <Sparkles size={16} className="text-line" />
            <h2 className="text-sm font-semibold">แบรนด์เด่น</h2>
          </div>
          <div className="hide-scrollbar flex gap-2 overflow-x-auto pb-1">
            {brands.map((brand) => (
              <button
                key={brand}
                type="button"
                onClick={() => setActiveBrand((prev) => (prev === brand ? null : brand))}
                className={cn(
                  'shrink-0 rounded-full px-4 py-2 text-xs font-semibold transition',
                  activeBrand === brand ? 'bg-slate-900 text-white' : 'bg-white text-slate-500 ring-1 ring-slate-200'
                )}
              >
                {brand}
              </button>
            ))}
          </div>
        </section>
      ) : null}

      {banners.length > 0 ? <BannerSlider banners={banners} /> : null}

      {merchSections.map((section) => (
        <ShopMerchSectionRail key={section.id} section={section} />
      ))}

      <section className="space-y-4">
        <div className="flex items-end justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Catalog</p>
            <h2 className="text-lg font-bold text-slate-900">
              {activeCategoryId || activeBrand || searchTerm ? 'ผลลัพธ์ที่ตรงกับการเลือก' : 'สินค้าทั้งหมด'}
            </h2>
          </div>
          {firstPage?.total != null ? (
            <span className="text-sm font-medium text-slate-400">{firstPage.total.toLocaleString()} รายการ</span>
          ) : null}
        </div>

        {!lineUserId ? (
          <p className="rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-700">
            เข้าสู่ระบบ LINE เพื่อเพิ่มสินค้าและบันทึกรายการโปรด
          </p>
        ) : null}

        {catalogQuery.isLoading ? (
          <div className="grid grid-cols-2 gap-3">
            {[1, 2, 3, 4].map((item) => (
              <div key={item} className="skeleton aspect-[0.92] w-full rounded-[1.6rem]" />
            ))}
          </div>
        ) : products.length === 0 ? (
          <div className="retail-surface flex flex-col items-center gap-3 py-14 text-center">
            <Store size={40} className="text-slate-300" />
            <div className="space-y-1">
              <p className="text-base font-semibold text-slate-700">ไม่พบสินค้าที่ตรงกับเงื่อนไข</p>
              <p className="text-sm text-slate-400">ลองล้างตัวกรองหรือเปลี่ยนคำค้นหา</p>
            </div>
          </div>
        ) : (
          <>
            <div className="grid grid-cols-2 gap-3">
              {products.map((product) => (
                <ShopProductCard
                  key={product.id}
                  product={product}
                  lineUserId={lineUserId}
                  disabledAdd={!lineUserId || (addingId !== null && addingId !== product.id)}
                  isAdding={addingId === product.id}
                  isFavoriteToggling={favoriteId === product.id}
                  onAdd={() => addMutation.mutate({ productId: product.id })}
                  onToggleFavorite={
                    lineUserId
                      ? () => {
                          favoriteMutation.mutate(product.id)
                        }
                      : undefined
                  }
                />
              ))}
            </div>

            {catalogQuery.hasNextPage ? (
              <button
                type="button"
                onClick={() => catalogQuery.fetchNextPage()}
                disabled={catalogQuery.isFetchingNextPage}
                className="btn-secondary w-full"
              >
                {catalogQuery.isFetchingNextPage ? 'กำลังโหลดเพิ่ม...' : 'โหลดสินค้าเพิ่ม'}
              </button>
            ) : null}
          </>
        )}
      </section>
    </AppShell>
  )
}
