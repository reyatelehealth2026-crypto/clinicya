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
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ affectedRows: 1 }),
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/unassign-conversation', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ user_id: 1, admin_id: 5 }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it.each([[{ admin_id: 5 }], [{ user_id: 0, admin_id: 5 }]])(
    '400 "User ID is required" for body=%j, no DB queries issued',
    async (body) => {
      const queries = wireFakeDb();
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
      expect(queries).toHaveLength(0);
    }
  );

  describe('admin_id > 0 branch: single-admin removeAssignee()', () => {
    it('deletes only the (user_id, admin_id) row from conversation_multi_assignees, conversation_assignments untouched', async () => {
      const queries = wireFakeDb();

      const res = await POST(req({ user_id: 42, admin_id: 5 }));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body).toEqual({ success: true, message: 'ยกเลิกการมอบหมายสำเร็จ' });

      const multiDelete = queries.find((q) => q.sql.toLowerCase().includes('delete from `conversation_multi_assignees`'));
      expect(multiDelete).toBeDefined();
      expect(multiDelete!.params).toEqual([42, 5]);

      const legacyDelete = queries.find((q) => q.sql.toLowerCase().includes('delete from `conversation_assignments`'));
      expect(legacyDelete).toBeUndefined();
    });

    it('exact Thai message string for the single-admin branch', async () => {
      wireFakeDb();
      const res = await POST(req({ user_id: 1, admin_id: 9 }));
      const body = await res.json();
      expect(body.message).toBe('ยกเลิกการมอบหมายสำเร็จ');
    });
  });

  describe('admin_id omitted or 0 branch: unassignConversation() (remove all)', () => {
    it('clears BOTH conversation_multi_assignees (all rows for user) AND conversation_assignments', async () => {
      const queries = wireFakeDb();

      const res = await POST(req({ user_id: 42 }));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body).toEqual({ success: true, message: 'ยกเลิกการมอบหมายทั้งหมดสำเร็จ' });

      const multiDelete = queries.find((q) => q.sql.toLowerCase().includes('delete from `conversation_multi_assignees`'));
      expect(multiDelete).toBeDefined();
      expect(multiDelete!.params).toEqual([42]);

      const legacyDelete = queries.find((q) => q.sql.toLowerCase().includes('delete from `conversation_assignments`'));
      expect(legacyDelete).toBeDefined();
      expect(legacyDelete!.params).toEqual([42]);
    });

    it('admin_id: 0 explicitly also takes the remove-all branch', async () => {
      const queries = wireFakeDb();
      const res = await POST(req({ user_id: 7, admin_id: 0 }));
      const body = await res.json();

      expect(body.message).toBe('ยกเลิกการมอบหมายทั้งหมดสำเร็จ');
      expect(queries.some((q) => q.sql.toLowerCase().includes('delete from `conversation_assignments`'))).toBe(true);
    });

    it('exact Thai message string for the remove-all branch', async () => {
      wireFakeDb();
      const res = await POST(req({ user_id: 1 }));
      const body = await res.json();
      expect(body.message).toBe('ยกเลิกการมอบหมายทั้งหมดสำเร็จ');
    });
  });

  it('DB failure -> 400 {success:false, error} with the literal PHP-matching prefix, not an unhandled crash', async () => {
    wireFakeDb(() => {
      throw new Error('connection refused');
    });

    const res = await POST(req({ user_id: 1, admin_id: 5 }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body.success).toBe(false);
    expect(body.error).toContain('Failed to unassign conversation:');
    expect(body.error).toContain('connection refused');
  });
});
