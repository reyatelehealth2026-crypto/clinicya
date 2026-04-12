import { appConfig } from '@/lib/config'

export type ShopProduct = {
  id: number
  name: string
  description?: string | null
  price?: number | string | null
  sale_price?: number | string | null
  image_url?: string | null
  stock?: number | null
  sku?: string | null
  category_id?: number | null
}

export type TransferBankRow = {
  bank_name: string
  account_number: string
  account_holder: string
}

export type TransferInfo = {
  banks: TransferBankRow[]
  promptpay_number: string
}

export type ProductsResponse = {
  success: boolean
  products?: ShopProduct[]
  categories?: { id: number; name: string }[]
  transfer_info?: TransferInfo
  message?: string
}

export async function fetchProducts(categoryId?: string | null, search?: string): Promise<ProductsResponse> {
  const params = new URLSearchParams({
    action: 'products',
    line_account_id: String(appConfig.lineAccountId)
  })
  if (categoryId) params.set('category_id', categoryId)
  if (search && search.trim() !== '') params.set('search', search.trim())
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
      line_user_id: lineUserId
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
