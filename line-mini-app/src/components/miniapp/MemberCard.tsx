import { Package, Star, TrendingUp } from 'lucide-react'
import type { MemberProfile, TierInfo } from '@/types/member'

const LABEL_MAX_TIER = '\u0e23\u0e30\u0e14\u0e31\u0e1a\u0e2a\u0e39\u0e07\u0e2a\u0e38\u0e14'
const LABEL_POINTS = '\u0e41\u0e15\u0e49\u0e21\u0e2a\u0e30\u0e2a\u0e21'
const LABEL_ORDERS = '\u0e2d\u0e2d\u0e23\u0e4c\u0e40\u0e14\u0e2d\u0e23\u0e4c'
const LABEL_TO = '\u0e44\u0e1b\u0e22\u0e31\u0e07'
const LABEL_REMAINING = '\u0e40\u0e2b\u0e25\u0e37\u0e2d\u0e2d\u0e35\u0e01'
const LABEL_POINT_UNIT = '\u0e41\u0e15\u0e49\u0e21'
const LABEL_UPGRADE_TO = '\u0e40\u0e1e\u0e37\u0e48\u0e2d\u0e40\u0e25\u0e37\u0e48\u0e2d\u0e19\u0e40\u0e1b\u0e47\u0e19'
const LABEL_TOP_LEVEL = '\u0e04\u0e38\u0e13\u0e2d\u0e22\u0e39\u0e48\u0e43\u0e19\u0e23\u0e30\u0e14\u0e31\u0e1a\u0e2a\u0e39\u0e07\u0e2a\u0e38\u0e14\u0e41\u0e25\u0e49\u0e27'

export function MemberCard({ member, tier }: { member: MemberProfile; tier: TierInfo }) {
  const displayName = member.display_name || [member.first_name, member.last_name].filter(Boolean).join(' ') || 'LINE User'
  const progress = Math.min(Math.max(tier.progress_percent || 0, 0), 100)
  const nextTier = tier.next_tier_name || LABEL_MAX_TIER
  const fallbackUrl = 'https://placehold.co/96x96/187162/ffffff?text=' + encodeURIComponent(displayName.charAt(0))

  return (
    <section className="animate-fade-in overflow-hidden rounded-2xl bg-white shadow-card">
      <div className="gradient-card relative overflow-hidden px-5 pb-5 pt-5 text-white">
        <div className="absolute -right-10 -top-10 h-28 w-28 rounded-full bg-white/10" aria-hidden />
        <div className="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/15">
          <Star size={20} className="text-white/90" aria-hidden />
        </div>

        <div className="flex items-center gap-3.5 pr-12">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={member.picture_url || fallbackUrl}
            alt={displayName}
            className="h-14 w-14 rounded-2xl border-2 border-white/30 object-cover shadow-lg"
          />
          <div className="min-w-0 flex-1">
            <h2 className="truncate text-lg font-bold">{displayName}</h2>
            <p className="mt-0.5 truncate text-sm text-white/75">ID: {member.member_id}</p>
          </div>
        </div>

        <div className="mt-5 flex items-end justify-between gap-4">
          <div>
            <p className="text-xs font-medium text-white/70">{LABEL_POINTS}</p>
            <p className="mt-0.5 text-3xl font-extrabold tabular-nums">{member.points.toLocaleString()}</p>
          </div>
          <div className="flex flex-col items-end gap-2 text-right">
            <div className="rounded-full bg-white/20 px-3 py-1.5 text-xs font-bold backdrop-blur-sm">
              {tier.tier_name || 'Bronze'}
            </div>
            <div className="flex items-center gap-1.5 text-xs text-white/75">
              <Package size={14} aria-hidden />
              <span>{(member.total_orders ?? 0).toLocaleString()} {LABEL_ORDERS}</span>
            </div>
          </div>
        </div>
      </div>

      <div className="bg-white px-5 py-4">
        <div className="flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-1.5 text-xs font-medium text-slate-500">
            <TrendingUp size={14} className="shrink-0" aria-hidden />
            <span className="truncate">{progress >= 100 ? 'Max Level' : `${LABEL_TO} ${nextTier}`}</span>
          </div>
          <span className="text-xs font-bold text-line">{Math.round(progress)}%</span>
        </div>
        <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
          <div
            className="h-full rounded-full bg-gradient-to-r from-line to-brand-300 transition-all duration-700 ease-out"
            style={{ width: `${progress}%` }}
          />
        </div>
        {tier.points_to_next && tier.points_to_next > 0 ? (
          <p className="mt-2 text-xs text-slate-400">
            {LABEL_REMAINING} <span className="font-semibold text-slate-600">{tier.points_to_next.toLocaleString()}</span> {LABEL_POINT_UNIT} {LABEL_UPGRADE_TO} {nextTier}
          </p>
        ) : (
          <p className="mt-2 text-xs font-semibold text-line">{LABEL_TOP_LEVEL}</p>
        )}
      </div>
    </section>
  )
}
