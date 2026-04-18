import { appConfig } from '@/lib/config'

export type ShopProductBadge = {
  text: string
  color?: string
}

export type ProductSort = 'latest' | 'discount' | 'price_asc' | 'price_desc' | 'name_asc'

export type ShopCategory = {
  /** Numeric id (`business_items`) or category label string (`odoo_products_cache`) */
  id: number | string
  name: string
  icon_url?: string | null
}

export type ShopProduct = {
  id: number
  name: string
  description?: string | null
  price?: number | string | null
  sale_price?: number | string | null
  image_url?: string | null
  image_gallery?: string[]
  stock?: number | null
  sku?: string | null
  barcode?: string | null
  category_id?: number | null
  manufacturer?: string | null
  brand?: string | null
  generic_name?: string | null
  usage_instructions?: string | null
  properties_other?: string | null
  unit?: string | null
  is_favorite?: boolean
  /** From PHP `enrichProductRow` or derived client-side */
  is_flash_sale?: boolean
  promotion_label?: string | null
  discount_percent?: number | null
  badges?: ShopProductBadge[]
  category_name?: string | null
  /** Product-level publish flags from upstream e-commerce/backoffice systems. */
  is_active?: boolean | number | null
  is_published?: boolean | number | null
  is_enabled?: boolean | number | null
  catalog_visible?: boolean | number | null
  catalog_bucket?: string | null
  /** Set when catalog is served from Odoo cache (`api/checkout.php` / `shop-products.php`) */
  product_source?: 'odoo_products_cache' | 'business_items'
}

export type TransferBankRow = {
  bank_name: string
  account_number: string
  account_holder: string
}

export type BankAccount = {
  bank_name: string
  account_name: string
  account_number: string
}

export type TransferInfo = {
  banks: TransferBankRow[]
  promptpay_number: string
}

export type ProductsResponse = {
  success: boolean
  products?: ShopProduct[]
  categories?: ShopCategory[]
  brands?: string[]
  total?: number
  offset?: number
  limit?: number
  has_more?: boolean
  transfer_info?: TransferInfo
  message?: string
  /** Present when `action=products` uses Odoo storefront rows */
  product_catalog_source?: 'odoo_products_cache' | 'business_items'
  category_id_is_string?: boolean
}

export type FetchProductsInput = {
  categoryId?: string | null
  search?: string
  limit?: number
  offset?: number
  sort?: ProductSort
  brand?: string | null
  lineUserId?: string
  includeZeroPrice?: boolean
  includeInactive?: boolean
  catalogMode?: string
  catalogBucket?: string | null
}

export async function fetchProducts({
  categoryId,
  search,
  limit,
  offset,
  sort,
  brand,
  lineUserId,
  includeZeroPrice,
  includeInactive,
  catalogMode,
  catalogBucket,
}: FetchProductsInput = {}): Promise<ProductsResponse> {
  const params = new URLSearchParams({
    action: 'products',
    line_account_id: String(appConfig.lineAccountId)
  })
  if (categoryId) params.set('category_id', categoryId)
  if (search && search.trim() !== '') params.set('search', search.trim())
  if (limit != null) params.set('limit', String(limit))
  if (offset != null) params.set('offset', String(offset))
  if (sort) params.set('sort', sort)
  if (brand && brand.trim() !== '') params.set('brand', brand.trim())
  if (lineUserId) params.set('line_user_id', lineUserId)
  if (includeZeroPrice != null) params.set('include_zero_price', includeZeroPrice ? '1' : '0')
  if (includeInactive != null) params.set('include_inactive', includeInactive ? '1' : '0')
  if (catalogMode && catalogMode.trim() !== '') params.set('catalog_mode', catalogMode.trim())
  if (catalogBucket && catalogBucket.trim() !== '') params.set('catalog_bucket', catalogBucket.trim())
  const res = await fetch(`/api/checkout?${params}`, { cache: 'no-store' })
  return res.json()
}

export type ProductDetailResponse = {
  success: boolean
  product?: ShopProduct
  message?: string
}

/** Single product — `action=product_detail` on `api/checkout.php` */
export async function fetchProductDetail(productId: number, lineUserId?: string): Promise<ProductDetailResponse> {
  const params = new URLSearchParams({
    action: 'product_detail',
    product_id: String(productId),
    line_account_id: String(appConfig.lineAccountId)
  })
  if (lineUserId) params.set('line_user_id', lineUserId)
  const res = await fetch(`/api/checkout?${params}`, { cache: 'no-store' })
  return res.json()
}

/** Bank / PromptPay text for transfer checkout (also nested in products). */
export async function fetchPaymentInfo(): Promise<{ success: boolean; transfer_info?: TransferInfo; message?: string }> {
  const params = new URLSearchParams({
    action: 'payment_info',
    line_account_id: String(appConfig.lineAccountId)
  })
  const res = await fetch(`/api/checkout?${params}`, { cache: 'no-store' })
  return res.json()
}

export async function addToCart(lineUserId: string, productId: number, quantity = 1) {
  const res = await fetch('/api/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'add_to_cart',
      line_user_id: lineUserId,
      line_account_id: appConfig.lineAccountId,
      product_id: productId,
      quantity
    })
  })
  return res.json()
}

export type CartLine = {
  product_id: number
  name?: string
  quantity: number
  subtotal?: number
  image_url?: string | null
  sale_price?: number | string
  price?: number | string
}

