'use client'

import { Pill, ShoppingCart } from 'lucide-react'
import type { TriageProduct } from '@/types/ai-chat'

interface ProductCardListProps {
  products: TriageProduct[]
  onAddToCart?: (productId: number) => void
}

function formatPrice(p: number): string {
  return new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    maximumFractionDigits: 0
  }).format(p)
}

/**
 * Product cards ที่ AI แนะนำหลัง triage จบ — แสดง 3-5 ตัว
 * แต่ละการ์ดมี: รูป, ชื่อ, ราคา, "ทำไมแนะนำ" (reason จาก AI), ปุ่ม "เพิ่มลงตะกร้า"
 */
export function ProductCardList({ products, onAddToCart }: ProductCardListProps) {
  if (products.length === 0) return null

  return (
    <div className="mt-3 space-y-2">
      {products.map((p) => {
        const displayPrice = p.sale_price ?? p.price
        const hasDiscount =
          p.sale_price !== null && p.sale_price !== undefined && p.sale_price < p.price
        return (
          <div
            key={p.id}
            className="flex gap-3 bg-white rounded-xl p-3 border border-gray-100 shadow-sm"
          >
            <div className="w-16 h-16 rounded-lg bg-gradient-to-br from-purple-50 to-blue-50 flex items-center justify-center shrink-0 overflow-hidden">
              {p.image_url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={p.image_url} alt={p.name} className="w-full h-full object-cover" />
              ) : (
                <Pill className="w-7 h-7 text-purple-400" />
              )}
            </div>

            <div className="flex-1 min-w-0">
              <div className="flex items-start justify-between gap-2">
                <h4 className="text-sm font-semibold text-gray-900 line-clamp-2">{p.name}</h4>
                {p.is_first_line ? (
                  <span className="px-1.5 py-0.5 text-[10px] bg-emerald-100 text-emerald-700 rounded-md font-medium shrink-0">
                    แนะนำ
                  </span>
                ) : null}
              </div>

              {p.strength || p.dosage_form ? (
                <p className="text-xs text-gray-500 mt-0.5">
                  {[p.strength, p.dosage_form].filter(Boolean).join(' • ')}
                </p>
              ) : null}

              {p.reason ? (
                <p className="text-xs text-purple-700 mt-1 line-clamp-2 italic">💡 {p.reason}</p>
              ) : null}

              <div className="flex items-center justify-between mt-2">
                <div className="flex items-baseline gap-1.5">
                  <span className="text-base font-bold text-gray-900">
                    {formatPrice(displayPrice)}
                  </span>
                  {hasDiscount ? (
                    <span className="text-xs text-gray-400 line-through">
                      {formatPrice(p.price)}
                    </span>
                  ) : null}
                </div>
                {onAddToCart ? (
                  <button
                    type="button"
                    onClick={() => onAddToCart(p.id)}
                    className="min-h-[36px] px-3 py-1.5 bg-purple-600 text-white text-xs rounded-full flex items-center gap-1 active:scale-95"
                  >
                    <ShoppingCart className="w-3 h-3" />
                    เพิ่มลงตะกร้า
                  </button>
                ) : null}
              </div>
            </div>
          </div>
        )
      })}

      <p className="text-xs text-gray-500 text-center pt-1">
        ⚠️ คำแนะนำเบื้องต้นเท่านั้น ควรปรึกษาเภสัชกรก่อนใช้
      </p>
    </div>
  )
}
