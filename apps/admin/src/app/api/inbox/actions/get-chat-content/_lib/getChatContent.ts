import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * getChatContent.ts — literal port of `api/inbox-v2.php`'s
 * `case 'get_chat_content':` (lines 3057-3163). Read the full case body
 * before editing this file.
 *
 * ```php
 * case 'get_chat_content':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? $_GET['user'] ?? 0);
 *     $limit = min((int) ($_GET['limit'] ?? 50), 100);
 *     $offset = (int) ($_GET['offset'] ?? 0);
 *     if (!$userId) { sendError('User ID is required'); }
 *     try {
 *         $userStmt = $db->prepare("
 *             SELECT u.*,
 *                    (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread_count
 *             FROM users u
 *             WHERE u.id = ? AND u.line_account_id = ?
 *         ");
 *         $userStmt->execute([$userId, $lineAccountId]);
 *         $user = $userStmt->fetch(PDO::FETCH_ASSOC);
 *         if (!$user) { sendError('User not found', 404); }
 *
 *         $msgStmt = $db->prepare("
 *             SELECT id, direction, message_type, content, created_at, is_read, sent_by
 *             FROM messages WHERE user_id = ? AND line_account_id = ?
 *             ORDER BY id DESC LIMIT ? OFFSET ?
 *         ");
 *         $msgStmt->execute([$userId, $lineAccountId, $limit, $offset]);
 *         $messages = array_reverse($msgStmt->fetchAll(PDO::FETCH_ASSOC));
 *
 *         $countStmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND line_account_id = ?");
 *         $countStmt->execute([$userId, $lineAccountId]);
 *         $totalMessages = $countStmt->fetchColumn();
 *
 *         $tagsStmt = $db->prepare("
 *             SELECT ut.id, ut.name, ut.color
 *             FROM user_tag_assignments uta JOIN user_tags ut ON uta.tag_id = ut.id
 *             WHERE uta.user_id = ?
 *         ");
 *         $tagsStmt->execute([$userId]);
 *         $tags = $tagsStmt->fetchAll(PDO::FETCH_ASSOC);
 *
 *         $assignees = [];
 *         try {
 *             $tableCheck = $db->query("SHOW TABLES LIKE 'conversation_multi_assignees'");
 *             if ($tableCheck->rowCount() > 0) {
 *                 $assignStmt = $db->prepare("
 *                     SELECT cma.admin_id, au.username, au.display_name
 *                     FROM conversation_multi_assignees cma
 *                     LEFT JOIN admin_users au ON cma.admin_id = au.id
 *                     WHERE cma.user_id = ? AND cma.status = 'active'
 *                 ");
 *                 $assignStmt->execute([$userId]);
 *                 $assignees = $assignStmt->fetchAll(PDO::FETCH_ASSOC);
 *             }
 *         } catch (Exception $e) {
 *             $assignees = []; // Table doesn't exist, continue with empty assignees
 *         }
 *
 *         $updateStmt = $db->prepare("
 *             UPDATE messages SET is_read = 1
 *             WHERE user_id = ? AND line_account_id = ? AND direction = 'incoming' AND is_read = 0
 *         ");
 *         $updateStmt->execute([$userId, $lineAccountId]);
 *
 *         sendResponse(['success' => true, 'data' => [
 *             'user' => [...], 'messages' => $messages, 'total_messages' => (int) $totalMessages,
 *             'tags' => $tags, 'assignees' => $assignees,
 *             'has_more' => ($offset + count($messages)) < $totalMessages
 *         ]]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to get chat content: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SCHEMA-DRIFT DECISION — `conversation_multi_assignees` unconditional read
 * ═══════════════════════════════════════════════════════════════════════
 * PHP wraps the assignees read in a runtime `SHOW TABLES LIKE
 * 'conversation_multi_assignees'` probe + a defensive `catch` that swallows
 * ANY exception (including a genuine query failure, not just "table
 * missing") down to an empty array — a leftover from a time before this
 * table was part of the committed tenant schema. It IS on the committed
 * schema now (`packages/db/src/generated/tenant-db.d.ts`'s
 * `ConversationMultiAssignees` interface, confirmed present), so this port
 * drops BOTH the `SHOW TABLES` probe and the swallow-any-exception `catch`
 * entirely and queries the table unconditionally, exactly like every other
 * query in this function. This is the same call `get-assignment/_lib/getAssignment.ts`
 * already made for the structurally identical `SELECT ... FROM
 * conversation_multi_assignees cma LEFT JOIN admin_users au ON cma.admin_id
 * = au.id WHERE cma.user_id = ?` query that endpoint runs — see that file's
 * own "CONFIRMED FINDING" doc block for the one remaining wrinkle this
 * decision inherits: `admin_users` itself is a PLATFORM-level table absent
 * from the tenant DB template, so this `LEFT JOIN` has no
 * `.leftJoin('admin_users', ...)` type-safe Kysely path and is issued via a
 * raw `sql` tagged template. If a tenant DB genuinely lacks `admin_users`,
 * this query throws — and, matching `get-assignment.ts`'s own precedent,
 * that throw is NOT caught here either; it propagates up to `route.ts`'s
 * own try/catch, becoming `'Failed to get chat content: {message}'` at
 * HTTP 400 (the literal `case`-level catch text below — same generic shape
 * every other unexpected error in this function already produces).
 *
 * `array_reverse($msgStmt->fetchAll(...))` — the messages query fetches the
 * LATEST `$limit` rows (`ORDER BY id DESC LIMIT ? OFFSET ?`), then reverses
 * them in application code to oldest-first chat order. This is NOT the same
 * as `ORDER BY id ASC LIMIT ? OFFSET ?` (which would page from the OLDEST
 * message forward) — reproduced literally: DESC fetch, then `.reverse()`.
 *
 * `has_more = ($offset + count($messages)) < $totalMessages` — uses the
 * COUNT of messages actually RETURNED (which can be less than `$limit` on
 * the last page), not `$limit` itself.
 *
 * Marking incoming messages as read (`UPDATE messages SET is_read = 1
 * WHERE ... direction = 'incoming' AND is_read = 0`) is an unusual side
 * effect on a GET request — preserved verbatim, per the brief. It happens
 * unconditionally on every successful `get_chat_content` call, AFTER the
 * assignees read and BEFORE the response is built.
 */

export interface ChatContentResult {
  status: number;
  body: Record<string, unknown>;
}

function errorResult(status: number, error: string): ChatContentResult {
  return { status, body: { success: false, error } };
}

/** Mirrors PHP's `(int)$value` / `intval($value)` loose cast on a query-string value. */
export function phpIntCast(value: string | null): number {
  if (value === null) return 0;
  const match = /^\s*[+-]?\d+/.exec(value);
  return match ? Number.parseInt(match[0], 10) : 0;
}

/** `(int) ($_GET[key] ?? default)` — PHP's `??` short-circuits on `isset()`, not truthiness: an ABSENT query param falls back to `default`; a PRESENT-but-empty/non-numeric one is cast (usually to 0), never the default. */
export function phpIntCastOrDefault(value: string | null, defaultValue: number): number {
  return value === null ? defaultValue : phpIntCast(value);
}

/** PHP's `!empty($string)` on a nullable DB string column: false for null/undefined/''/'0'. */
function phpTruthyString(value: string | null | undefined): value is string {
  return value !== null && value !== undefined && value !== '' && value !== '0';
}

interface ChatUserRow {
  id: number;
  custom_display_name: string | null;
  display_name: string | null;
  picture_url: string | null;
  phone: string | null;
  chat_status: string | null;
  unread_count: number;
}

interface ChatMessageRow {
  id: number;
  direction: 'incoming' | 'outgoing';
  message_type: string | null;
  content: string | null;
  created_at: Date;
  is_read: number | null;
  sent_by: string | null;
}

interface ChatMessageRowJson extends Omit<ChatMessageRow, 'created_at'> {
  created_at: string;
}

interface ChatTagRow {
  id: number;
  name: string;
  color: string | null;
}

interface ChatAssigneeRow {
  admin_id: number;
  username: string | null;
  display_name: string | null;
}

/** `YYYY-MM-DD HH:MM:SS`, matching PDO's unconverted DATETIME string read — see `api/inbox/messages/_lib/query.ts`'s `toMysqlDateTimeString()` for the canonical citation of this exact convention (duplicated here per this codebase's established per-folder-helper pattern). */
function toMysqlDateTimeString(value: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

function toJsonMessage(row: ChatMessageRow): ChatMessageRowJson {
  return { ...row, created_at: toMysqlDateTimeString(row.created_at) };
}

export async function getChatContent(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  userId: number,
  limit: number,
  offset: number
): Promise<ChatContentResult> {
  // inbox-v2.php lines 3070-3078: `SELECT u.*, (subquery) as unread_count FROM users u WHERE u.id = ? AND u.line_account_id = ?`.
  // Narrowed to the columns this case body actually reads out of `$user` in its response payload
  // (id, custom_display_name, display_name, picture_url, phone, chat_status, unread_count) rather
  // than a literal `SELECT u.*`.
  const userRows = await sql<ChatUserRow>`
    SELECT u.id, u.custom_display_name, u.display_name, u.picture_url, u.phone, u.chat_status,
      (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) AS unread_count
    FROM users u
    WHERE u.id = ${userId} AND u.line_account_id = ${lineAccountId}
  `.execute(db);
  const user = userRows.rows[0];
  if (!user) {
    // inbox-v2.php line 3081: `sendError('User not found', 404)` — sendError() calls exit()
    // immediately, so this NEVER reaches the case-level `catch (Exception $e)` below; it is a
    // literal, direct 404, not something remapped by the generic error path.
    return errorResult(404, 'User not found');
  }

  // inbox-v2.php lines 3083-3092: latest `$limit` rows newest-first, then reversed to chat order
  // (oldest-first) — see this file's module doc for why this is NOT `ORDER BY id ASC`.
  const msgRows = await sql<ChatMessageRow>`
    SELECT id, direction, message_type, content, created_at, is_read, sent_by
    FROM messages
    WHERE user_id = ${userId} AND line_account_id = ${lineAccountId}
    ORDER BY id DESC
    LIMIT ${limit} OFFSET ${offset}
  `.execute(db);
  const messages = [...msgRows.rows].reverse().map(toJsonMessage);

  // inbox-v2.php lines 3094-3096.
  const countRows = await sql<{ total: number }>`
    SELECT COUNT(*) AS total FROM messages WHERE user_id = ${userId} AND line_account_id = ${lineAccountId}
  `.execute(db);
  const totalMessages = Number(countRows.rows[0]?.total ?? 0);

  // inbox-v2.php lines 3098-3105 — NOTE: no `line_account_id` filter on this join at all, matching
  // the literal PHP query (`user_tag_assignments`/`user_tags` are scoped by `uta.user_id` only).
  const tagRows = await sql<ChatTagRow>`
    SELECT ut.id, ut.name, ut.color
    FROM user_tag_assignments uta
    JOIN user_tags ut ON uta.tag_id = ut.id
    WHERE uta.user_id = ${userId}
  `.execute(db);

  // inbox-v2.php lines 3107-3121 — unconditional now; see this file's module doc "SCHEMA-DRIFT
  // DECISION" section for why the `SHOW TABLES LIKE` probe + swallow-any-exception `catch` are
  // dropped rather than ported.
  const assigneeRows = await sql<ChatAssigneeRow>`
    SELECT cma.admin_id, au.username, au.display_name
    FROM conversation_multi_assignees cma
    LEFT JOIN admin_users au ON cma.admin_id = au.id
    WHERE cma.user_id = ${userId} AND cma.status = 'active'
  `.execute(db);

  // inbox-v2.php lines 3123-3127 — side effect on a GET request, preserved verbatim (see module doc).
  await db
    .updateTable('messages')
    .set({ is_read: 1 })
    .where('user_id', '=', userId)
    .where('line_account_id', '=', lineAccountId)
    .where('direction', '=', 'incoming')
    .where('is_read', '=', 0)
    .execute();

  // inbox-v2.php lines 3129-3145.
  return {
    status: 200,
    body: {
      success: true,
      data: {
        user: {
          id: Number(user.id),
          // `!empty($user['custom_display_name']) ? ... : $user['display_name']` — prefers the
          // admin-set custom name, same as the server-rendered header.
          display_name: phpTruthyString(user.custom_display_name) ? user.custom_display_name : user.display_name,
          picture_url: user.picture_url ?? null,
          phone: user.phone ?? null,
          chat_status: user.chat_status ?? null,
          unread_count: Number(user.unread_count),
        },
        messages,
        total_messages: totalMessages,
        tags: tagRows.rows,
        assignees: assigneeRows.rows,
        has_more: offset + messages.length < totalMessages,
      },
    },
  };
}
