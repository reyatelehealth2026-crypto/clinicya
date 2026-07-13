import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  applyMigrationViaPool,
  createDefaultMigrateAllDeps,
  getAppliedMigrationFilesFromLedger,
  listTenantsFromMaster,
  migrateAll,
  parseMigrateAllArgs,
  recordMigrationToLedger,
  splitSqlStatements,
  type MigrateAllDeps,
  type MigrationFile,
  type TenantTarget,
} from '../src/migrateAll';
import { resetMasterDb } from '../src/masterPool';
import { tenantPoolRegistry } from '../src/tenantPoolRegistry';
import { makeFakeMysqlPool, type FakeMysqlPool, type QueryImpl } from './helpers/fakeMysqlPool';

/** Overridden per-test right before invoking the function under test. */
let currentQueryImpl: QueryImpl = () => [];

const { createPoolMock } = vi.hoisted(() => ({ createPoolMock: vi.fn() }));

vi.mock('mysql2', () => ({
  createPool: createPoolMock,
}));

vi.mock('@reya/config', () => ({
  loadEnv: vi.fn(() => ({
    NODE_ENV: 'test',
    DB_HOST: 'db-host.internal',
    DB_USER: 'reya_user',
    DB_PASS: 'reya_pass',
    REYA_BASE_DOMAIN: 're-ya.com',
    REDIS_URL: 'redis://redis:6379',
  })),
  PLATFORM_DB_NAME: 'zrismpsz_reya_platform',
}));

function migrationFile(filename: string, sql = 'SELECT 1;'): MigrationFile {
  return { filename, sql, checksum: `checksum-${filename}` };
}

function fakeTenants(): TenantTarget[] {
  return [
    { id: 1, dbName: 'reya_tenant_0001', status: 'active' },
    { id: 2, dbName: 'reya_tenant_0002', status: 'active' },
  ];
}

describe('parseMigrateAllArgs', () => {
  it('parses --tenant, --dry-run, --continue-on-error together', () => {
    expect(parseMigrateAllArgs(['--tenant=42', '--dry-run', '--continue-on-error'])).toEqual({
      tenantId: 42,
      dryRun: true,
      continueOnError: true,
    });
  });

  it('defaults to an empty options object for no args', () => {
    expect(parseMigrateAllArgs([])).toEqual({});
  });

  it('rejects a non-numeric --tenant value', () => {
    expect(() => parseMigrateAllArgs(['--tenant=abc'])).toThrow(/positive integer/);
  });

  it('rejects a zero/negative --tenant value', () => {
    expect(() => parseMigrateAllArgs(['--tenant=0'])).toThrow(/positive integer/);
    expect(() => parseMigrateAllArgs(['--tenant=-5'])).toThrow(/positive integer/);
  });

  it('rejects --tenant with no value', () => {
    expect(() => parseMigrateAllArgs(['--tenant'])).toThrow(/requires a value/);
  });

  it('rejects an unknown flag', () => {
    expect(() => parseMigrateAllArgs(['--bogus'])).toThrow(/Unknown migrate-all argument/);
  });
});

describe('splitSqlStatements', () => {
  it('splits on semicolons and strips full-line comments', () => {
    const sql = `-- a comment\nCREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\n`;
    expect(splitSqlStatements(sql)).toEqual(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)']);
  });

  it('drops empty statements', () => {
    expect(splitSqlStatements('SELECT 1;;;  ;')).toEqual(['SELECT 1']);
  });
});

