import { beforeEach, describe, expect, it, vi } from 'vitest';
import { makeFakeMysqlPool, type FakeMysqlPool } from './helpers/fakeMysqlPool';

/**
 * heartbeat.ts's handler goes through BOTH forEachActiveTenant() (queries
 * the master db) AND withTenant() (queries @reya/db's tenantPoolRegistry,
 * which itself ALSO queries the master db to resolve tenantId -> db_name —
 * see packages/db/src/tenantPoolRegistry.ts). So the mocked master pool
 * below has to answer two distinct query shapes, and a fresh fake mysql2
 * pool gets created per tenant database — mirrors
 * packages/db/tests/tenantPoolRegistry.test.ts's own `setUpMasterLookups()`
 * helper.
 */
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

const ACTIVE_TENANTS = [
  { id: 1, status: 'active', display_name: 'Active Pharmacy One' },
  { id: 2, status: 'active', display_name: 'Active Pharmacy Two' },
];

const TENANT_DB_NAMES: Record<number, string> = {
  1: 'reya_tenant_0001',
  2: 'reya_tenant_0002',
};

interface InsertCall {
  sqlText: string;
  params: unknown[];
}

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
  PLATFORM_DB_NAME,
}));

let insertsByDatabase: Record<string, InsertCall[]>;
let tenantPoolsCreated: FakeMysqlPool[];

function setUpPools(): void {
  insertsByDatabase = {};
  tenantPoolsCreated = [];

  createPoolMock.mockImplementation((options: { database: string }) => {
    if (options.database === PLATFORM_DB_NAME) {
      return makeFakeMysqlPool(options.database, (sqlText, params) => {
        if (/WHERE status = 'active'/.test(sqlText)) {
          return ACTIVE_TENANTS;
        }
        if (/SELECT db_name FROM tenants WHERE id = \?/.test(sqlText)) {
          const tenantId = params[0] as number;
          const dbName = TENANT_DB_NAMES[tenantId];
          return dbName ? [{ db_name: dbName }] : [];
        }
        throw new Error(`Unexpected master-db query in heartbeat test: ${sqlText}`);
      });
    }

    insertsByDatabase[options.database] = [];
    const pool = makeFakeMysqlPool(options.database, (sqlText, params) => {
      insertsByDatabase[options.database]!.push({ sqlText, params });
      return { insertId: 1, affectedRows: 1 };
    });
    tenantPoolsCreated.push(pool);
    return pool;
  });
}

beforeEach(async () => {
  vi.resetModules();
  const { resetMasterDb } = await import('@reya/db');
  await resetMasterDb();
  createPoolMock.mockReset();
  setUpPools();
});

describe('heartbeatJob.handler', () => {
  it('issues exactly one activity_logs insert per active tenant, with the exact action/log_type/extra_data shape', async () => {
    const { heartbeatJob, HEARTBEAT_JOB_NAME } = await import('../src/jobs/heartbeat');

    expect(HEARTBEAT_JOB_NAME).toBe('worker-heartbeat');
    expect(heartbeatJob.tenantFanout).toBe(true);

    await heartbeatJob.handler({}, { tenantFanout: true });

    const dbNames = Object.keys(insertsByDatabase);
    expect(dbNames.sort()).toEqual(['reya_tenant_0001', 'reya_tenant_0002']);

    for (const [tenantId, dbName] of Object.entries(TENANT_DB_NAMES)) {
      const calls = insertsByDatabase[dbName]!;
      expect(calls).toHaveLength(1); // exactly one insert per active tenant

      const [{ sqlText, params }] = calls;
      expect(sqlText.toLowerCase()).toContain('insert');
      expect(sqlText.toLowerCase()).toContain('activity_logs');

      expect(params).toEqual(
        expect.arrayContaining(['worker.heartbeat', 'system', 'apps/worker heartbeat scaffold job'])
      );

      const extraDataParam = params.find(
        (p): p is string => typeof p === 'string' && p.includes('"tenantId"')
      );
      expect(extraDataParam).toBeDefined();
      const parsedExtraData = JSON.parse(extraDataParam!) as { tenantId: number; ranAt: string };
      expect(parsedExtraData.tenantId).toBe(Number(tenantId));
      expect(() => new Date(parsedExtraData.ranAt).toISOString()).not.toThrow();
      expect(new Date(parsedExtraData.ranAt).toISOString()).toBe(parsedExtraData.ranAt);
    }
  });

  it('issues zero inserts when no tenant is active', async () => {
    ACTIVE_TENANTS.length = 0; // mutate shared fixture for this one test
    try {
      const { heartbeatJob } = await import('../src/jobs/heartbeat');
      await heartbeatJob.handler({}, { tenantFanout: true });
      expect(Object.keys(insertsByDatabase)).toHaveLength(0);
    } finally {
      ACTIVE_TENANTS.push(
        { id: 1, status: 'active', display_name: 'Active Pharmacy One' },
        { id: 2, status: 'active', display_name: 'Active Pharmacy Two' }
      );
    }
  });
});
