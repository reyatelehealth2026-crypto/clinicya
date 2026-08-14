import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * deleteTemplate.ts — literal port of `classes/TemplateService.php`'s
 * `getById()` (lines 46-58) + `deleteTemplate()` (lines 168-178), backing
 * `api/inbox-v2.php`'s `case 'delete_template':` (lines 2329-2359).
 *
 * ```php
 * public function getById(int $id): ?array {
 *     $sql = "SELECT * FROM quick_reply_templates WHERE id = ? AND line_account_id = ?";
 *     $stmt = $this->db->prepare($sql);
 *     $stmt->execute([$id, $this->lineAccountId]);
 *     $result = $stmt->fetch(PDO::FETCH_ASSOC);
 *     return $result ?: null;
 * }
 *
 * public function deleteTemplate(int $id): bool {
 *     $template = $this->getById($id);
 *     if (!$template) {
 *         return false;
 *     }
 *
 *     $stmt = $this->db->prepare(
 *         "DELETE FROM quick_reply_templates WHERE id = ? AND line_account_id = ?"
 *     );
 *     return $stmt->execute([$id, $this->lineAccountId]);
 * }
 * ```
 *
 * `getById($id)` (scoped to `line_account_id`) runs FIRST — a template that
 * doesn't exist, or exists but belongs to a different LINE account, makes
 * `deleteTemplate()` return `false` WITHOUT ever issuing the `DELETE`. This
 * port preserves that exact two-step shape (SELECT-then-DELETE, not a
 * single unconditional `DELETE ... WHERE id = ? AND line_account_id = ?`
 * that would just no-op on zero matched rows) so a not-found template never
 * issues a DELETE statement at all — `route.test.ts` asserts this directly
 * on the recorded query list.
 *
 * `quick_reply_templates` (columns `id`, `line_account_id`, ...) is
 * confirmed present in `packages/db/src/generated/tenant-db.d.ts`
 * (`QuickReplyTemplates`) — a fully literal, unmodified port, no
 * schema-drift fix needed.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `loadTemplateService()` — the mockable port of PHP's `loadService(...)` gate
 * ═══════════════════════════════════════════════════════════════════════
 * Same reasoning as `../../create-template/_lib/createTemplate.ts`'s own
 * `loadTemplateService()` doc: `classes/TemplateService.php` is a committed
 * file that always resolves in this repo, so this factory always returns a
 * real handle on real traffic — `../route.ts`'s `503` branch it backs is
 * defensive-only, exercised solely via an explicit `jest.mock` in
 * `route.test.ts`. A SEPARATE, independently-duplicated copy of this
 * factory — not imported from `../../create-template/**` or
 * `../../update-template/**` — same "every consumer keeps its own copy"
 * precedent this whole `api/inbox/actions/*` family already established.
 */

export interface TemplateServiceHandle {
  deleteTemplate(id: number): Promise<boolean>;
}

/** Port of `loadService('TemplateService', $db, $lineAccountId)` — see module doc. */
export function loadTemplateService(db: Kysely<TenantDB>, lineAccountId: number): TemplateServiceHandle | null {
  return {
    deleteTemplate: (id) => deleteTemplate(db, lineAccountId, id),
  };
}

/** Port of `TemplateService::getById()` — `SELECT * ... WHERE id = ? AND line_account_id = ?`, `null` when no row. */
async function getTemplateById(db: Kysely<TenantDB>, lineAccountId: number, id: number) {
  const row = await db
    .selectFrom('quick_reply_templates')
    .selectAll()
    .where('id', '=', id)
    .where('line_account_id', '=', lineAccountId)
    .executeTakeFirst();

  return row ?? null;
}

export async function deleteTemplate(db: Kysely<TenantDB>, lineAccountId: number, id: number): Promise<boolean> {
  const existing = await getTemplateById(db, lineAccountId, id);
  if (!existing) {
    return false;
  }

  await db.deleteFrom('quick_reply_templates').where('id', '=', id).where('line_account_id', '=', lineAccountId).execute();

  return true;
}