describe('migrateAll — pure runner (mocked deps, no real DB/filesystem)', () => {
  let deps: MigrateAllDeps;
  let recordCalls: unknown[];

  beforeEach(() => {
    recordCalls = [];
    deps = {
      listTenants: vi.fn(async () => fakeTenants()),
      loadMigrations: vi.fn(async () => [migrationFile('migration_a.sql'), migrationFile('migration_b.sql')]),
      getAppliedMigrationFiles: vi.fn(async () => new Set<string>()),
      applyMigration: vi.fn(async () => ({ executionMs: 12 })),
      recordMigration: vi.fn(async (entry) => {
        recordCalls.push(entry);
      }),
    };
  });

  it('applies every pending migration to every tenant and writes an "applied" ledger row for each', async () => {
    const result = await migrateAll({}, deps);

    expect(result.applied).toHaveLength(4); // 2 tenants x 2 migrations
    expect(result.failed).toHaveLength(0);
    expect(result.aborted).toBe(false);
    expect(deps.applyMigration).toHaveBeenCalledTimes(4);
    expect(deps.recordMigration).toHaveBeenCalledTimes(4);
    expect(recordCalls[0]).toMatchObject({ tenantId: 1, migrationFile: 'migration_a.sql', status: 'applied' });
  });

  it('skips migrations already recorded in the ledger', async () => {
    deps.getAppliedMigrationFiles = vi.fn(async (tenantId: number) =>
      tenantId === 1 ? new Set(['migration_a.sql']) : new Set<string>()
    );

    const result = await migrateAll({}, deps);

    expect(result.skipped).toEqual([{ tenantId: 1, dbName: 'reya_tenant_0001', migrationFile: 'migration_a.sql' }]);
    expect(deps.applyMigration).toHaveBeenCalledTimes(3); // 4 total - 1 skipped
  });

  it('--tenant limits the run to a single tenant', async () => {
    const result = await migrateAll({ tenantId: 2 }, deps);

    expect(result.applied.every((item) => item.tenantId === 2)).toBe(true);
    expect(result.applied).toHaveLength(2);
  });

  it('--dry-run computes the plan (incl. skips) without calling applyMigration or recordMigration', async () => {
    deps.getAppliedMigrationFiles = vi.fn(async (tenantId: number) =>
      tenantId === 1 ? new Set(['migration_a.sql']) : new Set<string>()
    );

    const result = await migrateAll({ dryRun: true }, deps);

    expect(deps.applyMigration).not.toHaveBeenCalled();
    expect(deps.recordMigration).not.toHaveBeenCalled();
    expect(result.applied).toHaveLength(0);
    expect(result.skipped).toHaveLength(1);
    // planned = every pending (tenant, migration) pair that isn't already applied
    expect(result.planned).toHaveLength(3);
    expect(result.options).toEqual({ dryRun: true, continueOnError: false, tenantId: undefined });
  });

  it('without --continue-on-error, a failure aborts the whole run and still records a "failed" ledger row', async () => {
    deps.applyMigration = vi.fn(async (target: TenantTarget, migration: MigrationFile) => {
      if (target.id === 1 && migration.filename === 'migration_b.sql') {
        throw new Error('boom');
      }
      return { executionMs: 1 };
    });

    const result = await migrateAll({}, deps);

    expect(result.aborted).toBe(true);
    expect(result.failed).toEqual([
      { tenantId: 1, dbName: 'reya_tenant_0001', migrationFile: 'migration_b.sql', error: 'boom' },
    ]);
    expect(recordCalls).toContainEqual(
      expect.objectContaining({ tenantId: 1, migrationFile: 'migration_b.sql', status: 'failed', errorMessage: 'boom' })
    );
    // Tenant 2 was never reached.
    expect(result.applied.some((item) => item.tenantId === 2)).toBe(false);
  });

  it('with --continue-on-error, remaining tenants/migrations still run after a failure', async () => {
    deps.applyMigration = vi.fn(async (target: TenantTarget, migration: MigrationFile) => {
      if (target.id === 1 && migration.filename === 'migration_b.sql') {
        throw new Error('boom');
      }
      return { executionMs: 1 };
    });

    const result = await migrateAll({ continueOnError: true }, deps);

    expect(result.aborted).toBe(false);
    expect(result.failed).toHaveLength(1);
    // tenant 1's migration_a + tenant 2's migration_a and migration_b all still applied.
    expect(result.applied).toHaveLength(3);
    expect(result.applied.some((item) => item.tenantId === 2 && item.migrationFile === 'migration_b.sql')).toBe(true);
  });

  it('a ledger-write failure never crashes the runner', async () => {
    deps.recordMigration = vi.fn(async () => {
      throw new Error('ledger unavailable');
    });

    await expect(migrateAll({}, deps)).resolves.not.toThrow();
  });
});

