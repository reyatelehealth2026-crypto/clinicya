import { sql, type Expression, type Kysely, type SqlBool } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * query.ts — cursor-pagination data layer for the inbox left-pane
 * conversation list. Single source of truth for BOTH:
 *   - the Route Handler (../route.ts), which must byte-match
 *     api/inbox-v2.php's `action=getConversations`/`get_conversations`
 *     handler (lines 2706-2783), backed by
 *     classes/InboxService.php::getConversationsDelta() (lines 270-436) and
 *     its 4 batch helpers getLastMessageMetaBatch/getUnreadCountsBatch/
 *     getUserTagsBatch/getAssignedAdminIdsBatch (lines 444-562);
 *   - (tenant)/inbox/layout.tsx's SSR fetch of the initial unfiltered
 *     200-row list (inbox-v2.php lines 993-1167 + the assignee/tag prefetch
 *     at 1068-1095).
 *
 * ARCHITECTURE NOTE — reconciling the two callers' conflicting behavior:
 * inbox-v2.php's SSR path (lines 1041-1054) runs its OWN raw query with no
 * row cap beyond `LIMIT $conversationLimit` (200) — it never calls
 * InboxService at all. The AJAX "load more" path (ConversationLoader,
 * inbox-v2.php lines 11524-11700) always requests `limit=200` too, but goes
 * through api/inbox-v2.php -> InboxService::getConversationsDelta(), whose
 * OWN internal `$limit = max(1, min(100, $limit))` (line 278) silently
 * re-caps every such call to 100 rows regardless of what was requested —
 * so real production inbox-v2.php currently shows 200 rows on first paint,
 * then ~100-row batches after that. This is a genuine, confirmed PHP quirk
 * (not a typo to "fix" here — see CLAUDE.md's instruction to replicate
 * quirks, and this batch's brief: "CRITICAL BEHAVIOR TO REPLICATE, NOT
 * 'FIX'"). To keep exact byte-parity for the Route Handler (which must
 * match api/inbox-v2.php's real, quirky behavior) WITHOUT also silently
 * capping the SSR initial load to 100 (a real regression vs the 200 rows
 * PHP actually shows on first paint), the SQL-building + row-enrichment
 * logic lives in one un-capped function, `fetchConversationsPage()`, and
 * `getConversationsDelta()` is a thin wrapper that applies the quirky
 * 100-cap on top of it — exactly mirroring InboxService's own method body.
 * The Route Handler calls `getConversationsDelta()` (capped, PHP-faithful).
 * layout.tsx calls `fetchConversationsPage()` directly with limit=200
 * (uncapped, matching the real SSR raw query) — this is "one query
 * function, two call sites with different resolved limits", not duplicated
 * cursor SQL.
 */

// ---------------------------------------------------------------------------
// Types — field names are the wire contract (snake_case, see route.ts's own
// doc comment for why: this is a JSON contract other consumers rely on, NOT
// an internal camelCase TS convention like users/queries.ts's UsersListRow).
// ---------------------------------------------------------------------------

export type ConversationPlatform = 'line' | 'facebook' | 'tiktok';

export interface ConversationFilters {
  chatStatus?: string;
  unreadOnly?: boolean;
  tagId?: number;
  /** 'unassigned' or an admin_id as a string — server-side has no 'me' special case (see route.ts doc). */
  assigneeId?: string;
  platform?: ConversationPlatform;
}

export interface ConversationTag {
  id: number;
  name: string;
  color: string | null;
}

export interface ConversationRow {
  id: number;
  display_name: string | null;
  picture_url: string | null;
  chat_status: string | null;
  platform: string;
  platform_user_id: string | null;
  /** 'YYYY-MM-DD HH:MM:SS', Bangkok wall-clock (tenant connection runs `SET time_zone='+07:00'`) — raw, unformatted; see _lib/preview.ts for display formatting. */
  last_message_at: string | null;
  assigned_to: number | null;
  assignment_status: string | null;
  unread_count: number;
  /** Raw `SUBSTRING(content, 1, 100)` — NOT emoji-substituted/truncated-to-30; see _lib/preview.ts. */
  last_message_preview: string | null;
  last_message_type: string | null;
  tags: ConversationTag[];
  assignees: number[];
}

export interface ConversationsDeltaResult {
  conversations: ConversationRow[];
  next_cursor: string | null;
  has_more: boolean;
  count: number;
}

export interface ConversationsPageOptions {
  since?: number;
  cursor?: string | null;
  limit: number;
  search?: string | null;
  filters?: ConversationFilters;
}

// ---------------------------------------------------------------------------
// WHERE-clause construction — ported literally from
// InboxService::getConversationsDelta()'s SQL string concatenation
// (lines 284-393), same condition order.
// ---------------------------------------------------------------------------

function accountConditionExpr(accountId: number, platformFilter: ConversationPlatform | undefined): Expression<SqlBool> {
  if (platformFilter === 'facebook') {
    return sql<SqlBool>`u.platform = 'facebook'`;
  }
  if (platformFilter === 'tiktok') {
    return sql<SqlBool>`u.platform = 'tiktok'`;
  }
  return sql<SqlBool>`u.line_account_id = ${accountId}`;
}

function buildConversationsWhereExpr(
  accountId: number,
  since: number,
  cursor: string | null | undefined,
  search: string | null | undefined,
  filters: ConversationFilters
): Expression<SqlBool> {
  const conditions: Expression<SqlBool>[] = [
    accountConditionExpr(accountId, filters.platform),
    sql<SqlBool>`EXISTS (SELECT 1 FROM messages WHERE user_id = u.id)`,
  ];

  if (search !== null && search !== undefined && search.trim() !== '') {
    const term = `%${search.trim()}%`;
    conditions.push(sql<SqlBool>`(
      COALESCE(u.custom_display_name, u.display_name) LIKE ${term}
      OR EXISTS (
        SELECT 1 FROM messages m_search
        WHERE m_search.user_id = u.id
        AND m_search.content LIKE ${term}
        LIMIT 1
      )
    )`);
  }

  if (filters.chatStatus) {
    conditions.push(sql<SqlBool>`u.chat_status = ${filters.chatStatus}`);
  }

  if (filters.unreadOnly) {
    conditions.push(sql<SqlBool>`EXISTS (
      SELECT 1 FROM messages m_unread
      WHERE m_unread.user_id = u.id
      AND m_unread.direction = 'incoming'
      AND m_unread.is_read = 0
    )`);
  }

  if (filters.tagId) {
    conditions.push(sql<SqlBool>`EXISTS (
      SELECT 1 FROM user_tag_assignments uta
      WHERE uta.user_id = u.id
      AND uta.tag_id = ${filters.tagId}
    )`);
  }

  if (filters.assigneeId) {
    if (filters.assigneeId === 'unassigned') {
      conditions.push(sql<SqlBool>`NOT EXISTS (
        SELECT 1 FROM conversation_multi_assignees cma
        WHERE cma.user_id = u.id
        AND cma.status = 'active'
      )`);
    } else {
      conditions.push(sql<SqlBool>`EXISTS (
        SELECT 1 FROM conversation_multi_assignees cma
        WHERE cma.user_id = u.id
        AND cma.admin_id = ${Number(filters.assigneeId)}
        AND cma.status = 'active'
      )`);
    }
  }

  if (since > 0) {
    conditions.push(sql<SqlBool>`(SELECT MAX(created_at) FROM messages WHERE user_id = u.id) > FROM_UNIXTIME(${since})`);
  }

  if (cursor !== null && cursor !== undefined && cursor.trim() !== '') {
    conditions.push(sql<SqlBool>`(SELECT MAX(created_at) FROM messages WHERE user_id = u.id) < ${cursor}`);
  }

  return sql<SqlBool>`(${sql.join(conditions, sql` AND `)})`;
}

interface RawConversationRow {
  id: number;
  display_name: string | null;
  picture_url: string | null;
  chat_status: string | null;
  platform: string;
  platform_user_id: string | null;
  last_message_at: string | null;
  assigned_to: number | null;
  assignment_status: string | null;
}

/**
 * The un-capped query builder + row enrichment — see module doc for why
 * this exists separately from getConversationsDelta(). `limit` here is the
 * FINAL resolved page size (caller decides any capping).
 */
export async function fetchConversationsPage(db: Kysely<TenantDB>, accountId: number, options: ConversationsPageOptions): Promise<ConversationsDeltaResult> {
  const { since = 0, cursor = null, limit, search = null, filters = {} } = options;
  const whereExpr = buildConversationsWhereExpr(accountId, since, cursor, search, filters);
  const limitPlusOne = limit + 1; // fetch one extra to detect has_more, matching PHP.

  const rowsResult = await sql<RawConversationRow>`
    SELECT
      u.id AS id,
      COALESCE(u.custom_display_name, u.display_name) AS display_name,
      u.picture_url AS picture_url,
      u.chat_status AS chat_status,
      COALESCE(u.platform, 'line') AS platform,
      u.platform_user_id AS platform_user_id,
      DATE_FORMAT(
        (SELECT MAX(m_last.created_at) FROM messages m_last WHERE m_last.user_id = u.id),
        '%Y-%m-%d %H:%i:%s'
      ) AS last_message_at,
      ca.assigned_to AS assigned_to,
      ca.status AS assignment_status
    FROM users u
    LEFT JOIN conversation_assignments ca ON ca.user_id = u.id
    WHERE ${whereExpr}
    ORDER BY last_message_at DESC
    LIMIT ${limitPlusOne}
  `.execute(db);

  const rows = [...rowsResult.rows];
  const hasMore = rows.length > limit;
  if (hasMore) {
    rows.pop();
  }
  const nextCursor = hasMore && rows.length > 0 ? (rows[rows.length - 1]?.last_message_at ?? null) : null;

  const userIds = rows.map((r) => r.id);
  const [lastMsgMap, unreadMap, tagsMap, assigneesMap] = await Promise.all([
    getLastMessageMetaBatch(db, userIds),
    getUnreadCountsBatch(db, userIds),
    getUserTagsBatch(db, userIds),
    getAssignedAdminIdsBatch(db, userIds),
  ]);

  const conversations: ConversationRow[] = rows.map((row) => {
    const meta = lastMsgMap[row.id];
    return {
      id: row.id,
      display_name: row.display_name,
      picture_url: row.picture_url,
      chat_status: row.chat_status,
      platform: row.platform,
      platform_user_id: row.platform_user_id,
      last_message_at: row.last_message_at,
      assigned_to: row.assigned_to,
      assignment_status: row.assignment_status,
      unread_count: unreadMap[row.id] ?? 0,
      last_message_preview: meta?.preview ?? null,
      last_message_type: meta?.type ?? null,
      tags: tagsMap[row.id] ?? [],
      assignees: assigneesMap[row.id] ?? [],
    };
  });

  return {
    conversations,
    next_cursor: nextCursor,
    has_more: hasMore,
    count: conversations.length,
  };
}

/**
 * Port of InboxService::getConversationsDelta() (lines 270-436), INCLUDING
 * its own internal `max(1, min(100, $limit))` re-cap (line 278) — see
 * module doc "ARCHITECTURE NOTE" for why this is preserved rather than
 * "fixed". This is what the Route Handler calls.
 */
export async function getConversationsDelta(
  db: Kysely<TenantDB>,
  accountId: number,
  options: { since?: number; cursor?: string | null; limit?: number; search?: string | null; filters?: ConversationFilters } = {}
): Promise<ConversationsDeltaResult> {
  const requestedLimit = options.limit ?? 50;
  const cappedLimit = Math.max(1, Math.min(100, requestedLimit));
  return fetchConversationsPage(db, accountId, { ...options, limit: cappedLimit });
}

// ---------------------------------------------------------------------------
// Batch enrichment helpers — ported from InboxService.php lines 444-562.
// ---------------------------------------------------------------------------

function toUniqueIntIds(userIds: number[]): number[] {
  return Array.from(new Set(userIds.map((id) => Math.trunc(id))));
}

export interface MessageMeta {
  preview: string | null;
  type: string | null;
}

/** Port of InboxService::getLastMessageMetaBatch() (lines 444-469). */
export async function getLastMessageMetaBatch(db: Kysely<TenantDB>, userIds: number[]): Promise<Record<number, MessageMeta>> {
  const ids = toUniqueIntIds(userIds);
  if (ids.length === 0) {
    return {};
  }
  const result = await sql<{ user_id: number; preview: string | null; type: string | null }>`
    SELECT m.user_id, SUBSTRING(m.content, 1, 100) as preview, m.message_type as type
    FROM messages m
    JOIN (
      SELECT user_id, MAX(id) as max_id
      FROM messages
      WHERE user_id IN (${sql.join(ids)})
      GROUP BY user_id
    ) t ON t.max_id = m.id
  `.execute(db);

  const map: Record<number, MessageMeta> = {};
  for (const row of result.rows) {
    map[row.user_id] = { preview: row.preview, type: row.type };
  }
  return map;
}

/** Port of InboxService::getUnreadCountsBatch() (lines 477-500). */
export async function getUnreadCountsBatch(db: Kysely<TenantDB>, userIds: number[]): Promise<Record<number, number>> {
  const ids = toUniqueIntIds(userIds);
  if (ids.length === 0) {
    return {};
  }
  const result = await sql<{ user_id: number; unread: number }>`
    SELECT user_id, COUNT(*) as unread
    FROM messages
    WHERE user_id IN (${sql.join(ids)})
    AND direction = 'incoming'
    AND is_read = 0
    GROUP BY user_id
  `.execute(db);

  const map: Record<number, number> = {};
  for (const row of result.rows) {
    map[row.user_id] = Number(row.unread);
  }
  return map;
}

/** Port of InboxService::getUserTagsBatch() (lines 508-532, private in PHP — exported here per this batch's brief). */
export async function getUserTagsBatch(db: Kysely<TenantDB>, userIds: number[]): Promise<Record<number, ConversationTag[]>> {
  const ids = toUniqueIntIds(userIds);
  if (ids.length === 0) {
    return {};
  }
  const result = await sql<{ user_id: number; id: number; name: string; color: string | null }>`
    SELECT uta.user_id, ut.id, ut.name, ut.color
    FROM user_tags ut
    JOIN user_tag_assignments uta ON ut.id = uta.tag_id
    WHERE uta.user_id IN (${sql.join(ids)})
    ORDER BY ut.name
  `.execute(db);

  const map: Record<number, ConversationTag[]> = {};
  for (const row of result.rows) {
    (map[row.user_id] ??= []).push({ id: row.id, name: row.name, color: row.color });
  }
  return map;
}

/** Port of InboxService::getAssignedAdminIdsBatch() (lines 541-562, private in PHP — exported here per this batch's brief). */
export async function getAssignedAdminIdsBatch(db: Kysely<TenantDB>, userIds: number[]): Promise<Record<number, number[]>> {
  const ids = toUniqueIntIds(userIds);
  if (ids.length === 0) {
    return {};
  }
  const result = await sql<{ user_id: number; admin_id: number }>`
    SELECT user_id, admin_id
    FROM conversation_multi_assignees
    WHERE user_id IN (${sql.join(ids)})
    AND status = 'active'
  `.execute(db);

  const map: Record<number, number[]> = {};
  for (const row of result.rows) {
    (map[row.user_id] ??= []).push(row.admin_id);
  }
  return map;
}

// ---------------------------------------------------------------------------
// Query-string parsing — port of api/inbox-v2.php lines 2712-2741.
// ---------------------------------------------------------------------------

/** PHP `(int) $value` cast on a query-string value: leading optional sign + digits, else 0; missing key -> fallback (mirrors `$_GET['x'] ?? $fallback` evaluated BEFORE the cast). */
function phpIntCast(raw: string | null, fallback: number): number {
  if (raw === null) {
    return fallback;
  }
  const match = /^\s*[-+]?\d+/.exec(raw);
  return match ? Number.parseInt(match[0], 10) : 0;
}

/** PHP `empty($v)`-style falsy check for query-string values: null, '', and the literal string '0' are all "not set". */
function phpNotEmpty(raw: string | null): raw is string {
  return raw !== null && raw !== '' && raw !== '0';
}

const VALID_PLATFORMS: readonly ConversationPlatform[] = ['line', 'facebook', 'tiktok'];

export interface ParsedConversationsQuery {
  since: number;
  cursor: string | null;
  /** Already clamped to [1,500], falling back to 200 — see CONVERSATIONS_LIMIT_FALLBACK doc. */
  limit: number;
  search: string | null;
  filters: ConversationFilters;
}

export const CONVERSATIONS_LIMIT_DEFAULT = 200;
export const CONVERSATIONS_LIMIT_MIN = 1;
export const CONVERSATIONS_LIMIT_MAX = 500;

/**
 * Port of api/inbox-v2.php's `getConversations`/`get_conversations` param
 * parsing (lines 2712-2741) — note the OUTER [1,500]->200-fallback clamp
 * here is a DIFFERENT, earlier clamp than getConversationsDelta()'s own
 * internal [1,100] cap (see module doc). Both are preserved, applied in the
 * same order PHP applies them (this one first, in the Route Handler; the
 * inner one inside getConversationsDelta()).
 */
export function parseConversationsQuery(searchParams: URLSearchParams): ParsedConversationsQuery {
  const since = phpIntCast(searchParams.get('since'), 0);
  const cursor = searchParams.get('cursor');

  let limit = phpIntCast(searchParams.get('limit'), CONVERSATIONS_LIMIT_DEFAULT);
  if (limit < CONVERSATIONS_LIMIT_MIN || limit > CONVERSATIONS_LIMIT_MAX) {
    limit = CONVERSATIONS_LIMIT_DEFAULT;
  }

  const searchRaw = searchParams.get('search');
  const search = searchRaw !== null ? searchRaw.trim() : null;

  const filters: ConversationFilters = {};
  const chatStatus = searchParams.get('chatStatus');
  if (phpNotEmpty(chatStatus)) {
    filters.chatStatus = chatStatus;
  }
  if (searchParams.get('unreadOnly') === 'true') {
    filters.unreadOnly = true;
  }
  const tagIdRaw = searchParams.get('tagId');
  if (phpNotEmpty(tagIdRaw)) {
    filters.tagId = phpIntCast(tagIdRaw, 0);
  }
  const assigneeIdRaw = searchParams.get('assigneeId');
  if (phpNotEmpty(assigneeIdRaw)) {
    filters.assigneeId = assigneeIdRaw;
  }
  const platformRaw = searchParams.get('platform');
  if (phpNotEmpty(platformRaw) && (VALID_PLATFORMS as readonly string[]).includes(platformRaw)) {
    filters.platform = platformRaw as ConversationPlatform;
  }

  return { since, cursor, limit, search, filters };
}
