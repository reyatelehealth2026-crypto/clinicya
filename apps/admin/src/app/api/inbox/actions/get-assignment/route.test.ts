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

function req(search: string): NextRequest {
  const url = `https://admin.re-ya.com/api/inbox/actions/get-assignment${search}`;
  return { url } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 7,
    tenantId: 1,
    currentBotId: 3,
    role: 'admin',
    username: 'pharmacist1',
    displayName: 'Pharmacist One',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
    ...overrides,
  };
}

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/get-assignment', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?user_id=1'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it.each(['', '?user_id=0', '?user_id=abc'])('400 "User ID is required" for search=%j, no DB queries issued', async (search) => {
    const queries = wireFakeDb();
    const res = await GET(req(search));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('returns is_assigned:false and assignees:[] (not an error) when the LEFT JOIN returns zero rows for a valid user_id', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: { user_id: 42, assignees: [], is_assigned: false },
    });
  });

  it('happy path: LEFT JOIN admin_users on cma.admin_id = au.id, ordered by assigned_at DESC, returns full assignees array + is_assigned/status/assigned_at from row 0', async () => {
    const rows = [
      {
        admin_id: 5,
        assigned_by: 7,
        assigned_at: '2026-08-13 10:00:00',
        status: 'active',
        resolved_at: null,
        username: 'pharm1',
        display_name: 'Pharmacist One',
      },
      {
        admin_id: 6,
        assigned_by: 7,
        assigned_at: '2026-08-12 09:00:00',
        status: 'active',
        resolved_at: null,
        username: 'pharm2',
        display_name: 'Pharmacist Two',
      },
    ];
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM conversation_multi_assignees') ? rows : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        user_id: 42,
        assignees: rows,
        is_assigned: true,
        status: 'active',
        assigned_at: '2026-08-13 10:00:00',
      },
    });

    const query = queries.find((q) => q.sql.includes('FROM conversation_multi_assignees'));
    expect(query).toBeDefined();
    expect(query!.sql).toContain('LEFT JOIN admin_users au ON cma.admin_id = au.id');
    expect(query!.sql).toContain('ORDER BY cma.assigned_at DESC');
    expect(query!.params).toEqual([42]);
  });

  it('status falls back to "active" when row 0 has a null/falsy status ($assignees[0][\'status\'] ?? \'active\')', async () => {
    const rows = [
      { admin_id: 5, assigned_by: 7, assigned_at: '2026-08-13 10:00:00', status: null, resolved_at: null, username: 'p', display_name: 'P' },
    ];
    wireFakeDb(() => rows);

    const res = await GET(req('?user_id=1'));
    const body = await res.json();

    expect(body.data.status).toBe('active');
  });

  it('admin_users missing-table throw -> clean 400 JSON error, not an unhandled crash (schema-drift finding, see _lib/getAssignment.ts)', async () => {
    wireFakeDb(() => {
      throw new Error("Table 'reya_tenant_0001.admin_users' doesn't exist");
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body.success).toBe(false);
    expect(body.error).toContain('Failed to get assignment:');
    expect(body.error).toContain("doesn't exist");
  });

  it('POST is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
