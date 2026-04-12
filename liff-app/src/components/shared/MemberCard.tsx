import { useAuthStore } from '@/stores/useAuthStore'
import { useLiff } from '@/providers/LiffProvider'
import { formatNumber, formatMemberId } from '@/lib/format'
import { CreditCard } from 'lucide-react'

export function MemberCard() {
  const { isLoggedIn, profile, login } = useLiff()
  const member = useAuthStore((s) => s.member)
  const tier = useAuthStore((s) => s.tier)

  if (!isLoggedIn || !member) {
    return (
      <div className="bg-gradient-to-br from-primary to-primary/80 rounded-2xl p-5 text-white">
        <div className="flex items-center gap-3 mb-4">
          <CreditCard className="w-6 h-6" />
          <span className="text-sm font-medium opacity-80">Re-Ya Member</span>
        </div>
        <p className="text-sm opacity-80 mb-3">เข้าสู่ระบบเพื่อดูข้อมูลสมาชิก</p>
        <button onClick={login} className="bg-white/20 hover:bg-white/30 text-white text-sm px-4 py-2 rounded-xl font-medium transition">
          เข้าสู่ระบบ
        </button>
      </div>
    )
  }

  const progress = tier ? Math.min(100, (tier.current_tier_points / (tier.next_tier_points || 1)) * 100) : 0

  return (
    <div className="bg-gradient-to-br from-primary to-primary/80 rounded-2xl p-5 text-white">
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-3">
          {profile?.pictureUrl && <img src={profile.pictureUrl} alt="" className="w-10 h-10 rounded-full border-2 border-white/30" />}
          <div>
            <p className="font-semibold text-sm">{member.display_name || member.first_name || profile?.displayName}</p>
            <p className="text-xs opacity-70">{formatMemberId(member.member_id)}</p>
          </div>
        </div>
        {tier && <span className="bg-white/20 text-white text-[10px] font-bold px-2.5 py-1 rounded-full">{tier.name}</span>}
      </div>
      <div className="flex items-end justify-between">
        <div>
          <p className="text-2xl font-bold">{formatNumber(member.points)}</p>
          <p className="text-xs opacity-70">คะแนนสะสม</p>
        </div>
        {tier?.next_tier_name && (
          <div className="text-right">
            <div className="w-24 h-1.5 bg-white/20 rounded-full overflow-hidden mb-1">
              <div className="h-full bg-white rounded-full transition-all" style={{ width: `${progress}%` }} />
            </div>
            <p className="text-[10px] opacity-70">อีก {formatNumber(Math.max(0, (tier.next_tier_points || 0) - (tier.current_tier_points || 0)))} ถึง {tier.next_tier_name}</p>
          </div>
        )}
      </div>
    </div>
  )
}
