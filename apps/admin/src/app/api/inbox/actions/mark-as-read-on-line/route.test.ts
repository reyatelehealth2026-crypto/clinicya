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

const mockLineMarkAsRead = jest.fn();
jest.mock('./_lib/lineMarkAsRead', () => {
  const actual = jest.requireActual('./_lib/lineMarkAsRead');
  return {
    ...actual,
    lineMarkAsRead: (...args: unknown[]) => mockLineMarkAsRead(...args),
  };
});

import { GET, POST } from './route';

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

const LINE_ACCOUNT_ROW = { channel_access_token: 'chan-token-abc' };

/** Three pending messages, ordered as the real SELECT (created_at DESC) would return them — newest first. */
const PENDING_MESSAGES = [
  { id: 30, mark_as_read_token: 'tok-30-newest' },
  { id: 20, mark_as_read_token: 'tok-20' },
  { id: 10, mark_as_read_token: 'tok-10-oldest' },
];

function wireQueryRouter(pendingMessages: Array<{ id: number; mark_as_read_token: string }> = PENDING_MESSAGES) {
  return (sqlText: string) => {
    const lower = sqlText.toLowerCase();
    if (lower.includes('from line_accounts')) return [LINE_ACCOUNT_ROW];
    if (lower.includes('from messages') && lower.includes('select')) return pendingMessages;
    return { insertId: 0, affectedRows: 1 };
  };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/mark-as-read-on-line', () => {
  it('401 JSON when unauthenticated, no DB/LINE calls', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1 }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockLineMarkAsRead).not.toHaveBeenCalled();
  });

  it.each([[{}], [{ user_id: 0 }], [{ user_id: '' }]])(
    '400 {success:false, error:"User ID is required"} for body=%j, no DB queries issued',
    async (body) => {
      const queries = wireFakeDb();
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
      expect(queries).toHaveLength(0);
      expect(mockLineMarkAsRead).not.toHaveBeenCalled();
    }
  );

  it('LINE account not configured -> 400, no further queries/LINE call', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.toLowerCase().includes('from line_accounts')) return [];
      return [];
    });

    const res = await POST(req({ user_id: 42 }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'LINE account not configured' });
    expect(mockLineMarkAsRead).not.toHaveBeenCalled();
    expect(queries).toHaveLength(1); // only the line_accounts SELECT
  });

  it('empty channel_access_token also counts as "not configured" (PHP empty() check)', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.toLowerCase().includes('from line_accounts')) return [{ channel_access_token: '' }];
      return [];
    });

    const res = await POST(req({ user_id: 42 }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'LINE account not configured' });
    expect(mockLineMarkAsRead).not.toHaveBeenCalled();
  });

  it('(a) empty pending-messages branch: returns early with marked_count:0, LINE call NEVER invoked, and performs the plain local is_read fallback UPDATE', async () => {
    const queries = wireFakeDb((sqlText) => {
      const lower = sqlText.toLowerCase();
      if (lower.includes('from line_accounts')) return [LINE_ACCOUNT_ROW];
      if (lower.includes('from messages') && lower.includes('select')) return []; // no pending messages
      return { insertId: 0, affectedRows: 2 };
    });

    const res = await POST(req({ user_id: 42 }));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({
      success: true,
      message: 'No messages with markAsReadToken to process',
      marked_count: 0,
    });
    expect(mockLineMarkAsRead).not.toHaveBeenCalled();

    const updateQuery = queries.find((q) => q.sql.toLowerCase().startsWith('update'));
    expect(updateQuery).toBeDefined();
    expect(updateQuery!.sql.toLowerCase()).not.toContain('is_read_on_line'); // plain is_read-only UPDATE
    // set is_read=1, where user_id/line_account_id/direction/is_read=0 — literal WHERE values are bound too.
    expect(updateQuery!.params).toEqual([1, 42, 7, 'incoming', 0]);
  });

  it('(b) LINE-success branch: updates is_read AND is_read_on_line=1 for ALL fetched message ids (not just the newest), calls LINE with only the newest token', async () => {
    mockLineMarkAsRead.mockResolvedValue({ success: true });
    const queries = wireFakeDb(wireQueryRouter());

    const res = await POST(req({ user_id: 42 }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      message: 'Messages marked as read',
      marked_count: 3,
      line_api_success: true,
      errors: [],
    });

    // Only the newest token (messages[0], id 30) is sent to LINE.
    expect(mockLineMarkAsRead).toHaveBeenCalledTimes(1);
    expect(mockLineMarkAsRead).toHaveBeenCalledWith('tok-30-newest', { channelAccessToken: 'chan-token-abc' });

    // ALL THREE fetched ids get is_read=1, is_read_on_line=1 — not just id 30.
    // (The initial SELECT's WHERE clause also mentions the column
    // `is_read_on_line`, so the UPDATE is disambiguated by both starting
    // with "update" AND mentioning is_read_on_line.)
    const updateQuery = queries.find(
      (q) => q.sql.toLowerCase().startsWith('update') && q.sql.toLowerCase().includes('is_read_on_line')
    );
    expect(updateQuery).toBeDefined();
    expect(updateQuery!.sql.toLowerCase()).toContain('in (');
    // set is_read=1, is_read_on_line=1, where id in (30, 20, 10) — all three fetched ids.
    expect(updateQuery!.params).toEqual([1, 1, 30, 20, 10]);
  });

  it('(c) LINE-failure branch: still performs the local is_read fallback UPDATE, still responds success:true with line_api_success:false and a populated errors array (success:false on LINE failure would be WRONG)', async () => {
    mockLineMarkAsRead.mockResolvedValue({ success: false, error: 'HTTP 400' });
    const queries = wireFakeDb(wireQueryRouter());

    const res = await POST(req({ user_id: 42 }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true); // NOT false — this is the intentional graceful-degradation quirk
    expect(body.marked_count).toBe(0);
    expect(body.line_api_success).toBe(false);
    expect(body.errors).toEqual(['HTTP 400']);

    // Local fallback UPDATE (plain is_read=1, no is_read_on_line) still ran.
    const fallbackUpdate = queries.find(
      (q) => q.sql.toLowerCase().startsWith('update') && !q.sql.toLowerCase().includes('is_read_on_line')
    );
    expect(fallbackUpdate).toBeDefined();
    expect(fallbackUpdate!.params).toEqual([1, 42, 7, 'incoming', 0]);

    // The is_read_on_line bulk-UPDATE branch must NOT have run. (The initial
    // SELECT's own WHERE clause also mentions the column `is_read_on_line`,
    // so this is scoped to UPDATE statements specifically.)
    expect(
      queries.some((q) => q.sql.toLowerCase().startsWith('update') && q.sql.toLowerCase().includes('is_read_on_line'))
    ).toBe(false);
  });

  it('errors defaults to "Unknown error" when the LINE result has no error string', async () => {
    mockLineMarkAsRead.mockResolvedValue({ success: false });
    wireFakeDb(wireQueryRouter());

    const res = await POST(req({ user_id: 42 }));
    const body = await res.json();

    expect(body.errors).toEqual(['Unknown error']);
    expect(body.line_api_success).toBe(false);
  });

  it('resolves lineAccountId as session.currentBotId ?? 1', async () => {
    mockLineMarkAsRead.mockResolvedValue({ success: true });
    const queries = wireFakeDb(wireQueryRouter(), { currentBotId: null });

    await POST(req({ user_id: 42 }));

    const lineAccountQuery = queries.find((q) => q.sql.toLowerCase().includes('from line_accounts'));
    expect(lineAccountQuery!.params).toEqual([1]);
  });

  it('DB failure -> 400 {success:false, error: "Failed to mark as read: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 42 }));
    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
    expect(body.error).toBe('Failed to mark as read: boom');
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
