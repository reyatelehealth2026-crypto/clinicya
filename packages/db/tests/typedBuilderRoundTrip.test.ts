import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createPool, type Pool as RawMysqlPool } from 'mysql2';
import { Kysely, MysqlDialect, sql } from 'kysely';
import type { DB as TenantDB } from '../src/generated/tenant-db';

/**
 * typedBuilderRoundTrip.test.ts — proves the typed Kysely `.selectFrom()`
 * builder actually round-trips against a live DB now that codegen no longer
 * passes `--camel-case` (see src/codegen.ts + README.md "Regenerating the
 * types" for the decision record).
 *
 * Before this fix: `src/generated/tenant-db.d.ts` declared camelCase keys
 * (e.g. `createdAt`) but neither Kysely instance in this package configures
 * a `CamelCasePlugin`, so a typed `.selectFrom('users').select('createdAt')`
 * compiled fine yet emitted SQL referencing the literal identifier
 * `createdAt`, which does not exist (the real column is `created_at`) — a
 * silent runtime failure TypeScript could never catch. This test is the
 * "prove it for real" counterpart to that: it opens a genuine mysql2
 * connection (no mocks) and runs an actual `.selectFrom()` query end to end.
 *
 * Mirrors codegen.ts's own --dry-run pattern for CI-safety: this whole file
 * no-ops (all tests skipped, exit 0) unless explicitly opted in via
 * REYA_DB_LIVE_TEST=1, because this dev container/CI has no reachable DB by
 * default. To actually run it, stand up the exact scratch MariaDB from
 * packages/db/README.md's "Regenerating the types" recipe (steps 1-4: bring
 * up the container, create `reya_tenant_scratch`, apply
 * database/migration_2026-05-25_tenant_template.sql) and then:
 *
 *   REYA_DB_LIVE_TEST=1 DB_HOST=127.0.0.1 DB_PORT=33061 DB_USER=root \
 *     DB_PASS=<scratch root password> TENANT_DB_NAME=reya_tenant_scratch \
 *     pnpm --filter @reya/db test -- typedBuilderRoundTrip
 *
 * Uses a plain mysql2 `createPool({ host, port, ... })` directly (NOT
 * @reya/config's loadEnv() + the mysql://host/db URL trick codegen.ts uses)
 * — README.md's step 5 notes that URL-folded-port trick is specific to the
 * codegen path; every other raw mysql2 Pool config in this monorepo
 * (masterPool.ts, tenantPoolRegistry.ts, and this test) takes a separate
 * `port` field instead.
 */

const LIVE = process.env.REYA_DB_LIVE_TEST === '1';

describe.skipIf(!LIVE)('typed .selectFrom() round trip (live scratch tenant DB)', () => {
  let pool: RawMysqlPool;
  let db: Kysely<TenantDB>;
  let insertedUserId: number;
  const uniqueLineUserId = `typed-builder-roundtrip-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  const displayName = 'Typed Builder Round Trip';

  beforeAll(async () => {
    pool = createPool({
      host: process.env.DB_HOST ?? '127.0.0.1',
      port: process.env.DB_PORT ? Number(process.env.DB_PORT) : 3306,
      user: process.env.DB_USER ?? 'root',
      password: process.env.DB_PASS ?? '',
      database: process.env.TENANT_DB_NAME ?? 'reya_tenant_scratch',
      charset: 'utf8mb4_unicode_ci',
      waitForConnections: true,
      queueLimit: 0,
    });
    db = new Kysely<TenantDB>({ dialect: new MysqlDialect({ pool }) });

    // Seed via a raw `sql` tag (this codebase's own house style, per
    // queries.ts's module doc) so the fixture setup doesn't itself lean on
    // the very builder path this test exists to verify.
    const insertResult = await sql`
      INSERT INTO users (line_account_id, line_user_id, display_name)
      VALUES (1, ${uniqueLineUserId}, ${displayName})
    `.execute(db);
    insertedUserId = Number(insertResult.insertId);
  });

  afterAll(async () => {
    if (insertedUserId) {
      await sql`DELETE FROM users WHERE id = ${insertedUserId}`.execute(db);
    }
    await new Promise<void>((resolve) => pool.end(() => resolve()));
  });

  it('selectFrom("users").select([...]).where(...) returns the real snake_case row', async () => {
    const row = await db
      .selectFrom('users')
      .select(['id', 'created_at', 'line_account_id', 'line_user_id', 'display_name'])
      .where('id', '=', insertedUserId)
      .executeTakeFirst();

    expect(row).toBeDefined();
    expect(row?.id).toBe(insertedUserId);
    expect(row?.line_account_id).toBe(1);
    expect(row?.line_user_id).toBe(uniqueLineUserId);
    expect(row?.display_name).toBe(displayName);
    expect(row?.created_at).toBeInstanceOf(Date);

    // The regression this test guards against: without this fix, the typed
    // builder's runtime output was keyed by the (wrong) camelCase generated
    // property names never actually present in a real mysql2 result row.
    const keys = Object.keys(row ?? {});
    expect(keys).not.toContain('createdAt');
    expect(keys).not.toContain('lineAccountId');
    expect(keys).not.toContain('lineUserId');
    expect(keys).not.toContain('displayName');
    expect(keys).toContain('created_at');
    expect(keys).toContain('line_account_id');
  });
});
