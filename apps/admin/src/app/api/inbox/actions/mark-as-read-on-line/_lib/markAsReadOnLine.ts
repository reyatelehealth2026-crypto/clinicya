import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { lineMarkAsRead, type LineMarkAsReadOptions, type LineMarkAsReadResult } from './lineMarkAsRead';

/**
 * markAsReadOnLine.ts — literal port of api/inbox-v2.php's
 * `case 'mark_as_read_on_line':` (lines 2601-2679), backed by
 * classes/LineAPI.php::markAsRead() (now `lineMarkAsRead.ts` in this same
 * directory — see that file's module doc for why it's not `@reya/line`).
 *
 * ```php
 * $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
 * $stmt->execute([$lineAccountId]);
 * $account = $stmt->fetch(PDO::FETCH_ASSOC);
 * if (!$account || empty($account['channel_access_token'])) {
 *     sendError('LINE account not configured');
 * }
 *
 * $stmt = $db->prepare("
 *     SELECT id, mark_as_read_token
 *     FROM messages
 *     WHERE user_id = ?
 *     AND line_account_id = ?
 *     AND direction = 'incoming'
 *     AND mark_as_read_token IS NOT NULL
 *     AND (is_read_on_line = 0 OR is_read_on_line IS NULL)
 *     ORDER BY created_at DESC
 *     LIMIT 10
 * ");
 * $stmt->execute([$userId, $lineAccountId]);
 * $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *
 * if (empty($messages)) {
 *     $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND line_account_id = ? AND direction = 'incoming' AND is_read = 0");
 *     $stmt->execute([$userId, $lineAccountId]);
 *     sendResponse(['success' => true, 'message' => 'No messages with markAsReadToken to process', 'marked_count' => 0]);
 *     // sendResponse() calls exit() — true early return, LINE API never called.
 * }
 *
 * $latestMessage = $messages[0]; // ORDER BY created_at DESC -> newest first
 * $result = $lineApi->markAsRead($latestMessage['mark_as_read_token']);
 *
 * $markedCount = 0;
 * $errors = [];
 * if ($result['success']) {
 *     $messageIds = array_column($messages, 'id'); // ALL fetched ids, not just the newest
 *     $stmt = $db->prepare("UPDATE messages SET is_read = 1, is_read_on_line = 1 WHERE id IN ($placeholders)");
 *     $stmt->execute($messageIds);
 *     $markedCount = count($messageIds);
 * } else {
 *     $errors[] = $result['error'] ?? 'Unknown error';
 *     $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND line_account_id = ? AND direction = 'incoming' AND is_read = 0");
 *     $stmt->execute([$userId, $lineAccountId]);
 * }
 *
 * sendResponse([
 *     'success' => true,
 *     'message' => 'Messages marked as read',
 *     'marked_count' => $markedCount,
 *     'line_api_success' => empty($errors),
 *     'errors' => $errors
 * ]);
 * ```
 *
 * CRITICAL RESPONSE QUIRK, PRESERVED EXACTLY (intentional graceful
 * degradation in the PHP source, not a bug): the final response is ALWAYS
 * `{success: true, ...}` regardless of whether the LINE API call itself
 * succeeded — `success` reflects "the local DB state is now consistent",
 * NOT "LINE was actually notified". `line_api_success` is the field a
 * caller must check for the latter. Do not change `success` to `false` on
 * a LINE-API failure.
 */

export interface MarkAsReadOnLineActionResult {
  status: number;
  body: Record<string, unknown>;
}

interface PendingMessageRow {
  id: number;
  mark_as_read_token: string;
}

interface LineAccountTokenRow {
  channel_access_token: string | null;
}

export type MarkAsReadFn = (
  markAsReadToken: string,
  options: LineMarkAsReadOptions
) => Promise<LineMarkAsReadResult>;

export async function markAsReadOnLineAction(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  userId: number,
  markAsRead: MarkAsReadFn = lineMarkAsRead
): Promise<MarkAsReadOnLineActionResult> {
  // inbox-v2.php lines 2612-2619.
  const accountRows = await sql<LineAccountTokenRow>`
    SELECT channel_access_token FROM line_accounts WHERE id = ${lineAccountId}
  `.execute(db);
  const account = accountRows.rows[0];
  if (!account || !account.channel_access_token) {
    // sendError('LINE account not configured') — default sendError status code is 400.
    return { status: 400, body: { success: false, error: 'LINE account not configured' } };
  }

  // inbox-v2.php lines 2622-2635. "Don't check is_read because local read
  // status is updated before this API runs" (PHP's own comment) — hence no
  // `is_read` filter here, only `is_read_on_line`.
  const pendingRows = await sql<PendingMessageRow>`
    SELECT id, mark_as_read_token
    FROM messages
    WHERE user_id = ${userId}
    AND line_account_id = ${lineAccountId}
    AND direction = 'incoming'
    AND mark_as_read_token IS NOT NULL
    AND (is_read_on_line = 0 OR is_read_on_line IS NULL)
    ORDER BY created_at DESC
    LIMIT 10
  `.execute(db);
  const messages = pendingRows.rows;

  if (messages.length === 0) {
    // inbox-v2.php lines 2638-2649: PHP's sendResponse() here calls exit()
    // immediately — a true early return. The LINE API is NEVER invoked in
    // this branch.
    await db
      .updateTable('messages')
      .set({ is_read: 1 })
      .where('user_id', '=', userId)
      .where('line_account_id', '=', lineAccountId)
      .where('direction', '=', 'incoming')
      .where('is_read', '=', 0)
      .execute();

    return {
      status: 200,
      body: { success: true, message: 'No messages with markAsReadToken to process', marked_count: 0 },
    };
  }

  // inbox-v2.php lines 2655-2660: "only need to mark the latest one... /
  // According to LINE API, marking one message marks all previous messages
  // as read". The SELECT above is ORDER BY created_at DESC, so messages[0]
  // is the newest. Non-null assertion is safe: the `messages.length === 0`
  // branch above already returned, so this array has at least one element
  // (TS's noUncheckedIndexedAccess can't infer that on its own).
  const latestMessage = messages[0]!;
  const result = await markAsRead(latestMessage.mark_as_read_token, {
    channelAccessToken: account.channel_access_token,
  });

  let markedCount = 0;
  const errors: string[] = [];

  if (result.success) {
    // inbox-v2.php lines 2668-2672: ALL fetched message ids get
    // is_read_on_line = 1, not just the newest one that was actually sent
    // to the LINE API.
    const messageIds = messages.map((m) => m.id);
    await db
      .updateTable('messages')
      .set({ is_read: 1, is_read_on_line: 1 })
      .where('id', 'in', messageIds)
      .execute();
    markedCount = messageIds.length;
  } else {
    errors.push(result.error ?? 'Unknown error');
    // inbox-v2.php lines 2675-2677: best-effort local fallback — still mark
    // as read locally even though the LINE API call itself failed.
    await db
      .updateTable('messages')
      .set({ is_read: 1 })
      .where('user_id', '=', userId)
      .where('line_account_id', '=', lineAccountId)
      .where('direction', '=', 'incoming')
      .where('is_read', '=', 0)
      .execute();
  }

  // inbox-v2.php lines 2679-2685: see this module's doc comment above for
  // the `success: true` regardless-of-LINE-outcome quirk — preserved as-is.
  return {
    status: 200,
    body: {
      success: true,
      message: 'Messages marked as read',
      marked_count: markedCount,
      line_api_success: errors.length === 0,
      errors,
    },
  };
}
