import { randomInt } from 'node:crypto';
import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { DataRightsExport } from '@reya/contracts';

/**
 * service.ts — port of `modules/PDPA/Services/DataRightsService.php` (365 lines, read in full). Every
 * query touching `data_deletion_requests` / `users.deletion_status` / `users.deletion_requested_at` uses
 * the `sql` tagged-template escape hatch, NOT the typed Kysely query builder — those columns/that table
 * are confirmed MISSING from `packages/db/src/generated/tenant-db.d.ts` (they live only in the
 * separately-committed `database/migration_2026-07-04_pdpa_data_rights.sql` — see
 * `packages/contracts/src/data-rights.ts`'s doc comment for the full migration-dependency writeup).
 *
 * `ensureDeletionSchema()`'s lazy self-healing ALTER/CREATE (a PHP resilience fallback for tenants that
 * haven't run the migration yet) is DELIBERATELY NOT PORTED here — DDL-on-request from application code
 * is discouraged by CLAUDE.md's own "Auto-create tables" convention, and the migration-dependency note
 * above makes it unnecessary once the fixture DB has the migration applied.
 */

/** `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` — no 0/O/1/I, to avoid customer transcription errors. */
const CONFIRMATION_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/** Port of `DataRightsService::generateConfirmationCode()` — `REYA-DEL-` + 8 random chars. */
export function generateConfirmationCode(): string {
  let code = '';
  for (let i = 0; i < 8; i++) {
    code += CONFIRMATION_CODE_ALPHABET[randomInt(0, CONFIRMATION_CODE_ALPHABET.length)];
  }
  return `REYA-DEL-${code}`;
}

export interface UserRow {
  id: number;
  [key: string]: unknown;
}

/**
 * Port of `DataRightsService::resolveUser()` — the security-critical resolution: `users.id` is ALWAYS
 * derived server-side from `(line_user_id, line_account_id)`, NEVER from a client-supplied `user_id`.
 * `line_account_id`-scoped lookup first; falls back to a `line_user_id`-only lookup if that specific
 * combo misses (tenant DB already scopes to one tenant, mirrors `member.php`'s own fallback).
 *
 * Selects `users.*` PLUS `deletion_status`/`deletion_requested_at` via the `sql` escape hatch (present
 * once the PDPA migration has run; absent otherwise — `normaliseUserProfile()`'s `array_key_exists`
 * semantics are naturally reproduced by whatever columns MySQL actually returns).
 */
export async function resolveUser(db: Kysely<TenantDB>, lineUserId: string | null, lineAccountId: number | null): Promise<UserRow | null> {
  if (lineUserId === null) return null;
  const trimmed = lineUserId.trim();
  if (trimmed === '') return null;

  try {
    if (lineAccountId !== null) {
      const scoped = await sql<UserRow>`SELECT * FROM users WHERE line_user_id = ${trimmed} AND line_account_id = ${lineAccountId} LIMIT 1`.execute(db);
      if (scoped.rows.length > 0) return scoped.rows[0]!;
    }
    const unscoped = await sql<UserRow>`SELECT * FROM users WHERE line_user_id = ${trimmed} LIMIT 1`.execute(db);
    return unscoped.rows[0] ?? null;
  } catch {
    // Mirrors PHP's catch (\Throwable $e) { error_log(...); return null; }.
    return null;
  }
}

/** Port of `withdrawConsent()` — mirrors `api/consent.php::handleWithdrawConsent()`'s UPDATE + INSERT pair. */
export async function withdrawConsent(
  db: Kysely<TenantDB>,
  userId: number,
  consentType: string,
  lineAccountId: number | null,
  ip: string | null,
  ua: string | null
): Promise<void> {
  await sql`
    UPDATE user_consents SET is_accepted = 0, withdrawn_at = NOW(), updated_at = NOW()
    WHERE user_id = ${userId} AND consent_type = ${consentType}
  `.execute(db);

  await sql`
    INSERT INTO consent_logs (line_account_id, user_id, consent_type, action, consent_version, ip_address, user_agent)
    VALUES (${lineAccountId ?? 1}, ${userId}, ${consentType}, 'withdraw', '1.0', ${ip}, ${ua})
  `.execute(db);
}

/**
 * Port of `markForDeletion()` — SOFT flag ONLY (`UPDATE users SET deletion_status='requested', ...`),
 * NEVER a `DELETE`. Writes a `data_deletion_requests` ledger row with the returned confirmation code.
 */
