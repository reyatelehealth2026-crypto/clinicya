'use client'

import Link from 'next/link'
import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Activity,
  Bot,
  Calendar,
  Check,
  ChevronRight,
  Coins,
  CreditCard,
  Gift,
  Heart,
  LogOut,
  Package,
  Pill,
  Store,
  UserPlus,
  Video,
  X
} from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { MemberCard } from '@/components/miniapp/MemberCard'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { checkMember, getMemberCard } from '@/lib/member-api'
import type { MemberProfile, TierInfo } from '@/types/member'

function LoadingSkeleton() {
  return (
    <div className="space-y-4">
      <div className="skeleton h-48 w-full" />
      <div className="skeleton h-32 w-full" />
    </div>
  )
}

function QuickLink({
  href,
  icon: Icon,
  title,
  description
}: {
  href: string
  icon: typeof Store
  title: string
  description: string
}) {
  return (
    <Link
      href={href}
      className="flex items-center gap-3 rounded-2xl bg-white p-4 shadow-soft transition-colors hover:bg-slate-50"
    >
      <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-line-soft">
        <Icon size={20} className="text-line" />
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold text-slate-900">{title}</p>
        <p className="mt-0.5 text-xs text-slate-500">{description}</p>
      </div>
    </Link>
  )
}

function ProfileMenuRow({
  href,
  onClick,
  icon: Icon,
  title,
  subtitle
}: {
  href?: string
  onClick?: () => void
  icon: typeof Store
  title: string
  subtitle?: string
}) {
  const content = (
    <>
      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-line-soft">
        <Icon size={20} className="text-line" />
      </div>
      <div className="min-w-0 flex-1 text-left">
        <p className="text-sm font-semibold text-slate-900">{title}</p>
        {subtitle ? <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p> : null}
      </div>
      <ChevronRight className="shrink-0 text-slate-300" size={20} aria-hidden />
    </>
  )
  const cls = 'flex w-full items-center gap-3 rounded-2xl bg-white px-4 py-3.5 shadow-soft transition-colors hover:bg-slate-50'
  if (onClick) {
    return <button type="button" onClick={onClick} className={cls}>{content}</button>
  }
  return <Link href={href ?? '#'} className={cls}>{content}</Link>
}

// Bottom-sheet modal แสดงสิทธิประโยชน์ + ข้อมูลบัตรสมาชิกแบบละเอียด
function MemberBenefitsSheet({
  member,
  tier,
  onClose
}: {
  member: MemberProfile
  tier: TierInfo
  onClose: () => void
}) {
  const benefits = [
    { tier: 'bronze', label: 'Bronze', perks: ['สะสมแต้มจากการซื้อ 1฿ = 1 แต้ม', 'รับโปรโมชั่นพิเศษทาง LINE'] },
    { tier: 'silver', label: 'Silver', perks: ['ส่วนลด 5% ทุกออเดอร์', 'แต้มสะสม x1.2', 'ของขวัญวันเกิด'] },
    { tier: 'gold', label: 'Gold', perks: ['ส่วนลด 10% ทุกออเดอร์', 'แต้มสะสม x1.5', 'จัดส่งฟรี', 'ปรึกษาเภสัชกร VIP'] },
    { tier: 'platinum', label: 'Platinum', perks: ['ส่วนลด 15% ทุกออเดอร์', 'แต้มสะสม x2', 'จัดส่งด่วนฟรี', 'เภสัชกรประจำตัว', 'สิทธิ์เข้าร่วมกิจกรรมพิเศษ'] }
  ]
  const currentTier = (tier.tier_code || 'bronze').toLowerCase()
  const isCurrent = (t: string) => t === currentTier

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center" onClick={onClose}>
      <div className="absolute inset-0 bg-black/50" />
      <div
        className="relative w-full max-w-md rounded-t-3xl bg-white p-5 pb-safe-bottom max-h-[85vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <div>
            <h3 className="text-base font-bold text-slate-900">บัตรสมาชิก</h3>
            <p className="mt-0.5 text-xs text-slate-500">สิทธิประโยชน์ตามระดับ</p>
          </div>
          <button onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
            <X size={20} />
          </button>
        </div>

        {/* Member ID card */}
        <div className="gradient-card mb-4 rounded-2xl p-4 text-white">
          <div className="text-xs opacity-80">รหัสสมาชิก</div>
          <div className="mt-1 text-2xl font-bold tabular-nums tracking-wider">{member.member_id || '—'}</div>
          <div className="mt-2 flex items-center justify-between">
            <div className="text-xs opacity-80">{member.display_name || 'LINE User'}</div>
            <div className="rounded-full bg-white/25 px-3 py-1 text-xs font-bold backdrop-blur-sm">{tier.tier_name || 'Bronze'}</div>
          </div>
        </div>

        {/* Tier benefits */}
        <div className="space-y-3">
          {benefits.map((b) => {
            const active = isCurrent(b.tier)
            return (
              <div
                key={b.tier}
                className={`rounded-2xl border p-3.5 ${active ? 'border-line bg-emerald-50' : 'border-slate-100 bg-slate-50'}`}
              >
                <div className="mb-2 flex items-center gap-2">
                  <p className={`text-sm font-bold ${active ? 'text-line' : 'text-slate-700'}`}>{b.label}</p>
                  {active && (
                    <span className="rounded-full bg-line px-2 py-0.5 text-[10px] font-semibold text-white">ระดับของคุณ</span>
                  )}
                </div>
                <ul className="space-y-1">
                  {b.perks.map((p) => {
                    const isVipConsult = /ปรึกษาเภสัชกร VIP|เภสัชกรประจำตัว/.test(p)
                    if (isVipConsult) {
                      return (
                        <li key={p}>
                          <Link
                            href="/video"
                            onClick={onClose}
                            className="flex items-center gap-2 rounded-lg bg-white px-2 py-1.5 text-xs font-medium text-line hover:bg-line-soft transition-colors"
                          >
                            <Check size={12} className="shrink-0 text-line" />
                            <span className="flex-1">{p}</span>
                            <ChevronRight size={14} className="shrink-0 text-line" />
                          </Link>
                        </li>
                      )
                    }
                    return (
                      <li key={p} className="flex items-start gap-2 text-xs text-slate-600">
                        <Check size={12} className="mt-0.5 shrink-0 text-line" />
                        <span>{p}</span>
                      </li>
                    )
                  })}
                </ul>
              </div>
            )
          })}
        </div>

        <button
          type="button"
          onClick={onClose}
          className="mt-5 w-full rounded-xl bg-line py-3 text-sm font-semibold text-white"
        >
          ปิด
        </button>
      </div>
    </div>
  )
}

