export interface RewardItem {
  id: number
  name: string
  description?: string | null
  points_required: number
  reward_type?: string | null
  reward_value?: string | null
  stock?: number | null
  image_url?: string | null
  is_active?: number | boolean
}

export interface RewardListResponse {
  success: boolean
  message: string
  rewards: RewardItem[]
}

export interface RedemptionItem {
  id: number
  reward_id: number
  reward_name: string
  redemption_code: string
  points_used: number
  status: string
  created_at: string
  expires_at?: string | null
}

export interface RedemptionHistoryResponse {
  success: boolean
  message: string
  redemptions: RedemptionItem[]
}

export interface RedeemRewardResponse {
  success: boolean
  message: string
  redemption_code?: string
  redemption_id?: number
  expires_at?: string | null
  new_balance?: number
  reward?: RewardItem
}
