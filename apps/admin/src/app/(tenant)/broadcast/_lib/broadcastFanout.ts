import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { broadcastMessage, multicastMessage, type LineApiOptions, type LineMessage } from '@reya/line';

/**
 * broadcastFanout.ts — shared "send these LINE messages to a resolved recipient set, chunked
 * by 500" primitive, backing BOTH POST handlers this batch ports:
 *   - includes/broadcast/send.php's `action==='send'` (immediate-send branch) -> delegates to
 *     classes/BroadcastHelper.php::executeBroadcastSend(), ported below as
 *     `executeBroadcastSend()`.
 *   - includes/broadcast/products.php's `action==='send_broadcast'` -> its OWN inline
 *     targeting logic (NOT via BroadcastHelper — read products.php lines 141-197 again: it
 *     never calls BroadcastHelper::executeBroadcastSend() at all), ported below as
 *     `executeProductBroadcastSend()`.
 *
 * These two PHP call sites use the SAME LINE API calls (multicastMessage()/
 * broadcastMessage()) and the SAME chunk-by-500 pattern, but genuinely DIFFERENT SQL and
 * DIFFERENT success-count semantics for their nominally-overlapping target types — see each
 * function's own doc comment below for the specific divergence. `chunkedMulticast()` is the
 * one piece of logic actually shared byte-for-byte between them (both do
 * `array_chunk($userIds, 500)` then `if ($r['code'] === 200) $sentCount += count($chunk)`),
 * factored out here once.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════
 * Target types intentionally NOT ported (documented, matching this file's own "flag, don't
 * silently drop" precedent used elsewhere in this migration)
 * ═══════════════════════════════════════════════════════════════════════════════════════
 * classes/BroadcastHelper.php::executeBroadcastSend() also handles `narrowcast`, `limit`,
 * `select`, and `single` target types (lines 108-175). NONE of the four has a reachable UI
 * control anywhere in send.php's or products.php's rendered forms:
 *   - `narrowcast`: no `<input name="target_type" value="narrowcast">` anywhere in send.php's
 *     radio group (lines 337-378 list only database/all/segment/tag/group).
 *   - `limit`: same — no `target_type=limit` radio, and the `<input name="limit_count">`
 *     the branch reads doesn't exist in the form either.
 *   - `select` / `single`: no `selected_users[]`/`single_user_id` form control anywhere.
 * This is genuinely dead-but-reachable-by-hand-crafted-POST code in real PHP, same class of
 * finding as (tenant)/broadcast's own products.php `$categories`/send.php `$allUsers` dead
 * fetches (see send-queries.ts's / products-queries.ts's module docs) — not ported here, and
 * `narrowcastMessage()` is correspondingly NOT added to packages/line/src/api.ts this round
 * (see that file's module doc).
 */

const CHUNK_SIZE = 500;

/**
 * `array_chunk($userIds, 500)` + `foreach ($chunk) { $r = $line->multicastMessage($chunk,
 * $messages); if ($r['code'] === 200) $sentCount += count($chunk); }` — the loop shape shared
 * by BroadcastHelper's `database`/`segment`/`tag` branches AND products.php's `tags` branch.
 * A non-200 chunk response does not increment `sentCount` (nor does it throw/abort the
 * remaining chunks — PHP's loop keeps going), matching PHP exactly.
 */
