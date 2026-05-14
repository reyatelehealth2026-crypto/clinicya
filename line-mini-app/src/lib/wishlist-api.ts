import { appConfig } from '@/lib/config'
import { phpGet, phpPost } from '@/lib/php-bridge'

export type WishlistItem = {
  id: number
  product_id: number
  name: string
  sku?: string | null
  price: number | string | null
  sale_price?: number | string | null
  image_url?: string | null
  stock?: number | null
  price_when_added: number | string
  is_on_sale: boolean | number
  discount_percent: number | null
  created_at: string
}

export type WishlistResponse = {
  success: boolean
  items: WishlistItem[]
  count: number
}

export type ToggleResponse = {
  success: boolean
  is_favorite: boolean
  message?: string
}

export function getWishlist(lineUserId: string) {
  return phpGet<WishlistResponse>('/api/wishlist.php', {
    action: 'list',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId
  })
}

export function toggleWishlist(lineUserId: string, productId: number) {
  return phpPost<ToggleResponse>('/api/wishlist.php', {
    action: 'toggle',
    line_user_id: lineUserId,
    product_id: productId,
    line_account_id: appConfig.lineAccountId
  })
}

export function removeWishlistItem(lineUserId: string, productId: number) {
  return phpPost<{ success: boolean; message?: string }>('/api/wishlist.php', {
    action: 'remove',
    line_user_id: lineUserId,
    product_id: productId,
    line_account_id: appConfig.lineAccountId
  })
}
