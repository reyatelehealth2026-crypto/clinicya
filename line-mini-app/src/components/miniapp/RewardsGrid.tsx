import { PackageOpen } from 'lucide-react'
import type { RewardItem } from '@/types/rewards'
import { RewardCard } from '@/components/miniapp/RewardCard'

export function RewardsGrid({
  rewards,
  onRedeem,
  onShare,
  disabled
}: {
  rewards: RewardItem[]
  onRedeem: (rewardId: number) => void
  onShare: (reward: RewardItem) => void
  disabled?: boolean
}) {
  if (!rewards.length) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-3xl bg-white py-12 shadow-soft">
        <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100">
          <PackageOpen size={28} className="text-slate-400" />
        </div>
        <p className="text-sm font-medium text-slate-500">ยังไม่มีของรางวัลในขณะนี้</p>
      </div>
    )
  }

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      {rewards.map((reward, i) => (
        <div key={reward.id} style={{ animationDelay: `${i * 80}ms` }}>
          <RewardCard reward={reward} onRedeem={onRedeem} onShare={onShare} disabled={disabled} />
        </div>
      ))}
    </div>
  )
}
