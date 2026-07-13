import { z } from 'zod';
import { flatSuccessEnvelope } from './envelope';

/**
 * addresses.ts — zod contracts for `/api/miniapp/addresses`.
 *
 * ============================================================================
 * PROMINENT FINDING: THERE IS NO `api/user-addresses.php` — THIS IS NOT A PORT.
 * ============================================================================
 * Verified exhaustively (Phase 3 batch 2 brief): `ls api/*address*.php` returns
 * nothing, `grep -rl 'user-addresses\|user_addresses' --include=*.php .`
 * returns nothing, and `git log --all -- api/user-addresses.php` / a
 * repo-wide deleted-file search both come up empty. The endpoint was NEVER
 * written on the PHP side — it is a pre-existing production gap, not
 * something this migration is retiring. `line-mini-app/src/lib/addresses-api.ts`
 * and the live `AddressesSheet.tsx` component (wired into `ProfileClient.tsx`)
 * both call `/api/user-addresses.php` today, so this feature 404s in
 * production right now.
 *
 * Because there is no PHP to read, this contract (and its Route Handler) is a
 * **first-class Next implementation**, derived from two sources only:
 *   1. The client contract already shipped in `addresses-api.ts` (78 lines) —
 *      request/response shapes the mini-app already sends/expects.
 *   2. The `user_addresses` table, already committed in
 *      `database/migration_2026-05-25_tenant_template.sql` (columns: id,
 *      line_user_id, line_account_id DEFAULT 0, label, name, phone, address,
 *      subdistrict, district, province, postcode, created_at, updated_at;
 *      `UNIQUE KEY unique_user_label (line_user_id, line_account_id, label)`).
 *      Note this table has NO `user_id` column at all — rows are keyed
 *      directly by (line_user_id, line_account_id, label), so (unlike every
 *      other endpoint in this batch) there is no `users` row to resolve or
 *      auto-create here.
 *
 * No extra fields beyond what `addresses-api.ts`'s `UserAddress` type
 * declares + the DB schema's own columns are introduced.
 *
 * ENVELOPE: `flatSuccessEnvelope()` (`{success, message, ...data}`, always
 * HTTP 200) per the brief's explicit instruction — "matching the dominant
 * convention across every sibling file in this batch" (member.ts/rewards.ts/
 * consent.ts/data-rights.ts) — since there is no PHP precedent constraining
 * the choice otherwise. `addresses-api.ts`'s own response types don't declare
 * a `message` key, but that's just the client not reading it; an unread extra
 * field on the envelope is harmless and keeps this endpoint consistent with
 * its siblings rather than forking a third ad hoc shape into the batch.
 *
 * PARITY HARNESS IMPACT: because there is no PHP original, `infra/e2e/
 * api-parity.mjs` (or its extension for this batch) CANNOT PHP-diff this
 * endpoint — there is nothing on the PHP side to call. It must instead run a
 * Next-only self-consistency check (e.g. upsert → list reflects the write →
 * delete → list no longer contains it) for the 3 cases (`list`, `upsert`,
 * `delete`). See the parity runbook for how that check is implemented; this
 * doc comment is the flag mig-infra's brief asks for.
 */

// ---------------------------------------------------------------------------
// Shared sub-shape
// ---------------------------------------------------------------------------

/** The 4 address slots the mini-app UI offers — matches the DB column's own comment. */
export const AddressLabelSchema = z.union([
  z.literal('primary'),
  z.literal('secondary_1'),
  z.literal('secondary_2'),
  z.literal('secondary_3'),
]);
export type AddressLabel = z.infer<typeof AddressLabelSchema>;

/** Field-for-field match of `addresses-api.ts`'s `UserAddress` type. */
export const UserAddressSchema = z.object({
  label: AddressLabelSchema,
  name: z.string().nullable(),
  phone: z.string().nullable(),
  address: z.string().nullable(),
  subdistrict: z.string().nullable(),
  district: z.string().nullable(),
  province: z.string().nullable(),
  postcode: z.string().nullable(),
  updated_at: z.string().nullable().optional(),
});
export type UserAddress = z.infer<typeof UserAddressSchema>;

// ---------------------------------------------------------------------------
// GET action=list
// ---------------------------------------------------------------------------

export const AddressesListQuerySchema = z.object({
  action: z.literal('list'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type AddressesListQuery = z.infer<typeof AddressesListQuerySchema>;

const AddressesListOk = flatSuccessEnvelope({ success: z.literal(true), addresses: z.array(UserAddressSchema) });
/** Always carries `addresses: []` even on failure — `addresses-api.ts`'s `ListAddressesResponse.addresses` is non-optional. */
const AddressesListFail = flatSuccessEnvelope({ success: z.literal(false), addresses: z.array(UserAddressSchema).length(0) });
export const AddressesListResponseSchema = z.union([AddressesListOk, AddressesListFail]);
export type AddressesListResponse = z.infer<typeof AddressesListResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=upsert — upsert-by-label via the table's own UNIQUE(line_user_id, line_account_id, label)
// ---------------------------------------------------------------------------

export const AddressesUpsertRequestSchema = z.object({
  action: z.literal('upsert'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  label: AddressLabelSchema,
  name: z.string().nullable().optional(),
  phone: z.string().nullable().optional(),
  address: z.string().nullable().optional(),
  subdistrict: z.string().nullable().optional(),
  district: z.string().nullable().optional(),
  province: z.string().nullable().optional(),
  postcode: z.string().nullable().optional(),
});
export type AddressesUpsertRequest = z.infer<typeof AddressesUpsertRequestSchema>;

const AddressesUpsertOk = flatSuccessEnvelope({ success: z.literal(true), address: UserAddressSchema });
const AddressesUpsertFail = flatSuccessEnvelope({ success: z.literal(false) });
export const AddressesUpsertResponseSchema = z.union([AddressesUpsertOk, AddressesUpsertFail]);
export type AddressesUpsertResponse = z.infer<typeof AddressesUpsertResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=delete — by (line_user_id, line_account_id, label)
// ---------------------------------------------------------------------------

export const AddressesDeleteRequestSchema = z.object({
  action: z.literal('delete'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  label: AddressLabelSchema,
});
export type AddressesDeleteRequest = z.infer<typeof AddressesDeleteRequestSchema>;

/**
 * DESIGN DECISION (no PHP precedent to constrain this): `delete` always returns
 * `{success:true}` regardless of whether a row actually matched — deleting an
 * already-empty slot is a no-op from the mini-app's point of view, mirroring
 * the idempotent-delete convention this batch's `medication-reminders.ts` and
 * batch 1's `wishlist.ts` `remove` action both already use for the same
 * reason (a client retry or a double-tap must not surface as an error).
 */
const AddressesDeleteOk = flatSuccessEnvelope({ success: z.literal(true) });
const AddressesDeleteFail = flatSuccessEnvelope({ success: z.literal(false) });
export const AddressesDeleteResponseSchema = z.union([AddressesDeleteOk, AddressesDeleteFail]);
export type AddressesDeleteResponse = z.infer<typeof AddressesDeleteResponseSchema>;
