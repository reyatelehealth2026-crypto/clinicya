'use client'

import Link from 'next/link'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useRef } from 'react'
import { Gift, MessageCircle, Package, Star, Store, UserRound } from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { BottomNav } from '@/components/miniapp/BottomNav'
import { ServiceMessageBanner } from '@/components/miniapp/ServiceMessageBanner'
import { BannerSlider, BannerSliderSkeleton } from '@/components/miniapp/BannerSlider'
import { HomeSectionRenderer } from '@/components/miniapp/HomeSectionRenderer'
import { getMemberCard } from '@/lib/member-api'
import { getMyOrders } from '@/lib/orders-api'
import { getHomeAll } from '@/lib/miniapp-home-api'
import { usePullToRefresh } from '@/lib/hooks'

function QuickActionIcon({ href, icon: Icon, label, color }: {
  href: string
  icon: typeof Gift
  label: string
  color: string
}) {
  return (
    <Link href={href} className="flex flex-col items-center gap-1.5 py-1">
      <div className={`flex h-12 w-12 items-center justify-center rounded-2xl ${color} transition-transform active:scale-95`}>
        <Icon size={22} />
      </div>
      <span className="text-[11px] font-medium text-slate-600 leading-tight text-center">{label}</span>
    </Link>
  )
}

