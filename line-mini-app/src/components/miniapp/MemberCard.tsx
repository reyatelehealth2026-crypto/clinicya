import { Star, TrendingUp } from 'lucide-react'
import type { MemberProfile, TierInfo } from '@/types/member'

export function MemberCard({ member, tier }: { member: MemberProfile; tier: TierInfo }) {
  const displayName = member.display_name || [member.first_name, member.last_name].filter(Boolean).join(' ') || 'LINE User'
  const progress = Math.min(Math.max(tier.progress_percent || 0, 0), 100)

  return (
    <section className="animate-fade-in overflow-hidden rounded-3xl shadow-card">
      <div className="gradient-card relative px-5 pb-5 pt-5 text-white">
        <div className="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/15">
          <Star size={20} className="text-white/90" />
        </div>
        <div className="flex items-center gap-3.5">
          <img
            src={member.picture_url || 'https://placehold.co/96x96/004aad/ffffff?text=' + encodeURIComponent(displayName.charAt(0))}
            alt={displayName}
            className="h-14 w-14 rounded-2xl border-2 border-white/25 object-cover shadow-lg"
          />
          <div className="min-w-0 flex-1">
            <h2 className="truncate text-lg font-bold">{displayName}</h2>
            <p className="mt-0.5 text-sm text-white/70">ID: {member.member_id}</p>
          </div>
        </div>
        <div className="mt-5 flex items-end justify-between">
          <div>
            <p className="text-xs font-medium text-white/60">แต้มสะสม</p>
            <p className="mt-0.5 text-3xl font-extrabold tabular-nums tracking-tight">{member.points.toLocaleString()}</p>
          </div>
          <div className="rounded-full bg-white/20 px-3 py-1.5 text-xs font-bold backdrop-blur-sm">
            {tier.tier_name}
          </div>
        </div>
      </div>

      <div className="bg-white px-5 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-1.5 text-xs font-medium text-slate-500">
            <TrendingUp size={14} />
            <span>{progress >= 100 ? 'Max Level' : `ไปยัง ${tier.next_tier_name || 'ระดับสูงสุด'}`}</span>
          </div>
          <span className="text-xs font-bold text-line">{Math.round(progress)}%</span>
        </div>
        <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
          <div
            className="h-full rounded-full bg-gradient-to-r from-line to-brand-400 transition-all duration-700 ease-out"
            style={{ width: `${progress}%` }}
          />
        </div>
        {tier.points_to_next && tier.points_to_next > 0 ? (
          <p className="mt-2 text-xs text-slate-400">
            เหลืออีก <span className="font-semibold text-slate-600">{tier.points_to_next.toLocaleString()}</span> แต้ม เพื่อเลื่อนเป็น {tier.next_tier_name}
          </p>
        ) : (
          <p className="mt-2 text-xs font-semibold text-line">คุณอยู่ในระดับสูงสุดแล้ว! ✨</p>
        )}
      </div>
    </section>
  )
}
