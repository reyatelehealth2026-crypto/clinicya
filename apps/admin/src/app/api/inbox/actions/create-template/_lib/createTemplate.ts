import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * createTemplate.ts — literal port of `classes/TemplateService.php`'s
 * `createTemplate()` (lines 71-99), backing `api/inbox-v2.php`'s
 * `case 'create_template':` (lines 2237-2270).
 *
 * ```php
 * public function createTemplate(string $name, string $content, string $category = '', ?int $createdBy = null, ?string $quickReply = null): int {
 *     $name = trim($name);
 *     $content = trim($content);
 *     $category = trim($category);
 *
 *     if (empty($name)) {
 *         throw new InvalidArgumentException('Template name is required');
 *     }
 *
 *     if (empty($content)) {
 *         throw new InvalidArgumentException('Template content is required');
 *     }
 *
 *     $stmt = $this->db->prepare("
 *         INSERT INTO quick_reply_templates
 *         (line_account_id, name, content, category, created_by, quick_reply)
 *         VALUES (?, ?, ?, ?, ?, ?)
 *     ");
 *     $stmt->execute([
 *         $this->lineAccountId,
 *         $name,
 *         $content,
 *         $category,
 *         $createdBy,
 *         $quickReply
 *     ]);
 *
 *     return (int)$this->db->lastInsertId();
 * }
 * ```
 *
 * `empty($name)`/`empty($content)` here run on the ALREADY-`trim()`'d
 * string — PHP's `empty()` on a string is `true` for exactly `''` and the
 * literal string `'0'` (NOT a generic falsy/whitespace check), so a name
 * that trims down to the single character `'0'` throws here exactly like
 * an empty name does. `isEmptyPhpString()` below replicates precisely
 * those two cases, nothing broader — this is a SEPARATE, later check than
 * `../route.ts`'s own PRE-trim `empty($name) || empty($content)` gate (see
 * that file's own doc for how a whitespace-only name passes the route-level
 * check but still throws from here).
 *
 * `quick_reply_templates` (columns `id`, `line_account_id`, `name`,
 * `content`, `category`, `quick_reply`, `usage_count`, `last_used_at`,
 * `created_by`, `created_at`, `updated_at`) is confirmed present in
 * `packages/db/src/generated/tenant-db.d.ts` (`QuickReplyTemplates`) with
 * every column this INSERT touches (`line_account_id`, `name`, `content`,
 * `category`, `created_by`, `quick_reply`) correctly typed — a fully
 * literal, unmodified port, no schema-drift fix needed.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `loadTemplateService()` — the mockable port of PHP's `loadService(...)` gate
 * ═══════════════════════════════════════════════════════════════════════
 * Ports `api/inbox-v2.php`'s own `loadService('TemplateService', $db,
 * $lineAccountId)` (lines 84-98: `file_exists()` + `require_once` +
 * `class_exists()` -> `new $className($db, $lineAccountId)`, `null` on
 * either check failing). In THIS repo `classes/TemplateService.php` is a
 * committed file that always resolves both checks, so this factory always
 * returns a real handle on real traffic — `../route.ts`'s `503 'Template
 * service not available'` branch it backs is defensively coded and
 * structurally unreachable in production, exactly like every other
 * `loadService(...)`-gated action in this `api/inbox/actions/*` family
 * (see e.g. `../../detect-urgency/route.ts`'s own module doc for the same
 * "never fabricated as reachable" reasoning). UNLIKE those siblings,
 * though, this batch's brief calls for the branch to remain exercisable
 * from a test — `route.test.ts` does so with an explicit `jest.mock` of
 * this named export, never by finding a real way to make it return `null`.
 */

/** PHP `empty($v)` on a value already known to be a `string` (post-`trim()`) — true for `''` or the literal `'0'`. */
function isEmptyPhpString(value: string): boolean {
  return value === '' || value === '0';
}

export interface TemplateServiceHandle {
  createTemplate(
    name: string,
    content: string,
    category: string,
    createdBy: number | null,
    quickReply: string | null
  ): Promise<number>;
}

/** Port of `loadService('TemplateService', $db, $lineAccountId)` — see module doc. */
export function loadTemplateService(db: Kysely<TenantDB>, lineAccountId: number): TemplateServiceHandle | null {
  return {
    createTemplate: (name, content, category, createdBy, quickReply) =>
      createTemplate(db, lineAccountId, name, content, category, createdBy, quickReply),
  };
}

export async function createTemplate(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  name: string,
  content: string,
  category: string,
  createdBy: number | null,
  quickReply: string | null
): Promise<number> {
  const trimmedName = name.trim();
  const trimmedContent = content.trim();
  const trimmedCategory = category.trim();

  if (isEmptyPhpString(trimmedName)) {
    throw new Error('Template name is required');
  }

  if (isEmptyPhpString(trimmedContent)) {
    throw new Error('Template content is required');
  }

  const result = await db
    .insertInto('quick_reply_templates')
    .values({
      line_account_id: lineAccountId,
      name: trimmedName,
      content: trimmedContent,
      category: trimmedCategory,
      created_by: createdBy,
      quick_reply: quickReply,
    })
    .executeTakeFirstOrThrow();

  return Number(result.insertId ?? 0);
}
