'use client'

import Image from 'next/image'
import Link from 'next/link'
import { Heart, Loader2, MessageCircle, ShoppingCart, Store } from 'lucide-react'
import type { ShopProduct } from '@/lib/shop-api'
import { getEffectivePrice, priceLabelFromProduct } from '@/lib/shop-product-utils'
import { cn } from '@/lib/utils'

type ShopProductCardProps = {
  product: ShopProduct
  lineUserId: string
  disabledAdd: boolean
  onAdd: () => void
  isAdding?: boolean
  layout?: 'grid' | 'rail'
  onToggleFavorite?: () => void
  isFavoriteToggling?: boolean
}

export function ShopProductCard({
  product,
  lineUserId,
  disabledAdd,
  onAdd,
  isAdding = false,
  layout = 'grid',
  onToggleFavorite,
  isFavoriteToggling = false,
}: ShopProductCardProps) {
  const sale = product.sale_price != null && product.sale_price !== '' ? Number(product.sale_price) : null
  const base = product.price != null && product.price !== '' ? Number(product.price) : null
  const showStrike = sale != null && base != null && !Number.isNaN(sale) && !Number.isNaN(base) && sale < base
  const outOfStock = product.stock === 0
  const isRail = layout === 'rail'
  const effectivePrice = getEffectivePrice(product)
  const isInquireOnly = effectivePrice == null || effectivePrice <= 0

  return (
    <article
      className={cn(
        'retail-surface overflow-hidden p-3 transition-transform duration-200',
        outOfStock && 'opacity-60',
        isRail ? 'min-w-[15rem] max-w-[15rem]' : 'w-full'
      )}
    >
      <div className="relative">
        <Link href={`/shop/product?id=${product.id}`} className="block overflow-hidden rounded-[1.35rem] bg-slate-50">
          <div className={cn('relative w-full overflow-hidden', isRail ? 'aspect-[1.05/1]' : 'aspect-[1/1.02]')}>
            {product.image_url ? (
              <Image
                src={product.image_url}
                alt={product.name}
                fill
                className="object-cover transition duration-300 hover:scale-[1.03]"
                sizes={isRail ? '240px' : '(max-width: 480px) 50vw, 240px'}
                unoptimized
              />
            ) : (
              <div className="flex h-full items-center justify-center text-slate-300">
                <Store size={34} />
              </div>
            )}
          </div>
        </Link>

        <div className="absolute left-2 top-2 flex max-w-[70%] flex-wrap gap-1">
          {product.discount_percent != null && product.discount_percent > 0 ? (
            <span className="rounded-full bg-rose-500 px-2 py-1 text-[10px] font-semibold text-white">
              -{product.discount_percent}%
            </span>
          ) : null}
          {product.promotion_label ? (
            <span className="rounded-full bg-slate-900/75 px-2 py-1 text-[10px] font-semibold text-white">
              {product.promotion_label}
            </span>
          ) : null}
        </div>

        {onToggleFavorite ? (
          <button
            type="button"
            onClick={onToggleFavorite}
            disabled={!lineUserId || isFavoriteToggling}
            className="absolute right-2 top-2 flex h-9 w-9 items-center justify-center rounded-full border border-white/70 bg-white/90 text-slate-500 shadow-soft transition hover:text-rose-500 disabled:cursor-not-allowed disabled:opacity-50"
            aria-label={product.is_favorite ? 'นำออกจากรายการโปรด' : 'เพิ่มรายการโปรด'}
          >
            {isFavoriteToggling ? (
              <Loader2 size={16} className="animate-spin" />
            ) : (
              <Heart size={16} className={cn(product.is_favorite && 'fill-rose-500 text-rose-500')} />
            )}
          </button>
        ) : null}

        {outOfStock ? (
          <div className="absolute inset-x-0 bottom-2 flex justify-center">
            <span className="rounded-full bg-slate-900/70 px-3 py-1 text-[11px] font-semibold text-white backdrop-blur-[2px]">
              สินค้าหมด
            </span>
          </div>
        ) : null}
      </div>

      <div className="mt-3 space-y-2">
        <div className="space-y-1">
          {product.brand ? (
            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">
              {product.brand}
            </p>
          ) : null}
          <Link href={`/shop/product?id=${product.id}`} className="block">
            <h3 className={cn('font-semibold leading-snug text-slate-900', isRail ? 'line-clamp-2 text-sm' : 'line-clamp-2 text-[0.95rem]')}>
              {product.name}
            </h3>
          </Link>
          {product.generic_name ? (
            <p className="line-clamp-1 text-xs text-slate-500">{product.generic_name}</p>
          ) : product.category_name ? (
            <p className="line-clamp-1 text-xs text-slate-500">{product.category_name}</p>
          ) : null}
        </div>

        <div className="flex flex-wrap items-end gap-x-2 gap-y-1">
          {isInquireOnly ? (
            <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
              สอบถามราคา
            </span>
          ) : (
            <>
              <span className="text-xl font-bold text-line">{priceLabelFromProduct(product)}</span>
              {showStrike && base != null ? (
                <span className="text-xs text-slate-400 line-through">฿{base.toLocaleString()}</span>
              ) : null}
              {product.unit ? (
                <span className="text-xs font-medium text-slate-400">/{product.unit}</span>
              ) : null}
            </>
          )}
        </div>

        <div className="flex items-center gap-2 pt-1">
          <Link
            href={`/shop/product?id=${product.id}`}
            className="flex min-w-0 flex-1 items-center justify-center rounded-2xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
          >
            ดูสินค้า
          </Link>
          {isInquireOnly ? (
            <Link
              href={`/shop/product?id=${product.id}&inquire=1`}
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-amber-500 text-white shadow-glow transition hover:bg-amber-600"
              aria-label="สอบถามราคา"
              title="สอบถามราคา"
            >
              <MessageCircle size={18} />
            </Link>
          ) : (
            <button
              type="button"
              disabled={!lineUserId || disabledAdd || outOfStock || isAdding}
              onClick={(event) => {
                event.preventDefault()
                onAdd()
              }}
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-line text-white shadow-glow transition hover:bg-line-dark disabled:cursor-not-allowed disabled:opacity-50"
              aria-label="ใส่ตะกร้า"
            >
              {isAdding ? <Loader2 size={18} className="animate-spin" /> : <ShoppingCart size={18} />}
            </button>
          )}
        </div>
      </div>
    </article>
  )
}
