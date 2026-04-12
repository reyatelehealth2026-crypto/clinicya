'use client'

import { useCallback, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useLineContext } from '@/components/providers'
import { AppShell } from '@/components/miniapp/AppShell'
import { RewardShareSheet } from '@/components/miniapp/RewardShareSheet'
import { RewardsGrid } from '@/components/miniapp/RewardsGrid'
import { VerifiedOnlyNotice } from '@/components/miniapp/VerifiedOnlyNotice'
import { getRewards, redeemReward } from '@/lib/rewards-api'
import { useToast } from '@/lib/toast'
import { useHaptic } from '@/lib/hooks'
import type { RewardItem } from '@/types/rewards'

function LoadingSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      {[1, 2, 3, 4].map((i) => (
        <div key={i} className="skeleton h-64 w-full rounded-3xl" />
      ))}
    </div>
  )
}

export function RewardsClient() {
  const line = useLineContext()
  const lineUserId = line.profile?.userId || ''
  const [selectedReward, setSelectedReward] = useState<RewardItem | null>(null)
  const { toast } = useToast()
  const haptic = useHaptic()
  const queryClient = useQueryClient()

  const rewardsQuery = useQuery({
    queryKey: ['rewards-list'],
    queryFn: () => getRewards()
  })

  const handleRefresh = useCallback(async () => {
    await queryClient.invalidateQueries({ queryKey: ['rewards-list'] })
  }, [queryClient])

  const redeemMutation = useMutation({
    mutationFn: (rewardId: number) => redeemReward(lineUserId, rewardId),
    onSuccess: (data: { message?: string }) => {
      haptic('heavy')
      toast.success(data.message || 'แลกรางวัลสำเร็จ!')
      void queryClient.invalidateQueries({ queryKey: ['member-card'] })
    },
    onError: (error: unknown) => {
      haptic('medium')
      toast.error(error instanceof Error ? error.message : 'แลกรางวัลไม่สำเร็จ')
    }
  })

  return (
    <AppShell title="แลกของรางวัล" subtitle="ใช้แต้มสะสมแลกสินค้าและสิทธิประโยชน์" onRefresh={handleRefresh}>
      {line.error ? <VerifiedOnlyNotice title="LINE bootstrap issue" description={line.error} /> : null}

      {rewardsQuery.isLoading ? <LoadingSkeleton /> : null}

      {rewardsQuery.data?.rewards ? (
        <RewardsGrid
          rewards={rewardsQuery.data.rewards}
          disabled={!lineUserId || redeemMutation.isPending}
          onShare={(reward) => {
            setSelectedReward(reward)
          }}
          onRedeem={(rewardId) => {
            if (!lineUserId) {
              window.alert('กรุณาเข้าสู่ระบบ LINE ก่อน')
              return
            }
            void redeemMutation.mutateAsync(rewardId)
          }}
        />
      ) : null}

      <RewardShareSheet reward={selectedReward} onClose={() => setSelectedReward(null)} />
    </AppShell>
  )
}
