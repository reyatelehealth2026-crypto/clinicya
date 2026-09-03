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

describe('POST /api/inbox/actions/remove-customer-tag', () => {
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
    [{}],
  ])('400 {success:false, error:"User ID and tag ID are required"} for body=%j, no DB queries issued', async (body) => {
    const queries = wireFakeDb();
    const res = await POST(req(body));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID and tag ID are required' });
    expect(queries).toHaveLength(0);
  });

  it('DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));

    const res = await POST(req({ user_id: 10, tag_id: 3 }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Tag removed successfully' });

    expect(queries).toHaveLength(1);
    const deleteQuery = queries[0]!;
    expect(deleteQuery.sql.toLowerCase()).toContain('delete from');
    expect(deleteQuery.sql.toLowerCase()).toContain('user_tag_assignments');
    expect(deleteQuery.params).toEqual([10, 3]);
  });

  it('success is unconditional — a DELETE matching zero rows (already removed) still returns {success:true}', async () => {
    wireFakeDb(() => ({ insertId: 0, affectedRows: 0 }));

    const res = await POST(req({ user_id: 10, tag_id: 3 }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Tag removed successfully' });
  });

  it('DB failure -> 400 {success:false, error: "Failed to remove tag: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 1, tag_id: 2 }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to remove tag: boom' });
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
