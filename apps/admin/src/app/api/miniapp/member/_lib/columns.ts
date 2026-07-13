import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * columns.ts — dynamic-column detection + member ID generation, ported from api/member.php.
 * Read api/member.php in full (812 lines) before editing this file.
 *
 * WHY DYNAMIC COLUMN DETECTION AT ALL: api/member.php runs `SHOW COLUMNS FROM users` before building
 * its INSERT/UPDATE statements (handleRegister/autoRegisterMember/autoUpgradeMember) because some
 * tenant DBs have drifted from the canonical template (plan §4 cross-cutting workstream #1: "ปีของ
 * ensureColumn ทำให้ tenants เพี้ยนจาก template"). @reya/db's generated `Users` type reflects the
 * CANONICAL template schema, not any one tenant's actual live columns — so this port intentionally
 * does NOT trust that generated type for column presence and instead re-runs the same `SHOW COLUMNS`
 * check PHP does, at request time, exactly like the original. Do not "simplify" this into a hardcoded
 * column list — that would silently drop optional columns some tenant schemas lack, which is precisely
 * the bug class this file exists to avoid (brief: "port the dynamic-columns behavior faithfully").
 */

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

/** Best-effort `SHOW COLUMNS FROM users` -> Set of column names. Empty set on any failure (mirrors PHP's `catch (Exception $e) {}`). */
export async function getExistingUserColumns(db: Kysely<TenantDB>): Promise<Set<string>> {
  try {
    const result = await sql<{ Field: string }>`SHOW COLUMNS FROM users`.execute(db);
    return new Set(result.rows.map((row) => row.Field));
  } catch {
    return new Set();
  }
}

/**
 * Port of api/member.php::generateMemberId(). PHP's `date('y')` is the Gregorian (NOT Buddhist-era)
 * 2-digit year — Buddhist-era +543 formatting is a Phase 5 (documents/VAT) concern, not this one;
 * verified against the literal PHP source, which calls bare `date('y')`. Computed via an explicit
 * Asia/Bangkok Intl timeZone rather than server-local time, per CLAUDE.md's dates convention.
 */
export async function generateMemberId(db: Kysely<TenantDB>, lineAccountId: number, now: Date = new Date()): Promise<string> {
  const prefix = 'M';
  const yy = new Intl.DateTimeFormat('en-GB', { timeZone: BANGKOK_TIME_ZONE, year: '2-digit' }).format(now);

  const result = await sql<{ member_id: string }>`
    SELECT member_id FROM users
    WHERE member_id LIKE ${prefix + yy + '%'} AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
    ORDER BY member_id DESC LIMIT 1
  `.execute(db);

  const last = result.rows[0]?.member_id;
  const match = last ? /^M\d{2}(\d{5})$/.exec(last) : null;
  const nextNum = match ? parseInt(match[1]!, 10) + 1 : 1;

  return prefix + yy + String(nextNum).padStart(5, '0');
}