export async function markForDeletion(
  db: Kysely<TenantDB>,
  userId: number,
  lineUserId: string,
  lineAccountId: number | null,
  reason: string | null,
  ip: string | null,
  ua: string | null
): Promise<string> {
  const code = generateConfirmationCode();

  await db.transaction().execute(async (trx) => {
    await sql`UPDATE users SET deletion_status = 'requested', deletion_requested_at = NOW() WHERE id = ${userId}`.execute(trx);

    await sql`
      INSERT INTO data_deletion_requests
        (line_account_id, user_id, line_user_id, confirmation_code, status, reason, ip_address, user_agent)
      VALUES (${lineAccountId}, ${userId}, ${lineUserId}, ${code}, 'requested', ${reason}, ${ip}, ${ua})
    `.execute(trx);
  });

  return code;
}

/**
 * Port of `normaliseUserProfile()` — the literal 34-key allowlist read off `DataRightsService.php`'s
 * `$allow` array (NOT a paraphrased count). `array_key_exists` in PHP gates inclusion; here every column
 * present on `userRow` (which may or may not include `deletion_status`/`deletion_requested_at`, per
 * whether the PDPA migration has run) is copied over as-is.
 */
const PROFILE_ALLOWLIST = [
  'id', 'line_account_id', 'line_user_id', 'display_name', 'real_name',
  'first_name', 'last_name', 'phone', 'email', 'birthday', 'gender',
  'address', 'district', 'province', 'postal_code', 'member_id',
  'is_registered', 'total_orders', 'total_spent', 'available_points',
  'medical_conditions', 'drug_allergies', 'current_medications',
  'blood_type', 'weight', 'height', 'created_at', 'registered_at',
  'consent_privacy', 'consent_terms', 'consent_health_data', 'consent_date',
  'deletion_status', 'deletion_requested_at',
] as const;

/**
 * PHP's `date('c')` (ISO 8601 WITH a numeric offset) runs under the server's PHP timezone, which
 * CLAUDE.md pins to `Asia/Bangkok` (`+07:00`) for this whole codebase — `new Date().toISOString()`
 * would instead emit UTC with a `Z` suffix, a format mismatch regardless of what timezone the Node
 * process itself happens to run in. Computed directly from the offset rather than relying on the
 * container's local TZ setting.
 */
function nowIsoWithBangkokOffset(): string {
  const bangkok = new Date(Date.now() + 7 * 60 * 60 * 1000);
  const pad = (n: number) => String(n).padStart(2, '0');
  const y = bangkok.getUTCFullYear();
  const mo = pad(bangkok.getUTCMonth() + 1);
  const d = pad(bangkok.getUTCDate());
  const h = pad(bangkok.getUTCHours());
  const mi = pad(bangkok.getUTCMinutes());
  const s = pad(bangkok.getUTCSeconds());
  return `${y}-${mo}-${d}T${h}:${mi}:${s}+07:00`;
}

function asDateTimeString(value: unknown): unknown {
  if (value instanceof Date) {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
  }
  return value;
}

const DATE_LIKE_KEYS = new Set(['created_at', 'registered_at', 'consent_date', 'deletion_requested_at']);

export function normaliseUserProfile(userRow: UserRow): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const key of PROFILE_ALLOWLIST) {
    if (Object.prototype.hasOwnProperty.call(userRow, key)) {
      const value = userRow[key];
      out[key] = DATE_LIKE_KEYS.has(key) ? asDateTimeString(value) : value;
    }
  }
  // birthday is a DATE column — formatted separately (no time component) so it doesn't collide with the
  // DATETIME columns above.
  if (Object.prototype.hasOwnProperty.call(userRow, 'birthday')) {
    const birthday = userRow.birthday;
    out.birthday = birthday instanceof Date ? birthday.toISOString().slice(0, 10) : birthday;
  }
  return out;
}

interface ConsentRow {
  consent_type: string;
  consent_version: string;
  is_accepted: number;
  accepted_at: Date | string | null;
  withdrawn_at: Date | string | null;
  updated_at: Date | string | null;
}

