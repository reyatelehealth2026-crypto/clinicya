import { z } from 'zod';
import { flatSuccessEnvelope } from './envelope';

/**
 * member.ts — zod contracts for the ported api/member.php actions owned by this batch
 * (mig-api, writes lane): `check` (GET, real write side effects — see brief), `get_card` (GET, pure
 * read, kept in this same file/owner grouping), `register` (POST), `update_profile` (POST).
 * `get_tiers` is explicitly OUT of scope (zero line-mini-app callers, confirmed via grep).
 *
 * Field lists are read directly off api/member.php's jsonResponse() call sites (read in full before
 * writing this file) and cross-checked against line-mini-app/src/types/member.ts (the consumer
 * contract) — every field the client type declares is represented here.
 */

// ---------------------------------------------------------------------------
// Shared sub-shapes
// ---------------------------------------------------------------------------

/** Mirrors TierService::calculateTier()'s return array (classes/TierService.php). */
export const TierInfoSchema = z.object({
  tier_code: z.string(),
  tier_name: z.string(),
  name: z.string(),
  color: z.string(),
  icon: z.string(),
  discount_percent: z.number(),
  min_points: z.number(),
  current_tier_points: z.number(),
  next_tier_points: z.number().nullable(),
  next_tier_name: z.string().nullable(),
  points_to_next: z.number(),
  progress_percent: z.number(),
});

export const NextTierSchema = z
  .object({
    tier_code: z.string(),
    tier_name: z.string(),
    min_points: z.number(),
  })
  .nullable();

export const MemberProfileSchema = z.object({
  id: z.number(),
  member_id: z.string().nullable(),
  is_registered: z.boolean(),
  first_name: z.string().nullable(),
  last_name: z.string().nullable(),
  display_name: z.string().nullable(),
  picture_url: z.string().nullable(),
  phone: z.string().nullable(),
  email: z.string().nullable(),
  birthday: z.string().nullable(),
  gender: z.string().nullable(),
  address: z.string().nullable(),
  district: z.string().nullable(),
  province: z.string().nullable(),
  postal_code: z.string().nullable(),
  weight: z.number().nullable(),
  height: z.number().nullable(),
  medical_conditions: z.string().nullable(),
  drug_allergies: z.string().nullable(),
  points: z.number(),
  total_spent: z.number(),
  total_orders: z.number(),
  registered_at: z.string().nullable(),
});

// ---------------------------------------------------------------------------
// GET action=check — auto-register / auto-upgrade side effects live server-side;
// the response itself is a flat read-shaped payload.
// ---------------------------------------------------------------------------

