'use client'

import { useEffect, useState } from 'react'
import Image from 'next/image'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Banknote, CheckCircle2, CreditCard, Loader2, MapPin, Tag } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import {
  createShopOrder,
  fetchCart,
  fetchLastAddress,
  fetchPaymentInfo,
  formatThb,
  promptPayQrSrc,
  validatePromo,
  type LastAddress
} from '@/lib/shop-api'
import { TransferBankInfo } from '@/components/miniapp/TransferBankInfo'
import { useToast } from '@/lib/toast'

type PaymentMethod = 'transfer' | 'cod'

/** Match `checkout.php` free-shipping vs flat rate when promo lowers subtotal. */
function estimateShipping(
  cart: { subtotal?: number; shipping_fee?: number; free_shipping_min?: number } | undefined,
  netSubtotal: number
): number {
  if (!cart) return 0
  const freeMin = cart.free_shipping_min ?? 500
  if (netSubtotal >= freeMin) return 0
  const raw = cart.subtotal ?? 0
  if (raw < freeMin) return cart.shipping_fee ?? 50
  return 50
}

export function CheckoutClient() {
  const line = useLineContext()
  const { toast } = useToast()
  const router = useRouter()
  const queryClient = useQueryClient()
  const lineUserId = line.profile?.userId || ''

  const [payment, setPayment] = useState<PaymentMethod>('transfer')
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [address, setAddress] = useState('')
  const [promoInput, setPromoInput] = useState('')
  const [appliedPromo, setAppliedPromo] = useState<{ code: string; discount: number } | null>(null)
  const [successOrderId, setSuccessOrderId] = useState<number | null>(null)

  const cartQuery = useQuery({
    queryKey: ['shop-cart', lineUserId],
    queryFn: () => fetchCart(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const lastAddrQuery = useQuery({
    queryKey: ['last-address', lineUserId],
    queryFn: () => fetchLastAddress(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const paymentInfoQuery = useQuery({
    queryKey: ['payment-info', payment],
    queryFn: () => fetchPaymentInfo(),
    enabled: Boolean(lineUserId) && payment === 'transfer'
  })

  useEffect(() => {
    const a = lastAddrQuery.data
    if (!a) return
    if (a.name) setName(a.name)
    if (a.phone) setPhone(a.phone)
    if (a.address) setAddress(a.address)
    else if (a.subdistrict || a.district || a.province) {
      setAddress([a.address, a.subdistrict, a.district, a.province, a.postcode].filter(Boolean).join(' '))
    }
  }, [lastAddrQuery.data])

  const items = cartQuery.data?.items ?? []
  const subtotal = cartQuery.data?.subtotal ?? 0
  const discount = appliedPromo?.discount ?? 0
  const netSubtotal = Math.max(0, subtotal - discount)
  const shippingFee = estimateShipping(cartQuery.data, netSubtotal)
  const total = netSubtotal + shippingFee

  useEffect(() => {
    if (!lineUserId || cartQuery.isLoading) return
    // Only redirect away if we haven't just completed an order
    if (items.length === 0 && !createMutation.isSuccess) {
      router.replace('/cart')
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lineUserId, items.length, cartQuery.isLoading, router])

  const promoMutation = useMutation({
    mutationFn: async () => {
      const code = promoInput.trim()
      if (!code) {
        throw new Error('กรุณากรอกโค้ด')
      }
      return validatePromo(code, lineUserId, subtotal)
    },
    onSuccess: (data) => {
      if (!data.success || !data.valid || data.discount == null || data.discount <= 0) {
        toast.error(data.message || 'โค้ดไม่ถูกต้อง')
        setAppliedPromo(null)
        return
      }
      setAppliedPromo({ code: promoInput.trim().toUpperCase(), discount: data.discount })
      toast.success(data.message || 'ใช้โค้ดสำเร็จ')
    },
    onError: (e: Error) => {
      toast.error(e.message || 'ตรวจสอบโค้ดไม่สำเร็จ')
    }
  })

  const createMutation = useMutation({
    mutationFn: async () => {
      const addr: LastAddress = { name, phone, address }
      return createShopOrder({
        lineUserId,
        paymentMethod: payment,
        address: addr,
        ...(appliedPromo ? { subtotal: netSubtotal } : {})
      })
    },
    onSuccess: (data) => {
      if (!data.success) {
        toast.error(data.message || 'สั่งซื้อไม่สำเร็จ')
        return
      }
      queryClient.invalidateQueries({ queryKey: ['shop-cart', lineUserId] })
      queryClient.invalidateQueries({ queryKey: ['my-orders', lineUserId] })
      setSuccessOrderId(data.order_id ?? null)
    },
    onError: (e: Error) => {
      toast.error(e.message || 'เกิดข้อผิดพลาด')
    }
  })

  const handleSubmit = () => {
    if (!name?.trim() || !phone?.trim() || !address?.trim()) {
      toast.warning('กรุณากรอกข้อมูลจัดส่งให้ครบ')
      return
    }
    createMutation.mutate()
  }

  const inputClass =
    'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-line/30'

  if (!lineUserId) {
    return (
      <AppShell title="ชำระเงิน" subtitle="ดำเนินการสั่งซื้อ">
        <VerifiedOnlyNotice title="ต้องเข้าสู่ระบบ LINE" description="เปิดจาก LINE เพื่อสั่งซื้อ" />
      </AppShell>
    )
  }

  if (cartQuery.isLoading) {
    return (
      <AppShell title="ชำระเงิน" subtitle="ดำเนินการสั่งซื้อ">
        <div className="space-y-3">
          <div className="skeleton h-40 w-full rounded-3xl" />
          <div className="skeleton h-32 w-full rounded-3xl" />
        </div>
      </AppShell>
    )
  }

  if (items.length === 0 && !createMutation.isSuccess) {
    return null
  }

  // Success screen — rendered after order is placed
  if (successOrderId !== null || createMutation.isSuccess) {
    return (
      <AppShell title="สั่งซื้อสำเร็จ" subtitle="">
        <div className="flex flex-col items-center gap-4 rounded-3xl bg-white p-8 text-center shadow-soft">
          {/* Big green checkmark */}
          <div className="flex h-20 w-20 items-center justify-center rounded-full bg-emerald-50">
            <CheckCircle2 size={40} className="text-emerald-500" />
          </div>
          <div>
            <h2 className="text-xl font-bold text-slate-900">สั่งซื้อสำเร็จ!</h2>
            {successOrderId ? (
              <p className="mt-1 text-sm text-slate-500">หมายเลขออเดอร์ #{successOrderId}</p>
            ) : (
              <p className="mt-1 text-sm text-slate-500">ระบบรับออเดอร์แล้ว</p>
            )}
          </div>

          {/* Next steps */}
          <div className="w-full rounded-2xl bg-slate-50 p-4 text-left text-sm text-slate-600 space-y-2">
            <p className="font-semibold text-slate-800">ขั้นตอนถัดไป</p>
            {payment === 'transfer' ? (
              <>
                <p>1. โอนเงินตามบัญชีด้านล่าง หรือผ่าน PromptPay</p>
                <p>2. อัปโหลดสลิปในหน้าออเดอร์</p>
                <p>3. ร้านจะยืนยันและจัดส่งสินค้า</p>
              </>
            ) : (
              <>
                <p>1. ร้านกำลังเตรียมออเดอร์</p>
                <p>2. ชำระเงินเมื่อได้รับสินค้า (COD)</p>
                <p>3. ติดตามสถานะในหน้าออเดอร์</p>
              </>
            )}
          </div>

          {/* Action buttons */}
          <div className="flex w-full flex-col gap-2">
            {successOrderId ? (
              <Link
                href={`/order/${successOrderId}`}
                className="flex w-full items-center justify-center rounded-2xl bg-line py-3 text-sm font-semibold text-white"
              >
                ดูรายละเอียดออเดอร์
              </Link>
            ) : null}
            <Link
              href="/orders"
              className="flex w-full items-center justify-center rounded-2xl border border-slate-200 py-3 text-sm font-semibold text-slate-700"
            >
              ดูออเดอร์ทั้งหมด
            </Link>
            <Link
              href="/shop"
              className="text-center text-sm text-slate-500 underline decoration-slate-300"
            >
              กลับร้านค้า
            </Link>
          </div>
        </div>
      </AppShell>
    )
  }

  return (
    <AppShell title="ชำระเงิน" subtitle="ยืนยันที่อยู่และช่องทางชำระเงิน">
      <div className="space-y-4">
        <section className="rounded-3xl bg-white p-4 shadow-soft">
          <div className="mb-3 flex items-center gap-2">
            <MapPin size={18} className="text-line" />
            <h3 className="text-sm font-semibold text-slate-900">ที่อยู่จัดส่ง</h3>
          </div>
          <div className="space-y-2">
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="ชื่อผู้รับ"
              className={inputClass}
            />
            <input
              type="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              placeholder="เบอร์โทร"
              className={inputClass}
            />
            <textarea
              value={address}
              onChange={(e) => setAddress(e.target.value)}
              placeholder="ที่อยู่เต็ม"
              rows={3}
              className={`${inputClass} resize-none`}
            />
          </div>
        </section>

        <section className="rounded-3xl bg-white p-4 shadow-soft">
          <h3 className="mb-3 text-sm font-semibold text-slate-900">ช่องทางชำระเงิน</h3>
          <div className="space-y-2">
            {[
              { key: 'transfer' as const, icon: CreditCard, label: 'โอนเงิน / พร้อมเพย์' },
              { key: 'cod' as const, icon: Banknote, label: 'ชำระปลายทาง (COD)' }
            ].map((m) => (
              <button
                key={m.key}
                type="button"
                onClick={() => setPayment(m.key)}
                className={`flex w-full items-center gap-3 rounded-2xl border p-3 transition-colors ${
                  payment === m.key ? 'border-line bg-line-soft' : 'border-slate-200'
                }`}
              >
                <m.icon size={20} className={payment === m.key ? 'text-line' : 'text-slate-400'} />
                <span className={`text-sm ${payment === m.key ? 'font-medium text-line' : 'text-slate-600'}`}>
                  {m.label}
                </span>
              </button>
            ))}
          </div>
        </section>

        {payment === 'transfer' ? (
          <section className="rounded-3xl bg-white p-4 shadow-soft">
            <h3 className="mb-2 text-sm font-semibold text-slate-900">ชำระด้วยการโอน</h3>
            <TransferBankInfo info={paymentInfoQuery.data?.transfer_info} className="mb-4" />
            <h3 className="mb-2 text-sm font-semibold text-slate-900">QR พร้อมเพย์</h3>
            <p className="mb-3 text-xs text-slate-500">
              สแกนจ่ายยอด {formatThb(total)} (ตั้งค่าหมายเลขใน Admin / shop_settings.promptpay_number)
            </p>
            <div className="flex justify-center rounded-2xl bg-slate-50 p-4">
              <Image
                src={promptPayQrSrc(total)}
                alt="PromptPay QR"
                width={200}
                height={200}
                className="h-[200px] w-[200px] object-contain"
                unoptimized
              />
            </div>
          </section>
        ) : null}

        <section className="rounded-3xl bg-white p-4 shadow-soft">
          <div className="mb-3 flex items-center gap-2">
            <Tag size={18} className="text-line" />
            <h3 className="text-sm font-semibold text-slate-900">โค้ดส่วนลด (ถ้ามี)</h3>
          </div>
          <div className="flex gap-2">
            <input
              type="text"
              value={promoInput}
              onChange={(e) => setPromoInput(e.target.value)}
              placeholder="เช่น WELCOME10"
              className={inputClass}
            />
            <button
              type="button"
              disabled={promoMutation.isPending}
              onClick={() => promoMutation.mutate()}
              className="shrink-0 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
            >
              ใช้โค้ด
            </button>
          </div>
          {appliedPromo ? (
            <p className="mt-2 text-xs font-medium text-emerald-700">
              ใช้ {appliedPromo.code} แล้ว — ลด {formatThb(appliedPromo.discount)}
            </p>
          ) : null}
        </section>

        <section className="rounded-3xl bg-white p-4 shadow-soft">
          <h3 className="mb-3 text-sm font-semibold text-slate-900">สรุปคำสั่งซื้อ</h3>
          <div className="space-y-1.5 text-sm">
            <div className="flex justify-between text-slate-600">
              <span>ค่าสินค้า ({items.length} รายการ)</span>
              <span className="font-medium text-slate-900">{formatThb(subtotal)}</span>
            </div>
            {appliedPromo ? (
              <div className="flex justify-between text-emerald-700">
                <span>ส่วนลด ({appliedPromo.code})</span>
                <span className="font-medium">−{formatThb(appliedPromo.discount)}</span>
              </div>
            ) : null}
            <div className="flex justify-between text-slate-600">
              <span>ค่าจัดส่ง</span>
              <span className={shippingFee === 0 ? 'font-medium text-emerald-600' : 'font-medium text-slate-900'}>
                {shippingFee === 0 ? 'ฟรี' : formatThb(shippingFee)}
              </span>
            </div>
            <div className="flex justify-between border-t border-slate-100 pt-3 text-base font-bold text-slate-900">
              <span>รวมทั้งหมด</span>
              <span className="text-lg text-line">{formatThb(total)}</span>
            </div>
          </div>
        </section>

        <Link href="/cart" className="block text-center text-sm text-slate-500 underline">
          แก้ไขตะกร้า
        </Link>

        <button
          type="button"
          disabled={createMutation.isPending}
          onClick={handleSubmit}
          className="mt-2 flex w-full items-center justify-center gap-2 rounded-2xl bg-line py-3.5 text-sm font-semibold text-white transition-opacity disabled:opacity-60"
        >
          {createMutation.isPending ? (
            <>
              <Loader2 size={16} className="animate-spin" />
              กำลังส่งคำสั่งซื้อ…
            </>
          ) : (
            `ยืนยันสั่งซื้อ ${formatThb(total)}`
          )}
        </button>
      </div>
    </AppShell>
  )
}
