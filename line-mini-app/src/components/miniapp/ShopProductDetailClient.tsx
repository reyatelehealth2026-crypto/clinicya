'use client'

import { useEffect, useMemo, useState } from 'react'
import Link from 'next/link'
import { useSearchParams, useRouter } from 'next/navigation'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Heart, Loader2, ShoppingCart } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { QuantityStepper } from '@/components/miniapp/QuantityStepper'
import { ShopDetailGallery } from '@/components/miniapp/ShopDetailGallery'
import { ShopProductCard } from '@/components/miniapp/ShopProductCard'
import { StickyCommerceBar } from '@/components/miniapp/StickyCommerceBar'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { addToCart, fetchCart, fetchProductDetail, fetchProducts } from '@/lib/shop-api'
import { enrichShopProduct, filterVisibleShopProducts, priceLabelFromProduct } from '@/lib/shop-product-utils'
import { toggleWishlist } from '@/lib/wishlist-api'
import { cn } from '@/lib/utils'
import { appConfig } from '@/lib/config'

function getNumericPrice(value: number | string | null | undefined) {
  if (value == null || value === '') return null
  const next = typeof value === 'number' ? value : Number(value)
  return Number.isNaN(next) ? null : next
}

function DetailHeader({
  cartCount,
  isFavorite,
  onBack,
  onToggleFavorite,
  favoritePending,
}: {
  cartCount: number
  isFavorite: boolean
  onBack: () => void
  onToggleFavorite?: () => void
  favoritePending: boolean
}) {
  return (
    <header className="safe-top shrink-0 border-b border-slate-100 bg-white/95 backdrop-blur-sm">
      <div className="mx-auto flex max-w-md items-center justify-between px-4 pb-4 pt-3">
        <button
          type="button"
          onClick={onBack}
          className="flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 shadow-soft"
          aria-label="กลับ"
        >
          <ArrowLeft size={20} />
        </button>

        <div className="flex items-center gap-2">
          {onToggleFavorite ? (
            <button
              type="button"
              onClick={onToggleFavorite}
              disabled={favoritePending}
              className="flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 shadow-soft disabled:opacity-50"
              aria-label={isFavorite ? 'นำออกจากรายการโปรด' : 'เพิ่มรายการโปรด'}
            >
              {favoritePending ? (
                <Loader2 size={18} className="animate-spin" />
              ) : (
                <Heart size={18} className={cn(isFavorite && 'fill-rose-500 text-rose-500')} />
              )}
            </button>
          ) : null}

          <Link
            href="/cart"
            className="relative flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 shadow-soft"
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

function DetailSection({ title, content }: { title: string; content: string }) {
  return (
    <details className="retail-surface overflow-hidden px-4 py-3" open>
      <summary className="cursor-pointer list-none text-sm font-semibold text-slate-900">{title}</summary>
      <p className="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-600">{content}</p>
    </details>
  )
}

export function ShopProductDetailClient() {
  const searchParams = useSearchParams()
  const router = useRouter()
  const queryClient = useQueryClient()
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const productId = Number(searchParams.get('id') || 0)

  const [quantity, setQuantity] = useState(1)

  const cartQuery = useQuery({
    queryKey: ['shop-cart', lineUserId],
    queryFn: () => fetchCart(lineUserId),
    enabled: Boolean(lineUserId),
    staleTime: 30_000,
  })

  const detailQuery = useQuery({
    queryKey: ['shop-product', productId, lineUserId],
    queryFn: () => fetchProductDetail(productId, lineUserId),
    enabled: Number.isFinite(productId) && productId > 0,
  })

  const product = detailQuery.data?.product ? enrichShopProduct(detailQuery.data.product) : null
  const visibleProduct = product
    ? filterVisibleShopProducts([product], {
        hideZeroPrice: appConfig.shopCatalog.hideZeroPriceProducts,
        hideInactive: appConfig.shopCatalog.hideInactiveProducts,
        mode: appConfig.shopCatalog.mode,
      })[0] ?? null
    : null

  useEffect(() => {
    setQuantity(1)
  }, [productId])

  const relatedQuery = useQuery({
    queryKey: ['shop-products-related', product?.category_id, product?.brand, productId, lineUserId],
    queryFn: () =>
      fetchProducts({
        categoryId: product?.category_id != null ? String(product.category_id) : undefined,
        brand: product?.category_id ? undefined : product?.brand ?? undefined,
        limit: 8,
        lineUserId,
        catalogMode: appConfig.shopCatalog.mode,
        includeZeroPrice: !appConfig.shopCatalog.hideZeroPriceProducts,
        includeInactive: !appConfig.shopCatalog.hideInactiveProducts,
      }),
    enabled: Boolean(product),
  })

  const addMutation = useMutation({
    mutationFn: () => addToCart(lineUserId, productId, quantity),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shop-cart', lineUserId] })
    },
  })

  const favoriteMutation = useMutation({
    mutationFn: () => toggleWishlist(lineUserId, productId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shop-product', productId] })
      queryClient.invalidateQueries({ queryKey: ['shop-products'] })
      queryClient.invalidateQueries({ queryKey: ['wishlist', lineUserId] })
    },
  })

  const relatedProducts = useMemo(
    () =>
      (relatedQuery.data?.products ?? [])
        .filter((item) => item.id !== productId)
        .map((item) => enrichShopProduct(item))
        .filter((item) =>
          filterVisibleShopProducts([item], {
            hideZeroPrice: appConfig.shopCatalog.hideZeroPriceProducts,
            hideInactive: appConfig.shopCatalog.hideInactiveProducts,
            mode: appConfig.shopCatalog.mode,
          }).length > 0
        )
        .slice(0, 8),
    [relatedQuery.data?.products, productId]
  )

  if (!Number.isFinite(productId) || productId <= 0) {
    return (
      <AppShell title="สินค้า" subtitle="" showBottomNav={false}>
        <p className="text-center text-sm text-slate-500">ไม่พบสินค้า</p>
      </AppShell>
    )
  }

  if (detailQuery.isLoading) {
    return (
      <AppShell title="สินค้า" subtitle="" showBottomNav={false} contentClassName="pb-32">
        <div className="skeleton aspect-square w-full rounded-[1.75rem]" />
        <div className="skeleton h-24 w-full rounded-[1.75rem]" />
      </AppShell>
    )
  }

  if (!detailQuery.data?.success || !visibleProduct) {
    return (
      <AppShell title="สินค้า" subtitle="" showBottomNav={false}>
        <p className="text-center text-sm text-slate-500">
          {detailQuery.data?.message || 'ไม่พบสินค้า'}
        </p>
        <button type="button" onClick={() => router.push('/shop')} className="btn-primary w-full">
          กลับไปร้านค้า
        </button>
      </AppShell>
    )
  }

  const basePrice = getNumericPrice(visibleProduct.price)
  const effectivePrice = getNumericPrice(visibleProduct.sale_price) ?? basePrice ?? 0
  const totalPrice = effectivePrice * quantity
  const cartCount = cartQuery.data?.item_count ?? cartQuery.data?.items?.length ?? 0
  const detailSections = [
    { title: 'รายละเอียดสินค้า', content: visibleProduct.description ?? '' },
    { title: 'วิธีใช้', content: visibleProduct.usage_instructions ?? '' },
    { title: 'ข้อมูลเพิ่มเติม', content: visibleProduct.properties_other ?? '' },
  ].filter((section) => section.content.trim() !== '')

  return (
    <AppShell
      header={
        <DetailHeader
          cartCount={cartCount}
          isFavorite={Boolean(visibleProduct.is_favorite)}
          onBack={() => router.back()}
          onToggleFavorite={lineUserId ? () => favoriteMutation.mutate() : undefined}
          favoritePending={favoriteMutation.isPending}
        />
      }
      showBottomNav={false}
      contentClassName="gap-5 pb-40"
    >
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      <ShopDetailGallery images={visibleProduct.image_gallery ?? []} name={visibleProduct.name} />

      <section className="retail-surface space-y-4 p-5">
        <div className="space-y-2">
          <div className="flex flex-wrap gap-2">
            {visibleProduct.promotion_label ? (
              <span className="rounded-full bg-line-soft px-3 py-1 text-xs font-semibold text-line">
                {visibleProduct.promotion_label}
              </span>
            ) : null}
            {visibleProduct.discount_percent != null && visibleProduct.discount_percent > 0 ? (
              <span className="rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-600">
                ลด {visibleProduct.discount_percent}%
              </span>
            ) : null}
            {visibleProduct.brand ? (
              <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">
                {visibleProduct.brand}
              </span>
            ) : null}
          </div>

          <h1 className="text-[1.65rem] font-bold leading-tight text-slate-950">{visibleProduct.name}</h1>
          {visibleProduct.generic_name ? (
            <p className="text-sm text-slate-500">{visibleProduct.generic_name}</p>
          ) : null}
        </div>

        <div className="flex flex-wrap items-end gap-x-3 gap-y-1">
          <span className="text-3xl font-bold text-line">{priceLabelFromProduct(visibleProduct)}</span>
          {basePrice != null && basePrice > effectivePrice ? (
            <span className="text-sm text-slate-400 line-through">฿{basePrice.toLocaleString()}</span>
          ) : null}
          {visibleProduct.unit ? <span className="text-sm text-slate-400">/{visibleProduct.unit}</span> : null}
        </div>

        <div className="grid grid-cols-2 gap-3 rounded-[1.4rem] bg-slate-50 p-4 text-sm">
          <div>
            <p className="text-slate-400">หมวดสินค้า</p>
            <p className="mt-1 font-semibold text-slate-800">{visibleProduct.category_name || '-'}</p>
          </div>
          <div>
            <p className="text-slate-400">SKU</p>
            <p className="mt-1 font-semibold text-slate-800">{visibleProduct.sku || '-'}</p>
          </div>
        </div>
      </section>

      <section className="retail-surface flex flex-col items-center gap-3 p-5 text-center">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Quantity</p>
          <p className="mt-1 text-sm text-slate-500">เลือกจำนวนก่อนใส่ตะกร้า</p>
        </div>
        <QuantityStepper
          value={quantity}
          onChange={setQuantity}
          min={1}
          max={visibleProduct.stock != null && visibleProduct.stock > 0 ? visibleProduct.stock : undefined}
          disabled={!lineUserId || addMutation.isPending || visibleProduct.stock === 0}
          size="lg"
        />
      </section>

      {detailSections.map((section) => (
        <DetailSection key={section.title} title={section.title} content={section.content} />
      ))}

      {relatedProducts.length > 0 ? (
        <section className="space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-bold text-slate-900">สินค้าแนะนำเพิ่มเติม</h2>
            <Link href="/shop" className="text-sm font-semibold text-line">
              ดูเพิ่ม
            </Link>
          </div>
          <div className="hide-scrollbar flex gap-3 overflow-x-auto pb-1">
            {relatedProducts.map((item) => (
              <ShopProductCard
                key={item.id}
                product={item}
                lineUserId={lineUserId}
                layout="rail"
                disabledAdd={addMutation.isPending}
                onAdd={() => {
                  void addToCart(lineUserId, item.id, 1).then(() => {
                    queryClient.invalidateQueries({ queryKey: ['shop-cart', lineUserId] })
                  })
                }}
                onToggleFavorite={
                  lineUserId
                    ? () => {
                        void toggleWishlist(lineUserId, item.id).then(() => {
                          queryClient.invalidateQueries({ queryKey: ['shop-products'] })
                          queryClient.invalidateQueries({ queryKey: ['wishlist', lineUserId] })
                        })
                    }
                    : undefined
                }
              />
            ))}
          </div>
        </section>
      ) : null}

      <StickyCommerceBar
        summary={
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">รวมคำสั่งซื้อ</p>
            <p className="mt-1 text-xl font-bold text-slate-900">฿{totalPrice.toLocaleString()}</p>
            <p className="text-xs text-slate-500">{quantity} ชิ้น</p>
          </div>
        }
        action={
          <button
            type="button"
            onClick={() => addMutation.mutate()}
            disabled={!lineUserId || addMutation.isPending || visibleProduct.stock === 0}
            className="btn-primary min-w-[11rem] px-6"
          >
            {addMutation.isPending ? (
              <>
                <Loader2 size={18} className="animate-spin" />
                กำลังเพิ่ม
              </>
            ) : (
              <>
                <ShoppingCart size={18} />
                ใส่ตะกร้า
              </>
            )}
          </button>
        }
      />
    </AppShell>
  )
}
