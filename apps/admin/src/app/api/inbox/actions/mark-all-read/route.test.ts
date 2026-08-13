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
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ insertId: 0, affectedRows: 0 }),
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/mark-all-read', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST();
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it("UPDATE messages SET is_read = 1 WHERE line_account_id = ? AND direction = 'incoming' AND is_read = 0, message interpolates affected count", async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 5 }));

    const res = await POST();
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Marked 5 messages as read' });

    expect(queries).toHaveLength(1);
    const updateQuery = queries[0];
    expect(updateQuery.sql.toLowerCase()).toContain('update');
    expect(updateQuery.sql.toLowerCase()).toContain('messages');
    expect(updateQuery.sql.toLowerCase()).toContain('direction');
    // Kysely/mysql2 binds every literal WHERE value as a `?` param — 'incoming' is
    // never inlined into the SQL text itself, so it's asserted via params below
    // instead of a literal-string SQL substring check.
    // set is_read=1, where line_account_id=7 AND direction='incoming' AND is_read=0.
    expect(updateQuery.params).toEqual([1, 7, 'incoming', 0]);
  });

  it('resolves lineAccountId as session.currentBotId ?? 1 (falls back to 1 when currentBotId is null)', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 0 }), { currentBotId: null });

    await POST();

    expect(queries[0].params).toEqual([1, 1, 'incoming', 0]);
  });

  it('0 affected rows still returns success:true with "Marked 0 messages as read"', async () => {
    wireFakeDb(() => ({ insertId: 0, affectedRows: 0 }));

    const res = await POST();
    expect(await res.json()).toEqual({ success: true, message: 'Marked 0 messages as read' });
  });

  it('DB failure -> 400 {success:false, error: "Failed to mark messages as read: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST();
    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
    expect(body.error).toBe('Failed to mark messages as read: boom');
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
