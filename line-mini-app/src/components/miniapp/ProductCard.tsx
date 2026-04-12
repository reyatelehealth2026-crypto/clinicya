'use client'

import { Truck } from 'lucide-react'
import type { HomeProduct } from '@/types/miniapp-home'
import { UniversalLink } from '@/components/miniapp/UniversalLink'

interface ProductCardProps {
  product: HomeProduct
  variant?: 'flash_sale' | 'default'
}

export function ProductCard({ product, variant = 'default' }: ProductCardProps) {
  const hasDiscount = product.originalPrice && product.salePrice && product.originalPrice > product.salePrice
  const displayPrice = product.salePrice ?? product.originalPrice

  return (
    <UniversalLink
      link={product.link}
      className={`product-card product-card-${variant}`}
    >
      {/* Image */}
      <div className="product-card-img-wrap">
        <img
          src={product.imageUrl}
          alt={product.title}
          className="product-card-img"
          loading="lazy"
        />
        {/* Discount badge — Makro Pro top-left red tag */}
        {product.discountPercent != null && product.discountPercent > 0 && (
          <span className="product-card-discount-badge">
            -{Math.round(product.discountPercent)}%
          </span>
        )}
        {/* Promotion label badge */}
        {product.promotionLabel && (
          <span className="product-card-promo-label">{product.promotionLabel}</span>
        )}
        {/* Badges overlay */}
        {product.badges.length > 0 && (
          <div className="product-card-badges">
            {product.badges.map((b, i) => (
              <span key={i} className={`product-card-badge product-card-badge-${b.color}`}>
                {b.text}
              </span>
            ))}
          </div>
        )}
      </div>

      {/* Card body */}
      <div className="product-card-body">
        {/* Promotion tags (blue tags) */}
        {product.promotionTags.length > 0 && (
          <div className="product-card-promo-tags">
            {product.promotionTags.map((tag, i) => (
              <span key={i} className="product-card-promo-tag">{tag}</span>
            ))}
          </div>
        )}

        {/* Title & description */}
        <p className="product-card-title">{product.title}</p>
        {product.shortDescription && (
          <p className="product-card-desc">{product.shortDescription}</p>
        )}

        {/* Delivery options */}
        {product.deliveryOptions.length > 0 && (
          <div className="product-card-delivery">
            {product.deliveryOptions.map((opt, i) => (
              <span key={i} className="product-card-delivery-tag">
                <Truck size={10} />
                {opt}
              </span>
            ))}
          </div>
        )}

        {/* Stock badge */}
        {product.showStockBadge && (
          <p className="product-card-stock">จำนวนจำกัด</p>
        )}

        {/* Price — Makro Pro style: sale price big red, original struck-through */}
        <div className="product-card-price-area">
          {displayPrice != null && (
            <span className="product-card-price">
              ฿{displayPrice.toLocaleString()}
            </span>
          )}
          {product.priceUnit && (
            <span className="product-card-price-unit">/{product.priceUnit}</span>
          )}
          {hasDiscount && (
            <span className="product-card-original-price">
              ฿{product.originalPrice!.toLocaleString()}
            </span>
          )}
        </div>
      </div>
    </UniversalLink>
  )
}
