import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * assignConversation.ts — literal port of `classes/InboxService.php`'s
 * `assignConversation()` (lines 1015-1082), as driven by api/inbox-v2.php's
 * `case 'assign_conversation':` (lines ~2461-2519).
 *
 * ```php
 * public function assignConversation(int $userId, $adminIds, ?int $assignedBy = null): array
 * {
 *     if (!is_array($adminIds)) { $adminIds = [$adminIds]; }
 *
 *     $checkStmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND line_account_id = ?");
 *     $checkStmt->execute([$userId, $this->lineAccountId]);
 *     if (!$checkStmt->fetch()) {
 *         return ['success' => false, 'error' => 'User not found', 'code' => 'USER_NOT_FOUND'];
 *     }
 *
 *     foreach ($adminIds as $adminId) {
 *         $checkAdminStmt = $this->db->prepare("SELECT id FROM admin_users WHERE id = ?");
 *         $checkAdminStmt->execute([$adminId]);
 *         if (!$checkAdminStmt->fetch()) {
 *             return ['success' => false, 'error' => "Admin ID $adminId not found", 'code' => 'ADMIN_NOT_FOUND'];
 *         }
 *     }
 *
 *     $sql = "INSERT INTO conversation_multi_assignees (user_id, admin_id, assigned_by, assigned_at, status)
 *             VALUES (?, ?, ?, NOW(), 'active')
 *             ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), assigned_at = NOW(), status = 'active'";
 *     $stmt = $this->db->prepare($sql);
 *     foreach ($adminIds as $adminId) {
 *         if (!$stmt->execute([$userId, $adminId, $assignedBy ?? $adminId])) {
 *             return ['success' => false, 'error' => 'Failed to assign conversation', 'code' => 'ASSIGN_FAILED'];
 *         }
 *     }
 *
 *     $legacySql = "INSERT INTO conversation_assignments (user_id, assigned_to, assigned_by, assigned_at, status)
 *                   VALUES (?, ?, ?, NOW(), 'active')
 *                   ON DUPLICATE KEY UPDATE assigned_to = VALUES(assigned_to), assigned_by = VALUES(assigned_by), assigned_at = NOW(), status = 'active'";
 *     $legacyStmt = $this->db->prepare($legacySql);
 *     $legacyStmt->execute([$userId, $adminIds[0], $assignedBy ?? $adminIds[0]]);
 *
 *     return ['success' => true];
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * DUAL-WRITE CONTRACT — both tables, in this order, every call
 * ═══════════════════════════════════════════════════════════════════════
 * Every successful assignment writes BOTH: (1) one
 * `conversation_multi_assignees` row per admin id (status='active'), AND
 * (2) separately, one `conversation_assignments` (legacy) row using ONLY
 * `adminIds[0]` — the first id in the array, regardless of how many ids
 * were assigned. This is not optional/best-effort — dropping either write
 * would desync the legacy single-assignee UI (still reads
 * `conversation_assignments`) from the multi-assignee UI (reads
 * `conversation_multi_assignees`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED FINDING — `admin_users` has no Kysely interface / does not
 * exist in the committed tenant template
 * ═══════════════════════════════════════════════════════════════════════
 * Same root cause as `../../get-admins/_lib/getAdmins.ts`'s own finding
 * (itself the third confirmed sighting after (tenant)/settings/_lib/
 * shop-tax-queries.ts and consent-queries.ts): `admin_users` is a
 * PLATFORM-level table (database/migration_2026-05-25_tenant_template.sql's
 * own header), absent from any tenant DB built from the committed template,
 * and absent from packages/db/src/generated/tenant-db.d.ts. The per-admin
 * existence check below is therefore issued via a raw `sql` tagged
 * template, not `.selectFrom('admin_users')`. UNLIKE the shop-tax tier-3
 * precedent (which locally swallows the throw), `InboxService::
 * assignConversation()` performs NO try/catch of its own around this
 * query — a missing-table exception bubbles straight out of the PHP method,
 * is caught only by `case 'assign_conversation':`'s outer try/catch, and
 * becomes a clean `sendError('Failed to assign conversation: ' .
 * $e->getMessage())` (HTTP 400). Reproduced literally: this function does
 * NOT catch that throw either — it propagates out of `assignConversation()`
 * for route.ts's own try/catch to convert into the same clean JSON error.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED FINDING — the committed tenant template's UNIQUE keys don't
 * match production, so ON DUPLICATE KEY UPDATE cannot dedupe there
 * ═══════════════════════════════════════════════════════════════════════
 * `database/install_complete_latest.sql` (production's actual schema) has
 * `UNIQUE KEY uk_user_admin (user_id, admin_id)` on
 * `conversation_multi_assignees` and `UNIQUE KEY uk_user (user_id)` (plus
 * `unique_user_account (user_id, line_account_id)`) on
 * `conversation_assignments` — both required for `ON DUPLICATE KEY UPDATE`
 * to actually update an existing row instead of inserting a duplicate.
 * `database/migration_2026-05-25_tenant_template.sql` — the schema this
 * repo's tenant DBs are actually provisioned from — has only non-unique
 * `KEY idx_cma_user_status (user_id, status)` / `KEY idx_ca_user (user_id)`
 * on those same two tables. On a freshly-provisioned committed-schema
 * tenant DB, re-assigning the same (user_id, admin_id) pair therefore
 * INSERTs a second row instead of updating the first — a genuine,
 * pre-existing schema-governance gap, out of scope to fix here (database/**
 * is off-limits to this batch; this is the drift-audit workstream's
 * finding to act on). The `.onDuplicateKeyUpdate()` calls below are written
 * against the typed `ConversationMultiAssignees`/`ConversationAssignments`
 * Kysely interfaces exactly as the PHP SQL shape dictates — no
 * check-then-upsert workaround, which would diverge from a literal port.
 *
 * `userId`/`adminIds`/`assignedBy` are assumed already PHP-cast + validated
 * by the caller (route.ts, via `normalizeAssignTo()` below) — this
 * function's precondition is `userId > 0` and `adminIds` a non-empty array
 * of positive ints, matching inbox-v2.php's own validation (lines
 * ~2470-2497) having already run before `InboxService::assignConversation()`
 * is ever called.
 */

export type AssignConversationResult =
  | { success: true }
  | { success: false; error: string; code: 'USER_NOT_FOUND' | 'ADMIN_NOT_FOUND' | 'ASSIGN_FAILED' };

/** PHP's `intval($v ?? 0)` — loose int cast, non-numeric -> 0. */
export function phpIntCast(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? Math.trunc(value) : 0;
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  return 0;
}

/**
 * PHP's `empty($v)` for the value shapes this endpoint's `assign_to` field
 * can actually carry (number | number[] | string | null | undefined) —
 * true/null/undefined, `0`, `'0'`, `''`, and `[]` are all "empty"; any
 * other value (including a non-empty array or a non-'0' non-empty string)
 * is not.
 */
function phpEmpty(value: unknown): boolean {
  if (value === null || value === undefined || value === false) return true;
  if (value === 0 || value === '0' || value === '') return true;
  if (Array.isArray(value)) return value.length === 0;
  return false;
}

/**
 * Literal port of inbox-v2.php lines ~2478-2497:
 *
 * ```php
 * if (is_string($assignTo)) {
 *     $decoded = json_decode($assignTo, true);
 *     if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
 *         $assignTo = $decoded;
 *     }
 * }
 * $adminIds = is_array($assignTo) ? $assignTo : [$assignTo];
 * $adminIds = array_map('intval', $adminIds);
 * $adminIds = array_filter($adminIds); // Remove zeros
 * ```
 *
 * Supports `assign_to` as a bare number, an array of numbers, or a
 * JSON-encoded string of either shape — all three normalize to the same
 * `number[]` of positive admin ids (zeros filtered out, matching PHP's
 * `array_filter()` with no callback: it drops every falsy element).
 */
export function normalizeAssignTo(assignTo: unknown): number[] {
  let value: unknown = assignTo;

  if (typeof value === 'string') {
    try {
      const decoded: unknown = JSON.parse(value);
      if (Array.isArray(decoded)) {
        value = decoded;
      }
    } catch {
      // json_decode() failure -> json_last_error() !== JSON_ERROR_NONE -> $assignTo left as the original string.
    }
  }

  const raw: unknown[] = Array.isArray(value) ? value : [value];
  return raw.map(phpIntCast).filter((id) => id !== 0);
}

/** Entry point used by route.ts before any DB access: mirrors the `!$userId` / `empty($assignTo)` / `empty($adminIds)` guards. */
export interface ParsedAssignRequest {
  userId: number;
  adminIds: number[];
}

export type ParseAssignRequestResult =
  | { ok: true; value: ParsedAssignRequest }
  | { ok: false; error: string };

export function parseAssignRequest(body: Record<string, unknown>): ParseAssignRequestResult {
  const userId = phpIntCast(body.user_id ?? 0);
  const assignToRaw = body.assign_to ?? null;

  if (!userId) {
    return { ok: false, error: 'User ID is required' };
  }
  if (phpEmpty(assignToRaw)) {
    return { ok: false, error: 'Admin ID(s) to assign is required' };
  }

  const adminIds = normalizeAssignTo(assignToRaw);
  if (adminIds.length === 0) {
    return { ok: false, error: 'Valid admin ID(s) required' };
  }

  return { ok: true, value: { userId, adminIds } };
}

interface IdRow {
  id: number;
}

export async function assignConversation(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  userId: number,
  adminIds: number[],
  assignedBy: number | null
): Promise<AssignConversationResult> {
  // InboxService.php lines 1030-1035: `SELECT id FROM users WHERE id = ? AND line_account_id = ?`.
  const userRows = await sql<IdRow>`
    SELECT id FROM users WHERE id = ${userId} AND line_account_id = ${lineAccountId}
  `.execute(db);
  if (userRows.rows.length === 0) {
    return { success: false, error: 'User not found', code: 'USER_NOT_FOUND' };
  }

  // InboxService.php lines 1038-1045: per-admin existence check, `admin_users`
  // scoped by id only — no line_account_id filter (unlike the users check
  // above). See module doc for the admin_users schema-drift finding this
  // raw-sql call depends on, and why a thrown "table doesn't exist" is left
  // to propagate rather than being caught here.
  for (const adminId of adminIds) {
    const adminRows = await sql<IdRow>`SELECT id FROM admin_users WHERE id = ${adminId}`.execute(db);
    if (adminRows.rows.length === 0) {
      return { success: false, error: `Admin ID ${adminId} not found`, code: 'ADMIN_NOT_FOUND' };
    }
  }

  // InboxService.php lines 1047-1065: one conversation_multi_assignees row
  // per admin id, ON DUPLICATE KEY UPDATE (see module doc — dedup is
  // schema-drift-broken on the committed tenant template, written literally
  // anyway). `$assignedBy ?? $adminId` is evaluated PER admin id, not once.
  //
  // NOTE on ASSIGN_FAILED: PHP checks `$stmt->execute()`'s boolean return
  // value per iteration and short-circuits with 'ASSIGN_FAILED' on a false
  // return. Kysely's mysql2-backed driver throws on a failed query rather
  // than resolving to a falsy value (this repo's PDO connection is
  // configured with PDO::ERRMODE_EXCEPTION too — modules/Core/Database.php
  // — so `$stmt->execute()` returning false without throwing is already
  // effectively unreachable on the PHP side as well). The 'ASSIGN_FAILED'
  // code is kept in this function's return type — and mapped to HTTP 500 in
  // route.ts — for literal parity with InboxService.php's contract, but a
  // real insert failure surfaces as a thrown exception here, caught by
  // route.ts's outer try/catch instead of this branch.
  for (const adminId of adminIds) {
    const effectiveAssignedBy = assignedBy ?? adminId;
    await db
      .insertInto('conversation_multi_assignees')
      .values({
        user_id: userId,
        admin_id: adminId,
        assigned_by: effectiveAssignedBy,
        assigned_at: sql<Date>`NOW()`,
        status: 'active',
      })
      .onDuplicateKeyUpdate({
        assigned_by: effectiveAssignedBy,
        assigned_at: sql<Date>`NOW()`,
        status: 'active',
      })
      .execute();
  }

  // InboxService.php lines 1068-1080: legacy conversation_assignments row,
  // `adminIds[0]` ONLY — even when multiple admins were assigned above.
  const firstAdminId = adminIds[0];
  const legacyAssignedBy = assignedBy ?? firstAdminId;
  await db
    .insertInto('conversation_assignments')
    .values({
      user_id: userId,
      assigned_to: firstAdminId,
      assigned_by: legacyAssignedBy,
      assigned_at: sql<Date>`NOW()`,
      status: 'active',
    })
    .onDuplicateKeyUpdate({
      assigned_to: firstAdminId,
      assigned_by: legacyAssignedBy,
      assigned_at: sql<Date>`NOW()`,
      status: 'active',
    })
    .execute();

  return { success: true };
}