export async function chunkedMulticast(
  userIds: string[],
  messages: LineMessage[],
  lineOptions: LineApiOptions
): Promise<number> {
  let sentCount = 0;
  for (let i = 0; i < userIds.length; i += CHUNK_SIZE) {
    const chunk = userIds.slice(i, i + CHUNK_SIZE);
    const result = await multicastMessage(chunk, messages, lineOptions);
    if (result.code === 200) {
      sentCount += chunk.length;
    }
  }
  return sentCount;
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
// send.php's send action -> classes/BroadcastHelper.php::executeBroadcastSend()
// ═══════════════════════════════════════════════════════════════════════════════════════════

export type SendTabTargetType = 'database' | 'all' | 'segment' | 'tag' | 'group';

export interface ExecuteBroadcastSendParams {
  db: Kysely<TenantDB>;
  currentBotId: number | null;
  lineOptions: LineApiOptions;
  targetType: SendTabTargetType;
  messages: LineMessage[];
  /** Required when targetType === 'segment'. */
  segmentId?: number | null;
  /** Required when targetType === 'tag' — one row per selected tag checkbox. */
  tagIds?: number[];
  /** Required when targetType === 'group' — send.php's `target_group_id` select value. */
  targetGroupId?: string | number | null;
}

export interface ExecuteBroadcastSendResult {
  sentCount: number;
  /**
   * Mirrors BroadcastHelper.php's `$targetGroupId` return field. `null` for every branch
   * except 'group', which echoes back `$post['target_group_id'] ?? null` UNCHANGED (line
   * 122: `$targetGroupId = $post['target_group_id'] ?? null;`, set before the branch's own
   * query, then returned as-is at the bottom of the function) — 'narrowcast' is the only
   * OTHER branch that sets this (to LINE's `requestId`), and it isn't ported (see module doc).
   * send.php's caller (`send-actions.ts`) only reads this when `targetType !== 'tag'`, exactly
   * mirroring send.php line 100-102's `if ($targetType !== 'tag') { $targetGroupId =
   * $result['targetGroupId']; }`.
   */
  targetGroupId: string | number | null;
}

/**
 * Port of classes/BroadcastHelper.php::executeBroadcastSend(), restricted to the 5 target
 * types send.php's form can actually produce (see this file's module doc). Branch-by-branch:
 *
 *   - 'database': `SELECT line_user_id FROM users WHERE is_blocked = 0 AND (line_account_id
 *     = ? OR line_account_id IS NULL)` -> chunkedMulticast().
 *   - 'all': `$line->broadcastMessage($messages)`; on HTTP 200, `sentCount` is set to a FRESH
 *     `SELECT COUNT(*)` of non-blocked users (line 91-94) — NOT the LINE API's own delivery
 *     count (LINE never returns one for /message/broadcast) and NOT `-1` (contrast with
 *     `executeProductBroadcastSend()`'s 'all' branch below, which uses a `-1` sentinel — these
 *     are genuinely two different PHP call sites with two different conventions, ported
 *     faithfully as two different behaviors, not unified).
 *   - 'segment': `AdvancedCRM::getSegmentMembers($segmentId)` -> `SELECT u.*, sm.score,
 *     sm.added_at FROM users u JOIN segment_members sm ON u.id = sm.user_id WHERE
 *     sm.segment_id = ? ORDER BY sm.score DESC LIMIT 1000`, then `line_user_id` extracted
 *     per row -> chunkedMulticast().
 *   - 'tag': `BroadcastHelper::getUserIdsByTags()` -> PER TAG ID, `AdvancedCRM::getUsersByTag()`
 *     (`SELECT u.*, a.created_at as assigned_at FROM users u JOIN user_tag_assignments a ON
 *     u.id = a.user_id WHERE a.tag_id = ? ORDER BY a.created_at DESC LIMIT 1000`), collecting
 *     UNIQUE `line_user_id`s across all tags into one deduplicated list (a PHP associative
 *     array keyed by line_user_id, `array_keys($seen)`) -> chunkedMulticast() on the
 *     deduplicated set. This is deliberately NOT the same query shape as
 *     `executeProductBroadcastSend()`'s 'tags' branch (a single `IN (...)` query with
 *     `DISTINCT`) — two different PHP source files, two different queries, ported as two
 *     different functions.
 *   - 'group': `SELECT u.line_user_id FROM users u JOIN user_groups ug ON u.id = ug.user_id
 *     WHERE ug.group_id = ? AND u.is_blocked = 0` -> a SINGLE `multicastMessage()` call with
 *     the FULL (unchunked) user-ID list — BroadcastHelper.php lines 129-135 is the one branch
 *     among database/segment/tag/group that has NO `array_chunk(...,500)` wrapping it. This is
 *     a genuine, pre-existing PHP inconsistency (LINE's multicast endpoint caps at 500
 *     recipients per call, so a group with >500 members would get a LINE-side error on real
 *     PHP too) — ported here exactly as-is, not "fixed" into a chunked call.
 */
export async function executeBroadcastSend(params: ExecuteBroadcastSendParams): Promise<ExecuteBroadcastSendResult> {
  const { db, currentBotId, lineOptions, targetType, messages } = params;

  if (targetType === 'database') {
    const rows = await sql<{ line_user_id: string }>`
      SELECT line_user_id FROM users WHERE is_blocked = 0 AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)
    `.execute(db);
    const sentCount = await chunkedMulticast(rows.rows.map((r) => r.line_user_id), messages, lineOptions);
    return { sentCount, targetGroupId: null };
  }

  if (targetType === 'all') {
    const result = await broadcastMessage(messages, lineOptions);
    if (result.code !== 200) {
      return { sentCount: 0, targetGroupId: null };
    }
    const countResult = await sql<{ c: number }>`
      SELECT COUNT(*) as c FROM users WHERE is_blocked = 0 AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)
    `.execute(db);
    return { sentCount: Number(countResult.rows[0]?.c ?? 0), targetGroupId: null };
  }

  if (targetType === 'segment') {
    const segmentId = params.segmentId ?? 0;
    const rows = await sql<{ line_user_id: string | null }>`
      SELECT u.line_user_id FROM users u JOIN segment_members sm ON u.id = sm.user_id
      WHERE sm.segment_id = ${segmentId} ORDER BY sm.score DESC LIMIT 1000
    `.execute(db);
    const userIds = rows.rows.map((r) => r.line_user_id).filter((id): id is string => Boolean(id));
    const sentCount = await chunkedMulticast(userIds, messages, lineOptions);
    return { sentCount, targetGroupId: null };
  }

  if (targetType === 'tag') {
    const tagIds = params.tagIds ?? [];
    const seen = new Set<string>();
    for (const tagId of tagIds) {
      const rows = await sql<{ line_user_id: string | null }>`
        SELECT u.line_user_id FROM users u JOIN user_tag_assignments a ON u.id = a.user_id
        WHERE a.tag_id = ${tagId} ORDER BY a.created_at DESC LIMIT 1000
      `.execute(db);
      for (const row of rows.rows) {
        if (row.line_user_id) {
          seen.add(row.line_user_id);
        }
      }
    }
    const sentCount = await chunkedMulticast(Array.from(seen), messages, lineOptions);
    return { sentCount, targetGroupId: null };
  }

  // targetType === 'group' — targetGroupId is echoed back unchanged, matching
  // BroadcastHelper.php:122's `$targetGroupId = $post['target_group_id'] ?? null;`.
  const targetGroupId = params.targetGroupId ?? null;
  const rows = await sql<{ line_user_id: string }>`
    SELECT u.line_user_id FROM users u JOIN user_groups ug ON u.id = ug.user_id
    WHERE ug.group_id = ${targetGroupId} AND u.is_blocked = 0
  `.execute(db);
  const userIds = rows.rows.map((r) => r.line_user_id);
  if (userIds.length === 0) {
    return { sentCount: 0, targetGroupId };
  }
  // Deliberately UNCHUNKED — see doc comment above.
  const result = await multicastMessage(userIds, messages, lineOptions);
  return { sentCount: result.code === 200 ? userIds.length : 0, targetGroupId };
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
// products.php's send_broadcast action — its OWN inline targeting (NOT BroadcastHelper)
// ═══════════════════════════════════════════════════════════════════════════════════════════

export type ProductsTabTargetType = 'all' | 'tags';

export interface ExecuteProductBroadcastSendParams {
  db: Kysely<TenantDB>;
  currentBotId: number | null;
  lineOptions: LineApiOptions;
  targetType: ProductsTabTargetType;
  messages: LineMessage[];
  /** products.php's `target_tags[]` checkbox values — only read when targetType === 'tags'. */
  targetTagIds?: number[];
}

/**
 * Port of products.php lines 172-190 (the `send_broadcast` action's own inline targeting —
 * NOT a BroadcastHelper call):
 *
 *   $sentCount = 0;
 *   if ($targetType === 'all') {
 *       $result = $line->broadcastMessage([$flexMessage]);
 *       if ($result['code'] === 200) $sentCount = -1;
 *   } else {
 *       $userIds = [];
 *       if (!empty($targetTags)) {
 *           $stmt = "SELECT DISTINCT u.line_user_id FROM users u JOIN user_tag_assignments uta
 *               ON u.id = uta.user_id WHERE uta.tag_id IN (...) AND (u.line_account_id = ? OR
 *               u.line_account_id IS NULL)";
 *           ...
 *       }
 *       if (!empty($userIds)) {
 *           foreach (array_chunk($userIds, 500) as $chunk) {
 *               $result = $line->multicastMessage($chunk, [$flexMessage]);
 *               if ($result['code'] === 200) $sentCount += count($chunk);
 *           }
 *       }
 *   }
 *
 * The `-1` on a successful 'all' send is a genuine PHP sentinel value (meaning "sent to
 * everyone, exact count unknown/not tracked") that products.php's success banner then
 * displays literally as "(-1 คน)" — an existing PHP display oddity, ported as-is per the
 * brief, NOT "fixed" into a real count the way `executeBroadcastSend()`'s 'all' branch (a
 * DIFFERENT PHP source file, send.php via BroadcastHelper) does.
 *
 * Empty `targetTagIds` (or the query returning zero rows) short-circuits to `sentCount: 0`
 * with no LINE API call at all — matches PHP's `if (!empty($userIds))` guard exactly (an
 * empty `IN ()` clause is also invalid SQL, so this guard is load-bearing, not just an
 * optimization).
 */
export interface ExecuteProductBroadcastSendResult {
  sentCount: number;
}

export async function executeProductBroadcastSend(
  params: ExecuteProductBroadcastSendParams
): Promise<ExecuteProductBroadcastSendResult> {
  const { db, currentBotId, lineOptions, targetType, messages } = params;

  if (targetType === 'all') {
    const result = await broadcastMessage(messages, lineOptions);
    return { sentCount: result.code === 200 ? -1 : 0 };
  }

  const targetTagIds = params.targetTagIds ?? [];
  if (targetTagIds.length === 0) {
    return { sentCount: 0 };
  }

  const rows = await sql<{ line_user_id: string }>`
    SELECT DISTINCT u.line_user_id FROM users u
    JOIN user_tag_assignments uta ON u.id = uta.user_id
    WHERE uta.tag_id IN (${sql.join(targetTagIds)}) AND (u.line_account_id = ${currentBotId} OR u.line_account_id IS NULL)
  `.execute(db);
  const userIds = rows.rows.map((r) => r.line_user_id);
  if (userIds.length === 0) {
    return { sentCount: 0 };
  }
  const sentCount = await chunkedMulticast(userIds, messages, lineOptions);
  return { sentCount };
}
