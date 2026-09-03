import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * addCustomerTag.ts — the find-or-create + assign behind `api/inbox-v2.php`'s
 * `case 'add_customer_tag':` (lines 2057-2090):
 *
 * ```php
 * case 'add_customer_tag':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_POST['user_id'] ?? 0);
 *     $tagName = trim($_POST['tag_name'] ?? '');
 *     if (!$userId || empty($tagName)) { sendError('User ID and tag name are required'); }
 *     try {
 *         // Find or create tag
 *         $stmt = $db->prepare("SELECT id FROM user_tags WHERE name = ? AND (line_account_id = ? OR line_account_id IS NULL)");
 *         $stmt->execute([$tagName, $lineAccountId]);
 *         $tag = $stmt->fetch(PDO::FETCH_ASSOC);
 *
 *         if (!$tag) {
 *             // Create new tag with random color
 *             $colors = ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899', '#6366F1'];
 *             $color = $colors[array_rand($colors)];
 *
 *             $stmt = $db->prepare("INSERT INTO user_tags (name, color, line_account_id, created_at) VALUES (?, ?, ?, NOW())");
 *             $stmt->execute([$tagName, $color, $lineAccountId]);
 *             $tagId = $db->lastInsertId();
 *         } else {
 *             $tagId = $tag['id'];
 *         }
 *
 *         // Assign tag to user
 *         $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())");
 *         $stmt->execute([$userId, $tagId, $adminId ?? 'Admin']);
 *
 *         sendResponse(['success' => true, 'message' => 'Tag added successfully', 'tag_id' => $tagId]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to add tag: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * NOT THE SAME ROUTE AS THE ALREADY-MERGED `actions/assign-tag/route.ts`
 * ═══════════════════════════════════════════════════════════════════════
 * `actions/assign-tag/route.ts` ports the byte-adjacent `case 'assign_tag':`
 * (lines 2135-2153, SAME `api/inbox-v2.php` file, a DIFFERENT case label) —
 * its INSERT into `user_tag_assignments` is byte-for-byte identical to this
 * action's own second half:
 *
 * ```php
 * // case 'assign_tag': (lines 2135-2153) — no find-or-create preamble at all.
 * $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())");
 * $stmt->execute([$userId, $tagId, $adminId ?? 'Admin']);
 * ```
 *
 * The genuine, load-bearing difference is entirely UPSTREAM of that shared
 * INSERT: `assign_tag` takes an EXISTING `tag_id` directly from the request
 * body — no `SELECT`/`INSERT` against `user_tags` at all. `add_customer_tag`
 * (this action) instead takes a `tag_name` STRING and runs a whole
 * find-or-create preamble against `user_tags` first (a `SELECT` by name,
 * falling back to an `INSERT` with a randomly-chosen color from a 7-color
 * palette) to resolve a `tag_id` before reaching that same
 * `INSERT IGNORE INTO user_tag_assignments`. This file does not import
 * from, and is not imported by, `actions/assign-tag/**`.
 *
 * `user_tags` (columns `id`/`name`/`color`/`line_account_id`) and
 * `user_tag_assignments` (`user_id`/`tag_id`/`assigned_by`/`created_at`) are
 * both confirmed present in `packages/db/src/generated/tenant-db.d.ts` — a
 * fully literal, unmodified port, no schema-drift fix needed here (the
 * `user_tag_assignments` non-unique-key gap already documented on
 * `actions/assign-tag/_lib/assignTag.ts` applies identically to this
 * action's own `INSERT IGNORE`, but is a pre-existing schema gap outside
 * this batch's allowed paths — not attempted here either, matching that
 * sibling file's own precedent).
 *
 * `created_at` is bound to `NOW()` explicitly (matching the literal PHP
 * SQL) on both the `user_tags` INSERT and the `user_tag_assignments`
 * INSERT, even though both columns also carry a `current_timestamp()`
 * default — same pattern already established by `actions/notes/_lib/` and
 * `actions/assign-tag/_lib/assignTag.ts`.
 */

const TAG_COLORS = ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899', '#6366F1'] as const;

/**
 * PHP's `$colors[array_rand($colors)]` — `array_rand()` picks one uniformly
 * random KEY from the array. Any uniform random choice over the same
 * 7-color palette satisfies this port; bit-for-bit parity with PHP's PRNG
 * is not required (per this batch's brief).
 */
export function randomTagColor(): string {
  const index = Math.floor(Math.random() * TAG_COLORS.length);
  return TAG_COLORS[index] as string;
}

interface TagIdRow {
  id: number;
}

/** `SELECT id FROM user_tags WHERE name = ? AND (line_account_id = ? OR line_account_id IS NULL)`. */
async function findTagByName(db: Kysely<TenantDB>, tagName: string, lineAccountId: number): Promise<number | null> {
  const result = await sql<TagIdRow>`
    SELECT id FROM user_tags WHERE name = ${tagName} AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
  `.execute(db);
  const row = result.rows[0];
  return row ? Number(row.id) : null;
}

/** `INSERT INTO user_tags (name, color, line_account_id, created_at) VALUES (?, ?, ?, NOW())`, color randomly chosen from `TAG_COLORS`. */
async function createTag(db: Kysely<TenantDB>, tagName: string, lineAccountId: number): Promise<number> {
  const color = randomTagColor();
  const insertResult = await db
    .insertInto('user_tags')
    .values({ name: tagName, color, line_account_id: lineAccountId, created_at: sql`NOW()` })
    .executeTakeFirstOrThrow();
  return Number(insertResult.insertId ?? 0);
}

/** `INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())`. */
async function assignTagToUser(
  db: Kysely<TenantDB>,
  userId: number,
  tagId: number,
  assignedBy: string
): Promise<void> {
  await db
    .insertInto('user_tag_assignments')
    // line_account_id intentionally omitted — PHP's prepared statement never
    // binds it either (matches the sibling assign-tag INSERT IGNORE's own
    // omission); the column is NOT NULL DEFAULT 1, so MySQL applies that
    // default.
    .values({ user_id: userId, tag_id: tagId, assigned_by: assignedBy, created_at: sql`NOW()` })
    .ignore()
    .execute();
}

export interface AddCustomerTagResult {
  tagId: number;
}

export async function addCustomerTagAction(
  db: Kysely<TenantDB>,
  userId: number,
  tagName: string,
  lineAccountId: number,
  assignedBy: string
): Promise<AddCustomerTagResult> {
  const existingTagId = await findTagByName(db, tagName, lineAccountId);
  const tagId = existingTagId ?? (await createTag(db, tagName, lineAccountId));

  await assignTagToUser(db, userId, tagId, assignedBy);

  return { tagId };
}

/**
 * PHP's `$adminId ?? 'Admin'` (`api/inbox-v2.php` line 2085), where
 * `$adminId` is the file-level `$_SESSION['admin_id'] ?? $_GET['admin_id']
 * ?? $_POST['admin_id'] ?? null` (line 71). `TenantSession.adminUserId`
 * (`@reya/auth`) is always a number — this fallback is therefore
 * structurally unreachable via this Route Handler today, but kept for
 * literal parity with the broader PHP expression (which genuinely can be
 * null when no session/`admin_id` request param is present) and to keep the
 * `assigned_by varchar(50)` column fed a string either way. Copied verbatim
 * from `actions/assign-tag/_lib/assignTag.ts`'s own `resolveAssignedBy()`
 * (not imported — see that file's own module doc for why every consumer
 * keeps its own copy).
 */
export function resolveAssignedBy(adminUserId: number | null | undefined): string {
  return adminUserId !== null && adminUserId !== undefined ? String(adminUserId) : 'Admin';
}
