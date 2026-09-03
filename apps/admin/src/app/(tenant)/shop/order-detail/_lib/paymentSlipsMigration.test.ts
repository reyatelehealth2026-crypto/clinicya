import { readFileSync } from 'node:fs';
import { existsSync } from 'node:fs';
import path from 'node:path';

/**
 * paymentSlipsMigration.test.ts — direct TEXT comparison (no live DB) that
 * `packages/db/migrations/tenant/migration_2026-08-14_
 * payment_slips_verification.sql`'s column list/types/ALTER order match
 * `install/migration_payment_slips_verification.php`'s two ALTER TABLE
 * statements exactly, per this batch's acceptance criteria.
 *
 * Lives under this page's own `_lib/` (not under packages/db) because this
 * batch's allowed-paths boundary only grants two named exceptions inside
 * packages/db — the migration file itself and a surgical tenant-db.d.ts
 * patch — neither of which is "a new test file". Walks up from `__dirname`
 * to the repo root (marked by `pnpm-workspace.yaml`) rather than hardcoding
 * a `../../../../../../../..` depth, so this test survives the page ever
 * moving to a different nesting depth.
 */

function findRepoRoot(startDir: string): string {
  let dir = startDir;
  for (let i = 0; i < 20; i++) {
    if (existsSync(path.join(dir, 'pnpm-workspace.yaml'))) {
      return dir;
    }
    const parent = path.dirname(dir);
    if (parent === dir) break;
    dir = parent;
  }
  throw new Error('Could not locate repo root (pnpm-workspace.yaml) walking up from ' + startDir);
}

const REPO_ROOT = findRepoRoot(__dirname);
const PHP_SOURCE_PATH = path.join(REPO_ROOT, 'install', 'migration_payment_slips_verification.php');
const SQL_MIGRATION_PATH = path.join(REPO_ROOT, 'packages', 'db', 'migrations', 'tenant', 'migration_2026-08-14_payment_slips_verification.sql');

/** Strips whitespace runs down to single spaces so formatting differences (indentation, line breaks) don't affect the comparison — only column list/types/order/AFTER-clauses/comments matter. */
function normalize(sql: string): string {
  return sql.replace(/\s+/g, ' ').trim();
}

describe('payment_slips verification migration matches the PHP install script exactly', () => {
  const phpSource = readFileSync(PHP_SOURCE_PATH, 'utf8');
  const sqlMigration = readFileSync(SQL_MIGRATION_PATH, 'utf8');

  it('the 4-column ALTER TABLE (verify_ref/verify_amount/verify_data/verified_at) matches verbatim', () => {
    const phpMatch = /ALTER TABLE payment_slips\s+ADD COLUMN verify_ref[\s\S]*?AFTER verify_data\s*/.exec(phpSource);
    expect(phpMatch).not.toBeNull();
    const phpStatement = normalize(phpMatch![0]);

    const sqlMatch = /ALTER TABLE payment_slips\s+ADD COLUMN verify_ref[\s\S]*?AFTER verify_data/.exec(sqlMigration);
    expect(sqlMatch).not.toBeNull();
    const sqlStatement = normalize(sqlMatch![0]);

    expect(sqlStatement).toBe(phpStatement);
  });

  it('the unique index statement matches verbatim', () => {
    const phpMatch = /ALTER TABLE payment_slips ADD UNIQUE INDEX uniq_verify_ref \(verify_ref\)/.exec(phpSource);
    expect(phpMatch).not.toBeNull();

    const sqlMatch = /ALTER TABLE payment_slips ADD UNIQUE INDEX uniq_verify_ref \(verify_ref\)/.exec(sqlMigration);
    expect(sqlMatch).not.toBeNull();

    expect(normalize(sqlMatch![0])).toBe(normalize(phpMatch![0]));
  });

  it('the qr_payload column statement matches verbatim', () => {
    const phpMatch = /ALTER TABLE payment_slips ADD COLUMN qr_payload TEXT DEFAULT NULL COMMENT 'Raw QR string from the slip' AFTER verified_at/.exec(
      phpSource
    );
    expect(phpMatch).not.toBeNull();

    const sqlMatch = /ALTER TABLE payment_slips ADD COLUMN qr_payload TEXT DEFAULT NULL COMMENT 'Raw QR string from the slip' AFTER verified_at/.exec(
      sqlMigration
    );
    expect(sqlMatch).not.toBeNull();

    expect(normalize(sqlMatch![0])).toBe(normalize(phpMatch![0]));
  });

  it('the migration file declares exactly the 5 new columns, in the same order as the PHP script', () => {
    const columnOrderRegex = /ADD COLUMN (\w+)/g;
    const extractOrder = (text: string): string[] => Array.from(text.matchAll(columnOrderRegex)).map((m) => m[1]!);

    expect(extractOrder(sqlMigration)).toEqual(['verify_ref', 'verify_amount', 'verify_data', 'verified_at', 'qr_payload']);
    expect(extractOrder(phpSource)).toEqual(['verify_ref', 'verify_amount', 'verify_data', 'verified_at', 'qr_payload']);
  });

  it('every statement in the migration file ends with a semicolon (required for the migrateAll.ts statement splitter)', () => {
    const statements = sqlMigration
      .split('\n')
      .filter((line) => !line.trim().startsWith('--'))
      .join('\n')
      .split(';')
      .map((s) => s.trim())
      .filter((s) => s.length > 0);
    expect(statements).toHaveLength(3);
  });
});
