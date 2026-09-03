/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { resolveAssignedBy } from './_lib/assignTag';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { GET, POST } from './route';

function req(body: unknown): NextRequest {
  return { json: async () => body } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 42,
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
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ insertId: 0, affectedRows: 1 }),
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/assign-tag', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, tag_id: 2 }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it.each([
    [{ tag_id: 2 }], // missing user_id
    [{ user_id: 0, tag_id: 2 }], // falsy user_id
    [{ user_id: 1 }], // missing tag_id
    [{ user_id: 1, tag_id: 0 }], // falsy tag_id
    [{}], // both missing
  ])('400 {success:false, error:"User ID and tag ID are required"} for body=%j, no DB queries issued', async (body) => {
    const queries = wireFakeDb();

    const res = await POST(req(body));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID and tag ID are required' });
    expect(queries).toHaveLength(0);
  });

  it("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW()), assigned_by = admin's id as a string", async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));

    const res = await POST(req({ user_id: 10, tag_id: 3 }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Tag assigned successfully' });

    expect(queries).toHaveLength(1);
    const insertQuery = queries[0];
    expect(insertQuery.sql.toLowerCase()).toContain('insert ignore into');
    expect(insertQuery.sql.toLowerCase()).toContain('user_tag_assignments');
    expect(insertQuery.sql.toLowerCase()).toContain('now()');
    // Exactly 3 bound params (user_id, tag_id, assigned_by) — created_at is
    // the raw NOW() expression, not a bound param; no line_account_id bound.
    expect(insertQuery.params).toEqual([10, 3, '42']);
  });

  it('resolveAssignedBy() falls back to the literal string "Admin" only when adminUserId is null/undefined (PHP `$adminId ?? \'Admin\'`) — structurally unreachable via this route today since TenantSession.adminUserId is always a number, exercised directly as a unit', () => {
    expect(resolveAssignedBy(null)).toBe('Admin');
    expect(resolveAssignedBy(undefined)).toBe('Admin');
    expect(resolveAssignedBy(0)).toBe('0'); // 0 is a valid admin id, not "unset" — PHP's ?? checks isset, not falsiness
    expect(resolveAssignedBy(42)).toBe('42');
  });

  it('duplicate (user_id, tag_id) call does not error, second call does not duplicate-insert (INSERT IGNORE semantics, contingent on the underlying unique key existing — see _lib/assignTag.ts\'s schema-drift note)', async () => {
    // Simulates MySQL's real INSERT IGNORE behavior against a unique
    // (user_id, tag_id) key: first call is a genuine insert
    // (affectedRows: 1), second call with the same pair hits the unique-key
    // collision that IGNORE silently swallows (mysql2 reports that as
    // affectedRows: 0, not a thrown duplicate-key error).
    const { db, queries, setQueryImpl } = makeFakeTenantDb(() => ({ insertId: 5, affectedRows: 1 }));
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const first = await POST(req({ user_id: 10, tag_id: 3 }));
    expect(first.status).toBe(200);
    expect(await first.json()).toEqual({ success: true, message: 'Tag assigned successfully' });

    setQueryImpl(() => ({ insertId: 0, affectedRows: 0 })); // IGNORE swallowed the duplicate-key collision
    const second = await POST(req({ user_id: 10, tag_id: 3 }));
    expect(second.status).toBe(200); // no thrown error, no 400
    expect(await second.json()).toEqual({ success: true, message: 'Tag assigned successfully' });

    expect(queries).toHaveLength(2);
    for (const q of queries) {
      expect(q.sql.toLowerCase()).toContain('insert ignore into');
      expect(q.params).toEqual([10, 3, '42']);
    }
  });

  it('never performs a DELETE against user_tag_assignments — unlike the separate, already-merged update_tags action\'s remove branch (a different action family entirely; this route does not import or reference it)', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));

    await POST(req({ user_id: 1, tag_id: 2 }));

    expect(queries.every((q) => !/delete/i.test(q.sql))).toBe(true);
    expect(queries.some((q) => /insert ignore/i.test(q.sql))).toBe(true);
  });

  it('response never echoes a "tags" list — insert-only, distinct from the toggle action\'s {success, tags} shape', async () => {
    wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));

    const res = await POST(req({ user_id: 1, tag_id: 2 }));
    const body = await res.json();

    expect(body).toEqual({ success: true, message: 'Tag assigned successfully' });
    expect(body.tags).toBeUndefined();
  });

  it('DB failure -> 400 {success:false, error: "Failed to assign tag: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 1, tag_id: 2 }));
    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
    expect(body.error).toBe('Failed to assign tag: boom');
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
