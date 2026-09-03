import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * markAllRead.ts — the single bulk UPDATE behind
 * `case 'mark_all_read':` (api/inbox-v2.php lines 2414-2431):
 *
 * ```php
 * $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE line_account_id = ? AND direction = 'incoming' AND is_read = 0");
 * $stmt->execute([$lineAccountId]);
 * $affected = $stmt->rowCount();
 * ```
 *
 * Returns the affected-row count (PDOStatement::rowCount()) so route.ts can
 * interpolate it into the literal `"Marked {$affected} messages as read"`
 * response string — mysql2's OkPacket `affectedRows` (surfaced by Kysely's
 * MysqlDriver as `UpdateResult.numUpdatedRows`) is the exact TS equivalent
 * of PDO's rowCount() for an UPDATE.
 */
export async function markAllReadAction(db: Kysely<TenantDB>, lineAccountId: number): Promise<number> {
  const result = await db
    .updateTable('messages')
    .set({ is_read: 1 })
    .where('line_account_id', '=', lineAccountId)
    .where('direction', '=', 'incoming')
    .where('is_read', '=', 0)
    .executeTakeFirst();

  return Number(result.numUpdatedRows ?? 0);
}
