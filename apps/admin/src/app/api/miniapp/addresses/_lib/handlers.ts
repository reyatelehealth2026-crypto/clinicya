import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { AddressLabel } from '@reya/contracts';

/**
 * handlers.ts — `/api/miniapp/addresses`'s three action handlers.
 *
 * NO PHP ORIGINAL EXISTS for this endpoint — see `packages/contracts/src/addresses.ts`'s doc comment
 * for the full "no PHP source" finding. This file is a first-class implementation built from
 * `line-mini-app/src/lib/addresses-api.ts` (the client contract) + the `user_addresses` table already
 * committed in `database/migration_2026-05-25_tenant_template.sql`.
 *
 * `user_addresses` has NO `user_id` column — rows are keyed directly by
 * `(line_user_id, line_account_id, label)` via `UNIQUE KEY unique_user_label`, so (unlike every other
 * endpoint in this batch) there is no `users` row to resolve or auto-create here.
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

const VALID_LABELS: readonly AddressLabel[] = ['primary', 'secondary_1', 'secondary_2', 'secondary_3'];

function isValidLabel(value: unknown): value is AddressLabel {
  return typeof value === 'string' && (VALID_LABELS as readonly string[]).includes(value);
}

/** Port of the `flatSuccessEnvelope()`-shaped `{success, message, ...data}` convention this batch uses. */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): ActionResult {
  return { status: 200, body: { success, message, ...data } };
}

/**
 * packages/db's mysql2 pool has no `dateStrings: true`, so DATETIME/TIMESTAMP columns hydrate as JS
 * `Date` objects — formatted here to a MySQL-shaped string for a stable, PHP-PDO-like JSON serialization.
 * Same fix already applied in every sibling `_lib` file this batch (`asDateTimeString()` in
 * member/wishlist), mirrored here rather than imported, per this batch's allowed-paths boundary.
 */
function asDateTimeString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

/** `undefined`/`null`/`''` all normalize to a stored `NULL`, matching the column's own `DEFAULT NULL`. */
function textOrNull(value: unknown): string | null {
  if (value === undefined || value === null) return null;
  const str = String(value);
  return str === '' ? null : str;
}

interface AddressRow {
  label: string;
  name: string | null;
  phone: string | null;
  address: string | null;
  subdistrict: string | null;
  district: string | null;
  province: string | null;
  postcode: string | null;
  updated_at: string | Date | null;
}

function normalizeAddressRow(row: AddressRow) {
  return {
    label: row.label,
    name: row.name,
    phone: row.phone,
    address: row.address,
    subdistrict: row.subdistrict,
    district: row.district,
    province: row.province,
    postcode: row.postcode,
    updated_at: asDateTimeString(row.updated_at),
  };
}

const ADDRESS_COLUMNS = sql`label, name, phone, address, subdistrict, district, province, postcode, updated_at`;

// ---------------------------------------------------------------------------
// GET action=list
// ---------------------------------------------------------------------------

export async function handleList(db: Kysely<TenantDB>, lineUserId: string, lineAccountId: number): Promise<ActionResult> {
  if (!lineUserId) {
    return { status: 200, body: { success: false, message: 'LINE User ID required', addresses: [] } };
  }

  // ORDER BY label ASC happens to already sort 'primary' before 'secondary_1'/'_2'/'_3' lexicographically
  // (p < s), which matches ADDRESS_LABELS' own display order in addresses-api.ts — a deliberate choice,
  // since there is no PHP precedent to constrain the ordering.
  const result = await sql<AddressRow>`
    SELECT ${ADDRESS_COLUMNS} FROM user_addresses
    WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId}
    ORDER BY label ASC
  `.execute(db);

  return ok(true, '', { addresses: result.rows.map(normalizeAddressRow) });
}

// ---------------------------------------------------------------------------
// POST action=upsert — insert-or-update via the table's own UNIQUE(line_user_id, line_account_id, label)
// ---------------------------------------------------------------------------

export interface UpsertFields {
  name?: unknown;
  phone?: unknown;
  address?: unknown;
  subdistrict?: unknown;
  district?: unknown;
  province?: unknown;
  postcode?: unknown;
}

export async function handleUpsert(
  db: Kysely<TenantDB>,
  lineUserId: string,
  lineAccountId: number,
  label: unknown,
  fields: UpsertFields
): Promise<ActionResult> {
  if (!lineUserId) {
    return ok(false, 'LINE User ID required');
  }
  if (!isValidLabel(label)) {
    return ok(false, 'Invalid label');
  }

  const name = textOrNull(fields.name);
  const phone = textOrNull(fields.phone);
  const address = textOrNull(fields.address);
  const subdistrict = textOrNull(fields.subdistrict);
  const district = textOrNull(fields.district);
  const province = textOrNull(fields.province);
  const postcode = textOrNull(fields.postcode);

  await sql`
    INSERT INTO user_addresses (line_user_id, line_account_id, label, name, phone, address, subdistrict, district, province, postcode)
    VALUES (${lineUserId}, ${lineAccountId}, ${label}, ${name}, ${phone}, ${address}, ${subdistrict}, ${district}, ${province}, ${postcode})
    ON DUPLICATE KEY UPDATE
      name = VALUES(name),
      phone = VALUES(phone),
      address = VALUES(address),
      subdistrict = VALUES(subdistrict),
      district = VALUES(district),
      province = VALUES(province),
      postcode = VALUES(postcode)
  `.execute(db);

  const result = await sql<AddressRow>`
    SELECT ${ADDRESS_COLUMNS} FROM user_addresses
    WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId} AND label = ${label}
  `.execute(db);
  const row = result.rows[0];
  if (!row) {
    // Should be unreachable — the INSERT ... ON DUPLICATE KEY UPDATE above always leaves exactly one
    // matching row. Surface as a 500 rather than silently returning a fabricated address.
    throw new Error(`addresses upsert: row not found after write (line_user_id=${lineUserId}, label=${label})`);
  }

  return ok(true, 'บันทึกที่อยู่แล้ว', { address: normalizeAddressRow(row) });
}

// ---------------------------------------------------------------------------
// POST action=delete — idempotent regardless of whether a row matched (see addresses.ts's doc comment)
// ---------------------------------------------------------------------------

export async function handleDelete(db: Kysely<TenantDB>, lineUserId: string, lineAccountId: number, label: unknown): Promise<ActionResult> {
  if (!lineUserId) {
    return ok(false, 'LINE User ID required');
  }
  if (!isValidLabel(label)) {
    return ok(false, 'Invalid label');
  }

  await sql`DELETE FROM user_addresses WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId} AND label = ${label}`.execute(db);

  return ok(true, 'ลบที่อยู่แล้ว');
}
