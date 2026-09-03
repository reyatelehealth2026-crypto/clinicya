import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * removeCustomerTag.ts — the delete behind `api/inbox-v2.php`'s
 * `case 'remove_customer_tag':` (lines 2105-2130):
 *
 * ```php
 * case 'remove_customer_tag':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $tagId = (int) ($_POST['tag_id'] ?? $body['tag_id'] ?? 0);
 *
 *     if (!$userId || !$tagId) {
 *         sendError('User ID and tag ID are required');
 *     }
 *
 *     try {
 *         $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
 *         $stmt->execute([$userId, $tagId]);
 *
 *         sendResponse(['success' => true, 'message' => 'Tag removed successfully']);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to remove tag: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * `user_tag_assignments` (columns `user_id`/`tag_id`) is confirmed present
 * in `packages/db/src/generated/tenant-db.d.ts` — a fully literal,
 * unmodified port, no schema-drift fix needed.
 *
 * The success response is sent UNCONDITIONALLY — PHP never checks
 * `$stmt->rowCount()` (a no-op `DELETE` that matches zero rows is not
 * distinguished from one that actually removed a row); this port mirrors
 * that exactly and does not inspect `numDeletedRows`.
 */
export async function removeCustomerTag(db: Kysely<TenantDB>, userId: number, tagId: number): Promise<void> {
  await db.deleteFrom('user_tag_assignments').where('user_id', '=', userId).where('tag_id', '=', tagId).execute();
}
