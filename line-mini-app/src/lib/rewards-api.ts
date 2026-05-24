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

export interface PointsTransaction {
  id: number | string
  type: 'earn' | 'redeem' | 'expire' | 'adjust' | string
  points: number | string
  balance_after: number | string
  description: string | null
  reference_type: string | null
  reference_id: number | string | null
  created_at: string
  formatted_date: string
}

export interface PointsHistoryResponse {
  success: boolean
  user?: {
    name: string
    total_points: number
    available_points: number
    used_points: number
  }
  history?: PointsTransaction[]
  error?: string
}

/** Returns earn + redeem + expire transactions for the user. */
export function getPointsHistory(lineUserId: string, limit: number = 50) {
  return phpGet<PointsHistoryResponse>('/api/points-history.php', {
    action: 'history',
    line_user_id: lineUserId,
    limit
  })
}
