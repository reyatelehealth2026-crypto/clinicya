/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { POST } from './route';

function req(body: unknown): NextRequest {
  return { json: async () => body } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 1,
    tenantId: 1,
    currentBotId: 7,
    role: 'admin',
    username: 'admin',
    displayName: 'Admin',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
    ...overrides,
  };
}

/** Wires resolveInboxApiContext() to a fake Kysely<TenantDB> answering `queryImpl`; returns the recorded queries for assertions. */
function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const TAG_ROW = {
  id: 3,
  name: 'VIP',
  color: '#ff0000',
  description: null,
  priority: 0,
  line_account_id: null,
  source_type: 'manual',
  source_id: null,
  auto_assign_rules: null,
  auto_remove_rules: null,
  created_at: '2026-07-01 00:00:00',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/tags', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, tag_id: 2, operation: 'add' }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it("operation='add' -> INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by='manual'), no line_account_id bound", async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/insert/i.test(sqlText)) return { insertId: 0, affectedRows: 1 };
      return [TAG_ROW];
    });

    const res = await POST(req({ user_id: 10, tag_id: 3, operation: 'add' }));
    expect(res.status).toBe(200);

    const insertQuery = queries.find((q) => /insert/i.test(q.sql));
    expect(insertQuery).toBeDefined();
    expect(insertQuery!.sql.toLowerCase()).toContain('insert ignore into');
    expect(insertQuery!.sql.toLowerCase()).toContain('user_tag_assignments');
    // Exactly 3 bound params (user_id, tag_id, assigned_by) — no line_account_id.
    expect(insertQuery!.params).toEqual([10, 3, 'manual']);

    const body = await res.json();
    expect(body).toEqual({ success: true, tags: [TAG_ROW] });
  });

  it("operation='remove' -> DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?", async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/delete/i.test(sqlText)) return { insertId: 0, affectedRows: 1 };
      return [];
    });

    const res = await POST(req({ user_id: 10, tag_id: 3, operation: 'remove' }));
    expect(res.status).toBe(200);

    const deleteQuery = queries.find((q) => /delete/i.test(q.sql));
    expect(deleteQuery).toBeDefined();
    expect(deleteQuery!.sql.toLowerCase()).toContain('delete from');
    expect(deleteQuery!.sql.toLowerCase()).toContain('user_tag_assignments');
    expect(deleteQuery!.params).toEqual([10, 3]);
  });

  it("ANY non-'add' operation value (not an enum/allow-list check) takes the delete branch — e.g. 'bogus'", async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/delete/i.test(sqlText)) return { insertId: 0, affectedRows: 0 };
      return [];
    });

    await POST(req({ user_id: 1, tag_id: 2, operation: 'bogus' }));

    expect(queries.some((q) => /insert/i.test(q.sql))).toBe(false);
    expect(queries.some((q) => /delete/i.test(q.sql))).toBe(true);
  });

  it('missing operation defaults to add (PHP `?? \'add\'`)', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/insert/i.test(sqlText)) return { insertId: 0, affectedRows: 1 };
      return [];
    });

    await POST(req({ user_id: 1, tag_id: 2 }));

    expect(queries.some((q) => /insert ignore/i.test(q.sql))).toBe(true);
  });

  it('SELECT t.* returns the FULL user_tags row shape (not narrowed to id/name/color)', async () => {
    wireFakeDb((sqlText) => {
      if (/insert/i.test(sqlText)) return { insertId: 0, affectedRows: 1 };
      if (/select/i.test(sqlText)) return [TAG_ROW];
      return [];
    });

    const res = await POST(req({ user_id: 1, tag_id: 3, operation: 'add' }));
    const body = await res.json();
    expect(body.tags[0]).toEqual(TAG_ROW);
    expect(Object.keys(body.tags[0])).toEqual(Object.keys(TAG_ROW));
  });

  it('SELECT joins user_tags to user_tag_assignments scoped by uta.user_id', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/insert/i.test(sqlText)) return { insertId: 0, affectedRows: 1 };
      return [];
    });

    await POST(req({ user_id: 77, tag_id: 3, operation: 'add' }));

    const selectQuery = queries.find((q) => /select/i.test(q.sql));
    expect(selectQuery).toBeDefined();
    expect(selectQuery!.sql.toLowerCase()).toContain('user_tags');
    expect(selectQuery!.sql.toLowerCase()).toContain('user_tag_assignments');
    expect(selectQuery!.sql.toLowerCase()).toContain('inner join');
    expect(selectQuery!.params).toEqual([77]);
  });

  it('NEVER writes to activity_logs — for both add and remove operations (unlike save_note/delete_note/save_medical)', async () => {
    const addQueries = wireFakeDb((sqlText) => {
      if (/insert/i.test(sqlText)) return { insertId: 0, affectedRows: 1 };
      return [];
    });
    await POST(req({ user_id: 1, tag_id: 2, operation: 'add' }));
    expect(addQueries.some((q) => q.sql.toLowerCase().includes('activity_logs'))).toBe(false);
    // Exactly two DB round trips: the INSERT IGNORE and the SELECT t.* — no third (log) write.
    expect(addQueries).toHaveLength(2);

    const removeQueries = wireFakeDb((sqlText) => {
      if (/delete/i.test(sqlText)) return { insertId: 0, affectedRows: 1 };
      return [];
    });
    await POST(req({ user_id: 1, tag_id: 2, operation: 'remove' }));
    expect(removeQueries.some((q) => q.sql.toLowerCase().includes('activity_logs'))).toBe(false);
    expect(removeQueries).toHaveLength(2);
  });

  it('DB failure -> 400 {success:false, error}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 1, tag_id: 2, operation: 'add' }));
    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
    expect(typeof body.error).toBe('string');
  });
});
