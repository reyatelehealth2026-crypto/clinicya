import { Gift, Share2, Sparkles } from 'lucide-react'
import type { RewardItem } from '@/types/rewards'

type RewardCardProps = {
  reward: RewardItem
  disabled?: boolean
  onRedeem: (rewardId: number) => void
  onShare: (reward: RewardItem) => void
}

export function RewardCard({ reward, disabled, onRedeem, onShare }: RewardCardProps) {
  const outOfStock = reward.stock === 0
  const stockLabel =
    reward.stock === null || reward.stock === undefined || reward.stock < 0
      ? null
      : reward.stock === 0
        ? 'หมดแล้ว'
        : `เหลือ ${reward.stock}`

  return (
    <article className="group animate-fade-in overflow-hidden rounded-3xl bg-white shadow-soft transition-shadow hover:shadow-card">
      <div className="relative aspect-[5/3] overflow-hidden bg-slate-100">
        <img
          src={reward.image_url || 'https://placehold.co/600x360/f1f5f9/94a3b8?text=Reward'}
          alt={reward.name}
          className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
        />
        <div className="absolute left-3 top-3 flex items-center gap-1 rounded-full bg-white/90 px-2.5 py-1 text-xs font-bold text-line shadow-sm backdrop-blur-sm">
          <Sparkles size={12} />
          {reward.points_required.toLocaleString()}
        </div>
        {stockLabel ? (
          <div className={`absolute right-3 top-3 badge ${outOfStock ? 'badge-red' : 'badge-green'}`}>
            {stockLabel}
          </div>
        ) : null}
      </div>
      <div className="p-4">
        <h3 className="font-semibold text-slate-900 leading-snug">{reward.name}</h3>
        {reward.description ? (
          <p className="mt-1 line-clamp-2 text-xs leading-relaxed text-slate-500">{reward.description}</p>
        ) : null}
        <div className="mt-3 grid grid-cols-[1fr,auto] gap-2">
          <button
            type="button"
            disabled={disabled || outOfStock}
            onClick={() => onRedeem(reward.id)}
            className="btn-primary w-full text-sm"
          >
            <Gift size={16} />
            {outOfStock ? 'ของรางวัลหมด' : 'แลกเลย'}
          </button>
          <button
            type="button"
            onClick={() => onShare(reward)}
            className="inline-flex items-center justify-center gap-1.5 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
          >
            <Share2 size={15} />
            แชร์
          </button>
        </div>
      </div>
    </article>
  )
}
