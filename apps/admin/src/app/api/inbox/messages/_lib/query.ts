import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * query.ts — TypeScript port of TWO genuinely different PHP queries against
 * `messages`; do not conflate them (see the brief):
 *
 *  1. `getMessagesCursor()` — id-based keyset pagination, limit+1/has_more
 *     pattern. Ports `classes/InboxService.php::getMessagesCursor()` (lines
 *     769-831) exactly, including its column list, its "fetch one extra row
 *     to detect more" trick, and its cap `max(1, min(100, $limit))`. Backs
 *     both `api/inbox-v2.php`'s `action=getMessages`/`get_messages` (lines
 *     2789-2837) — wired to the Route Handler at `../route.ts` — and this
 *     port's own "load older messages" client trigger.
 *
 *     NOTE (deliberately NOT replicated at this layer): `getMessagesCursor()`
 *     never filters by `line_account_id`, unlike the sibling offset-based
 *     `InboxService::getMessages()` (lines 714-757) which does
 *     (`WHERE user_id = ? AND line_account_id = ?`). This asymmetry exists
 *     in the literal PHP source — `getMessagesCursor` is the one actually
 *     wired to the API action, so this is the literal behavior being
 *     preserved ("preserve behavior, not markup"), not a bug introduced by
 *     this port.
 *
 *  2. `getInitialMessages()` — flat "latest N messages, ascending" read with
 *     NO cursor math at all. Ports the SSR query at inbox-v2.php lines
 *     1124-1129 (`SELECT * FROM (SELECT * FROM messages WHERE user_id = ?
 *     ORDER BY id DESC LIMIT 300) recent ORDER BY id ASC`), used ONLY by
 *     `(tenant)/inbox/[userId]/page.tsx`'s first paint. The nested subquery
 *     is preserved literally (not simplified to a single `ORDER BY id ASC
 *     LIMIT 300`, which would return the OLDEST 300 messages instead of the
 *     newest 300 — a materially different, wrong result) — MySQL must apply
 *     the DESC + LIMIT first, then the outer query re-sorts ASC for display
 *     order.
 *
 * Both queries select the same 8 columns PHP's PDO fetch touches
 * (id/user_id/direction/message_type/content/is_read/sent_by/created_at) —
 * NOT `SELECT *` for the cursor query (InboxService.php lines 779-788 name
 * the columns explicitly) and a literal `SELECT *` subquery for the SSR one
 * (inbox-v2.php line 1127), whose outer projection is still just these 8
 * columns since that's all `messages` conceptually needs for chat-thread
 * rendering — the generated `Messages` table type
 * (packages/db/src/generated/tenant-db.d.ts) has additional columns
 * (account_id, media_url, metadata, platform, reply_to_id, reply_token,
 * line_account_id, updated_at) neither PHP query path ever reads.
 */

export const INITIAL_MESSAGES_LIMIT = 300;

export interface MessageRow {
  id: number;
  user_id: number | null;
  direction: 'incoming' | 'outgoing';
  message_type: string | null;
  content: string | null;
  is_read: number | null;
  sent_by: string | null;
  created_at: Date;
}

/** JSON-serializable form of MessageRow — `created_at` as a MySQL `YYYY-MM-DD HH:MM:SS` string, matching PDO's raw fetch (NOT a `Z`-suffixed ISO string — see @reya-internal fakeKyselyDb.ts's `sqlDate()` doc for why this distinction has bitten this codebase before). */
export interface MessageRowJson extends Omit<MessageRow, 'created_at'> {
  created_at: string;
}

export interface MessagesCursorResult {
  messages: MessageRowJson[];
  next_cursor: string | null;
  has_more: boolean;
  count: number;
}

const MESSAGE_COLUMNS = sql.raw(
  'id, user_id, direction, message_type, content, is_read, sent_by, created_at'
);

/** Mirrors PHP's `(int)$value` cast on a raw query-string value: parse the leading integer run, default 0 for anything that doesn't start with one (including `''`/non-numeric garbage). */
export function phpIntCast(value: string): number {
  const match = /^\s*[+-]?\d+/.exec(value);
  return match ? Number.parseInt(match[0], 10) : 0;
}

/** `YYYY-MM-DD HH:MM:SS` in whatever wall-clock the Date object represents (mysql2 hydrates DATETIME columns using the process's local time zone, which production/CI pin to Asia/Bangkok — see CLAUDE.md — matching PDO's unconverted string read). */
function toMysqlDateTimeString(value: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

function toJson(row: MessageRow): MessageRowJson {
  return { ...row, created_at: toMysqlDateTimeString(row.created_at) };
}

/**
 * Ports `InboxService::getMessagesCursor()` (classes/InboxService.php lines
 * 769-831). `cursor` is the string form of a message id (or `null` for the
 * first/newest page) — `undefined` is NOT a valid input at this layer
 * (callers must resolve "cursor query param absent" to `null` themselves,
 * matching PHP's `$_GET['cursor'] ?? null`; a present-but-empty string is a
 * distinct, legal input here, matching PHP's `isset()`-based `??`).
 */
export async function getMessagesCursor(
  db: Kysely<TenantDB>,
  userId: number,
  cursor: string | null,
  limit: number
): Promise<MessagesCursorResult> {
  const clampedLimit = Math.max(1, Math.min(100, limit)); // InboxService.php line 774

  const rows =
    cursor !== null
      ? (
          await sql<MessageRow>`
            SELECT ${MESSAGE_COLUMNS}
            FROM messages
            WHERE user_id = ${userId} AND id < ${phpIntCast(cursor)}
            ORDER BY id DESC
            LIMIT ${clampedLimit + 1}
          `.execute(db)
        ).rows
      : (
          await sql<MessageRow>`
            SELECT ${MESSAGE_COLUMNS}
            FROM messages
            WHERE user_id = ${userId}
            ORDER BY id DESC
            LIMIT ${clampedLimit + 1}
          `.execute(db)
        ).rows;

  const hasMore = rows.length > clampedLimit;
  const kept = hasMore ? rows.slice(0, clampedLimit) : rows;
  const nextCursor = hasMore ? String(kept[kept.length - 1]?.id) : null;

  // Reverse DESC-fetched rows to ascending (oldest-first, chat order) — mirrors `array_reverse($messages)`.
  const messages = [...kept].reverse().map(toJson);

  return {
    messages,
    next_cursor: nextCursor,
    has_more: hasMore,
    count: messages.length,
  };
}

/**
 * Ports the SSR read at inbox-v2.php lines 1124-1129 — the latest
 * `INITIAL_MESSAGES_LIMIT` (300) messages for a conversation, ascending. No
 * cursor, no has_more — `(tenant)/inbox/[userId]/page.tsx`'s "load older"
 * affordance always starts its OWN cursor chain from the oldest id in this
 * result (see LoadOlderMessagesButton.tsx), independent of this function.
 */
export async function getInitialMessages(
  db: Kysely<TenantDB>,
  userId: number,
  limit: number = INITIAL_MESSAGES_LIMIT
): Promise<MessageRow[]> {
  const result = await sql<MessageRow>`
    SELECT * FROM (
      SELECT ${MESSAGE_COLUMNS}
      FROM messages
      WHERE user_id = ${userId}
      ORDER BY id DESC
      LIMIT ${limit}
    ) recent
    ORDER BY id ASC
  `.execute(db);
  return result.rows;
}
