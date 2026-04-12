'use client'

import Link from 'next/link'
import Image from 'next/image'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Minus, Plus, ShoppingBag, Trash2 } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import {
  clearCart,
  fetchCart,
  removeCartLine,
  updateCartItem,
  type CartLine
} from '@/lib/shop-api'
import { useToast } from '@/lib/toast'

export function CartClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const cartQuery = useQuery({
    queryKey: ['shop-cart', lineUserId],
    queryFn: () => fetchCart(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const invalidateCart = () =>
    queryClient.invalidateQueries({ queryKey: ['shop-cart', lineUserId] })

  const updateMut = useMutation({
    mutationFn: ({ productId, quantity }: { productId: number; quantity: number }) =>
      updateCartItem(lineUserId, productId, quantity),
    onSuccess: (data) => {
      if (!data.success) toast.error(data.message || 'อัปเดตตะกร้าไม่สำเร็จ')
      void invalidateCart()
    },
    onError: (e: Error) => toast.error(e.message || 'อัปเดตตะกร้าไม่สำเร็จ')
  })

  const removeMut = useMutation({
    mutationFn: (productId: number) => removeCartLine(lineUserId, productId),
    onSuccess: (data) => {
      if (!data.success) toast.error(data.message || 'ลบรายการไม่สำเร็จ')
      void invalidateCart()
    },
    onError: (e: Error) => toast.error(e.message || 'ลบรายการไม่สำเร็จ')
  })

  const clearMut = useMutation({
    mutationFn: () => clearCart(lineUserId),
    onSuccess: (data) => {
      if (!data.success) toast.error(data.message || 'ล้างตะกร้าไม่สำเร็จ')
      else toast.success('ล้างตะกร้าแล้ว')
      void invalidateCart()
    },
    onError: (e: Error) => toast.error(e.message || 'ล้างตะกร้าไม่สำเร็จ')
  })

  const items = (cartQuery.data?.items ?? []) as CartLine[]
  const subtotal = cartQuery.data?.subtotal ?? 0
  const shipping = cartQuery.data?.shipping_fee ?? 0
  const total = cartQuery.data?.total ?? 0

  return (
    <AppShell title="ตะกร้า" subtitle="สินค้าที่เลือกไว้">
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      {!lineUserId ? (
        <p className="text-center text-sm text-slate-500">กรุณาเข้าสู่ระบบ LINE</p>
      ) : cartQuery.isLoading ? (
        <div className="space-y-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="skeleton h-20 w-full rounded-2xl" />
          ))}
        </div>
      ) : items.length === 0 ? (
        <div className="flex flex-col items-center gap-3 rounded-3xl bg-white py-14 text-center shadow-soft">
          <ShoppingBag className="text-slate-300" size={40} />
          <p className="text-sm text-slate-500">ตะกร้าว่าง</p>
          <Link href="/shop" className="text-sm font-semibold text-line">
            ไปเลือกสินค้า
          </Link>
        </div>
      ) : (
        <div className="space-y-3">
          {items.map((row) => (
            <div key={row.product_id} className="flex gap-3 rounded-2xl bg-white p-3 shadow-soft">
              <div className="relative h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-slate-100">
                {row.image_url ? (
                  <Image src={row.image_url} alt="" fill className="object-cover" sizes="64px" />
                ) : (
                  <div className="flex h-full items-center justify-center text-slate-300">
                    <ShoppingBag size={24} />
                  </div>
                )}
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-slate-900">{row.name}</p>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <div className="inline-flex items-center rounded-xl border border-slate-200 bg-slate-50">
                    <button
                      type="button"
                      aria-label="ลดจำนวน"
                      disabled={updateMut.isPending}
                      onClick={() =>
                        updateMut.mutate({
                          productId: row.product_id,
                          quantity: Math.max(0, row.quantity - 1)
                        })
                      }
                      className="p-2 text-slate-600 transition-colors hover:bg-slate-100 disabled:opacity-50"
                    >
                      <Minus size={16} />
                    </button>
                    <span className="min-w-[2rem] text-center text-sm font-semibold tabular-nums">
                      {row.quantity}
                    </span>
                    <button
                      type="button"
                      aria-label="เพิ่มจำนวน"
                      disabled={updateMut.isPending}
                      onClick={() =>
                        updateMut.mutate({ productId: row.product_id, quantity: row.quantity + 1 })
                      }
                      className="p-2 text-slate-600 transition-colors hover:bg-slate-100 disabled:opacity-50"
                    >
                      <Plus size={16} />
                    </button>
                  </div>
                  <button
                    type="button"
                    aria-label="ลบรายการ"
                    disabled={removeMut.isPending}
                    onClick={() => removeMut.mutate(row.product_id)}
                    className="inline-flex items-center gap-1 rounded-xl px-2 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"
                  >
                    <Trash2 size={14} />
                    ลบ
                  </button>
                </div>
                <p className="mt-1 text-sm font-bold text-slate-800">
                  ฿{(row.subtotal ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                </p>
              </div>
            </div>
          ))}
          <div className="rounded-2xl bg-white p-4 shadow-soft">
            <div className="flex justify-between text-sm text-slate-600">
              <span>ยอดสินค้า</span>
              <span>฿{subtotal.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
            </div>
            <div className="mt-2 flex justify-between text-sm text-slate-600">
              <span>ค่าจัดส่ง</span>
              <span>฿{shipping.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
            </div>
            <div className="mt-3 flex justify-between border-t border-slate-100 pt-3 text-base font-bold text-slate-900">
              <span>รวม</span>
              <span>฿{total.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
            </div>
            <button
              type="button"
              disabled={clearMut.isPending}
              onClick={() => {
                if (typeof window !== 'undefined' && !window.confirm('ล้างสินค้าทั้งหมดในตะกร้า?')) return
                clearMut.mutate()
              }}
              className="mt-2 w-full text-center text-xs font-medium text-slate-500 underline decoration-slate-300 disabled:opacity-50"
            >
              ล้างตะกร้าทั้งหมด
            </button>
            <Link
              href="/checkout"
              className="mt-4 flex w-full items-center justify-center rounded-2xl bg-line py-3 text-sm font-semibold text-white"
            >
              ไปชำระเงิน
            </Link>
          </div>
        </div>
      )}
    </AppShell>
  )
}