async function fetchOwnConsents(db: Kysely<TenantDB>, userId: number): Promise<unknown[]> {
  try {
    const result = await sql<ConsentRow>`
      SELECT consent_type, consent_version, is_accepted, accepted_at, withdrawn_at, updated_at
      FROM user_consents WHERE user_id = ${userId} ORDER BY id ASC
    `.execute(db);
    return result.rows.map((row) => ({
      ...row,
      accepted_at: asDateTimeString(row.accepted_at),
      withdrawn_at: asDateTimeString(row.withdrawn_at),
      updated_at: asDateTimeString(row.updated_at),
    }));
  } catch {
    return [];
  }
}

interface ConsentLogRow {
  consent_type: string;
  action: string;
  consent_version: string;
  created_at: Date | string | null;
}

async function fetchOwnConsentLogs(db: Kysely<TenantDB>, userId: number): Promise<unknown[]> {
  try {
    const result = await sql<ConsentLogRow>`
      SELECT consent_type, action, consent_version, created_at
      FROM consent_logs WHERE user_id = ${userId} ORDER BY created_at DESC LIMIT 200
    `.execute(db);
    return result.rows.map((row) => ({ ...row, created_at: asDateTimeString(row.created_at) }));
  } catch {
    return [];
  }
}

interface ChatHistoryRow {
  role: string;
  content: string;
  session_id: string | null;
  created_at: Date | string | null;
}

async function fetchOwnChatHistory(db: Kysely<TenantDB>, userId: number): Promise<unknown[]> {
  try {
    const result = await sql<ChatHistoryRow>`
      SELECT role, content, session_id, created_at
      FROM ai_conversation_history WHERE user_id = ${userId} ORDER BY created_at ASC LIMIT 500
    `.execute(db);
    return result.rows.map((row) => ({ ...row, created_at: asDateTimeString(row.created_at) }));
  } catch {
    return [];
  }
}

interface OrderRow {
  id: number;
  order_number: string;
  total_amount: number | string;
  status: string | null;
  created_at: Date | string | null;
  products: string | null;
}

async function fetchOwnOrders(db: Kysely<TenantDB>, userId: number): Promise<unknown[]> {
  try {
    const result = await sql<OrderRow>`
      SELECT t.id, t.order_number, t.total_amount, t.status, t.created_at,
             GROUP_CONCAT(ti.product_name SEPARATOR ', ') AS products
      FROM transactions t
      LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
      WHERE t.user_id = ${userId}
      GROUP BY t.id
      ORDER BY t.created_at DESC
      LIMIT 200
    `.execute(db);
    return result.rows.map((row) => ({
      ...row,
      // `t.total_amount` is a DECIMAL column: PHP's `fetchOwnOrders()` is a plain
      // `fetchAll(PDO::FETCH_ASSOC)` with no cast, so PDO hands back the raw decimal
      // string (e.g. `"25.00"`), not a float. mysql2 (no `decimalNumbers` option set —
      // see packages/db) matches that by default. Do NOT `Number()`-coerce this: that
      // silently drops trailing zeros / changes the JSON type from string to number and
      // breaks field-level parity. Raw passthrough via `...row`, same convention as
      // `total_spent`/`available_points` in `normaliseUserProfile()` and
      // `consultation_fee`/`rating` in appointments.ts's `PharmacistRow`.
      created_at: asDateTimeString(row.created_at),
    }));
  } catch {
    return [];
  }
}

/**
 * Port of `buildExportForUser()`/`buildExportShape()`. Every sub-fetch is individually try/catch-wrapped
 * (see the four `fetchOwn*()` helpers above) — a single failing read must not fail the whole export,
 * replicating PHP's per-query isolation.
 */
export async function buildExportForUser(db: Kysely<TenantDB>, userRow: UserRow): Promise<DataRightsExport> {
  const userId = Number(userRow.id ?? 0);

  const [consents, consentLogs, chatHistory, orders] = await Promise.all([
    fetchOwnConsents(db, userId),
    fetchOwnConsentLogs(db, userId),
    fetchOwnChatHistory(db, userId),
    fetchOwnOrders(db, userId),
  ]);

  return {
    export_meta: {
      generated_at: nowIsoWithBangkokOffset(),
      standard: 'PDPA (Thailand) — ข้อมูลส่วนบุคคลของเจ้าของข้อมูลเท่านั้น',
      user_id: Object.prototype.hasOwnProperty.call(userRow, 'id') ? userId : null,
    },
    profile: normaliseUserProfile(userRow),
    consents,
    consent_history: consentLogs,
    chat_history: chatHistory,
    orders,
  } as DataRightsExport;
}
