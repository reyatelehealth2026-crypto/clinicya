import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getUserPoints, type LoyaltyPointsResult } from './loyaltyPoints';

/**
 * customerCrm.ts — literal port of `api/inbox-v2.php`'s `case 'customer_crm':`
 * (lines 1908-1961), the CRM HUD panel's aggregate customer-data endpoint.
 *
 * ```php
 * case 'customer_crm':
 *     $userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
 *     if (!$userId) { sendError('User ID is required'); }
 *
 *     try {
 *         // Get user info
 *         $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
 *         $stmt->execute([$userId]);
 *         $user = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if (!$user) { sendError('User not found', 404); }
 *
 *         // Get loyalty points  [see loyaltyPoints.ts — block (a)]
 *         // Get stats           [block (b)]
 *         // Get tags            [block (c)]
 *         // Get all available tags for selector   [block (d)]
 *         // Get notes from user_notes table        [block (e)]
 *         // Get recent transactions                 [block (f)]
 *
 *         sendResponse([
 *             'success' => true,
 *             'data' => [
 *                 'user' => $user, 'points' => $points, 'tier' => $tier,
 *                 'stats' => $stats, 'tags' => $tags, 'all_tags' => $allTags,
 *                 'notes' => $notes, 'transactions' => $transactions
 *             ]
 *         ]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to load CRM data: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * `!$user` -> `sendError('User not found', 404)` is a HARD, IMMEDIATE exit
 * (PHP's `sendResponse()` calls `exit`) — it does NOT flow through the
 * enclosing `catch (Exception $e)` block; the outer catch only ever sees a
 * genuinely thrown exception (e.g. the `SELECT * FROM users` query itself
 * throwing), never this explicit not-found branch. `getUsersById()` below
 * mirrors this by returning `null` on no-row, and `route.ts` returns the 404
 * directly, BEFORE calling `getRestOfCrmData()` — none of the 6 best-effort
 * blocks below ever run for a nonexistent user.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SIX INDEPENDENT best-effort blocks (a)-(f) — each has its OWN local
 * try/catch degrading to a documented default WITHOUT aborting the rest of
 * the response. This mirrors PHP's structure exactly: each block below is
 * its own `try { ... } catch (Exception $e) { logInboxApiException($e,
 * 'catch'); }` in the original, so one block's DB error never blanks out
 * the others.
 * ═══════════════════════════════════════════════════════════════════════
 *
 * (a) points + tier — see `loyaltyPoints.ts` for `getUserPoints()`'s own
 *     literal port. Defaults on throw: `points = {available_points: 0,
 *     total_points: 0, used_points: 0}`, `tier = {name: 'Member', icon:
 *     '🥉', color: '#9CA3AF'}`. Tier thresholds (PHP lines 1927-1935, exact
 *     emoji/hex kept verbatim): `total_points >= 10000` -> Platinum 💎
 *     `#6366F1`; `>= 5000` -> Gold 🥇 `#F59E0B`; `>= 1000` -> Silver 🥈
 *     `#6B7280`; else the pre-set Member 🥉 `#9CA3AF` default stands.
 *     `$totalPts = $points['total_points'] ?? 0;` — PHP's `??` triggers on
 *     both a missing key AND an explicit `null` value, mirrored via `??`
 *     against `LoyaltyPointsResult.total_points` (`number | null`).
 *
 * (b) stats — TWO queries, no `line_account_id` filter on either (matches
 *     PHP exactly — do not add one):
 *     ```php
 *     $stmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total FROM transactions WHERE user_id = ? AND status NOT IN ('cancelled', 'pending')");
 *     $stmt->execute([$userId]);
 *     $txStats = $stmt->fetch(PDO::FETCH_ASSOC);
 *     $stats['order_count'] = $txStats['cnt'] ?? 0;
 *     $stats['total_spent'] = $txStats['total'] ?? 0;
 *
 *     $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ?");
 *     $stmt->execute([$userId]);
 *     $stats['message_count'] = $stmt->fetchColumn();
 *     ```
 *     Default on throw: `{order_count: 0, total_spent: 0, message_count: 0}`.
 *
 * (c) tags:
 *     ```php
 *     $stmt = $db->prepare("SELECT ut.id, ut.name, ut.color FROM user_tags ut
 *                           JOIN user_tag_assignments uta ON ut.id = uta.tag_id
 *                           WHERE uta.user_id = ?");
 *     ```
 *     Default on throw: `[]`.
 *
 * (d) all_tags:
 *     ```php
 *     $stmt = $db->prepare("SELECT id, name, color FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
 *     $stmt->execute([$lineAccountId]);
 *     ```
 *     Default on throw: `[]`. `$lineAccountId` (the session's
 *     `currentBotId`) is bound — the ONLY block in this batch's 6 that is
 *     line-account-scoped (blocks (b)/(c)/(e)/(f) intentionally are not,
 *     matching PHP exactly).
 *
 * (e) notes:
 *     ```php
 *     $stmt = $db->prepare("SELECT id, user_id, note as content, created_by, created_at FROM user_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
 *     ```
 *     Default on throw: `[]`. Note the `note AS content` alias — the
 *     response key is `content`, not `note`.
 *
 * (f) transactions:
 *     ```php
 *     $stmt = $db->prepare("SELECT id, grand_total, status, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
 *     ```
 *     Default on throw: `[]`.
 *
 * All 6 blocks' columns (`transactions.{grand_total,status,created_at}`,
 * `messages.user_id`, `user_tags.{id,name,color}`,
 * `user_tag_assignments.{tag_id,user_id}`, `user_notes.{id,user_id,note,
 * created_by,created_at}`) are confirmed present in
 * `packages/db/src/generated/tenant-db.d.ts` — no schema-drift fix needed
 * anywhere in this file.
 */

