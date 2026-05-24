'use client'

import { useState } from 'react'
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
  const [showClearConfirm, setShowClearConfirm] = useState(false)

  const cartQuery = useQuery({
    queryKey: ['shop-cart', lineUserId],
    queryFn: () => fetchCart(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const invalidateCart = () =>
    queryClient.invalidateQueries({ queryKey: ['shop-cart', lineUserId] })

  const updateMut = useMutation({
    mutationFn: ({
      productId,
      quantity,
      unitId,
    }: {
      productId: number
      quantity: number
      unitId?: number | null
    }) => updateCartItem(lineUserId, productId, quantity, unitId),
    onSuccess: (data) => {
      if (!data.success) toast.error(data.message || 'อัปเดตตะกร้าไม่สำเร็จ')
      void invalidateCart()
    },
    onError: (e: Error) => toast.error(e.message || 'อัปเดตตะกร้าไม่สำเร็จ')
  })

  const removeMut = useMutation({
    mutationFn: ({ productId, unitId }: { productId: number; unitId?: number | null }) =>
      removeCartLine(lineUserId, productId, unitId),
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
        <div className="flex flex-col items-center gap-3 rounded-3xl bg-white py-14 text-center shadow-soft">
          <ShoppingBag className="text-slate-300" size={40} />
          <p className="text-sm text-slate-500">กรุณาเข้าสู่ระบบ LINE</p>
          <Link href="/" className="rounded-2xl bg-line px-5 py-2 text-sm font-semibold text-white">
            กลับหน้าหลัก
          </Link>
        </div>
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
            <div key={`${row.product_id}-${row.unit_id ?? 'base'}`} className="flex gap-3 rounded-2xl bg-white p-3 shadow-soft">
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
                {row.unit_name ? (
                  <p className="mt-0.5 text-[11px] text-slate-500">
                    หน่วย: <span className="font-medium text-slate-700">{row.unit_name}</span>
                    {row.unit_price != null
                      ? ` · ฿${Number(row.unit_price).toLocaleString(undefined, { minimumFractionDigits: 2 })}/${row.unit_name}`
                      : ''}
                  </p>
                ) : null}
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <div className="inline-flex items-center rounded-xl border border-slate-200 bg-slate-50">
                    <button
                      type="button"
                      aria-label="ลดจำนวน"
                      disabled={updateMut.isPending || removeMut.isPending}
                      onClick={() => {
                        const next = row.quantity - 1
                        if (next <= 0) {
                          removeMut.mutate({ productId: row.product_id, unitId: row.unit_id })
                        } else {
                          updateMut.mutate({
                            productId: row.product_id,
                            quantity: next,
                            unitId: row.unit_id,
                          })
                        }
                      }}
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
                        updateMut.mutate({ productId: row.product_id, quantity: row.quantity + 1, unitId: row.unit_id })
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
                    onClick={() => removeMut.mutate({ productId: row.product_id, unitId: row.unit_id })}
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
            {showClearConfirm ? (
              <div className="mt-3 rounded-2xl border border-red-100 bg-red-50 p-3 text-center">
                <p className="text-xs font-medium text-red-700">ล้างสินค้าทั้งหมดในตะกร้า?</p>
                <div className="mt-2 flex gap-2">
                  <button
                    type="button"
                    onClick={() => setShowClearConfirm(false)}
                    className="flex-1 rounded-xl bg-white py-2 text-xs font-semibold text-slate-600 ring-1 ring-slate-200"
                  >
                    ยกเลิก
                  </button>
                  <button
                    type="button"
                    disabled={clearMut.isPending}
                    onClick={() => {
                      clearMut.mutate(undefined, {
                        onSettled: () => setShowClearConfirm(false)
                      })
                    }}
                    className="flex-1 rounded-xl bg-red-500 py-2 text-xs font-semibold text-white disabled:opacity-50"
                  >
                    {clearMut.isPending ? 'กำลังล้าง…' : 'ยืนยันล้าง'}
                  </button>
                </div>
              </div>
            ) : (
              <button
                type="button"
                disabled={clearMut.isPending}
                onClick={() => setShowClearConfirm(true)}
                className="mt-2 w-full text-center text-xs font-medium text-slate-500 underline decoration-slate-300 disabled:opacity-50"
              >
                ล้างตะกร้าทั้งหมด
              </button>
            )}
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
