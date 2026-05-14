'use client'

import { UniversalLink } from '@/components/miniapp/UniversalLink'
import type { HomeProduct } from '@/types/miniapp-home'

type ShopMerchProductCardProps = {
  product: HomeProduct
}

export function ShopMerchProductCard({ product }: ShopMerchProductCardProps) {
  const displayPrice = product.salePrice ?? product.originalPrice
  const hasDiscount =
    product.originalPrice != null &&
    product.salePrice != null &&
    product.salePrice < product.originalPrice

  return (
    <UniversalLink
      link={product.link}
      className="retail-surface block min-w-[11rem] max-w-[11rem] overflow-hidden p-3"
    >
      <div className="relative overflow-hidden rounded-2xl bg-slate-50">
        <img
          src={product.imageUrl}
          alt={product.title}
          className="aspect-square w-full object-cover"
          loading="lazy"
        />
        <div className="absolute left-2 top-2 flex flex-wrap gap-1">
          {product.discountPercent != null && product.discountPercent > 0 ? (
            <span className="rounded-full bg-rose-500 px-2 py-1 text-[10px] font-semibold text-white">
              -{Math.round(product.discountPercent)}%
            </span>
          ) : null}
          {product.promotionLabel ? (
            <span className="rounded-full bg-slate-900/75 px-2 py-1 text-[10px] font-semibold text-white">
              {product.promotionLabel}
            </span>
          ) : null}
        </div>
      </div>

      <div className="mt-3 space-y-1">
        <h3 className="line-clamp-2 text-sm font-semibold leading-snug text-slate-900">
          {product.title}
        </h3>
        {product.shortDescription ? (
          <p className="line-clamp-1 text-xs text-slate-400">{product.shortDescription}</p>
        ) : null}
      </div>

      <div className="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
        {displayPrice != null ? (
          <span className="text-lg font-bold text-line">
            ฿{displayPrice.toLocaleString()}
          </span>
        ) : null}
        {hasDiscount && product.originalPrice != null ? (
          <span className="text-xs text-slate-400 line-through">
            ฿{product.originalPrice.toLocaleString()}
          </span>
        ) : null}
      </div>
    </UniversalLink>
  )
}