export interface CustomerCrmTier {
  name: string;
  icon: string;
  color: string;
}

export interface CustomerCrmStats {
  order_count: number;
  total_spent: number;
  message_count: number;
}

export interface CustomerCrmTagRow {
  id: number;
  name: string;
  color: string | null;
}

export interface CustomerCrmAllTagRow {
  id: number;
  name: string;
  color: string | null;
}

export interface CustomerCrmNoteRow {
  id: number;
  user_id: number;
  content: string | null;
  created_by: number | null;
  created_at: Date;
}

export interface CustomerCrmTransactionRow {
  id: number;
  grand_total: string | number;
  status: string | null;
  created_at: Date;
}

export interface CustomerCrmData {
  user: Record<string, unknown>;
  points: LoyaltyPointsResult | { available_points: 0; total_points: 0; used_points: 0 };
  tier: CustomerCrmTier;
  stats: CustomerCrmStats;
  tags: CustomerCrmTagRow[];
  all_tags: CustomerCrmAllTagRow[];
  notes: CustomerCrmNoteRow[];
  transactions: CustomerCrmTransactionRow[];
}

const DEFAULT_TIER: CustomerCrmTier = { name: 'Member', icon: '🥉', color: '#9CA3AF' };
const DEFAULT_POINTS = { available_points: 0, total_points: 0, used_points: 0 } as const;
const DEFAULT_STATS: CustomerCrmStats = { order_count: 0, total_spent: 0, message_count: 0 };

/** `SELECT * FROM users WHERE id = ?` — returns `null` on no-row (PHP's `!$user` immediate-exit case, handled by the caller). */
export async function getCrmUserById(db: Kysely<TenantDB>, userId: number): Promise<Record<string, unknown> | null> {
  const result = await sql<Record<string, unknown>>`SELECT * FROM users WHERE id = ${userId}`.execute(db);
  return result.rows[0] ?? null;
}

/** Block (a): points + tier, degrading to the documented Member/default preset on any throw. */
async function loadPointsAndTier(
  db: Kysely<TenantDB>,
  userId: number
): Promise<{ points: CustomerCrmData['points']; tier: CustomerCrmTier }> {
  try {
    const points = await getUserPoints(db, userId);
    const totalPts = points.total_points ?? 0;
    let tier: CustomerCrmTier = DEFAULT_TIER;
    if (totalPts >= 10000) {
      tier = { name: 'Platinum', icon: '💎', color: '#6366F1' };
    } else if (totalPts >= 5000) {
      tier = { name: 'Gold', icon: '🥇', color: '#F59E0B' };
    } else if (totalPts >= 1000) {
      tier = { name: 'Silver', icon: '🥈', color: '#6B7280' };
    }
    return { points, tier };
  } catch {
    return { points: DEFAULT_POINTS, tier: DEFAULT_TIER };
  }
}

