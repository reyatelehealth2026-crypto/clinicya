'use client'

import Link from 'next/link'
import { useQuery } from '@tanstack/react-query'
import {
  Activity,
  Bell,
  Bot,
  Calendar,
  ChevronRight,
  Coins,
  CreditCard,
  Gift,
  Heart,
  LogOut,
  Package,
  Pill,
  Star,
  Stethoscope,
  Store,
  UserPlus,
  Video
} from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { MemberCard } from '@/components/miniapp/MemberCard'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { checkMember, getMemberCard } from '@/lib/member-api'

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
  icon: Icon,
  title,
  subtitle
}: {
  href: string
  icon: typeof Store
  title: string
  subtitle?: string
}) {
  return (
    <Link
      href={href}
      className="flex items-center gap-3 rounded-2xl bg-white px-4 py-3.5 shadow-soft transition-colors hover:bg-slate-50"
    >
      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-line-soft">
        <Icon size={20} className="text-line" />
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold text-slate-900">{title}</p>
        {subtitle ? <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p> : null}
      </div>
      <ChevronRight className="shrink-0 text-slate-300" size={20} aria-hidden />
    </Link>
  )
}

function handleLogout() {
  try {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const liff = (window as any).liff
    if (liff && typeof liff.logout === 'function') {
      liff.logout()
    }
  } catch {
    // not in LIFF context — fall through
  }
  try {
    sessionStorage.clear()
  } catch {
    // ignore
  }
  window.location.href = '/'
}

export function ProfileClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const displayName = line.profile?.displayName || ''
  const pictureUrl = line.profile?.pictureUrl || null
  const avatarFallback = displayName ? displayName.charAt(0).toUpperCase() : '?'

  const checkQuery = useQuery({
    queryKey: ['member-check', lineUserId],
    queryFn: () => checkMember(lineUserId, line.profile?.displayName, line.profile?.pictureUrl),
    enabled: Boolean(lineUserId)
  })

  const memberQuery = useQuery({
    queryKey: ['member-card', lineUserId],
    queryFn: () => getMemberCard(lineUserId),
    enabled: Boolean(lineUserId)
  })

  const member = memberQuery.data?.member
  const tier = memberQuery.data?.tier

  return (
    <AppShell title="โปรไฟล์" subtitle="สมาชิกและบริการ">
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      {/* Gradient hero header */}
      {lineUserId ? (
        <div className="-mx-4 -mt-5 mb-2 gradient-card px-4 pb-14 pt-6 text-white">
          <div className="flex flex-col items-center gap-3">
            {/* Avatar */}
            {pictureUrl ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={pictureUrl}
                alt={displayName}
                className="h-16 w-16 rounded-full border-2 border-white/30 object-cover shadow-lg ring-2 ring-white/20"
              />
            ) : (
              <div className="flex h-16 w-16 items-center justify-center rounded-full border-2 border-white/30 bg-white/20 text-xl font-bold shadow-lg ring-2 ring-white/20">
                {avatarFallback}
              </div>
            )}
            {/* Name */}
            <div className="text-center">
              <p className="text-base font-bold">{displayName || 'LINE User'}</p>
              {member?.phone ? (
                <p className="mt-0.5 text-xs text-white/60">{member.phone}</p>
              ) : member?.email ? (
                <p className="mt-0.5 text-xs text-white/60">{member.email}</p>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}

      {!lineUserId || memberQuery.isLoading ? <LoadingSkeleton /> : null}

      {checkQuery.data && (!checkQuery.data.is_registered || !checkQuery.data.has_profile) ? (
        <div className="space-y-2">
          <QuickLink
            href="/register"
            icon={UserPlus}
            title="สมัครสมาชิก / กรอกข้อมูล"
            description="สะสมแต้มและรับสิทธิพิเศษ — หรือข้ามไปช้อปที่ร้านค้า"
          />
        </div>
      ) : null}

      {member && tier ? (
        <>
          {/* Stats card */}
          <div className="-mt-8 rounded-2xl bg-white p-4 shadow-card">
            <div className="grid grid-cols-3 divide-x divide-slate-100">
              {/* Points */}
              <div className="flex flex-col items-center gap-1 px-2">
                <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-50">
                  <Coins size={16} className="text-emerald-600" />
                </div>
                <p className="text-sm font-bold tabular-nums text-slate-900">
                  {member.points.toLocaleString()}
                </p>
                <p className="text-[10px] text-slate-400">คะแนน</p>
              </div>
              {/* Tier */}
              <div className="flex flex-col items-center gap-1 px-2">
                <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-50">
                  <Star size={16} className="text-violet-600" />
                </div>
                <p className="text-sm font-bold text-slate-900">{tier.tier_name || 'Bronze'}</p>
                <p className="text-[10px] text-slate-400">ระดับ</p>
              </div>
              {/* Orders */}
              <Link href="/orders" className="flex flex-col items-center gap-1 px-2">
                <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-50">
                  <Package size={16} className="text-blue-600" />
                </div>
                <p className="text-sm font-bold tabular-nums text-slate-900">
                  {(member.total_orders ?? 0).toLocaleString()}
                </p>
                <p className="text-[10px] text-slate-400">ออเดอร์</p>
              </Link>
            </div>
          </div>

          <MemberCard member={member} tier={tier} />

          <div className="space-y-2">
            <p className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-400">สมาชิก</p>
            <ProfileMenuRow
              href="/profile"
              icon={CreditCard}
              title="บัตรสมาชิก"
              subtitle="ข้อมูลระดับและสิทธิประโยชน์"
            />
            <ProfileMenuRow
              href="/rewards/history"
              icon={Coins}
              title="ประวัติแต้ม"
              subtitle="สะสมและใช้แต้ม"
            />
            <ProfileMenuRow href="/rewards" icon={Gift} title="แลกของรางวัล" subtitle="ของรางวัลและสิทธิพิเศษ" />
            <ProfileMenuRow
              href="/rewards"
              icon={Bell}
              title="คูปองของฉัน"
              subtitle="โค้ดส่วนลด — กรอกตอนชำระเงิน"
            />
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
            <ProfileMenuRow
              href="/ai-chat"
              icon={Stethoscope}
              title="ประเมินอาการ"
              subtitle="สอบถามอาการเบื้องต้น"
            />
            <ProfileMenuRow href="/ai-chat" icon={Bot} title="ผู้ช่วย AI" subtitle="แชทสอบถามสินค้าและสุขภาพ" />
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
    </AppShell>
  )
}
