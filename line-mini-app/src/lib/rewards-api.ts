import { appConfig } from '@/lib/config'
import { phpGet, phpPost } from '@/lib/php-bridge'
import type { RedeemRewardResponse, RedemptionHistoryResponse, RewardListResponse } from '@/types/rewards'

export function getRewards() {
  return phpGet<RewardListResponse>('/api/rewards.php', {
    action: 'list',
    line_account_id: appConfig.lineAccountId
  })
}

export function redeemReward(lineUserId: string, rewardId: number) {
  return phpPost<RedeemRewardResponse>('/api/rewards.php', {
    action: 'redeem',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
    reward_id: rewardId
  })
}

export function getMyRedemptions(lineUserId: string) {
  return phpGet<RedemptionHistoryResponse>('/api/rewards.php', {
    action: 'my_redemptions',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
    limit: 50
  })
}
