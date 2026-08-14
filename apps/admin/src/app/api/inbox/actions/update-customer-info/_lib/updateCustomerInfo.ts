import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * `@reya/db` only re-exports the whole-database `TenantDB` interface (see
 * `packages/db/src/index.ts`'s doc comment — the individual generated table
 * interfaces like `Users` are not re-exported at the package root), so the
 * per-table row type is derived here via indexed access instead of an
 * `import type { Users }`.
 *
 * KNOWN PRE-EXISTING BUILD GAP (found while wiring this file, NOT
 * introduced by it, and NOT fixed here — `packages/db/**` is outside this
 * batch's allowed paths): `packages/db/tsconfig.json`'s `"include":
 * ["src/**\/*.ts"]` never emits `dist/generated/tenant-db.d.ts` — `tsc -b`
 * does not copy a hand/codegen-authored `.d.ts` file into `outDir`, it only
 * emits declarations FOR `.ts` sources it compiles. `dist/index.d.ts`'s
 * `export type { DB as TenantDB } from './generated/tenant-db'` therefore
 * points at a module that does not exist under `dist/`, and `TenantDB`
 * silently resolves to `any` for any consumer that imports the BUILT
 * `@reya/db` package (i.e. exactly what `apps/admin`'s own `tsc --noEmit -p
 * tsconfig.json` / `npm run lint` does, and exactly what production build
 * output does too — confirmed with a throwaway probe:
 * `db.insertInto('totally_bogus_table').values({nonsense: 1}).execute()`
 * type-checks with ZERO errors against the built package). This silently
 * degrades EVERY `Kysely<TenantDB>` column/table reference across this
 * entire `api/inbox/actions/*` family to unchecked `any` today — not just
 * this file — including every already-merged sibling's `.insertInto(...)
 * .values({...})` call. `jest` never surfaces this (its `moduleNameMapper`
 * resolves `@reya/db` straight to `packages/db/src/index.ts` SOURCE, where
 * the real generated types are intact), which is why this gap was
 * previously invisible to any test suite. `keyof Users` (and therefore
 * `ALLOWED_FIELD_TO_COLUMN` below) is consequently `keyof any = string |
 * number | symbol` under `npm run lint` today, not the real finite column
 * union its own doc comment describes — intersected with `& string` below
 * purely so `sql.ref()` (which requires a plain `string`) still compiles.
 * The moment a future change to `packages/db/tsconfig.json` starts
 * emitting `dist/generated/*.d.ts` (e.g. adding those files to
 * `"include"`, or a postbuild copy step), `keyof Users` will resolve to the
 * real column-name union again with NO changes needed here, and the
 * `Record<AllowedField, keyof Users & string>` map below will immediately
 * start proving each entry names a real `Users` column at compile time, as
 * originally intended.
 */
type Users = TenantDB['users'];

/**
 * updateCustomerInfo.ts — the whitelist-gated `UPDATE users` behind
 * `api/inbox-v2.php`'s `case 'update_customer_info':` (lines 2165-2196):
 *
 * ```php
 * case 'update_customer_info':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $field = $_POST['field'] ?? $body['field'] ?? '';
 *     $value = trim($_POST['value'] ?? $body['value'] ?? '');
 *
 *     // Whitelist allowed fields
 *     $allowedFields = ['display_name', 'phone', 'address', 'email', 'real_name', 'birthday', 'province', 'postal_code', 'district', 'gender', 'note', 'member_id'];
 *
 *     if (!$userId || !in_array($field, $allowedFields)) {
 *         sendError('Invalid user ID or field');
 *     }
 *
 *     try {
 *         // If updating display_name, save to custom_display_name instead
 *         // This prevents webhook from overwriting it with LINE API data
 *         if ($field === 'display_name') {
 *             $stmt = $db->prepare("UPDATE users SET custom_display_name = ? WHERE id = ?");
 *         } else {
 *             $stmt = $db->prepare("UPDATE users SET {$field} = ? WHERE id = ?");
 *         }
 *         $stmt->execute([$value ?: null, $userId]);
 *
 *         sendResponse(['success' => true, 'message' => 'Customer info updated successfully']);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to update customer info: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * `ALLOWED_FIELDS` is ported VERBATIM — exact order, exact spelling, never
 * widened. Every entry maps to a real, identically-named column on `Users`
 * (confirmed in `packages/db/src/generated/tenant-db.d.ts`) EXCEPT
 * `display_name`, which is special-cased to write `custom_display_name`
 * instead (PHP's own comment: prevents the LINE webhook sync from
 * overwriting it).
 *
 * `ALLOWED_FIELD_TO_COLUMN` is a `Record<AllowedField, keyof Users & string>`
 * map — TypeScript itself proves every mapped value names a real `Users`
 * column at compile time, IN PRINCIPLE (the "structural proof against
 * `tenant-db.d.ts`" this batch's acceptance criteria call for) — see the
 * `KNOWN PRE-EXISTING BUILD GAP` note on this file's very first doc comment
 * for why that proof is not currently ENFORCED by `npm run lint` (a
 * pre-existing `packages/db` packaging gap outside this batch's allowed
 * paths degrades `TenantDB` to `any` under the built package every other
 * `api/inbox/actions/*` sibling's own `Kysely<TenantDB>` usage is equally
 * exposed to). The type annotation is kept anyway: it is correct today by
 * inspection against `tenant-db.d.ts`, and will start being mechanically
 * enforced the moment that gap is fixed, with no changes needed here. The resolved column
 * name is then spliced into the query via Kysely's `sql.ref()` — an
 * identifier-escaping helper (quotes it as `` `column` ``, never
 * parameterizes it as a bound value) — the SAME pattern this codebase
 * already established for dynamic-column `UPDATE`s at
 * `apps/admin/src/app/api/miniapp/member/_lib/handlers.ts`'s
 * `handleUpdateProfile()` (`sql\`${sql.ref(field)} = ${value}\``). This
 * mirrors PHP's own dynamic `{$field}` SQL-text interpolation exactly
 * (a column NAME cannot be a bound parameter in either PHP's PDO or
 * Kysely), while still binding the VALUE as a real parameter (never
 * string-concatenated), so there is no SQL-injection risk introduced by the
 * dynamic identifier — `field` is drawn only from the closed
 * `ALLOWED_FIELD_TO_COLUMN` map, never from the raw request string.
 *
 * `$value ?: null` is PHP's FALSY-STRING short-circuit, NOT a generic
 * "empty check": `?:` (Elvis) evaluates the left operand for TRUTHINESS —
 * for a string, PHP considers exactly `''` and `'0'` falsy (every other
 * string, including `' '` a single space or `'00'`, is truthy). `toNullable()`
 * below implements precisely those two cases, not a broader
 * whitespace/empty-ish check. The bound value is passed through as a plain
 * string (or `null`) even for the `birthday` column (`Users.birthday:
 * Generated<Date | null>`) — matching PHP exactly: PDO binds the trimmed
 * string as-is, relying on MySQL's own `'YYYY-MM-DD'`-string-to-`DATE`
 * coercion, never constructing a PHP `DateTime` first.
 */

export const ALLOWED_FIELDS = [
  'display_name',
  'phone',
  'address',
  'email',
  'real_name',
  'birthday',
  'province',
  'postal_code',
  'district',
  'gender',
  'note',
  'member_id',
] as const;

export type AllowedField = (typeof ALLOWED_FIELDS)[number];

export function isAllowedField(field: string): field is AllowedField {
  return (ALLOWED_FIELDS as readonly string[]).includes(field);
}

/**
 * Maps every whitelisted field to the real `Users` column it writes.
 * `display_name` is the one deliberate exception — see module doc.
 * Typed as `Record<AllowedField, keyof Users & string>` so TypeScript itself proves
 * every mapped name is a real column on `Users`.
 */
const ALLOWED_FIELD_TO_COLUMN: Record<AllowedField, keyof Users & string> = {
  display_name: 'custom_display_name',
  phone: 'phone',
  address: 'address',
  email: 'email',
  real_name: 'real_name',
  birthday: 'birthday',
  province: 'province',
  postal_code: 'postal_code',
  district: 'district',
  gender: 'gender',
  note: 'note',
  member_id: 'member_id',
};

/**
 * PHP's `$value ?: null` — Elvis operator on a string: `''` and `'0'` (the
 * two falsy string values) become `null`; every other string (including a
 * single space) passes through unchanged.
 */
export function toNullable(value: string): string | null {
  return value === '' || value === '0' ? null : value;
}

export async function updateCustomerInfo(
  db: Kysely<TenantDB>,
  userId: number,
  field: AllowedField,
  value: string
): Promise<void> {
  const column = ALLOWED_FIELD_TO_COLUMN[field];
  const boundValue = toNullable(value);

  await sql`UPDATE users SET ${sql.ref(column)} = ${boundValue} WHERE id = ${userId}`.execute(db);
}
