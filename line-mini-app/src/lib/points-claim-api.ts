import { appConfig } from '@/lib/config'
import { phpGet, phpPost } from '@/lib/php-bridge'

export type ClaimState = 'pending' | 'claimed' | 'expired' | 'cancelled' | 'invalid'

export interface ClaimStatusResponse {
  success: boolean
  message?: string
  state?: ClaimState
  points?: number
  amount?: number
  voucher_no?: string
  expires_at?: string
}

export interface ClaimResult {
  success: boolean
  message?: string
  state?: ClaimState
  voucher_no?: string
  points?: number
  total_points?: number
  shop_name?: string
}

export interface ClaimProfile {
  lineUserId: string
  displayName?: string
  pictureUrl?: string
}

/** Read-only check of a claim token's state. */
export async function getClaimStatus(token: string): Promise<ClaimStatusResponse> {
  return phpGet<ClaimStatusResponse>('/api/points-claim.php', {
    action: 'status',
    token,
    line_account_id: appConfig.lineAccountId
  })
}

/** Claim the points for this token as the current LINE user (single-use). */
export async function claimPoints(token: string, profile: ClaimProfile): Promise<ClaimResult> {
  return phpPost<ClaimResult>('/api/points-claim.php', {
    action: 'claim',
    token,
    line_account_id: appConfig.lineAccountId,
    line_user_id: profile.lineUserId,
    display_name: profile.displayName ?? '',
    picture_url: profile.pictureUrl ?? ''
  })
}
