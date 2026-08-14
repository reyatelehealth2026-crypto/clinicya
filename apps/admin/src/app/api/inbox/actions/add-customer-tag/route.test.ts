/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { resolveAssignedBy } from './_lib/addCustomerTag';

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
  queryImpl: (sqlText: string, params: unknown[]) => unknown,
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/add-customer-tag', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, tag_name: 'VIP' }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it.each([
    [{ tag_name: 'VIP' }], // missing user_id
    [{ user_id: 0, tag_name: 'VIP' }], // falsy user_id
    [{ user_id: 1 }], // missing tag_name
    [{ user_id: 1, tag_name: '' }], // empty
    [{ user_id: 1, tag_name: '   ' }], // whitespace-only
    [{}],
  ])('400 {success:false, error:"User ID and tag name are required"} for body=%j, no DB queries issued', async (body) => {
    const queries = wireFakeDb(() => []);
    const res = await POST(req(body));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID and tag name are required' });
    expect(queries).toHaveLength(0);
  });

  it('tag already exists: SELECT finds it, no INSERT INTO user_tags, then INSERT IGNORE the assignment with the existing tag_id', async () => {
    const queries = wireFakeDb((sqlText: string) => {
      if (sqlText.includes('SELECT id FROM user_tags')) return [{ id: 9 }];
      return { insertId: 0, affectedRows: 1 };
    });

    const res = await POST(req({ user_id: 10, tag_name: 'VIP' }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Tag added successfully', tag_id: 9 });

    expect(queries).toHaveLength(2);
    expect(queries[0]!.sql).toContain('SELECT id FROM user_tags');
    expect(queries[0]!.params).toEqual(['VIP', 7]); // lineAccountId = session.currentBotId ?? 1 = 7
    expect(queries[1]!.sql.toLowerCase()).toContain('insert ignore into');
    expect(queries[1]!.sql.toLowerCase()).toContain('user_tag_assignments');
    expect(queries[1]!.params).toEqual([10, 9, '42']);
    // No INSERT INTO user_tags at all — the tag already existed.
    expect(queries.some((q) => /insert into `?user_tags`?\s*\(/i.test(q.sql))).toBe(false);
  });

  it('tag does not exist: SELECT finds nothing, INSERT INTO user_tags with a color from the whitelist, then INSERT IGNORE the assignment with the new tag_id', async () => {
    const queries = wireFakeDb((sqlText: string) => {
      const s = sqlText.toLowerCase();
      if (sqlText.includes('SELECT id FROM user_tags')) return [];
      if (s.includes('insert into') && s.includes('user_tags')) return { insertId: 77, affectedRows: 1 };
      return { insertId: 0, affectedRows: 1 };
    });

    const res = await POST(req({ user_id: 10, tag_name: 'New Tag' }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Tag added successfully', tag_id: 77 });

    expect(queries).toHaveLength(3);
    const createQuery = queries[1]!;
    expect(createQuery.sql.toLowerCase()).toContain('insert into');
    expect(createQuery.sql.toLowerCase()).toContain('user_tags');
    expect(createQuery.sql.toLowerCase()).toContain('now()');
    // params: [name, color, line_account_id] — color must be one of the 7-color whitelist.
    expect(createQuery.params).toHaveLength(3);
    expect(createQuery.params[0]).toBe('New Tag');
    expect(['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899', '#6366F1']).toContain(
      createQuery.params[1]
    );
    expect(createQuery.params[2]).toBe(7);

    const assignQuery = queries[2]!;
    expect(assignQuery.sql.toLowerCase()).toContain('insert ignore into');
    expect(assignQuery.params).toEqual([10, 77, '42']);
  });

  it("NOT the same route as the already-merged actions/assign-tag/route.ts ('assign_tag', a different case label with no find-or-create-by-name preamble) — this action always issues a SELECT id FROM user_tags first, which assign_tag's own INSERT IGNORE-only flow never does (see _lib/addCustomerTag.ts's module doc for the full SQL-text cross-reference)", async () => {
    const queries = wireFakeDb((sqlText: string) => {
      if (sqlText.includes('SELECT id FROM user_tags')) return [{ id: 9 }];
      return { insertId: 0, affectedRows: 1 };
    });
    await POST(req({ user_id: 10, tag_name: 'VIP' }));

    expect(queries.some((q) => q.sql.includes('SELECT id FROM user_tags'))).toBe(true);
  });

  it('resolveAssignedBy() falls back to the literal string "Admin" only when adminUserId is null/undefined — structurally unreachable via this route today, exercised directly as a unit', () => {
    expect(resolveAssignedBy(null)).toBe('Admin');
    expect(resolveAssignedBy(undefined)).toBe('Admin');
    expect(resolveAssignedBy(0)).toBe('0');
    expect(resolveAssignedBy(42)).toBe('42');
  });

  it('DB failure -> 400 {success:false, error: "Failed to add tag: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 1, tag_name: 'VIP' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to add tag: boom' });
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
