import type { ShopProduct, ShopProductBadge } from '@/lib/shop-api'

function asBool(value: boolean | number | null | undefined) {
  if (typeof value === 'boolean') return value
  if (typeof value === 'number') return value === 1
  return null
}

export function getEffectivePrice(p: ShopProduct) {
  const sale = p.sale_price != null && p.sale_price !== '' ? Number(p.sale_price) : null
  const base = p.price != null && p.price !== '' ? Number(p.price) : null
  if (sale != null && !Number.isNaN(sale)) return sale
  if (base != null && !Number.isNaN(base)) return base
  return null
}

export function isProductActive(p: ShopProduct) {
  const candidates = [p.is_active, p.is_published, p.is_enabled, p.catalog_visible].map(asBool)
  const explicit = candidates.find((candidate) => candidate != null)
  return explicit ?? true
}

/** Display price: prefer sale when valid. */
export function priceLabelFromProduct(p: ShopProduct) {
  const effective = getEffectivePrice(p)
  if (effective != null) return `฿${effective.toLocaleString()}`
  return '—'
}

export function filterVisibleShopProducts(
  products: ShopProduct[],
  options?: {
    hideZeroPrice?: boolean
    hideInactive?: boolean
    bucket?: string | null
    mode?: string
  }
) {
  return products.filter((product) => {
    if (options?.hideInactive && !isProductActive(product)) return false
    if (options?.hideZeroPrice) {
      const effective = getEffectivePrice(product)
      if (effective != null && effective <= 0) return false
    }
    if (options?.mode === 'catalog' && !isProductActive(product)) return false
    if (options?.bucket && (product.catalog_bucket ?? '').trim() !== options.bucket.trim()) return false
    return true
  })
}

/** Ensure badges / discount when PHP omits enrich (older cache). */
export function enrichShopProduct(p: ShopProduct): ShopProduct {
  const sale = p.sale_price != null && p.sale_price !== '' ? Number(p.sale_price) : null
  const base = p.price != null && p.price !== '' ? Number(p.price) : null
  if (
    p.badges &&
    p.badges.length > 0 &&
    (p.discount_percent != null || (sale != null && base != null && sale < base))
  ) {
    return p
  }
  let discount_percent = p.discount_percent ?? null
  const badges: ShopProductBadge[] = [...(p.badges ?? [])]
  if (
    sale != null &&
    base != null &&
    !Number.isNaN(sale) &&
    !Number.isNaN(base) &&
    sale < base &&
    base > 0
  ) {
    discount_percent = Math.round((1 - sale / base) * 100)
    if (badges.length === 0) {
      badges.push({ text: `-${discount_percent}%`, color: 'red' })
    }
  }
  return {
    ...p,
    discount_percent,
    promotion_label:
      p.promotion_label ??
      (discount_percent != null && discount_percent > 0 ? 'โปรโมชัน' : null),
    badges
  }
}