interface TransactionStatsRow {
  cnt: number;
  total: string | number;
}

/** Block (b): order/spend/message stats — no `line_account_id` filter on either query, matching PHP. */
async function loadStats(db: Kysely<TenantDB>, userId: number): Promise<CustomerCrmStats> {
  try {
    const txResult = await sql<TransactionStatsRow>`
      SELECT COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total
      FROM transactions
      WHERE user_id = ${userId} AND status NOT IN ('cancelled', 'pending')
    `.execute(db);
    const txStats = txResult.rows[0];

    // `SELECT COUNT(*) FROM ...` + PHP's `$stmt->fetchColumn()` reads the
    // first column of the first row regardless of its name; aliased to
    // \`cnt\` here purely for a stable TS key — does not change the query's
    // meaning or PHP-observable behavior.
    const msgResult = await sql<{ cnt: number }>`
      SELECT COUNT(*) as cnt FROM messages WHERE user_id = ${userId}
    `.execute(db);
    const messageCount = msgResult.rows[0]?.cnt ?? 0;

    return {
      order_count: Number(txStats?.cnt ?? 0),
      total_spent: Number(txStats?.total ?? 0),
      message_count: Number(messageCount),
    };
  } catch {
    return DEFAULT_STATS;
  }
}

/** Block (c): the user's currently-assigned tags. */
async function loadTags(db: Kysely<TenantDB>, userId: number): Promise<CustomerCrmTagRow[]> {
  try {
    const result = await sql<CustomerCrmTagRow>`
      SELECT ut.id, ut.name, ut.color FROM user_tags ut
      JOIN user_tag_assignments uta ON ut.id = uta.tag_id
      WHERE uta.user_id = ${userId}
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

/** Block (d): every tag available for the tag selector, scoped to this LINE account (or global). */
async function loadAllTags(db: Kysely<TenantDB>, lineAccountId: number): Promise<CustomerCrmAllTagRow[]> {
  try {
    const result = await sql<CustomerCrmAllTagRow>`
      SELECT id, name, color FROM user_tags WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL ORDER BY name
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

/** Block (e): the 10 most recent `user_notes`, `note` aliased to `content`. */
async function loadNotes(db: Kysely<TenantDB>, userId: number): Promise<CustomerCrmNoteRow[]> {
  try {
    const result = await sql<CustomerCrmNoteRow>`
      SELECT id, user_id, note as content, created_by, created_at
      FROM user_notes WHERE user_id = ${userId} ORDER BY created_at DESC LIMIT 10
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

/** Block (f): the 5 most recent transactions. */
async function loadTransactions(db: Kysely<TenantDB>, userId: number): Promise<CustomerCrmTransactionRow[]> {
  try {
    const result = await sql<CustomerCrmTransactionRow>`
      SELECT id, grand_total, status, created_at
      FROM transactions WHERE user_id = ${userId} ORDER BY created_at DESC LIMIT 5
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

/**
 * Assembles blocks (a)-(f) for an already-confirmed-to-exist user. Each
 * block is independently fault-tolerant (see module doc) — this function
 * itself never throws on any individual block's DB failure; a thrown error
 * here would only originate from something genuinely outside all 6 blocks'
 * own try/catch (structurally none, kept `async` for signature symmetry
 * with the rest of the `_lib` family).
 */
export async function getRestOfCrmData(
  db: Kysely<TenantDB>,
  userId: number,
  lineAccountId: number
): Promise<Omit<CustomerCrmData, 'user'>> {
  const { points, tier } = await loadPointsAndTier(db, userId);
  const stats = await loadStats(db, userId);
  const tags = await loadTags(db, userId);
  const all_tags = await loadAllTags(db, lineAccountId);
  const notes = await loadNotes(db, userId);
  const transactions = await loadTransactions(db, userId);

  return { points, tier, stats, tags, all_tags, notes, transactions };
}
