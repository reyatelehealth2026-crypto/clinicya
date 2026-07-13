import { z } from 'zod';

/**
 * points-history.ts — zod contracts for api/points-history.php's `action=history`
 * ONLY (this batch's scope — `dashboard`/`full_history`/`rewards`/`redeem` all
 * have zero line-mini-app callers per the brief's grep verification;
 * `redeem` in particular would silently duplicate rewards.php's canonical
 * redeem path, ported by mig-api-writes). Read api/points-history.php in
 * full (432 lines) before writing this file.
 *
 * RESPONSE ENVELOPE — ad hoc inline (`{success, user?, history?, error?}`),
 * NOT `flatSuccessEnvelope()` — no `message` key on any branch of `history`,
 * `error` (not `message`) on failure, always HTTP 200 on the normal error
 * path (the file's shutdown-function 500 only fires on a genuine PHP fatal,
 * which a thrown exception in the Next Route Handler naturally reproduces —
 * no special modeling needed here, per the brief).
 *
 * EXISTING-SYSTEM QUIRK preserved on purpose (contractNote §8, NOT fixed
 * here): this endpoint's `history` action reads `points_transactions` — a
 * DIFFERENT table from the one member.php's welcome-bonus write uses
 * (`points_history`, see mig-api-writes' member.ts). A brand-new member's
 * 50pt welcome bonus therefore never appears in this view. See
 * POINTS_TRANSACTIONS_TABLE_FOR_HISTORY below.
 */

// ---------------------------------------------------------------------------
// GET request (action=history)
// ---------------------------------------------------------------------------

export const PointsHistoryQuerySchema = z.object({
  action: z.literal('history').optional(), // PHP defaults $_GET['action'] ?? 'history'
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  limit: z.union([z.string(), z.number()]).optional(), // (int) $_GET['limit'] ?? 20, no upper bound on this action
});
export type PointsHistoryQuery = z.infer<typeof PointsHistoryQuerySchema>;

// ---------------------------------------------------------------------------
// Response
// ---------------------------------------------------------------------------

export const PointsHistoryUserSchema = z.object({
  name: z.string().nullable(),
  total_points: z.number(),
  available_points: z.number(),
  used_points: z.number(),
});
export type PointsHistoryUser = z.infer<typeof PointsHistoryUserSchema>;

/**
 * Mirrors `points_transactions.type`'s real MySQL ENUM (packages/db's
 * generated tenant-db schema) — the `history` action echoes this column
 * value verbatim, with no server-side remapping (unlike `full_history`'s
 * `earned`/`redeemed`/`expired` -> `earn`/`redeem`/`expire` `$typeMap`,
 * which belongs to the OUT-OF-SCOPE `full_history` action, not this one).
 */
export const PointsTransactionTypeSchema = z.enum(['adjust', 'earn', 'expire', 'redeem', 'refund']);
export type PointsTransactionType = z.infer<typeof PointsTransactionTypeSchema>;

export const PointsHistoryItemSchema = z.object({
  id: z.number(),
  type: PointsTransactionTypeSchema,
  points: z.number(),
  balance_after: z.number(),
  description: z.string().nullable(),
  reference_type: z.string().nullable(),
  reference_id: z.number().nullable(),
  created_at: z.string(),
  /** `date('d/m/Y H:i', strtotime($item['created_at']))` — Gregorian (not Buddhist-era) day/month/year, computed server-side. */
  formatted_date: z.string(),
});
export type PointsHistoryItem = z.infer<typeof PointsHistoryItemSchema>;

export const PointsHistoryOkSchema = z.object({
  success: z.literal(true),
  user: PointsHistoryUserSchema,
  history: z.array(PointsHistoryItemSchema),
});
export type PointsHistoryOk = z.infer<typeof PointsHistoryOkSchema>;

export const PointsHistoryFailSchema = z.object({
  success: z.literal(false),
  error: z.string(),
});
export type PointsHistoryFail = z.infer<typeof PointsHistoryFailSchema>;

export const PointsHistoryResponseSchema = z.union([PointsHistoryOkSchema, PointsHistoryFailSchema]);
export type PointsHistoryResponse = z.infer<typeof PointsHistoryResponseSchema>;

/** Every branch of this endpoint responds HTTP 200 — even `Missing line_user_id` / `User not found` / the catch-all. */
export const POINTS_HISTORY_STATUS = 200 as const;

/** See this file's module doc + contractNote §8 — the table `action=history` reads from (NOT the one member.php's welcome bonus writes to). */
export const POINTS_TRANSACTIONS_TABLE_FOR_HISTORY = 'points_transactions' as const;