describe('default DB-backed deps (mocked mysql2 — spy on the query call)', () => {
  let masterPool: FakeMysqlPool;

  beforeEach(async () => {
    // masterPool.ts's pool + tenantPoolRegistry's map are module-level
    // singletons; reset them explicitly (rather than vi.resetModules(),
    // which would be a no-op here since migrateAll.ts is imported statically
    // above) so each test gets its own fresh createPool() call to assert
    // against and never sees another test's cached pool/db_name.
    await resetMasterDb();
    await tenantPoolRegistry.closeAll();
    tenantPoolRegistry.clearDbNameCache();
    createPoolMock.mockReset();
    currentQueryImpl = () => [];
    createPoolMock.mockImplementation((options: { database: string }) => {
      const pool = makeFakeMysqlPool(options.database, (sqlText, params) => currentQueryImpl(sqlText, params));
      if (options.database === 'zrismpsz_reya_platform') {
        masterPool = pool;
      }
      return pool;
    });
  });

  it('recordMigrationToLedger writes an INSERT ... ON DUPLICATE KEY UPDATE against tenant_migrations', async () => {
    currentQueryImpl = () => ({ insertId: 0, affectedRows: 1, changedRows: 0 });

    await recordMigrationToLedger({
      tenantId: 7,
      migrationFile: 'migration_x.sql',
      checksum: 'abc123',
      executionMs: 42,
      status: 'applied',
    });

    expect(masterPool!.connection.query).toHaveBeenCalledTimes(1);
    const [sqlText, params] = masterPool!.connection.query.mock.calls[0]!;
    expect(sqlText).toMatch(/INSERT INTO tenant_migrations/);
    expect(sqlText).toMatch(/ON DUPLICATE KEY UPDATE/);
    expect(params).toEqual([7, 'migration_x.sql', 'abc123', 42, 'applied', null, null]);
  });

  it('listTenantsFromMaster queries active|pending_setup tenants from master.tenants', async () => {
    currentQueryImpl = () => [
      { id: 1, db_name: 'reya_tenant_0001', status: 'active' },
      { id: 2, db_name: 'reya_tenant_0002', status: 'pending_setup' },
    ];

    const tenants = await listTenantsFromMaster();

    expect(masterPool!.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/FROM tenants WHERE status IN/),
      [],
      expect.any(Function)
    );
    expect(tenants).toEqual([
      { id: 1, dbName: 'reya_tenant_0001', status: 'active' },
      { id: 2, dbName: 'reya_tenant_0002', status: 'pending_setup' },
    ]);
  });

  it('getAppliedMigrationFilesFromLedger returns a Set of already-applied filenames for a tenant', async () => {
    currentQueryImpl = () => [{ migration_file: 'migration_a.sql' }];

    const applied = await getAppliedMigrationFilesFromLedger(5);

    expect(masterPool!.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/tenant_migrations WHERE tenant_id = \?/),
      [5],
      expect.any(Function)
    );
    expect(applied).toEqual(new Set(['migration_a.sql']));
  });

  it('applyMigrationViaPool runs each split statement through the tenant pool via sql.raw()', async () => {
    const executed: string[] = [];
    currentQueryImpl = (sqlText: string) => {
      // tenantPoolRegistry.getTenantDb() resolves db_name via a query against
      // the SAME mocked createPool factory (master pool) before ever touching
      // the tenant pool — branch on the SQL text to answer both correctly.
      if (sqlText.includes('SELECT db_name FROM tenants')) {
        return [{ db_name: 'reya_tenant_0777' }];
      }
      executed.push(sqlText);
      return { insertId: 0, affectedRows: 0, changedRows: 0 };
    };

    const target: TenantTarget = { id: 777, dbName: 'reya_tenant_0777' };
    const migration = migrationFile('migration_two_statements.sql', 'CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);');

    const { executionMs } = await applyMigrationViaPool(target, migration);

    expect(executionMs).toBeGreaterThanOrEqual(0);
    expect(executed).toEqual(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)']);
    expect(createPoolMock).toHaveBeenCalledWith(expect.objectContaining({ database: 'reya_tenant_0777' }));
  });

  it('createDefaultMigrateAllDeps() wires the real implementations (no fake/stub substitution)', () => {
    const deps = createDefaultMigrateAllDeps();
    expect(deps.recordMigration).toBe(recordMigrationToLedger);
    expect(deps.listTenants).toBe(listTenantsFromMaster);
    expect(deps.getAppliedMigrationFiles).toBe(getAppliedMigrationFilesFromLedger);
    expect(deps.applyMigration).toBe(applyMigrationViaPool);
  });

  it('never opens a real TCP connection — createPool is always the mocked mysql2 export', async () => {
    currentQueryImpl = () => [];
    await getAppliedMigrationFilesFromLedger(1);
    expect(createPoolMock).toHaveBeenCalled();
  });
});
