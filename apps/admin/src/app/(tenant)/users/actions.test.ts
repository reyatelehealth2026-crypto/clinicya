import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('./_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRevalidatePath = jest.fn();
jest.mock('next/cache', () => ({
  revalidatePath: (path: string) => mockRevalidatePath(path),
}));

// actions.ts is a 'use server' module — importing it in a plain Jest test works fine
// (Next's SWC 'use server' transform only matters inside the real Next build/runtime;
// under next/jest these are just normal async function exports).
import { assignTagAction, bulkAssignTagAction, bulkRemoveTagAction, getUserTagsAction, removeTagAction } from './actions';

function fakeSession(): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 1,
    tenantId: 1,
    currentBotId: 5,
    role: 'admin',
    username: 'admin',
    displayName: 'Admin',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
  };
}

/** Wires requireTenantPageContext() to return a fake Kysely<TenantDB> backed by the given query implementation, and returns the recorded-query array for assertions. */
function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ insertId: 1, affectedRows: 1, changedRows: 0 })): {
  db: Kysely<TenantDB>;
  queries: RecordedQuery[];
} {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: fakeSession() });
  return { db, queries };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('assignTagAction', () => {
  it('runs an INSERT IGNORE with assigned_by=manual', async () => {
    const { queries } = wireFakeDb();
    const result = await assignTagAction(10, 3);
    expect(result).toEqual({ success: true });
    expect(queries[0]?.sql).toContain('INSERT IGNORE INTO user_tag_assignments');
    expect(queries[0]?.sql).toContain("'manual'");
    expect(queries[0]?.params).toEqual([10, 3]);
  });

  it('is a no-op (does not throw) when the assignment already exists (affectedRows=0 from INSERT IGNORE)', async () => {
    wireFakeDb(() => ({ insertId: 0, affectedRows: 0, changedRows: 0 }));
    await expect(assignTagAction(10, 3)).resolves.toEqual({ success: true });
  });

  it('rejects a missing/zero userId or tagId before touching the DB', async () => {
    const { queries } = wireFakeDb();
    await expect(assignTagAction(0, 3)).rejects.toThrow('Missing required fields');
    await expect(assignTagAction(10, 0)).rejects.toThrow('Missing required fields');
    expect(queries).toHaveLength(0);
  });
});

describe('removeTagAction', () => {
  it('runs a DELETE scoped to user_id AND tag_id', async () => {
    const { queries } = wireFakeDb(() => ({ affectedRows: 1 }));
    const result = await removeTagAction(10, 3);
    expect(result).toEqual({ success: true });
    expect(queries[0]?.sql).toContain('DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?');
    expect(queries[0]?.params).toEqual([10, 3]);
  });

  it('is a no-op (does not throw) when removing a tag that was never assigned (affectedRows=0)', async () => {
    wireFakeDb(() => ({ affectedRows: 0 }));
    await expect(removeTagAction(10, 999)).resolves.toEqual({ success: true });
  });
});

describe('bulkAssignTagAction', () => {
  it('loops one INSERT IGNORE per user id and counts only actually-inserted rows', async () => {
    let call = 0;
    const { queries } = wireFakeDb(() => {
      call++;
      // First user already has the tag (duplicate -> affectedRows 0), second is new.
      return { insertId: call, affectedRows: call === 1 ? 0 : 1, changedRows: 0 };
    });
    const result = await bulkAssignTagAction([10, 11], 3);
    expect(result).toEqual({ success: true, count: 1 });
    expect(queries).toHaveLength(2);
    expect(queries.every((q) => q.sql.includes("'bulk'"))).toBe(true);
    expect(mockRevalidatePath).toHaveBeenCalledWith('/users');
  });

  it('throws on an empty user id list without touching the DB', async () => {
    const { queries } = wireFakeDb();
    await expect(bulkAssignTagAction([], 3)).rejects.toThrow('Missing required fields');
    expect(queries).toHaveLength(0);
  });

  it('throws when tagId is falsy', async () => {
    await wireFakeDb();
    await expect(bulkAssignTagAction([10], 0)).rejects.toThrow('Missing required fields');
  });
});

describe('bulkRemoveTagAction', () => {
  it('loops one DELETE per user id and counts only actually-deleted rows', async () => {
    let call = 0;
    const { queries } = wireFakeDb(() => {
      call++;
      return { insertId: 0, affectedRows: call === 1 ? 1 : 0, changedRows: 0 };
    });
    const result = await bulkRemoveTagAction([10, 11], 3);
    expect(result).toEqual({ success: true, count: 1 });
    expect(queries).toHaveLength(2);
    expect(mockRevalidatePath).toHaveBeenCalledWith('/users');
  });
});

describe('getUserTagsAction', () => {
  it('joins user_tags to user_tag_assignments scoped by user_id', async () => {
    const { queries } = wireFakeDb(() => [{ id: 1, name: 'VIP', color: '#ff0000' }]);
    const tags = await getUserTagsAction(10);
    expect(tags).toEqual([{ id: 1, name: 'VIP', color: '#ff0000' }]);
    expect(queries[0]?.sql).toContain('JOIN user_tag_assignments a ON t.id = a.tag_id');
    expect(queries[0]?.sql).toContain('WHERE a.user_id = ?');
    expect(queries[0]?.params).toEqual([10]);
  });

  it('returns an empty list when the user has no tags', async () => {
    wireFakeDb(() => []);
    await expect(getUserTagsAction(10)).resolves.toEqual([]);
  });
});
