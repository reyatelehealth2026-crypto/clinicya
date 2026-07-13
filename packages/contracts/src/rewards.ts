import { z } from 'zod';
import { flatSuccessEnvelope } from './envelope';

/**
 * rewards.ts — zod contracts for the ported api/rewards.php actions owned by this batch:
 * `list` (alias `rewards`), `redeem`, `my_redemptions`. `points-claim`/`points-history`'s overlapping
 * `redeem`-shaped surface is explicitly NOT ported here (contractNote §5 — leave both PHP originals
 * as-is, this endpoint stays the sole canonical redeem path).
 *
 * Field lists read directly off api/rewards.php + classes/LoyaltyPoints.php (getActiveRewards ->
 * getRewards() SELECT *, redeemReward()'s returned shape, getUserRedemptions()'s JOIN) — read in full
 * before writing this file — cross-checked against line-mini-app/src/types/rewards.ts.
 */

// ---------------------------------------------------------------------------
// Shared sub-shapes
// ---------------------------------------------------------------------------

/** `SELECT * FROM rewards` (classes/LoyaltyPoints.php::getRewards) with stock normalized (NULL -> -1). */
export const RewardItemSchema = z.object({
  id: z.number(),
  line_account_id: z.number().nullable(),
  name: z.string(),
  description: z.string().nullable(),
  image_url: z.string().nullable(),
  points_required: z.number(),
  reward_type: z.string().nullable(),
  reward_value: z.string().nullable(),
  /** -1 = unlimited (LoyaltyPoints::getRewards() normalizes NULL -> -1). */
  stock: z.number(),
  max_per_user: z.number().nullable(),
  is_active: z.union([z.number(), z.boolean()]).nullable(),
  sort_order: z.number().nullable(),
  start_date: z.string().nullable(),
  end_date: z.string().nullable(),
  terms: z.string().nullable(),
  created_at: z.string(),
});
export type RewardItem = z.infer<typeof RewardItemSchema>;

/** `reward_redemptions rr JOIN rewards r` (classes/LoyaltyPoints.php::getUserRedemptions). */
export const RedemptionItemSchema = z.object({
  id: z.number(),
  user_id: z.number(),
  reward_id: z.number(),
  line_account_id: z.number().nullable(),
  points_used: z.number(),
  redemption_code: z.string().nullable(),
  status: z.string().nullable(),
  expires_at: z.string().nullable(),
  created_at: z.string(),
  approved_at: z.string().nullable(),
  approved_by: z.number().nullable(),
  delivered_at: z.string().nullable(),
  notes: z.string().nullable(),
  reward_name: z.string(),
  reward_image: z.string().nullable(),
});
export type RedemptionItem = z.infer<typeof RedemptionItemSchema>;

/** classes/LoyaltyPoints.php::getMemberByUserId() — nested under redeem's `member` field. */
export const RedeemMemberSchema = z
  .object({
    id: z.number(),
    display_name: z.string().nullable(),
    picture_url: z.string().nullable(),
    total_points: z.number().nullable(),
    available_points: z.number().nullable(),
    used_points: z.number().nullable(),
    line_user_id: z.string(),
    tier: z.object({
      name: z.string(),
      tier_code: z.string(),
      color: z.string(),
      icon: z.string(),
      current_points: z.number(),
      min_points: z.number(),
      next_tier_name: z.string().nullable(),
      next_tier_points: z.number().nullable(),
      points_to_next: z.number(),
      progress_percent: z.number(),
      discount_percent: z.number(),
    }),
    points: z.number(),
  })
  .nullable();

// ---------------------------------------------------------------------------
// GET action=list | action=rewards
// ---------------------------------------------------------------------------

export const RewardsListQuerySchema = z.object({
  action: z.union([z.literal('list'), z.literal('rewards')]),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type RewardsListQuery = z.infer<typeof RewardsListQuerySchema>;

const RewardsListOk = flatSuccessEnvelope({ success: z.literal(true), rewards: z.array(RewardItemSchema) });
const RewardsListFail = flatSuccessEnvelope({ success: z.literal(false) });
export const RewardsListResponseSchema = z.union([RewardsListOk, RewardsListFail]);
export type RewardsListResponse = z.infer<typeof RewardsListResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=redeem
// ---------------------------------------------------------------------------

export const RewardsRedeemRequestSchema = z.object({
  action: z.literal('redeem'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  reward_id: z.union([z.string(), z.number()]),
});
export type RewardsRedeemRequest = z.infer<typeof RewardsRedeemRequestSchema>;

const RewardsRedeemOk = flatSuccessEnvelope({
  success: z.literal(true),
  redemption_code: z.string(),
  reward: RewardItemSchema,
  redemption_id: z.number(),
  expires_at: z.string().nullable(),
  new_balance: z.number(),
  member: RedeemMemberSchema,
});
const RewardsRedeemFail = flatSuccessEnvelope({
  success: z.literal(false),
  error_details: z
    .object({ message: z.string(), file: z.string(), line: z.number() })
    .optional(),
});
export const RewardsRedeemResponseSchema = z.union([RewardsRedeemOk, RewardsRedeemFail]);
export type RewardsRedeemResponse = z.infer<typeof RewardsRedeemResponseSchema>;

// ---------------------------------------------------------------------------
// GET action=my_redemptions
// ---------------------------------------------------------------------------

export const RewardsMyRedemptionsQuerySchema = z.object({
  action: z.literal('my_redemptions'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  limit: z.union([z.string(), z.number()]).optional(),
});
export type RewardsMyRedemptionsQuery = z.infer<typeof RewardsMyRedemptionsQuerySchema>;

const RewardsMyRedemptionsOk = flatSuccessEnvelope({
  success: z.literal(true),
  redemptions: z.array(RedemptionItemSchema),
});
const RewardsMyRedemptionsFail = flatSuccessEnvelope({ success: z.literal(false) });
export const RewardsMyRedemptionsResponseSchema = z.union([RewardsMyRedemptionsOk, RewardsMyRedemptionsFail]);
export type RewardsMyRedemptionsResponse = z.infer<typeof RewardsMyRedemptionsResponseSchema>;
