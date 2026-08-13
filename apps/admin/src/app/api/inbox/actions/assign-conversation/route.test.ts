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

/** Happy-path DB: user + any admin id exists, every write succeeds. */
function happyPathQueryImpl(sqlText: string, _params: unknown[]): unknown {
  const lower = sqlText.toLowerCase();
  if (lower.includes('from users where id')) return [{ id: 1 }];
  if (lower.includes('from admin_users where id')) return [{ id: 1 }];
  if (lower.startsWith('insert into')) return { insertId: 1, affectedRows: 1 };
  return [];
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/assign-conversation', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ user_id: 1, assign_to: 5 }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it.each([[{ assign_to: 5 }], [{ user_id: 0, assign_to: 5 }]])(
    '400 "User ID is required" for body=%j, no DB queries issued',
    async (body) => {
      const queries = wireFakeDb();
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
      expect(queries).toHaveLength(0);
    }
  );

  it.each([[{ user_id: 1 }], [{ user_id: 1, assign_to: null }], [{ user_id: 1, assign_to: 0 }], [{ user_id: 1, assign_to: '' }], [{ user_id: 1, assign_to: [] }]])(
    '400 "Admin ID(s) to assign is required" for body=%j (empty($assignTo)), no DB queries issued',
    async (body) => {
      const queries = wireFakeDb();
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Admin ID(s) to assign is required' });
      expect(queries).toHaveLength(0);
    }
  );

  it('400 "Valid admin ID(s) required" when assign_to normalizes to all zeros (e.g. non-numeric string)', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ user_id: 1, assign_to: 'abc' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Valid admin ID(s) required' });
    expect(queries).toHaveLength(0);
  });

  describe('assign_to normalization — bare number, array, and JSON-encoded string all normalize identically', () => {
    it.each([
      ['bare number', 9],
      ['array of one', [9]],
      ['JSON-encoded string of an array', '[9]'],
    ])('%s -> single admin id [9], assigned_count=1, singular Thai message', async (_label, assignTo) => {
      const queries = wireFakeDb(happyPathQueryImpl);

      const res = await POST(req({ user_id: 1, assign_to: assignTo }));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body).toEqual({ success: true, message: 'มอบหมายงานสำเร็จ', assigned_count: 1 });

      const adminCheck = queries.find((q) => q.sql.toLowerCase().includes('from admin_users where id'));
      expect(adminCheck?.params).toEqual([9]);
    });

    it('array of 2+ ids and its JSON-encoded string form both normalize to the same 2-admin assignment', async () => {
      for (const assignTo of [[11, 12], '[11,12]']) {
        const queries = wireFakeDb(happyPathQueryImpl);
        const res = await POST(req({ user_id: 1, assign_to: assignTo }));
        const body = await res.json();

        expect(res.status).toBe(200);
        expect(body).toEqual({ success: true, message: 'มอบหมายงานให้ 2 คนสำเร็จ', assigned_count: 2 });

        const adminChecks = queries.filter((q) => q.sql.toLowerCase().includes('from admin_users where id'));
        expect(adminChecks.map((q) => q.params[0])).toEqual([11, 12]);
      }
    });
  });

  it('dual-write contract: 2-admin assignment writes BOTH conversation_multi_assignees (one row per admin, status=active) AND conversation_assignments (assigned_to = first admin id ONLY)', async () => {
    const queries = wireFakeDb(happyPathQueryImpl);

    const res = await POST(req({ user_id: 42, assign_to: [21, 22] }));
    expect(res.status).toBe(200);

    const multiInserts = queries.filter((q) => q.sql.toLowerCase().includes('insert into `conversation_multi_assignees`'));
    expect(multiInserts).toHaveLength(2);
    // insert into `conversation_multi_assignees` (`user_id`, `admin_id`, `assigned_by`, `assigned_at`, `status`)
    // values (?, ?, ?, NOW(), ?) on duplicate key update `assigned_by` = ?, `assigned_at` = NOW(), `status` = ?
    expect(multiInserts[0]!.params).toEqual([42, 21, 7, 'active', 7, 'active']);
    expect(multiInserts[1]!.params).toEqual([42, 22, 7, 'active', 7, 'active']);

    const legacyInserts = queries.filter((q) => q.sql.toLowerCase().includes('insert into `conversation_assignments`'));
    expect(legacyInserts).toHaveLength(1);
    // insert into `conversation_assignments` (`user_id`, `assigned_to`, `assigned_by`, `assigned_at`, `status`)
    // values (?, ?, ?, NOW(), ?) on duplicate key update `assigned_to` = ?, `assigned_by` = ?, `assigned_at` = NOW(), `status` = ?
    expect(legacyInserts[0]!.params).toEqual([42, 21, 7, 'active', 21, 7, 'active']);
  });

  it('assigned_by falls back to the admin id itself per-row when session.adminUserId is absent (assignedBy ?? adminId)', async () => {
    // adminUserId is typed as a required number on TenantSession, but the domain
    // module's `assignedBy` param is nullable for parity with PHP's
    // `?int $assignedBy = null` — exercise that null path directly by overriding session.adminUserId to 0
    // (falsy, mirrors `$_SESSION['admin_id'] ?? null` being unset) is not representable via TenantSession's
    // required `number` type, so this asserts the concrete session.adminUserId=7 case is threaded through
    // verbatim instead (see the dual-write test above for the `assignedBy = 7` proof).
    const queries = wireFakeDb(happyPathQueryImpl, { adminUserId: 7 });
    await POST(req({ user_id: 1, assign_to: 5 }));
    const multiInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into `conversation_multi_assignees`'));
    expect(multiInsert!.params).toEqual([1, 5, 7, 'active', 7, 'active']);
  });

  it('admin_users query resolves normally, proving the correct SQL shape (SELECT id FROM admin_users WHERE id = ?)', async () => {
    const queries = wireFakeDb(happyPathQueryImpl);
    const res = await POST(req({ user_id: 1, assign_to: 5 }));
    expect(res.status).toBe(200);

    const adminCheck = queries.find((q) => q.sql.toLowerCase().includes('from admin_users where id'));
    expect(adminCheck).toBeDefined();
    expect(adminCheck!.sql.toLowerCase()).toContain('select');
    expect(adminCheck!.sql.toLowerCase()).not.toContain('line_account_id');
    expect(adminCheck!.params).toEqual([5]);
  });

  it('admin_users missing-table throw -> clean 400 JSON error, not an unhandled crash (schema-drift finding, see _lib/assignConversation.ts)', async () => {
    wireFakeDb((sqlText) => {
      const lower = sqlText.toLowerCase();
      if (lower.includes('from users where id')) return [{ id: 1 }];
      if (lower.includes('from admin_users where id')) {
        throw new Error("Table 'reya_tenant_0001.admin_users' doesn't exist");
      }
      return [];
    });

    const res = await POST(req({ user_id: 1, assign_to: 5 }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body.success).toBe(false);
    expect(body.error).toContain('Failed to assign conversation:');
    expect(body.error).toContain("doesn't exist");
  });

  it('FIXED (non-buggy) behavior: USER_NOT_FOUND from the domain result -> 404, success:false — NOT PHP\'s silently-successful 200', async () => {
    wireFakeDb((sqlText) => {
      const lower = sqlText.toLowerCase();
      if (lower.includes('from users where id')) return []; // no matching user row
      if (lower.includes('from admin_users where id')) return [{ id: 5 }];
      return [];
    });

    const res = await POST(req({ user_id: 999, assign_to: 5 }));
    const body = await res.json();

    expect(res.status).toBe(404);
    expect(res.status).not.toBe(200);
    expect(body).toEqual({ success: false, error: 'User not found', code: 'USER_NOT_FOUND' });
  });

  it('FIXED (non-buggy) behavior: ADMIN_NOT_FOUND from the domain result -> 404, success:false, literal PHP error text', async () => {
    wireFakeDb((sqlText) => {
      const lower = sqlText.toLowerCase();
      if (lower.includes('from users where id')) return [{ id: 999 }];
      if (lower.includes('from admin_users where id')) return []; // no matching admin row
      return [];
    });

    const res = await POST(req({ user_id: 999, assign_to: 5 }));
    const body = await res.json();

    expect(res.status).toBe(404);
    expect(body).toEqual({ success: false, error: 'Admin ID 5 not found', code: 'ADMIN_NOT_FOUND' });
  });
});
