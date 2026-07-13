import { describe, expect, it } from 'vitest';
import { getTenantDbContext, runWithTenantDb } from '../src/tenantDbContext';

describe('tenantDbContext', () => {
  it('returns null outside of any runWithTenantDb() scope', () => {
    expect(getTenantDbContext()).toBeNull();
  });

  it('exposes {tenantId, db} inside the callback', async () => {
    const fakeDb = { marker: 'fake-db' } as any;
    await runWithTenantDb({ tenantId: 42, db: fakeDb }, () => {
      const ctx = getTenantDbContext();
      expect(ctx).toEqual({ tenantId: 42, db: fakeDb });
    });
  });

  it('is null again after the callback returns', async () => {
    await runWithTenantDb({ tenantId: 42, db: {} as any }, () => {});
    expect(getTenantDbContext()).toBeNull();
  });

  it('propagates through nested async calls within the same scope', async () => {
    const fakeDb = { marker: 'fake-db' } as any;
    async function inner() {
      await Promise.resolve();
      return getTenantDbContext();
    }
    const result = await runWithTenantDb({ tenantId: 7, db: fakeDb }, () => inner());
    expect(result).toEqual({ tenantId: 7, db: fakeDb });
  });

  it('does not leak across concurrent, differently-scoped calls', async () => {
    const dbA = { marker: 'a' } as any;
    const dbB = { marker: 'b' } as any;

    const [resultA, resultB] = await Promise.all([
      runWithTenantDb({ tenantId: 1, db: dbA }, async () => {
        await new Promise((r) => setTimeout(r, 5));
        return getTenantDbContext();
      }),
      runWithTenantDb({ tenantId: 2, db: dbB }, async () => {
        await new Promise((r) => setTimeout(r, 1));
        return getTenantDbContext();
      }),
    ]);

    expect(resultA).toEqual({ tenantId: 1, db: dbA });
    expect(resultB).toEqual({ tenantId: 2, db: dbB });
  });
});
