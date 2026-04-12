export interface TierInfo {
  tier_code: string
  tier_name: string
  color?: string
  icon?: string
  discount_percent?: number
  min_points?: number
  current_tier_points?: number
  next_tier_points?: number | null
  next_tier_name?: string | null
  points_to_next?: number
  progress_percent?: number
}

export interface MemberProfile {
  id: number
  member_id: string
  is_registered: boolean
  first_name: string | null
  last_name: string | null
  display_name: string | null
  picture_url: string | null
  phone: string | null
  email: string | null
  birthday: string | null
  gender: string | null
  address: string | null
  district: string | null
  province: string | null
  postal_code: string | null
  weight: number | null
  height: number | null
  medical_conditions: string | null
  drug_allergies: string | null
  points: number
  total_spent: number
  total_orders: number
  registered_at: string | null
}

export interface MemberCardResponse {
  success: boolean
  message: string
  member: MemberProfile
  tier: TierInfo
  next_tier?: {
    tier_code: string
    tier_name: string
    min_points: number
  } | null
  shop?: {
    name: string
    logo: string
  }
}

export interface MemberCheckResponse {
  success: boolean
  message: string
  exists: boolean
  is_registered: boolean
  has_profile: boolean
  member_id: string | null
  first_name: string | null
  last_name: string | null
  display_name: string | null
  tier: string
  tier_name: string
  points: number
  auto_registered?: boolean
}

export interface MemberUpdatePayload {
  line_user_id: string
  first_name?: string
  last_name?: string
  phone?: string
  email?: string
  weight?: number | string
  height?: number | string
  medical_conditions?: string
  drug_allergies?: string
  address?: string
  district?: string
  province?: string
  postal_code?: string
  birthday?: string
  gender?: string
}
