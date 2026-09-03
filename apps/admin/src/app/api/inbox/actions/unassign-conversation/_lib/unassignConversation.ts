import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * unassignConversation.ts — literal port of `classes/InboxService.php`'s
 * `removeAssignee()` and `unassignConversation()` (lines 1084-1113), as
 * driven by api/inbox-v2.php's `case 'unassign_conversation':` (lines
 * ~2529-2559).
 *
 * ```php
 * public function removeAssignee(int $userId, int $adminId): bool
 * {
 *     $sql = "DELETE FROM conversation_multi_assignees WHERE user_id = ? AND admin_id = ?";
 *     $stmt = $this->db->prepare($sql);
 *     return $stmt->execute([$userId, $adminId]);
 * }
 *
 * public function unassignConversation(int $userId): bool
 * {
 *     $sql = "DELETE FROM conversation_multi_assignees WHERE user_id = ?";
 *     $stmt = $this->db->prepare($sql);
 *     $result = $stmt->execute([$userId]);
 *
 *     $legacySql = "DELETE FROM conversation_assignments WHERE user_id = ?";
 *     $legacyStmt = $this->db->prepare($legacySql);
 *     $legacyStmt->execute([$userId]);
 *
 *     return $result;
 * }
 * ```
 *
 * `removeAssignee()` is LEGACY-TABLE-BLIND: it deletes only the one
 * matching row from `conversation_multi_assignees` and never touches
 * `conversation_assignments` at all — even when the admin being removed is
 * the one recorded in the legacy table's `assigned_to` column. This is
 * reproduced literally (not "fixed" to also clean up the legacy row) —
 * `route.test.ts` asserts `conversation_assignments` is left untouched by
 * this branch.
 *
 * `unassignConversation()` (the "remove all" path) DOES clear both tables —
 * `conversation_multi_assignees` (every row for the user, not scoped to a
 * specific admin) AND `conversation_assignments` (its one row for the
 * user, given the legacy table's real `uk_user (user_id)` unique
 * constraint in production — see `../../assign-conversation/_lib/
 * assignConversation.ts`'s own schema-drift finding doc for how that key
 * differs on the committed tenant template).
 *
 * Both tables (`conversation_multi_assignees`, `conversation_assignments`)
 * DO have generated Kysely interfaces
 * (packages/db/src/generated/tenant-db.d.ts) — unlike `admin_users`, these
 * two deletes are plain type-safe `.deleteFrom()` calls, no raw `sql`
 * tagged template needed.
 *
 * Neither PHP method's DB error path is distinguishable from success at
 * this layer: `$stmt->execute()` returns a bool, but this repo's PDO
 * connection is configured with `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
 * (modules/Core/Database.php), so a real failure throws rather than
 * resolving `false` — matching Kysely's own throw-on-failure delete
 * semantics. A thrown error here propagates out of both functions for the
 * caller (route.ts) to convert into a clean JSON error, mirroring
 * inbox-v2.php's own `case 'unassign_conversation':` try/catch ->
 * `sendError('Failed to unassign conversation: ' . $e->getMessage())`.
 */

/** InboxService.php `removeAssignee()` — deletes exactly one (user_id, admin_id) row, conversation_assignments untouched. */
export async function removeAssignee(db: Kysely<TenantDB>, userId: number, adminId: number): Promise<void> {
  await db.deleteFrom('conversation_multi_assignees').where('user_id', '=', userId).where('admin_id', '=', adminId).execute();
}

/** InboxService.php `unassignConversation()` — clears every conversation_multi_assignees row for the user AND the legacy conversation_assignments row. */
export async function unassignConversation(db: Kysely<TenantDB>, userId: number): Promise<void> {
  await db.deleteFrom('conversation_multi_assignees').where('user_id', '=', userId).execute();
  await db.deleteFrom('conversation_assignments').where('user_id', '=', userId).execute();
}
