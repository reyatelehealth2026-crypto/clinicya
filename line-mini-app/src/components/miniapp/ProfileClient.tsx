'use client'

import Link from 'next/link'
import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Activity,
  Bot,
  Calendar,
  ChevronRight,
  Coins,
  Gift,
  Heart,
  LogOut,
  MapPin,
  Package,
  Pill,
  ShieldCheck,
  Store,
  UserPlus,
  Video
} from 'lucide-react'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { AddressesSheet } from '@/components/miniapp/AddressesSheet'
import { FlipMemberCard } from '@/components/miniapp/FlipMemberCard'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { checkMember, getMemberCard } from '@/lib/member-api'
import { getHealthProfile } from '@/lib/health-api'

function LoadingSkeleton() {
  return (
    <div className="space-y-4">
      <div className="skeleton h-48 w-full" />
      <div className="skeleton h-32 w-full" />
    </div>
  )
}

/**
 * MenuSection — Shopee/7-Eleven Pre/Lazada style: one rounded card per group,
 * rows separated by hairline dividers (not separate cards floating in space).
 */
function MenuSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <p className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</p>
      <div className="overflow-hidden rounded-2xl bg-white shadow-soft divide-y divide-slate-100">
        {children}
      </div>
    </div>
  )
}

/**
 * MenuRow — single clean line: icon + title + optional right hint + chevron.
 * No subtitle (those just repeated the title in the old design). When extra
 * context matters, surface it as `rightHint` (count, percent, status).
 */
function MenuRow({
  href,
  onClick,
  icon: Icon,
  title,
  rightHint
}: {
  href?: string
  onClick?: () => void
  icon: typeof Store
  title: string
  rightHint?: string
}) {
  const content = (
    <>
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-line-soft">
        <Icon size={18} className="text-line" />
      </div>
      <span className="min-w-0 flex-1 truncate text-left text-sm font-medium text-slate-900">{title}</span>
      {rightHint ? (
        <span className="shrink-0 text-xs font-medium text-slate-400">{rightHint}</span>
      ) : null}
      <ChevronRight className="shrink-0 text-slate-300" size={18} aria-hidden />
    </>
  )
  const cls = 'flex w-full items-center gap-3 bg-white px-4 py-3 transition-colors hover:bg-slate-50 active:bg-slate-100'
  if (onClick) {
    return <button type="button" onClick={onClick} className={cls}>{content}</button>
  }
  return <Link href={href ?? '#'} className={cls}>{content}</Link>
}

// (MemberBenefitsSheet was removed 2026-05-23 — the "บัตรสมาชิก" modal is now
// an address-book editor. See AddressesSheet.tsx.)

export function ProfileClient() {
  const line = useLineContext()
  const queryClient = useQueryClient()
  const lineUserId = line.profile?.userId || ''
  const [showAddresses, setShowAddresses] = useState(false)

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

  // Health profile feeds the front of the FlipMemberCard (name, age, blood
  // type, height/weight, conditions, allergies). Wait for the same gate as
  // memberQuery so we don't call the API for unregistered users.
  const healthQuery = useQuery({
    queryKey: ['health-profile', lineUserId],
    queryFn: () => getHealthProfile(lineUserId),
    enabled: Boolean(lineUserId) && Boolean(checkQuery.data?.exists)
  })

  const member = memberQuery.data?.member
  const tier = memberQuery.data?.tier
  const healthProfile = healthQuery.data?.profile ?? null

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
          <FlipMemberCard member={member} tier={tier} health={healthProfile} />

          <MenuSection title="สมาชิก">
            <MenuRow onClick={() => setShowAddresses(true)} icon={MapPin} title="ที่อยู่จัดส่ง" />
            <MenuRow href="/rewards/history" icon={Coins} title="ประวัติแต้ม" rightHint={`${member.points.toLocaleString()} pt`} />
            <MenuRow href="/rewards" icon={Gift} title="แลกของรางวัล" />
            <MenuRow href="/wishlist" icon={Heart} title="รายการโปรด" />
          </MenuSection>

          <MenuSection title="สุขภาพและบริการ">
            <MenuRow
              href="/health"
              icon={Activity}
              title="ข้อมูลสุขภาพ"
              rightHint={healthProfile ? `${healthProfile.completion_percent ?? 0}%` : undefined}
            />
            <MenuRow href="/notifications" icon={Pill} title="เตือนทานยา" />
            <MenuRow href="/appointments" icon={Calendar} title="นัดหมาย" />
            <MenuRow href="/video" icon={Video} title="ปรึกษาเภสัชกร" />
            <MenuRow href="/ai-chat" icon={Bot} title="ผู้ช่วย AI" />
          </MenuSection>

          <MenuSection title="ช้อปปิ้ง">
            <MenuRow href="/shop" icon={Store} title="ร้านค้า" />
            <MenuRow href="/orders" icon={Package} title="ออเดอร์ของฉัน" />
          </MenuSection>

          <MenuSection title="ความเป็นส่วนตัวและข้อมูล">
            <MenuRow href="/privacy" icon={ShieldCheck} title="สิทธิของฉัน (PDPA)" />
          </MenuSection>

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

      {showAddresses && lineUserId ? (
        <AddressesSheet lineUserId={lineUserId} onClose={() => setShowAddresses(false)} />
      ) : null}
    </AppShell>
  )
}
