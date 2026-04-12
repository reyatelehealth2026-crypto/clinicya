'use client'

import { useRef, useState } from 'react'
import Image from 'next/image'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ImageUp, Package } from 'lucide-react'
import Link from 'next/link'
import { AppShell } from '@/components/miniapp/AppShell'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { useLineContext } from '@/components/providers'
import { fetchOrderDetail, promptPayQrSrc, uploadPaymentSlip, type OrderDetailApiResponse } from '@/lib/shop-api'
import { TransferBankInfo } from '@/components/miniapp/TransferBankInfo'
import { useToast } from '@/lib/toast'

function needsSlipUpload(order: NonNullable<OrderDetailApiResponse['order']>) {
  const method = (order.payment_method || '').toLowerCase()
  const pay = (order.payment_status || '').toLowerCase()
  if (method !== 'transfer') return false
  return pay === 'pending' || pay === ''
}

export function OrderDetailClient({ orderId }: { orderId: string }) {
  const line = useLineContext()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const fileRef = useRef<HTMLInputElement>(null)
  const [preview, setPreview] = useState<string | null>(null)

  const q = useQuery({
    queryKey: ['shop-order', orderId],
    queryFn: () => fetchOrderDetail(orderId),
    enabled: Boolean(orderId)
  })

  const order = q.data?.order

  const slipMutation = useMutation({
    mutationFn: (file: File) => uploadPaymentSlip(Number(orderId), file),
    onSuccess: (data) => {
      if (!data.success) {
        toast.error(data.message || 'อัปโหลดไม่สำเร็จ')
        return
      }
      toast.success('อัปโหลดสลิปเรียบร้อย')
      setPreview(null)
      if (fileRef.current) fileRef.current.value = ''
      queryClient.invalidateQueries({ queryKey: ['shop-order', orderId] })
      queryClient.invalidateQueries({ queryKey: ['my-orders'] })
    },
    onError: (e: Error) => {
      toast.error(e.message || 'อัปโหลดไม่สำเร็จ')
    }
  })

  const onPickFile = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) {
      setPreview(null)
      return
    }
    if (file.size > 5 * 1024 * 1024) {
      toast.error('ไฟล์ใหญ่เกิน 5MB')
      e.target.value = ''
      setPreview(null)
      return
    }
    setPreview(URL.createObjectURL(file))
  }

  const submitSlip = () => {
    const file = fileRef.current?.files?.[0]
    if (!file) {
      toast.warning('กรุณาเลือกรูปสลิป')
      return
    }
    slipMutation.mutate(file)
  }

  const showSlip = order && needsSlipUpload(order)

  return (
    <AppShell title="รายละเอียดออเดอร์" subtitle={order?.order_number}>
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      {q.isLoading ? <div className="skeleton h-40 w-full rounded-3xl" /> : null}

      {!q.isLoading && !order ? (
        <p className="text-center text-sm text-slate-500">ไม่พบออเดอร์</p>
      ) : null}

      {order ? (
        <div className="space-y-4">
          <div className="rounded-3xl bg-white p-4 shadow-soft">
            <div className="flex items-center gap-2 text-sm text-slate-600">
              <Package size={16} />
              <span>{order.order_number}</span>
            </div>
            <p className="mt-2 text-xs text-slate-500">
              สถานะคำสั่งซื้อ: {order.status} · การชำระเงิน: {order.payment_status}
            </p>
            {order.payment_method ? (
              <p className="mt-1 text-xs text-slate-500">ช่องทาง: {order.payment_method}</p>
            ) : null}
            <p className="mt-2 text-lg font-bold text-slate-900">
              ฿
              {(order.grand_total ?? 0).toLocaleString(undefined, {
                minimumFractionDigits: 2
              })}
            </p>
          </div>

          {showSlip && q.data?.transfer_info ? (
            <TransferBankInfo info={q.data.transfer_info} />
          ) : null}

          {showSlip ? (
            <div className="rounded-3xl bg-white p-4 shadow-soft">
              <p className="text-sm font-semibold text-slate-900">QR พร้อมเพย์</p>
              <p className="mt-1 text-xs text-slate-500">สแกนจ่ายยอดที่ตรงกับคำสั่งซื้อ</p>
              <div className="mt-3 flex justify-center rounded-2xl bg-slate-50 p-4">
                <Image
                  src={promptPayQrSrc(order.grand_total ?? 0)}
                  alt="PromptPay QR"
                  width={200}
                  height={200}
                  className="h-[200px] w-[200px] object-contain"
                  unoptimized
                />
              </div>
            </div>
          ) : null}

          {showSlip ? (
            <div className="rounded-3xl border border-amber-100 bg-amber-50/80 p-4 shadow-soft">
              <div className="flex items-center gap-2 text-sm font-semibold text-amber-900">
                <ImageUp size={18} />
                แจ้งชำระเงิน (โอนเงิน)
              </div>
              <p className="mt-2 text-xs text-amber-800/90">
                อัปโหลดรูปสลิปการโอน (JPG, PNG, GIF, WebP ไม่เกิน 5MB)
              </p>
              <input
                ref={fileRef}
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                className="mt-3 block w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-line file:px-3 file:py-2 file:text-xs file:font-medium file:text-white"
                onChange={onPickFile}
              />
              {preview ? (
                // eslint-disable-next-line @next/next/no-img-element -- blob preview
                <img src={preview} alt="" className="mt-3 max-h-48 w-full rounded-xl object-contain" />
              ) : null}
              <button
                type="button"
                disabled={slipMutation.isPending}
                onClick={submitSlip}
                className="mt-4 w-full rounded-2xl bg-line py-3 text-sm font-semibold text-white disabled:opacity-60"
              >
                {slipMutation.isPending ? 'กำลังอัปโหลด…' : 'ส่งสลิป'}
              </button>
            </div>
          ) : null}

          {order.items && order.items.length > 0 ? (
            <div className="rounded-3xl bg-white p-4 shadow-soft">
              <p className="text-sm font-semibold text-slate-900">รายการสินค้า</p>
              <ul className="mt-3 divide-y divide-slate-100">
                {order.items.map((it, idx) => (
                  <li key={idx} className="flex justify-between py-2 text-sm">
                    <span className="text-slate-700">{it.product_name}</span>
                    <span className="text-slate-500">
                      x{it.quantity} · ฿{(it.subtotal ?? 0).toLocaleString()}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
          <Link href="/orders" className="block text-center text-sm font-semibold text-line">
            กลับไปรายการออเดอร์
          </Link>
        </div>
      ) : null}
    </AppShell>
  )
}
