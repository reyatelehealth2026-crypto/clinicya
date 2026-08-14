import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * addCustomerNote.ts — the insert behind `api/inbox-v2.php`'s
 * `case 'add_customer_note':` (lines 2026-2049):
 *
 * ```php
 * case 'add_customer_note':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *
 *     $userId = (int) ($_POST['user_id'] ?? 0);
 *     $content = trim($_POST['content'] ?? '');
 *
 *     if (!$userId || empty($content)) {
 *         sendError('User ID and content are required');
 *     }
 *
 *     try {
 *         // Use user_notes table only
 *         $stmt = $db->prepare("INSERT INTO user_notes (user_id, note, created_by, created_at) VALUES (?, ?, ?, NOW())");
 *         $stmt->execute([$userId, $content, $adminId ?? null]);
 *
 *         sendResponse([
 *             'success' => true,
 *             'message' => 'Note added successfully',
 *             'note_id' => $db->lastInsertId()
 *         ]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to add note: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * NOT THE SAME ROUTE AS THE ALREADY-MERGED `actions/notes/route.ts`
 * ═══════════════════════════════════════════════════════════════════════
 * `actions/notes/route.ts` ports ROOT `inbox-v2.php`'s (NOT `api/inbox-v2.php`'s)
 * same-page-AJAX `case 'save_note':` — a DIFFERENT PHP FILE's OWN switch
 * statement entirely:
 *
 * ```php
 * // inbox-v2.php, case 'save_note': (lines 414-429)
 * $stmt = $db->prepare("INSERT INTO user_notes (user_id, note, created_at) VALUES (?, ?, NOW())");
 * $stmt->execute([$userId, $note]);
 * ```
 *
 * The SQL text differs in exactly one place — `save_note`'s INSERT has NO
 * `created_by` column at all (3 columns: `user_id`, `note`, `created_at`),
 * while THIS action's INSERT (`add_customer_note`, `api/inbox-v2.php`) has
 * FOUR: `user_id`, `note`, `created_by`, `created_at` — `created_by` is
 * bound to the acting admin's ID here and is genuinely absent from
 * `save_note`'s statement, not merely `NULL`-bound. `save_note`'s response
 * shape is also different (`{success, id}`, no `message` key) from this
 * action's (`{success, message, note_id}`). Both write to the same
 * `user_notes` table (the shared target this batch's brief calls out as the
 * collision to check) but are otherwise unrelated ports of unrelated PHP
 * case blocks. This file does not import from, and is not imported by,
 * `actions/notes/**`.
 *
 * `created_by` is bound to `$adminId ?? null` (`api/inbox-v2.php` line 71:
 * `$adminId = $_SESSION['admin_id'] ?? $_GET['admin_id'] ?? $_POST['admin_id'] ?? null;`).
 * `TenantSession.adminUserId` (`@reya/auth`) is always a number — this
 * Route Handler therefore always binds a real admin ID (never `null`), so
 * the `?? null` fallback is structurally unreachable here, but the
 * parameter is still typed nullable for literal parity with the broader PHP
 * expression (which genuinely can be `null` when no session/request param
 * is present).
 *
 * `user_notes` (columns `user_id`/`note`/`created_by`/`created_at`) is
 * confirmed present in `packages/db/src/generated/tenant-db.d.ts` — a fully
 * literal, unmodified port, no schema-drift fix needed. `line_account_id`
 * is DELIBERATELY OMITTED from the INSERT below — PHP's prepared statement
 * never binds it either, and the column is `NOT NULL DEFAULT 1` (per
 * `UserNotes.line_account_id: Generated<number>`), so MySQL applies that
 * default — same precedent as `actions/notes/_lib/`'s own equivalent
 * omission and `actions/assign-tag/_lib/assignTag.ts`'s.
 */
export interface AddCustomerNoteResult {
  noteId: number;
}

export async function addCustomerNote(
  db: Kysely<TenantDB>,
  userId: number,
  content: string,
  createdBy: number | null
): Promise<AddCustomerNoteResult> {
  const insertResult = await db
    .insertInto('user_notes')
    // line_account_id intentionally omitted — see module doc.
    .values({ user_id: userId, note: content, created_by: createdBy, created_at: sql`NOW()` })
    .executeTakeFirstOrThrow();

  return { noteId: Number(insertResult.insertId ?? 0) };
}
