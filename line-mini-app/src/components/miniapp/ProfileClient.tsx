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
  Package,
  Pill,
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

export function ProfileClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''

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

  return (
    <AppShell title="โปรไฟล์" subtitle="สมาชิกและบริการ">
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

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

      {memberQuery.data?.member && memberQuery.data?.tier ? (
        <>
          <MemberCard member={memberQuery.data.member} tier={memberQuery.data.tier} />

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
        </>
      ) : null}
    </AppShell>
  )
}