export function HomeClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const displayName = line.profile?.displayName || 'LINE User'
  const avatar = line.profile?.pictureUrl
  const queryClient = useQueryClient()
  const mainRef = useRef<HTMLElement>(null)

  const memberQuery = useQuery({
    queryKey: ['member-card', lineUserId],
    queryFn: () => getMemberCard(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const ordersQuery = useQuery({
    queryKey: ['my-orders-summary', lineUserId],
    queryFn: () => getMyOrders(lineUserId, 3),
    enabled: Boolean(lineUserId)
  })

  const member = memberQuery.data?.member
  const tier = memberQuery.data?.tier
  const recentOrders = ordersQuery.data?.orders || []

  const homeQuery = useQuery({
    queryKey: ['home-all'],
    queryFn: getHomeAll,
    staleTime: 60_000,
  })

  const banners = homeQuery.data?.data?.banners || []
  const sections = homeQuery.data?.data?.sections || []

  const handleRefresh = useCallback(async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['member-card', lineUserId] }),
      queryClient.invalidateQueries({ queryKey: ['my-orders-summary', lineUserId] }),
      queryClient.invalidateQueries({ queryKey: ['home-all'] }),
    ])
  }, [queryClient, lineUserId])

  const { isRefreshing, pullY } = usePullToRefresh(handleRefresh, mainRef)

  return (
    <div className="fixed inset-0 flex flex-col bg-[#f5f5f5]">
      {/* Compact Header — Makro Pro style */}
      <header className="shrink-0 bg-white safe-top">
        <div className="mx-auto max-w-md">
          <div className="flex items-center gap-3 px-4 py-3">
            {avatar ? (
              <img src={avatar} alt="" className="h-10 w-10 rounded-full border-2 border-slate-100 object-cover" />
            ) : (
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-line text-sm font-bold text-white">
                {displayName.charAt(0)}
              </div>
            )}
            <div className="min-w-0 flex-1">
              <p className="text-sm font-bold text-slate-900 truncate">{displayName}</p>
              {member && tier ? (
                <div className="flex items-center gap-2 mt-0.5">
                  <span className="inline-flex items-center gap-1 text-xs text-line font-semibold">
                    <Star size={11} />
                    {tier.tier_name}
                  </span>
                  <span className="text-xs text-slate-400">•</span>
                  <span className="text-xs font-bold text-slate-700 tabular-nums">{member.points.toLocaleString()} แต้ม</span>
                </div>
              ) : null}
            </div>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <main ref={mainRef} className="relative flex-1 overflow-y-auto overscroll-none">
        {/* Pull-to-refresh indicator */}
        <div
          className="flex items-end justify-center overflow-hidden transition-all duration-200"
          style={{ height: isRefreshing ? 44 : Math.min(pullY, 44) }}
        >
          <div className="flex items-center gap-2 pb-2 text-xs font-medium text-slate-400">
            <div className={`h-3.5 w-3.5 rounded-full border-2 border-line border-t-transparent ${isRefreshing ? 'animate-spin' : ''}`} />
            <span>{isRefreshing ? 'กำลังโหลด...' : pullY >= 70 ? 'ปล่อยเพื่อรีเฟรช' : ''}</span>
          </div>
        </div>

        <div className="mx-auto w-full max-w-md">
          {/* Banner Slider — full bleed */}
          <div className="px-3">
            {homeQuery.isLoading ? (
              <BannerSliderSkeleton />
            ) : (
              <BannerSlider banners={banners} />
            )}
          </div>

          {/* Quick Actions — Makro icon grid style */}
          <div className="mt-3 bg-white rounded-2xl mx-3 px-2 py-3">
            <div className="grid grid-cols-4 gap-1">
              <QuickActionIcon
                href="/profile"
                icon={UserRound}
                label="โปรไฟล์"
                color="bg-blue-50 text-blue-600"
              />
              <QuickActionIcon
                href="/rewards"
                icon={Gift}
                label="แลกแต้ม"
                color="bg-amber-50 text-amber-600"
              />
              <QuickActionIcon
                href="/orders"
                icon={Package}
                label="ออเดอร์"
                color="bg-purple-50 text-purple-600"
              />
              <QuickActionIcon
                href="/shop"
                icon={Store}
                label="ร้านค้า"
                color="bg-emerald-50 text-emerald-600"
              />
            </div>
            <Link
              href="/ai-chat"
              className="mt-3 flex w-full items-center justify-center gap-2 rounded-2xl bg-line-soft py-2.5 text-sm font-semibold text-line transition-colors hover:bg-line/10"
            >
              <MessageCircle size={18} />
              แชท AI เภสัชกร
            </Link>
          </div>

          {/* Notification Banner */}
          <div className="px-3 mt-3">
            <ServiceMessageBanner />
          </div>

          {/* Dynamic Sections — Makro Pro product feed style */}
          <div className="mt-3 flex flex-col gap-3">
            {sections.map((section) => (
              <HomeSectionRenderer key={section.id} section={section} />
            ))}
          </div>

          {/* Recent Orders — compact */}
          {recentOrders.length > 0 && (
            <div className="mx-3 mt-3 mb-4 bg-white rounded-2xl overflow-hidden">
              <div className="flex items-center justify-between px-4 pt-3 pb-2">
                <p className="text-sm font-bold text-slate-900">ออเดอร์ล่าสุด</p>
                <Link href="/orders" className="text-xs font-semibold text-line">ดูทั้งหมด</Link>
              </div>
              <div className="divide-y divide-slate-50">
                {recentOrders.map((order) => (
                  <Link
                    key={order.id}
                    href="/orders"
                    className="flex items-center justify-between px-4 py-3 transition-colors hover:bg-slate-50"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-slate-900">{order.order_name}</p>
                      <p className="mt-0.5 text-xs text-slate-500">
                        {order.date_order ? new Date(order.date_order).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' }) : ''}
                        {order.amount_total != null ? ` — ฿${order.amount_total.toLocaleString()}` : ''}
                      </p>
                    </div>
                    <OrderStateBadge state={order.state} isPaid={order.is_paid} isDelivered={order.is_delivered} />
                  </Link>
                ))}
              </div>
            </div>
          )}

          {/* Bottom spacing for nav */}
          <div className="h-4" />
        </div>
      </main>

      <BottomNav />
    </div>
  )
}

function OrderStateBadge({ state, isPaid, isDelivered }: { state: string | null; isPaid: boolean; isDelivered: boolean }) {
  if (isDelivered) return <span className="badge badge-green">จัดส่งแล้ว</span>
  if (isPaid) return <span className="badge badge-blue">ชำระแล้ว</span>
  switch (state) {
    case 'sale':
    case 'done':
      return <span className="badge badge-green">ยืนยันแล้ว</span>
    case 'draft':
    case 'sent':
      return <span className="badge badge-amber">รอดำเนินการ</span>
    case 'cancel':
      return <span className="badge badge-red">ยกเลิก</span>
    default:
      return <span className="badge badge-slate">{state || 'ไม่ทราบ'}</span>
  }
}