export function ProfileClient() {
  const line = useLineContext()
  const queryClient = useQueryClient()
  const lineUserId = line.profile?.userId || ''
  const [showBenefits, setShowBenefits] = useState(false)

  const handleLogout = () => {
    // Clear React Query cache first so cached member / order data doesn't
    // bleed into the next signed-in user on the same device.
    try { queryClient.clear() } catch {}
    try {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const liff = (window as any).liff
      if (liff && typeof liff.logout === 'function') {
        liff.logout()
      }
    } catch {
      // not in LIFF context — fall through
    }
    try { sessionStorage.clear() } catch {}
    window.location.href = '/'
  }

  const checkQuery = useQuery({
    queryKey: ['member-check', lineUserId],
    queryFn: () => checkMember(lineUserId, line.profile?.displayName, line.profile?.pictureUrl),
    enabled: Boolean(lineUserId)
  })

  // Wait for checkQuery to confirm the user exists (auto-registers if needed)
  // before calling get_card — otherwise get_card returns user_exists:false on
  // first load for brand-new users and the profile page renders blank.
  const memberQuery = useQuery({
    queryKey: ['member-card', lineUserId, checkQuery.data?.member_id ?? null],
    queryFn: () => getMemberCard(lineUserId),
    enabled: Boolean(lineUserId) && Boolean(checkQuery.data?.exists)
  })

  const member = memberQuery.data?.member
  const tier = memberQuery.data?.tier

  return (
    <AppShell header={<div className="safe-top bg-line" />}>
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      {/* Not logged in to LIFF */}
      {line.isReady && !lineUserId && !line.error ? (
        <div className="rounded-2xl bg-amber-50 p-4 text-sm text-amber-800 shadow-soft">
          <p className="font-semibold">ยังไม่ได้เข้าสู่ระบบ LINE</p>
          <p className="mt-1 text-xs text-amber-700">
            กรุณาเปิดแอปผ่าน LINE หรือเข้าสู่ระบบเพื่อใช้งานโปรไฟล์
          </p>
        </div>
      ) : null}

      {/* Loading state */}
      {lineUserId && (checkQuery.isLoading || (checkQuery.data?.exists && memberQuery.isLoading)) ? (
        <LoadingSkeleton />
      ) : null}

      {/* Check API error */}
      {checkQuery.isError ? (
        <div className="space-y-2 rounded-2xl bg-red-50 p-4 text-sm text-red-700 shadow-soft">
          <p className="font-semibold">ไม่สามารถโหลดข้อมูลสมาชิกได้</p>
          <p className="text-xs">
            {checkQuery.error instanceof Error ? checkQuery.error.message : 'Unknown error'}
          </p>
          <button
            type="button"
            onClick={() => checkQuery.refetch()}
            className="mt-2 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
          >
            ลองใหม่อีกครั้ง
          </button>
        </div>
      ) : null}

      {/* Member API error */}
      {!checkQuery.isError && memberQuery.isError ? (
        <div className="space-y-2 rounded-2xl bg-red-50 p-4 text-sm text-red-700 shadow-soft">
          <p className="font-semibold">ไม่สามารถโหลดบัตรสมาชิกได้</p>
          <p className="text-xs">
            {memberQuery.error instanceof Error ? memberQuery.error.message : 'Unknown error'}
          </p>
          <button
            type="button"
            onClick={() => memberQuery.refetch()}
            className="mt-2 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
          >
            ลองใหม่อีกครั้ง
          </button>
        </div>
      ) : null}

      {/* Not yet registered — prompt registration */}
      {checkQuery.data && !checkQuery.data.exists ? (
        <div className="rounded-2xl bg-gradient-to-br from-brand-50 to-line-soft p-5 shadow-card">
          <div className="flex items-center gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white shadow-soft">
              <UserPlus size={22} className="text-line" />
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-bold text-slate-900">ยังไม่ได้สมัครสมาชิก</p>
              <p className="mt-0.5 text-xs text-slate-600">
                สมัครเพื่อสะสมแต้ม รับโปรโมชันและสิทธิพิเศษ
              </p>
            </div>
          </div>
          <Link
            href="/register"
            className="mt-4 flex w-full items-center justify-center rounded-xl bg-line px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition-colors hover:bg-line/90"
          >
            สมัครสมาชิกตอนนี้
          </Link>
        </div>
      ) : null}

      {/* Loaded successfully but member data still missing (edge case) */}
      {checkQuery.data?.exists &&
      memberQuery.isSuccess &&
      (!member || !tier) ? (
        <div className="rounded-2xl bg-amber-50 p-4 text-sm text-amber-800 shadow-soft">
          <p className="font-semibold">ข้อมูลสมาชิกว่างเปล่า</p>
          <p className="mt-1 text-xs">
            ระบบตอบกลับไม่ครบถ้วน — ลองรีเฟรชหน้าอีกครั้ง หากยังไม่แสดง กรุณาติดต่อผู้ดูแล
          </p>
          <button
            type="button"
            onClick={() => memberQuery.refetch()}
            className="mt-2 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700"
          >
            โหลดใหม่
          </button>
        </div>
      ) : null}

      {member && tier ? (
        <>
          <MemberCard member={member} tier={tier} />

          <div className="space-y-2">
            <p className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-400">สมาชิก</p>
            <ProfileMenuRow
              onClick={() => setShowBenefits(true)}
              icon={CreditCard}
              title="บัตรสมาชิก"
              subtitle="ดูรหัสสมาชิก ระดับ และสิทธิประโยชน์"
            />
            <ProfileMenuRow
              href="/rewards/history"
              icon={Coins}
              title="ประวัติแต้ม"
              subtitle="สะสมและใช้แต้ม"
            />
            <ProfileMenuRow href="/rewards" icon={Gift} title="แลกของรางวัล" subtitle="ของรางวัลและสิทธิพิเศษ" />
            <ProfileMenuRow href="/wishlist" icon={Heart} title="รายการโปรด" subtitle="สินค้าที่บันทึกไว้" />
          </div>

          <div className="space-y-2">
            <p className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-400">สุขภาพและบริการ</p>
            <ProfileMenuRow href="/health" icon={Activity} title="ข้อมูลสุขภาพ" subtitle="โปรไฟล์สุขภาพของคุณ" />
            <ProfileMenuRow
              href="/notifications"
              icon={Pill}
              title="เตือนทานยา"
              subtitle="การแจ้งเตือนและยาที่เกี่ยวข้อง"
            />
            <ProfileMenuRow href="/appointments" icon={Calendar} title="นัดหมาย" subtitle="ตารางนัดและบริการ" />
            <ProfileMenuRow href="/video" icon={Video} title="ปรึกษาเภสัชกร" subtitle="วิดีโอปรึกษา" />
            <ProfileMenuRow href="/ai-chat" icon={Bot} title="ผู้ช่วย AI" subtitle="แชทสอบถามอาการและสินค้า" />
          </div>

          <div className="space-y-2">
            <p className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-400">ช้อปปิ้ง</p>
            <QuickLink href="/shop" icon={Store} title="ร้านค้า" description="เลือกสินค้าและสั่งซื้อ" />
            <QuickLink href="/orders" icon={Package} title="ออเดอร์ของฉัน" description="ติดตามคำสั่งซื้อ" />
          </div>

          {/* Logout */}
          <div className="pt-2">
            <button
              type="button"
              onClick={handleLogout}
              className="flex w-full items-center gap-3 rounded-2xl bg-white px-4 py-3.5 shadow-soft transition-colors hover:bg-red-50"
            >
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-red-50">
                <LogOut size={18} className="text-red-500" />
              </div>
              <span className="flex-1 text-left text-sm font-medium text-red-500">ออกจากระบบ</span>
            </button>
          </div>
        </>
      ) : null}

      {showBenefits && member && tier ? (
        <MemberBenefitsSheet member={member} tier={tier} onClose={() => setShowBenefits(false)} />
      ) : null}
    </AppShell>
  )
}
