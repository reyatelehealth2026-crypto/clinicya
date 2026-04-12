export interface Member {
  id: number
  member_id: string
  line_user_id: string
  line_account_id: number
  first_name: string | null
  last_name: string | null
  display_name: string | null
  phone: string | null
  email: string | null
  picture_url: string | null
  tier: string
  points: number
  expiry_date: string | null
  created_at: string
}

export interface Tier {
  name: string
  color: string | null
  icon: string | null
  min_points: number
  current_tier_points: number
  next_tier_points: number
  next_tier_name: string | null
}

export interface MemberCardData {
  member: Member
  tier: Tier | null
}

export interface PointsHistoryItem {
  id: number
  type: 'earn' | 'redeem' | 'expire' | 'adjust'
  points: number
  description: string
  created_at: string
}
