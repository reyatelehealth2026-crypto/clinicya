import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * getAssignment.ts — literal port of `classes/InboxService.php`'s
 * `getAssignment()` (lines 1157-1189), as driven by api/inbox-v2.php's
 * `case 'get_assignment':` (lines ~2565-2588).
 *
 * ```php
 * public function getAssignment(int $userId): array
 * {
 *     $sql = "
 *         SELECT
 *             cma.admin_id,
 *             cma.assigned_by,
 *             cma.assigned_at,
 *             cma.status,
 *             cma.resolved_at,
 *             au.username,
 *             au.display_name
 *         FROM conversation_multi_assignees cma
 *         LEFT JOIN admin_users au ON cma.admin_id = au.id
 *         WHERE cma.user_id = ?
 *         ORDER BY cma.assigned_at DESC
 *     ";
 *     $stmt = $this->db->prepare($sql);
 *     $stmt->execute([$userId]);
 *     $assignees = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *
 *     if (empty($assignees)) {
 *         return ['user_id' => $userId, 'assignees' => [], 'is_assigned' => false];
 *     }
 *
 *     return [
 *         'user_id' => $userId,
 *         'assignees' => $assignees,
 *         'is_assigned' => true,
 *         'status' => $assignees[0]['status'] ?? 'active',
 *         'assigned_at' => $assignees[0]['assigned_at'] ?? null,
 *     ];
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED FINDING — `admin_users` has no Kysely interface / does not
 * exist in the committed tenant template
 * ═══════════════════════════════════════════════════════════════════════
 * Same root cause as `../../get-admins/_lib/getAdmins.ts` and
 * `../../assign-conversation/_lib/assignConversation.ts`'s own findings
 * (itself the third+fourth confirmed sighting after (tenant)/settings/_lib/
 * shop-tax-queries.ts and consent-queries.ts): `admin_users` is a
 * PLATFORM-level table (database/migration_2026-05-25_tenant_template.sql's
 * own header), absent from any tenant DB built from the committed template
 * and from packages/db/src/generated/tenant-db.d.ts. The
 * `LEFT JOIN admin_users au ON cma.admin_id = au.id` below is therefore
 * issued via a raw `sql` tagged template — there is no
 * `.selectFrom('conversation_multi_assignees').leftJoin('admin_users', ...)`
 * type-safe path onto `admin_users`. `InboxService::getAssignment()`
 * performs NO try/catch of its own — a missing-table exception bubbles
 * straight out, caught only by `case 'get_assignment':`'s outer try/catch,
 * becoming `sendError('Failed to get assignment: ' . $e->getMessage())`
 * (HTTP 400). Reproduced literally: this function does not catch that
 * throw either — it propagates for route.ts's own try/catch to convert.
 *
 * `empty($assignees)` on an empty `fetchAll()` result is simply "zero
 * rows" — reproduced as `assignees.length === 0`, returning
 * `{user_id, assignees: [], is_assigned: false}` (NOT an error — a
 * never-assigned conversation is a perfectly valid, common state).
 */

export interface AssigneeRow {
  admin_id: number;
  assigned_by: number | null;
  assigned_at: Date | string | null;
  status: 'active' | 'resolved' | null;
  resolved_at: Date | string | null;
  username: string | null;
  display_name: string | null;
}

export interface AssignmentView {
  user_id: number;
  assignees: AssigneeRow[];
  is_assigned: boolean;
  status?: AssigneeRow['status'] | 'active';
  assigned_at?: AssigneeRow['assigned_at'] | null;
}

export async function getAssignment(db: Kysely<TenantDB>, userId: number): Promise<AssignmentView> {
  const result = await sql<AssigneeRow>`
    SELECT
      cma.admin_id,
      cma.assigned_by,
      cma.assigned_at,
      cma.status,
      cma.resolved_at,
      au.username,
      au.display_name
    FROM conversation_multi_assignees cma
    LEFT JOIN admin_users au ON cma.admin_id = au.id
    WHERE cma.user_id = ${userId}
    ORDER BY cma.assigned_at DESC
  `.execute(db);

  const assignees = result.rows;

  if (assignees.length === 0) {
    return { user_id: userId, assignees: [], is_assigned: false };
  }

  return {
    user_id: userId,
    assignees,
    is_assigned: true,
    status: assignees[0]?.status ?? 'active',
    assigned_at: assignees[0]?.assigned_at ?? null,
  };
}
