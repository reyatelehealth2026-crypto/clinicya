'use client'

import Link from 'next/link'
import { useCallback } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Heart, ShoppingCart, Trash2 } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { getWishlist, removeWishlistItem } from '@/lib/wishlist-api'
import { addToCart } from '@/lib/shop-api'

function parsePrice(v: number | string | null | undefined): number {
  if (v == null) return 0
  return typeof v === 'string' ? parseFloat(v) || 0 : v
}

export function WishlistClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const qc = useQueryClient()

  const wishlistQuery = useQuery({
    queryKey: ['wishlist', lineUserId],
    queryFn: () => getWishlist(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const removeMutation = useMutation({
    mutationFn: (productId: number) => removeWishlistItem(lineUserId, productId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['wishlist', lineUserId] })
  })

  const cartMutation = useMutation({
    mutationFn: (productId: number) => addToCart(lineUserId, productId, 1),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['shop-cart', lineUserId] })
  })

  const handleRefresh = useCallback(async () => {
    await qc.invalidateQueries({ queryKey: ['wishlist', lineUserId] })
  }, [qc, lineUserId])

  const items = wishlistQuery.data?.items || []

  return (
    <AppShell title="รายการโปรด" subtitle="สินค้าที่บันทึกไว้" onRefresh={handleRefresh}>
      {wishlistQuery.isLoading ? (
        <div className="space-y-3">
          {[1, 2, 3].map(i => (
            <div key={i} className="skeleton h-24 w-full rounded-2xl" />
          ))}
        </div>
      ) : items.length === 0 ? (
        <div className="flex flex-col items-center justify-center gap-4 py-16 text-center">
          <div className="flex h-20 w-20 items-center justify-center rounded-full bg-rose-50">
            <Heart size={36} className="text-rose-300" />
          </div>
          <div>
            <p className="text-base font-semibold text-slate-700">ยังไม่มีรายการโปรด</p>
            <p className="mt-1 text-sm text-slate-400">บันทึกสินค้าที่สนใจไว้ที่นี่</p>
          </div>
          <Link
            href="/shop"
            className="mt-2 rounded-2xl bg-line px-6 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90"
          >
            เลือกดูสินค้า
          </Link>
        </div>
      ) : (
        <div className="space-y-3">
          <p className="px-1 text-xs font-semibold text-slate-400">{items.length} รายการ</p>
          {items.map((item) => {
            const displayPrice = parsePrice(item.sale_price || item.price)
            const originalPrice = item.is_on_sale ? parsePrice(item.price) : null
            const isRemoving = removeMutation.isPending && removeMutation.variables === item.product_id
            const isAddingCart = cartMutation.isPending && cartMutation.variables === item.product_id

            return (
              <div
                key={item.id}
                className="flex items-center gap-3 rounded-2xl bg-white p-3 shadow-soft"
              >
                {/* Product image */}
                <Link href={`/shop/product?id=${item.product_id}`} className="shrink-0">
                  {item.image_url ? (
                    <img
                      src={item.image_url}
                      alt={item.name}
                      className="h-16 w-16 rounded-xl object-cover"
                    />
                  ) : (
                    <div className="flex h-16 w-16 items-center justify-center rounded-xl bg-slate-100 text-xl">
                      💊
                    </div>
                  )}
                </Link>

                {/* Info */}
                <div className="min-w-0 flex-1">
                  <Link href={`/shop/product?id=${item.product_id}`}>
                    <p className="truncate text-sm font-semibold text-slate-900">{item.name}</p>
                  </Link>
                  <div className="mt-1 flex items-center gap-2">
                    <span className="text-sm font-bold text-line">
                      ฿{displayPrice.toLocaleString()}
                    </span>
                    {originalPrice != null && originalPrice > displayPrice && (
                      <span className="text-xs text-slate-400 line-through">
                        ฿{originalPrice.toLocaleString()}
                      </span>
                    )}
                  </div>
                  {item.stock != null && item.stock <= 0 && (
                    <span className="mt-1 inline-block text-xs text-rose-500">สินค้าหมด</span>
                  )}
                </div>

                {/* Actions */}
                <div className="flex shrink-0 flex-col gap-2">
                  <button
                    type="button"
                    onClick={() => cartMutation.mutate(item.product_id)}
                    disabled={isAddingCart || (item.stock != null && item.stock <= 0)}
                    className="flex h-8 w-8 items-center justify-center rounded-xl bg-line-soft text-line transition-all hover:bg-line hover:text-white disabled:opacity-40"
                    title="เพิ่มในตะกร้า"
                  >
                    {isAddingCart ? (
                      <div className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-line border-t-transparent" />
                    ) : (
                      <ShoppingCart size={16} />
                    )}
                  </button>
                  <button
                    type="button"
                    onClick={() => removeMutation.mutate(item.product_id)}
                    disabled={isRemoving}
                    className="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-50 text-rose-400 transition-all hover:bg-rose-100 disabled:opacity-40"
                    title="ลบออกจากรายการโปรด"
                  >
                    {isRemoving ? (
                      <div className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-rose-400 border-t-transparent" />
                    ) : (
                      <Trash2 size={16} />
                    )}
                  </button>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </AppShell>
  )
}
