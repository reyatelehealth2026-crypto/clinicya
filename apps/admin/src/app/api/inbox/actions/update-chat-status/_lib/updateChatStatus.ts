import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * updateChatStatus.ts — literal port of api/inbox-v2.php's
 * `case 'update_chat_status':` (lines ~2362-2406):
 *
 * ```php
 * case 'update_chat_status':
 *     if ($method !== 'POST') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     $userId = (int) ($_POST['user_id'] ?? 0);
 *     $status = trim($_POST['status'] ?? '');
 *
 *     $allowedStatuses = ['', 'pending', 'completed', 'shipping', 'tracking', 'billing'];
 *
 *     if (!$userId) {
 *         sendError('User ID is required');
 *     }
 *
 *     if (!in_array($status, $allowedStatuses)) {
 *         sendError('Invalid status');
 *     }
 *
 *     try {
 *         $stmt = $db->prepare("SELECT chat_status FROM users WHERE id = ?");
 *         $stmt->execute([$userId]);
 *         $oldStatus = $stmt->fetchColumn();
 *
 *         $stmt = $db->prepare("UPDATE users SET chat_status = ? WHERE id = ?");
 *         $stmt->execute([$status ?: null, $userId]);
 *
 *         try {
 *             $adminId = $_SESSION['admin_user']['id'] ?? null;
 *             $stmt = $db->prepare("INSERT INTO chat_status_history (user_id, line_account_id, old_status, new_status, changed_by) VALUES (?, ?, ?, ?, ?)");
 *             $stmt->execute([$userId, $lineAccountId, $oldStatus, $status ?: null, $adminId]);
 *         } catch (Exception $e) {
 *             logInboxApiException($e, 'catch');
 *             // History table might not exist yet, ignore
 *         }
 *
 *         sendResponse(['success' => true, 'message' => 'Chat status updated successfully']);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to update chat status: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * This module owns exactly the SELECT/UPDATE/INSERT sequence — validation
 * (`user_id`/whitelist) and the outer response envelope live in route.ts,
 * matching the split established by every other action in this family.
 *
 * The `chat_status_history` INSERT is wrapped in the SAME inner
 * try/catch-and-swallow PHP has ("History table might not exist yet,
 * ignore") — a failure there must NOT propagate to the caller and must NOT
 * flip the outer response into an error, only the SELECT/UPDATE pair above
 * it does that. `updateChatStatus()` itself therefore never throws because
 * of the history insert; only a genuine SELECT/UPDATE failure propagates
 * out of this function for route.ts's own try/catch to turn into the 400
 * `'Failed to update chat status: ...'` response.
 *
 * `chat_status_history` columns (`user_id`, `line_account_id`, `old_status`,
 * `new_status`, `changed_by`) are all confirmed present on `ChatStatusHistory`
 * in `packages/db/src/generated/tenant-db.d.ts` — no schema drift, no fix
 * needed here.
 */

export interface UpdateChatStatusParams {
  userId: number;
  lineAccountId: number;
  /** Already `status || null` — PHP's `$status ?: null` (empty string clears the status). Resolved by route.ts. */
  newStatus: string | null;
  /** `TenantSession.adminUserId` — PHP re-reads `$_SESSION['admin_user']['id']` here (a different session key than the file-level `$adminId`), but both resolve to the same `admin_users.id` in practice; see route.ts's own doc. */
  changedBy: number;
}

export async function updateChatStatus(db: Kysely<TenantDB>, params: UpdateChatStatusParams): Promise<void> {
  const { userId, lineAccountId, newStatus, changedBy } = params;

  const oldStatusRow = await db.selectFrom('users').select('chat_status').where('id', '=', userId).executeTakeFirst();
  const oldStatus = oldStatusRow?.chat_status ?? null;

  await db.updateTable('users').set({ chat_status: newStatus }).where('id', '=', userId).execute();

  try {
    await db
      .insertInto('chat_status_history')
      .values({
        user_id: userId,
        line_account_id: lineAccountId,
        old_status: oldStatus,
        new_status: newStatus,
        changed_by: changedBy,
      })
      .execute();
  } catch {
    // "History table might not exist yet, ignore" — PHP's own inner catch/swallow. See module doc.
  }
}
