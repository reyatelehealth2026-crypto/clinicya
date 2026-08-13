/**
 * @jest-environment node
 */
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { GET, POST } from './route';

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

describe('GET /api/inbox/actions/get-admins', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET();

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('happy path: queries admin_users scoped to (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1, ordered by display_name ASC', async () => {
    const adminRows = [
      { id: 1, username: 'pharm1', display_name: 'Alice', role: 'pharmacist' },
      { id: 2, username: 'admin1', display_name: 'Bob', role: 'admin' },
    ];
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM admin_users') ? adminRows : []));

    const res = await GET();
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: adminRows });

    const adminQuery = queries.find((q) => q.sql.includes('FROM admin_users'));
    expect(adminQuery).toBeDefined();
    expect(adminQuery!.sql).toContain('line_account_id = ?');
    expect(adminQuery!.sql).toContain('line_account_id IS NULL');
    expect(adminQuery!.sql).toContain('is_active = 1');
    expect(adminQuery!.sql).toContain('ORDER BY display_name ASC');
    // lineAccountId = session.currentBotId ?? 1 -> 3
    expect(adminQuery!.params).toEqual([3]);
  });

  it('resolves lineAccountId to 1 when session.currentBotId is null (session.currentBotId ?? 1)', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM admin_users') ? [] : []), { currentBotId: null });

    await GET();

    const adminQuery = queries.find((q) => q.sql.includes('FROM admin_users'));
    expect(adminQuery!.params).toEqual([1]);
  });

  it('returns an empty array when no admins match, not an error', async () => {
    wireFakeDb(() => []);

    const res = await GET();
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: [] });
  });

  it('admin_users missing-table throw -> clean 400 JSON error, not an unhandled crash (schema-drift finding, see _lib/getAdmins.ts)', async () => {
    wireFakeDb(() => {
      throw new Error("Table 'reya_tenant_0001.admin_users' doesn't exist");
    });

    const res = await GET();
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body.success).toBe(false);
    expect(body.error).toContain('Failed to get admin list:');
    expect(body.error).toContain("doesn't exist");
  });

  it('POST is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
