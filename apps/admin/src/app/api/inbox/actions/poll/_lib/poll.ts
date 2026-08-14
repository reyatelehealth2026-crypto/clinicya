import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * poll.ts — literal port of `classes/InboxService.php::pollUpdates()`
 * (lines 842-909), as driven by `api/inbox-v2.php`'s `case 'poll':` (lines
 * ~172-193):
 *
 * ```php
 * public function pollUpdates(int $accountId, int $since): array
 * {
 *     $sql = "
 *         SELECT
 *             m.id,
 *             m.user_id,
 *             m.direction,
 *             m.message_type,
 *             m.content,
 *             m.is_read,
 *             m.created_at,
 *             u.display_name,
 *             u.picture_url,
 *             u.last_interaction
 *         FROM messages m
 *         JOIN users u ON u.id = m.user_id
 *         WHERE u.line_account_id = ?
 *         AND m.created_at > FROM_UNIXTIME(?)
 *         ORDER BY m.created_at ASC
 *     ";
 *
 *     $stmt = $this->db->prepare($sql);
 *     $stmt->execute([$accountId, $since]);
 *     $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *
 *     $updatedConversations = [];
 *     $seenUsers = [];
 *
 *     foreach ($newMessages as $message) {
 *         $userId = $message['user_id'];
 *
 *         if (!in_array($userId, $seenUsers)) {
 *             $seenUsers[] = $userId;
 *
 *             $unreadSql = "
 *                 SELECT COUNT(*)
 *                 FROM messages
 *                 WHERE user_id = ?
 *                 AND direction = 'incoming'
 *                 AND is_read = 0
 *             ";
 *             $unreadStmt = $this->db->prepare($unreadSql);
 *             $unreadStmt->execute([$userId]);
 *             $unreadCount = (int) $unreadStmt->fetchColumn();
 *
 *             $updatedConversations[] = [
 *                 'user_id' => $userId,
 *                 'display_name' => $message['display_name'],
 *                 'picture_url' => $message['picture_url'],
 *                 'last_message_at' => $message['last_interaction'],
 *                 'last_message_preview' => substr($message['content'], 0, 100),
 *                 'unread_count' => $unreadCount
 *             ];
 *         }
 *     }
 *
 *     return [
 *         'new_messages' => $newMessages,
 *         'updated_conversations' => $updatedConversations,
 *         'count' => count($newMessages)
 *     ];
 * }
 * ```
 *
 * `count` IS DROPPED from this function's return type — `case 'poll':`
 * (api/inbox-v2.php lines 184-192) reads only `$updates['new_messages']`
 * and `$updates['updated_conversations']` off `pollUpdates()`'s return
 * value; `$updates['count']` is computed by the PHP method but never read
 * anywhere in the case body, so it is not ported here (per this batch's
 * brief).
 *
 * `last_message_at` is `message.last_interaction` — the value carried on
 * the MESSAGE ROW from the `JOIN users u ON u.id = m.user_id` (i.e.
 * `u.last_interaction` as of the moment this specific message's joined row
 * was fetched), NOT a fresh per-conversation `users` lookup. Every
 * new-message row for a given `user_id` necessarily carries the identical
 * `last_interaction` value (it's the same joined `users` row every time),
 * so which particular occurrence "wins" for a given `user_id` is moot —
 * first-seen is used only because that is what the PHP loop (and this port)
 * naturally does.
 *
 * The unread-count query is deliberately UN-scoped by `line_account_id`
 * (PHP's own `$unreadSql` has no such filter) — preserved literally, not
 * "fixed", matching this codebase's stated policy of replicating quirks
 * rather than silently correcting them (CLAUDE.md; see also
 * `../../../conversations/_lib/query.ts`'s own module doc for the identical
 * "preserve behavior, not markup" framing on a different endpoint).
 *
 * `COUNT(*)` is aliased to `unread_count` purely for a stable TS key — PHP's
 * `fetchColumn()` reads the first column of the first row regardless of its
 * name, so this alias does not change the query's meaning or any
 * PHP-observable behavior (same precedent as
 * `../../customer-crm/_lib/customerCrm.ts`'s `SELECT COUNT(*) as cnt`).
 *
 * `m.created_at`/`u.last_interaction` are formatted to MySQL
 * `YYYY-MM-DD HH:MM:SS` wall-clock strings (matching PDO's raw string
 * fetch) — same `toMysqlDateTimeString()` convention already established at
 * `../../../messages/_lib/query.ts` and every other action in this family
 * that surfaces a DATETIME column in JSON.
 */

export interface NewMessageRow {
  id: number;
  user_id: number | null;
  direction: 'incoming' | 'outgoing';
  message_type: string | null;
  content: string | null;
  is_read: number | null;
  created_at: Date;
  display_name: string | null;
  picture_url: string | null;
  last_interaction: Date | null;
}

/** JSON-serializable form of NewMessageRow — DATETIME columns as MySQL `YYYY-MM-DD HH:MM:SS` strings, matching PDO's raw fetch. */
export interface NewMessageRowJson extends Omit<NewMessageRow, 'created_at' | 'last_interaction'> {
  created_at: string;
  last_interaction: string | null;
}

export interface UpdatedConversationRow {
  user_id: number;
  display_name: string | null;
  picture_url: string | null;
  last_message_at: string | null;
  last_message_preview: string;
  unread_count: number;
}

export interface PollUpdatesResult {
  new_messages: NewMessageRowJson[];
  updated_conversations: UpdatedConversationRow[];
}

/** `YYYY-MM-DD HH:MM:SS` in local wall-clock — see `../../../messages/_lib/query.ts`. */
function toMysqlDateTimeString(value: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

function toJson(row: NewMessageRow): NewMessageRowJson {
  return {
    ...row,
    created_at: toMysqlDateTimeString(row.created_at),
    last_interaction: row.last_interaction ? toMysqlDateTimeString(row.last_interaction) : null,
  };
}

export async function pollUpdates(db: Kysely<TenantDB>, accountId: number, since: number): Promise<PollUpdatesResult> {
  const result = await sql<NewMessageRow>`
    SELECT
      m.id,
      m.user_id,
      m.direction,
      m.message_type,
      m.content,
      m.is_read,
      m.created_at,
      u.display_name,
      u.picture_url,
      u.last_interaction
    FROM messages m
    JOIN users u ON u.id = m.user_id
    WHERE u.line_account_id = ${accountId}
    AND m.created_at > FROM_UNIXTIME(${since})
    ORDER BY m.created_at ASC
  `.execute(db);

  const rawMessages = result.rows;
  const newMessages = rawMessages.map(toJson);

  const updatedConversations: UpdatedConversationRow[] = [];
  const seenUsers = new Set<number>();

  for (const message of rawMessages) {
    const userId = message.user_id;
    if (userId === null || seenUsers.has(userId)) {
      continue;
    }
    seenUsers.add(userId);

    const unreadResult = await sql<{ unread_count: number }>`
      SELECT COUNT(*) as unread_count
      FROM messages
      WHERE user_id = ${userId}
      AND direction = 'incoming'
      AND is_read = 0
    `.execute(db);
    const unreadCount = Number(unreadResult.rows[0]?.unread_count ?? 0);

    updatedConversations.push({
      user_id: userId,
      display_name: message.display_name,
      picture_url: message.picture_url,
      last_message_at: message.last_interaction ? toMysqlDateTimeString(message.last_interaction) : null,
      last_message_preview: (message.content ?? '').slice(0, 100),
      unread_count: unreadCount,
    });
  }

  return { new_messages: newMessages, updated_conversations: updatedConversations };
}
