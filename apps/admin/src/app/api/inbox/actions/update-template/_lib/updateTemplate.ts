import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * updateTemplate.ts — literal port of `classes/TemplateService.php`'s
 * `getById()` (lines 46-58) + `updateTemplate()` (lines 110-159), backing
 * `api/inbox-v2.php`'s `case 'update_template':` (lines 2277-2325).
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
 * public function updateTemplate(int $id, array $data): bool {
 *     $template = $this->getById($id);
 *     if (!$template) {
 *         return false;
 *     }
 *
 *     $fields = [];
 *     $params = [];
 *
 *     if (isset($data['name'])) {
 *         $name = trim($data['name']);
 *         if (empty($name)) {
 *             throw new InvalidArgumentException('Template name cannot be empty');
 *         }
 *         $fields[] = 'name = ?';
 *         $params[] = $name;
 *     }
 *
 *     if (isset($data['content'])) {
 *         $content = trim($data['content']);
 *         if (empty($content)) {
 *             throw new InvalidArgumentException('Template content cannot be empty');
 *         }
 *         $fields[] = 'content = ?';
 *         $params[] = $content;
 *     }
 *
 *     if (isset($data['category'])) {
 *         $fields[] = 'category = ?';
 *         $params[] = trim($data['category']);
 *     }
 *
 *     if (array_key_exists('quick_reply', $data)) {
 *         $fields[] = 'quick_reply = ?';
 *         $params[] = $data['quick_reply'];
 *     }
 *
 *     if (empty($fields)) {
 *         return true; // Nothing to update
 *     }
 *
 *     $params[] = $id;
 *     $params[] = $this->lineAccountId;
 *
 *     $sql = "UPDATE quick_reply_templates SET " . implode(', ', $fields) .
 *            " WHERE id = ? AND line_account_id = ?";
 *
 *     $stmt = $this->db->prepare($sql);
 *     return $stmt->execute($params);
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * WHY `'key' in data` IS THE RIGHT PORT OF BOTH `isset()` AND `array_key_exists()` HERE
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `updateTemplate()` uses `isset($data['name'])`/`isset($data['content'])`/
 * `isset($data['category'])` (false for an explicit `null` value) but
 * `array_key_exists('quick_reply', $data)` (true even for an explicit `null`
 * value) for the fourth field. This looks like it matters, but by the time
 * `$data` reaches THIS method it was already built by `../route.ts`'s own
 * `isset()`-gated payload construction (mirroring `api/inbox-v2.php`'s
 * `case 'update_template':` case-block body) — which for `name`/`content`/
 * `category` only ever assigns a key when the caller's `isset()` check was
 * already `true` (so those three keys, if present in `$data` at all, are
 * NEVER `null`), and for `quick_reply` specifically assigns `null` itself
 * (the `'' -> null` coercion) while still adding the key. So: whenever a key
 * exists in the `$data` this method receives, `isset($data[key])` and
 * `array_key_exists(key, $data)` agree for ALL FOUR fields in practice —
 * key presence is the only thing that ever varies. `'name' in data` /
 * `'content' in data` / `'category' in data` / `'quick_reply' in data` below
 * therefore replicate the OBSERVABLE behavior of all four PHP checks
 * exactly, given the payload shape `UpdateTemplatePayload` guarantees. The
 * genuinely load-bearing `isset()`-vs-`null` distinction lives one layer up,
 * in `../route.ts`'s own payload-building step — see that file's module doc
 * for the full "quick_reply: null leaves the column untouched, quick_reply:
 * '' clears it" write-up.
 *
 * `empty($name)`/`empty($content)` (post-`trim()`) are PHP's string
 * `empty()` semantics — `true` for exactly `''` and the literal string
 * `'0'`, not a generic falsy/whitespace check. `category` is trimmed
 * UNCONDITIONALLY with NO emptiness check (an empty category is a valid,
 * intentional "clear the category" update) — do not add one.
 *
 * `if (empty($fields)) return true;` — PHP's early return when NONE of the
 * four `isset()`/`array_key_exists()` checks matched anything in `$data`.
 * In practice this is unreachable from `../route.ts` (which already
 * rejects an empty payload with its own `'No data to update'` 400 BEFORE
 * ever calling this function — see that file's module doc), but ported
 * anyway for literal parity with the PHP source, same precedent as every
 * other structurally-unreachable-but-literally-ported branch in this
 * `api/inbox/actions/*` family.
 *
 * `quick_reply_templates` (columns `id`, `line_account_id`, `name`,
 * `content`, `category`, `quick_reply`, ...) is confirmed present in
 * `packages/db/src/generated/tenant-db.d.ts` (`QuickReplyTemplates`) with
 * every column this UPDATE touches correctly typed — a fully literal,
 * unmodified port, no schema-drift fix needed.
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
 * `../../delete-template/**` — same "every consumer keeps its own copy"
 * precedent this whole `api/inbox/actions/*` family already established.
 */

export interface UpdateTemplatePayload {
  name?: string;
  content?: string;
  category?: string;
  quick_reply?: string | null;
}

export interface TemplateServiceHandle {
  updateTemplate(id: number, data: UpdateTemplatePayload): Promise<boolean>;
}

/** Port of `loadService('TemplateService', $db, $lineAccountId)` — see module doc. */
export function loadTemplateService(db: Kysely<TenantDB>, lineAccountId: number): TemplateServiceHandle | null {
  return {
    updateTemplate: (id, data) => updateTemplate(db, lineAccountId, id, data),
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

/** PHP `empty($v)` on a value already known to be a `string` (post-`trim()`) — true for `''` or the literal `'0'`. */
function isEmptyPhpString(value: string): boolean {
  return value === '' || value === '0';
}

export async function updateTemplate(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  id: number,
  data: UpdateTemplatePayload
): Promise<boolean> {
  const existing = await getTemplateById(db, lineAccountId, id);
  if (!existing) {
    return false;
  }

  const fields: { name?: string; content?: string; category?: string; quick_reply?: string | null } = {};

  if ('name' in data) {
    const name = (data.name ?? '').trim();
    if (isEmptyPhpString(name)) {
      throw new Error('Template name cannot be empty');
    }
    fields.name = name;
  }

  if ('content' in data) {
    const content = (data.content ?? '').trim();
    if (isEmptyPhpString(content)) {
      throw new Error('Template content cannot be empty');
    }
    fields.content = content;
  }

  if ('category' in data) {
    fields.category = (data.category ?? '').trim();
  }

  if ('quick_reply' in data) {
    fields.quick_reply = data.quick_reply ?? null;
  }

  if (Object.keys(fields).length === 0) {
    return true; // Nothing to update — PHP's `empty($fields)` early return. See module doc.
  }

  await db
    .updateTable('quick_reply_templates')
    .set(fields)
    .where('id', '=', id)
    .where('line_account_id', '=', lineAccountId)
    .execute();

  return true;
}
