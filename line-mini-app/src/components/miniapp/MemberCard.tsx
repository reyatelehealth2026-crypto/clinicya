import { Crown, Package, Star, TrendingUp } from 'lucide-react'
import type { MemberProfile, TierInfo } from '@/types/member'

const LABEL_MAX_TIER = 'ระดับสูงสุด'
const LABEL_POINTS = 'แต้มสะสม'
const LABEL_ORDERS = 'ออร์เดอร์'
const LABEL_TO = 'ไปยัง'
const LABEL_REMAINING = 'เหลืออีก'
const LABEL_POINT_UNIT = 'แต้ม'
const LABEL_UPGRADE_TO = 'เพื่อเลื่อนเป็น'
const LABEL_TOP_LEVEL = 'คุณอยู่ในระดับสูงสุดแล้ว'

export function MemberCard({ member, tier }: { member: MemberProfile; tier: TierInfo }) {
  const displayName = member.display_name || [member.first_name, member.last_name].filter(Boolean).join(' ') || 'LINE User'
  const progress = Math.min(Math.max(tier.progress_percent || 0, 0), 100)
  const nextTier = tier.next_tier_name || LABEL_MAX_TIER
  const fallbackUrl = 'https://placehold.co/96x96/187162/ffffff?text=' + encodeURIComponent(displayName.charAt(0))

  return (
    <section className="animate-fade-in overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-brand-300 via-line to-brand-800 p-[1px] shadow-card">
      <div className="relative overflow-hidden rounded-[1.65rem] bg-gradient-to-br from-[#0b5f50] via-[#187162] to-[#082d28] p-4 text-white">
        <div className="absolute -right-10 -top-12 h-32 w-32 rounded-full bg-white/12 blur-sm" aria-hidden />
        <div className="absolute -bottom-14 left-8 h-28 w-28 rounded-full bg-line-muted/20 blur-md" aria-hidden />
        <div className="relative flex items-start justify-between gap-3">
          <div>
            <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-white/60">REYA Member</p>
            <div className="mt-1 inline-flex items-center gap-1.5 rounded-full bg-white/14 px-2.5 py-1 text-[10px] font-bold text-white backdrop-blur-sm ring-1 ring-white/15">
              <Crown size={12} aria-hidden />
              {tier.tier_name || 'Bronze'}
            </div>
          </div>
          <div className="flex h-9 w-9 items-center justify-center rounded-2xl bg-white/14 ring-1 ring-white/15">
            <Star size={16} className="text-white" aria-hidden />
          </div>
        </div>

        <div className="relative mt-4 flex items-center gap-3">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={member.picture_url || fallbackUrl}
            alt={displayName}
            className="h-[52px] w-[52px] rounded-2xl border border-white/30 object-cover shadow-md"
          />
          <div className="min-w-0 flex-1">
            <h2 className="truncate text-lg font-extrabold leading-tight">{displayName}</h2>
            <p className="mt-1 truncate font-mono text-[10px] tracking-wider text-white/62">ID {member.member_id}</p>
          </div>
        </div>

        <div className="relative mt-4 grid grid-cols-[1.2fr_0.8fr] gap-2">
          <div className="rounded-2xl bg-white/14 p-3 ring-1 ring-white/15">
            <p className="text-[10px] font-semibold text-white/62">{LABEL_POINTS}</p>
            <p className="mt-1 text-2xl font-black leading-none tabular-nums">{member.points.toLocaleString()}</p>
          </div>
          <div className="rounded-2xl bg-white/10 p-3 text-right ring-1 ring-white/12">
            <p className="text-[10px] font-semibold text-white/62">{LABEL_ORDERS}</p>
            <div className="mt-1 flex items-end justify-end gap-1 text-white">
              <Package size={14} className="mb-0.5" aria-hidden />
              <span className="text-xl font-black leading-none tabular-nums">{(member.total_orders ?? 0).toLocaleString()}</span>
            </div>
          </div>
        </div>

        <div className="relative mt-4 rounded-2xl bg-white/12 p-3 ring-1 ring-white/14">
          <div className="flex items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-1.5 text-[10px] font-semibold text-white/70">
              <TrendingUp size={11} className="shrink-0" aria-hidden />
              <span className="truncate">{progress >= 100 ? LABEL_MAX_TIER : `${LABEL_TO} ${nextTier}`}</span>
            </div>
            <span className="text-[10px] font-black text-white">{Math.round(progress)}%</span>
          </div>
          <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/18">
            <div
              className="h-full rounded-full bg-white transition-all duration-700 ease-out"
              style={{ width: `${progress}%` }}
            />
          </div>
          {tier.points_to_next && tier.points_to_next > 0 ? (
            <p className="mt-2 text-[10px] text-white/62">
              {LABEL_REMAINING} <span className="font-bold text-white">{tier.points_to_next.toLocaleString()}</span> {LABEL_POINT_UNIT} {LABEL_UPGRADE_TO} {nextTier}
            </p>
          ) : (
            <p className="mt-2 text-[10px] font-bold text-white">{LABEL_TOP_LEVEL}</p>
          )}
        </div>
      </div>
    </section>
  )
}
