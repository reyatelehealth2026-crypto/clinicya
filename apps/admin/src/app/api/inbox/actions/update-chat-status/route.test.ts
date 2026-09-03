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

/** SELECT/UPDATE/INSERT-aware default: chat_status SELECT -> 'pending', everything else OkPacket-shaped. */
function defaultQueryImpl(sqlText: string): unknown {
  if (sqlText.toLowerCase().includes('select') && sqlText.toLowerCase().includes('chat_status')) {
    return [{ chat_status: 'pending' }];
  }
  return { insertId: 0, affectedRows: 1 };
}

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = defaultQueryImpl,
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/update-chat-status', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ user_id: 1, status: 'pending' }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "User ID is required" when user_id is missing/falsy, no DB queries issued', async () => {
    const queries = wireFakeDb();

    const res = await POST(req({ status: 'pending' }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Invalid status" for a status outside the whitelist, no DB queries issued', async () => {
    const queries = wireFakeDb();

    const res = await POST(req({ user_id: 5, status: 'not-a-real-status' }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid status' });
    expect(queries).toHaveLength(0);
  });

  it("empty-string status IS accepted (it's the first whitelist entry, clears the status) and is stored/logged as NULL, not ''", async () => {
    const queries = wireFakeDb((sqlText) =>
      sqlText.toLowerCase().includes('select') ? [{ chat_status: 'completed' }] : { insertId: 0, affectedRows: 1 }
    );

    const res = await POST(req({ user_id: 5, status: '' }));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Chat status updated successfully' });

    const updateQuery = queries.find((q) => q.sql.toLowerCase().startsWith('update'));
    expect(updateQuery).toBeDefined();
    expect(updateQuery!.params).toContain(null);
    expect(updateQuery!.params).not.toContain('');

    const historyInsert = queries.find((q) => q.sql.toLowerCase().includes('chat_status_history'));
    expect(historyInsert).toBeDefined();
    // (user_id, line_account_id, old_status, new_status, changed_by)
    expect(historyInsert!.params).toEqual([5, 7, 'completed', null, 42]);
  });

  it.each(['pending', 'completed', 'shipping', 'tracking', 'billing'])(
    'happy path for whitelisted status=%s: SELECT old chat_status, UPDATE users, INSERT chat_status_history',
    async (status) => {
      const queries = wireFakeDb((sqlText) =>
        sqlText.toLowerCase().includes('select') ? [{ chat_status: 'pending' }] : { insertId: 0, affectedRows: 1 }
      );

      const res = await POST(req({ user_id: 10, status }));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body).toEqual({ success: true, message: 'Chat status updated successfully' });

      const selectQuery = queries.find((q) => q.sql.toLowerCase().includes('select') && q.sql.toLowerCase().includes('chat_status'));
      expect(selectQuery).toBeDefined();
      expect(selectQuery!.params).toEqual([10]);

      const updateQuery = queries.find((q) => q.sql.toLowerCase().startsWith('update'));
      expect(updateQuery).toBeDefined();
      expect(updateQuery!.sql.toLowerCase()).toContain('users');
      expect(updateQuery!.params).toEqual([status, 10]);

      const historyInsert = queries.find((q) => q.sql.toLowerCase().includes('chat_status_history'));
      expect(historyInsert).toBeDefined();
      expect(historyInsert!.params).toEqual([10, 7, 'pending', status, 42]); // [user_id, line_account_id, old_status, new_status, changed_by]
    }
  );

  it('a chat_status_history INSERT failure does NOT flip the response to an error — the SELECT/UPDATE pair already succeeded', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.toLowerCase().includes('select') && sqlText.toLowerCase().includes('chat_status')) {
        return [{ chat_status: 'pending' }];
      }
      if (sqlText.toLowerCase().includes('chat_status_history')) {
        throw new Error('table chat_status_history does not exist');
      }
      return { insertId: 0, affectedRows: 1 };
    });

    const res = await POST(req({ user_id: 10, status: 'completed' }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, message: 'Chat status updated successfully' });

    // The UPDATE still ran despite the later history-insert throw.
    const updateQuery = queries.find((q) => q.sql.toLowerCase().startsWith('update'));
    expect(updateQuery).toBeDefined();
  });

  it('a SELECT/UPDATE failure -> 400 {success:false, error: "Failed to update chat status: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('connection refused');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 10, status: 'completed' }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to update chat status: connection refused' });
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