export const MemberCheckQuerySchema = z.object({
  action: z.literal('check'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  display_name: z.string().optional(),
  picture_url: z.string().optional(),
});
export type MemberCheckQuery = z.infer<typeof MemberCheckQuerySchema>;

const MemberCheckOk = flatSuccessEnvelope({
  success: z.literal(true),
  exists: z.literal(true),
  is_registered: z.boolean(),
  has_profile: z.boolean(),
  member_id: z.string().nullable(),
  first_name: z.string().nullable(),
  last_name: z.string().nullable(),
  display_name: z.string().nullable(),
  tier: z.string(),
  tier_name: z.string(),
  points: z.number(),
  // 2026 quirk preserved verbatim from api/member.php::handleCheck(): this is HARDCODED true on the
  // success path regardless of whether auto-register/auto-upgrade actually fired this request — do not
  // "fix" it into a real conditional flag.
  auto_registered: z.literal(true),
});
const MemberCheckMissingParam = flatSuccessEnvelope({ success: z.literal(false) });
export const MemberCheckResponseSchema = z.union([MemberCheckOk, MemberCheckMissingParam]);
export type MemberCheckResponse = z.infer<typeof MemberCheckResponseSchema>;

// ---------------------------------------------------------------------------
// GET action=get_card
// ---------------------------------------------------------------------------

export const MemberGetCardQuerySchema = z.object({
  action: z.literal('get_card'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type MemberGetCardQuery = z.infer<typeof MemberGetCardQuerySchema>;

const MemberGetCardOk = flatSuccessEnvelope({
  success: z.literal(true),
  member: MemberProfileSchema,
  tier: TierInfoSchema.or(
    z.object({
      tier_code: z.literal('bronze'),
      tier_name: z.literal('Bronze'),
      color: z.string(),
      icon: z.string(),
      discount_percent: z.number(),
      benefits: z.string(),
    })
  ),
  next_tier: NextTierSchema,
  shop: z.object({ name: z.string(), logo: z.string() }),
});
const MemberGetCardFail = flatSuccessEnvelope({
  success: z.literal(false),
  is_registered: z.boolean().optional(),
  user_exists: z.boolean().optional(),
  user_id: z.number().optional(),
});
export const MemberGetCardResponseSchema = z.union([MemberGetCardOk, MemberGetCardFail]);
export type MemberGetCardResponse = z.infer<typeof MemberGetCardResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=register
// ---------------------------------------------------------------------------

export const MemberRegisterRequestSchema = z.object({
  action: z.literal('register'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  first_name: z.string(),
  last_name: z.string().optional(),
  birthday: z.string(),
  gender: z.string(),
  phone: z.string().optional(),
  email: z.string().optional(),
  weight: z.union([z.string(), z.number()]).optional(),
  height: z.union([z.string(), z.number()]).optional(),
  medical_conditions: z.string().optional(),
  drug_allergies: z.string().optional(),
  address: z.string().optional(),
  district: z.string().optional(),
  province: z.string().optional(),
  postal_code: z.string().optional(),
  display_name: z.string().optional(),
  picture_url: z.string().optional(),
});
export type MemberRegisterRequest = z.infer<typeof MemberRegisterRequestSchema>;

const MemberRegisterOk = flatSuccessEnvelope({
  success: z.literal(true),
  member_id: z.string(),
  welcome_bonus: z.number(),
  tier: z.literal('bronze'),
});
const MemberRegisterFail = flatSuccessEnvelope({
  success: z.literal(false),
  member_id: z.string().optional(), // present only on the "already a member" branch
});
export const MemberRegisterResponseSchema = z.union([MemberRegisterOk, MemberRegisterFail]);
export type MemberRegisterResponse = z.infer<typeof MemberRegisterResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=update_profile
// ---------------------------------------------------------------------------

export const MemberUpdateProfileRequestSchema = z.object({
  action: z.literal('update_profile'),
  line_user_id: z.string(),
  first_name: z.string().optional(),
  last_name: z.string().optional(),
  phone: z.string().optional(),
  email: z.string().optional(),
  weight: z.union([z.string(), z.number()]).optional(),
  height: z.union([z.string(), z.number()]).optional(),
  medical_conditions: z.string().optional(),
  drug_allergies: z.string().optional(),
  address: z.string().optional(),
  district: z.string().optional(),
  province: z.string().optional(),
  postal_code: z.string().optional(),
  birthday: z.string().optional(),
  gender: z.string().optional(),
});
export type MemberUpdateProfileRequest = z.infer<typeof MemberUpdateProfileRequestSchema>;

export const MemberUpdateProfileResponseSchema = flatSuccessEnvelope({});
export type MemberUpdateProfileResponse = z.infer<typeof MemberUpdateProfileResponseSchema>;

// ---------------------------------------------------------------------------
// The one cross-cutting, existing-system quirk this batch must preserve byte-for-byte (contractNote
// §8): the welcome-bonus write in handleRegister()/autoRegisterMember()/autoUpgradeMember() goes to
// `points_history` (type='bonus'), which is a DIFFERENT table from the one LoyaltyPoints/rewards.php
// read/write (`points_transactions`). This is not modeled as a zod shape (it's a side-effect, not a
// response field) — flagged here so it's discoverable from the contract file, and asserted against in
// apps/admin's route tests + this package's own regression test (tests/member.test.ts).
// ---------------------------------------------------------------------------
export const POINTS_HISTORY_TABLE_FOR_WELCOME_BONUS = 'points_history' as const;
