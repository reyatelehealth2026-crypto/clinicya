import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * send-queries.ts — read-side port of the "send" tab of includes/broadcast/send.php
 * (the tab included by root broadcast.php for `?tab=send` / no `?tab=`) — every SELECT
 * the tab issues BEFORE its POST-handling `if` blocks, i.e. the data used to render the
 * form + history sidebar. Read the full 865-line source before touching this file.
 *
 * Scope boundary vs. `../_lib/broadcastFanout.ts` and `../_lib/send-actions.ts`: this file
 * is reads only. The two POST handlers at the top of send.php (`action==='cancel_scheduled'`
 * and `action==='send'`) are ported in `./send-actions.ts`.
 *
 * `resolveCurrentBotId()` — broadcast.php lines 33-42, EXACTLY:
 *   $currentBotId = $_SESSION['current_bot_id'] ?? null;
 *   if (!$currentBotId) {
 *       $lineManager = new LineAccountManager($db);
 *       $defaultAccount = $lineManager->getDefaultAccount();
 *       if ($defaultAccount) { $currentBotId = $defaultAccount['id']; $_SESSION['current_bot_id'] = $currentBotId; }
 *   }
 * `LineAccountManager::getDefaultAccount()` (classes/LineAccountManager.php:71-80) loads
 * `SELECT * FROM line_accounts WHERE is_active = 1` (NO ORDER BY) into `$this->accounts`,
 * then returns the first row with `is_default = 1`, falling back to `$this->accounts[0]`
 * (i.e. whatever row MySQL happens to return first for an unordered SELECT — not
 * guaranteed to be the lowest `id`, but in practice always is on InnoDB without an
 * intervening DELETE+reinsert reordering the clustered index). `ORDER BY is_default DESC,
 * id ASC LIMIT 1` below is the deterministic Next-side equivalent this batch's brief calls
 * for: it always picks the true `is_default = 1` row first (matching PHP's loop), and among
 * non-default rows (or if none is default) picks the lowest `id` — a stable, well-defined
 * choice PHP's unordered fallback merely happens to match today. This is a documented,
 * intentional determinism upgrade, NOT a behavior change PHP itself guarantees.
 *
 * The `$_SESSION['current_bot_id'] = $currentBotId;` write-back is NOT reproduced: this is a
 * pure read helper (no session-mutation side channel exists to hand a resolved value back to
 * the caller's session here), and every other resolveXxxId helper in this codebase
 * (settings/_lib/shop-tax-queries.ts's `resolveLineAccountId`) is likewise read-only.
 */
export async function resolveCurrentBotId(
  db: Kysely<TenantDB>,
  sessionCurrentBotId: number | null
): Promise<number | null> {
  if (sessionCurrentBotId) {
    return sessionCurrentBotId;
  }
  const result = await sql<{ id: number }>`
    SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1
  `.execute(db);
  const row = result.rows[0];
  return row ? Number(row.id) : null;
}

// ---------------------------------------------------------------------------
// Groups dropdown — send.php line 132 (target_type='group' option list)
// ---------------------------------------------------------------------------

export interface BroadcastGroupOption {
  id: number;
  name: string;
  memberCount: number;
}

/**
 * send.php:132 — `SELECT g.*, COUNT(ug.user_id) as member_count FROM groups g LEFT JOIN
 * user_groups ug ON g.id = ug.group_id GROUP BY g.id ORDER BY g.name`. Deliberately NOT
 * scoped by `currentBotId` — real PHP issues this exact unscoped query (every tenant's
 * `groups` row is offered in the dropdown regardless of which LINE OA is active), and this
 * is a byte-for-byte behavior port, not a "should probably filter this" fix.
 */
export async function getBroadcastGroups(db: Kysely<TenantDB>): Promise<BroadcastGroupOption[]> {
  const result = await sql<{ id: number; name: string; member_count: number }>`
    SELECT g.id, g.name, COUNT(ug.user_id) as member_count
    FROM groups g LEFT JOIN user_groups ug ON g.id = ug.group_id
    GROUP BY g.id ORDER BY g.name
  `.execute(db);
  return result.rows.map((r) => ({ id: Number(r.id), name: r.name, memberCount: Number(r.member_count) }));
}

// ---------------------------------------------------------------------------
// Segments dropdown — send.php line 135 (`$crm->getSegments()`, target_type='segment')
// ---------------------------------------------------------------------------

export interface BroadcastSegmentOption {
  id: number;
  name: string;
  userCount: number;
}

/** classes/AdvancedCRM.php::getSegments() — `SELECT * FROM customer_segments WHERE
 * line_account_id = ? OR line_account_id IS NULL ORDER BY user_count DESC`. */
export async function getSegments(
  db: Kysely<TenantDB>,
  currentBotId: number | null
): Promise<BroadcastSegmentOption[]> {
  const result = await sql<{ id: number; name: string; user_count: number | null }>`
    SELECT id, name, user_count FROM customer_segments
    WHERE line_account_id = ${currentBotId} OR line_account_id IS NULL
    ORDER BY user_count DESC
  `.execute(db);
  return result.rows.map((r) => ({ id: Number(r.id), name: r.name, userCount: Number(r.user_count ?? 0) }));
}

// ---------------------------------------------------------------------------
// Tags checklist — send.php lines 138-140 (target_type='tag')
// ---------------------------------------------------------------------------

export interface BroadcastTagOption {
  id: number;
  name: string;
  userCount: number;
}

export async function getBroadcastTags(
  db: Kysely<TenantDB>,
  currentBotId: number | null
): Promise<BroadcastTagOption[]> {
  const result = await sql<{ id: number; name: string; user_count: number }>`
    SELECT t.id, t.name, COUNT(a.user_id) as user_count
    FROM user_tags t LEFT JOIN user_tag_assignments a ON t.id = a.tag_id
    WHERE t.line_account_id = ${currentBotId} OR t.line_account_id IS NULL
    GROUP BY t.id ORDER BY user_count DESC
  `.execute(db);
  return result.rows.map((r) => ({ id: Number(r.id), name: r.name, userCount: Number(r.user_count) }));
}

// ---------------------------------------------------------------------------
// Templates quick-select — send.php lines 155-176
// ---------------------------------------------------------------------------

export interface BroadcastTemplateOption {
  id: number;
  name: string;
  category: string | null;
  messageType: string;
  content: string;
}

/**
 * send.php lines 155-176: `templates` (unscoped, `ORDER BY category, name`) UNION'd (in the
 * PHP/application sense — `array_merge`, not a SQL UNION) with `flex_templates` (scoped to
 * `currentBotId`, `ORDER BY created_at DESC`), each `flex_templates` row getting a synthetic
 * `message_type = 'flex'` tagged on and `category` defaulted to 'Flex Builder' when null/empty.
 * Both queries are individually try/catch-guarded in PHP (`flex_templates` may not exist on
 * an older schema) — the committed tenant template has both tables (packages/db's generated
 * schema confirms it), so the catch branches are unreachable on the schema this port targets;
 * not reproduced as a runtime guard, same precedent as templates/queries.ts.
 */
export async function getBroadcastTemplates(
  db: Kysely<TenantDB>,
  currentBotId: number | null
): Promise<BroadcastTemplateOption[]> {
  const plain = await sql<{
    id: number;
    name: string;
    category: string | null;
    message_type: string | null;
    content: string;
  }>`
    SELECT id, name, category, message_type, content, created_at FROM templates ORDER BY category, name
  `.execute(db);

  const flex = await sql<{ id: number; name: string; category: string | null; content: string }>`
    SELECT id, name, category, flex_json as content, created_at FROM flex_templates
    WHERE line_account_id = ${currentBotId} OR line_account_id IS NULL
    ORDER BY created_at DESC
  `.execute(db);

  const plainTemplates: BroadcastTemplateOption[] = plain.rows.map((r) => ({
    id: Number(r.id),
    name: r.name,
    category: r.category,
    messageType: r.message_type ?? 'text',
    content: r.content,
  }));
  const flexTemplates: BroadcastTemplateOption[] = flex.rows.map((r) => ({
    id: Number(r.id),
    name: r.name,
    category: r.category && r.category !== '' ? r.category : 'Flex Builder',
    messageType: 'flex',
    content: r.content,
  }));

  return [...plainTemplates, ...flexTemplates];
}

// ---------------------------------------------------------------------------
// Total users — send.php lines 194-196 (static "recipient count" seed value)
// ---------------------------------------------------------------------------

export async function getTotalUsers(db: Kysely<TenantDB>, currentBotId: number | null): Promise<number> {
  const result = await sql<{ c: number }>`
    SELECT COUNT(*) as c FROM users WHERE is_blocked = 0 AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)
  `.execute(db);
  return Number(result.rows[0]?.c ?? 0);
}

// ---------------------------------------------------------------------------
// Broadcast history sidebar — send.php lines 178-190 (paginated, LIMIT+1 hasMore trick)
// ---------------------------------------------------------------------------

export interface BroadcastHistoryItem {
  id: number;
  title: string;
  messageType: string | null;
  status: string | null;
  sentCount: number;
  sentAt: Date | null;
  scheduledAt: Date | null;
}

export interface BroadcastHistoryPage {
  items: BroadcastHistoryItem[];
  hasMore: boolean;
  page: number;
}

const HISTORY_LIMIT = 10;

/**
 * send.php lines 178-185:
 *   $historyPage = max(1, (int)($_GET['hist_page'] ?? 1));
 *   $historyOffset = ($historyPage - 1) * $historyLimit;
 *   SELECT b.*, g.name as group_name FROM broadcasts b LEFT JOIN groups g ON b.target_group_id = g.id
 *     WHERE (b.line_account_id = ? OR b.line_account_id IS NULL) ORDER BY b.created_at DESC
 *     LIMIT (historyLimit+1) OFFSET historyOffset
 *   $hasMoreHistory = count($historyRaw) > $historyLimit; $history = array_slice($historyRaw, 0, $historyLimit);
 * The classic "fetch one extra row" pagination trick — ported as-is, `group_name` is fetched
 * by PHP but never rendered in the history sidebar HTML (dead select-list column, not carried
 * into `BroadcastHistoryItem` here since nothing downstream reads it).
 */
export async function getBroadcastHistory(
  db: Kysely<TenantDB>,
  currentBotId: number | null,
  histPageRaw: number
): Promise<BroadcastHistoryPage> {
  const page = Math.max(1, Math.trunc(histPageRaw) || 1);
  const offset = (page - 1) * HISTORY_LIMIT;

  const result = await sql<{
    id: number;
    title: string;
    message_type: string | null;
    status: string | null;
    sent_count: number | null;
    sent_at: Date | null;
    scheduled_at: Date | null;
  }>`
    SELECT b.id, b.title, b.message_type, b.status, b.sent_count, b.sent_at, b.scheduled_at
    FROM broadcasts b LEFT JOIN groups g ON b.target_group_id = g.id
    WHERE (b.line_account_id = ${currentBotId} OR b.line_account_id IS NULL)
    ORDER BY b.created_at DESC
    LIMIT ${HISTORY_LIMIT + 1} OFFSET ${offset}
  `.execute(db);

  const rows = result.rows;
  const hasMore = rows.length > HISTORY_LIMIT;
  const items: BroadcastHistoryItem[] = rows.slice(0, HISTORY_LIMIT).map((r) => ({
    id: Number(r.id),
    title: r.title,
    messageType: r.message_type,
    status: r.status,
    sentCount: Number(r.sent_count ?? 0),
    sentAt: r.sent_at,
    scheduledAt: r.scheduled_at,
  }));

  return { items, hasMore, page };
}