export type CartResponse = {
  success: boolean
  items?: CartLine[]
  subtotal?: number
  shipping_fee?: number
  free_shipping_min?: number
  total?: number
  item_count?: number
  message?: string
}

export async function fetchCart(lineUserId: string): Promise<CartResponse> {
  const params = new URLSearchParams({
    action: 'cart',
    line_user_id: lineUserId,
    line_account_id: String(appConfig.lineAccountId)
  })
  const res = await fetch(`/api/checkout?${params}`, { cache: 'no-store' })
  return res.json()
}

export async function updateCartItem(lineUserId: string, productId: number, quantity: number) {
  const res = await fetch('/api/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'update_cart',
      line_user_id: lineUserId,
      line_account_id: appConfig.lineAccountId,
      product_id: productId,
      quantity
    })
  })
  return res.json() as Promise<{ success: boolean; message?: string }>
}

export async function removeCartLine(lineUserId: string, productId: number) {
  const res = await fetch('/api/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'remove_from_cart',
      line_user_id: lineUserId,
      line_account_id: appConfig.lineAccountId,
      product_id: productId
    })
  })
  return res.json() as Promise<{ success: boolean; message?: string }>
}

export async function clearCart(lineUserId: string) {
  const res = await fetch('/api/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'clear_cart',
      line_user_id: lineUserId,
      line_account_id: appConfig.lineAccountId
    })
  })
  return res.json() as Promise<{ success: boolean; message?: string }>
}

/** Relative URL for PromptPay QR image (proxied PNG). */
export function promptPayQrSrc(amount: number) {
  const params = new URLSearchParams({
    action: 'promptpay_qr',
    amount: String(Math.max(0, amount)),
    line_account_id: String(appConfig.lineAccountId)
  })
  return `/api/checkout?${params.toString()}`
}

export type ValidatePromoResponse = {
  success: boolean
  message?: string
  valid?: boolean
  discount?: number
}

export async function validatePromo(
  code: string,
  lineUserId: string,
  subtotal: number
): Promise<ValidatePromoResponse> {
  const res = await fetch('/api/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'validate_promo',
      code: code.trim(),
      line_user_id: lineUserId,
      line_account_id: appConfig.lineAccountId,
      subtotal
    })
  })
  return res.json()
}

export type LastAddress = {
  name?: string
  phone?: string
  address?: string
  subdistrict?: string
  district?: string
  province?: string
  postcode?: string
}

/** Last delivery address from PHP (`transactions` / user profile). */
export async function fetchLastAddress(lineUserId: string): Promise<LastAddress | null> {
  const params = new URLSearchParams({
    action: 'last_address',
    line_user_id: lineUserId
  })
  const res = await fetch(`/api/checkout?${params}`, { cache: 'no-store' })
  const data = await res.json()
  if (!data.success || !data.address) return null
  return data.address as LastAddress
}

export type CreateShopOrderInput = {
  lineUserId: string
  paymentMethod: 'transfer' | 'cod'
  address: LastAddress
  /** After promo: pass discounted subtotal so PHP recomputes shipping from `shop_settings`. */
  subtotal?: number
}

export type CreateShopOrderResult = {
  success: boolean
  message?: string
  order_id?: number
  order_number?: string
  total?: number
}

/** Create order from server-side cart (`cart_items`) — same contract as `liff-app` / `checkout.php`. */
export async function createShopOrder(input: CreateShopOrderInput): Promise<CreateShopOrderResult> {
  const { lineUserId, paymentMethod, address, subtotal: orderSubtotal } = input
  const res = await fetch('/api/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'create_order',
      line_user_id: lineUserId,
      line_account_id: appConfig.lineAccountId,
      address: {
        name: address.name ?? '',
        phone: address.phone ?? '',
        address: address.address ?? '',
        subdistrict: address.subdistrict ?? '',
        district: address.district ?? '',
        province: address.province ?? '',
        postcode: address.postcode ?? ''
      },
      payment_method: paymentMethod,
      ...(orderSubtotal != null ? { subtotal: orderSubtotal } : {})
    }),
    cache: 'no-store'
  })
  return res.json()
}

export function formatThb(value: number) {
  return `฿${value.toLocaleString(undefined, { minimumFractionDigits: value % 1 === 0 ? 0 : 2, maximumFractionDigits: 2 })}`
}

export type UploadSlipResult = {
  success: boolean
  message?: string
  image_url?: string
}

/** Upload payment slip (multipart) — `handleUploadSlip` in PHP. */
export async function uploadPaymentSlip(orderId: number, file: File): Promise<UploadSlipResult> {
  const fd = new FormData()
  fd.append('action', 'upload_slip')
  fd.append('order_id', String(orderId))
  fd.append('slip', file)
  const res = await fetch('/api/checkout-slip', {
    method: 'POST',
    body: fd
  })
  return res.json()
}

export type OrderDetailApiResponse = {
  success: boolean
  message?: string
  order?: {
    id: number
    order_number?: string
    status?: string
    payment_status?: string
    payment_method?: string
    grand_total?: number
    created_at?: string
    items?: Array<{
      product_name?: string
      quantity: number
      subtotal?: number
    }>
  }
  transfer_info?: TransferInfo
}

export async function fetchOrderDetail(orderId: string): Promise<OrderDetailApiResponse> {
  const params = new URLSearchParams({
    action: 'get_order',
    order_id: orderId
  })
  const res = await fetch(`/api/checkout?${params}`, { cache: 'no-store' })
  return res.json()
}
