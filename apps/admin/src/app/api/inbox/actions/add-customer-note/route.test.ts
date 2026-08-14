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
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ insertId: 501, affectedRows: 1 }),
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/add-customer-note', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, content: 'hi' }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it.each([
    [{ content: 'hi' }], // missing user_id
    [{ user_id: 0, content: 'hi' }], // falsy user_id
    [{ user_id: 1 }], // missing content
    [{ user_id: 1, content: '' }], // empty content
    [{ user_id: 1, content: '   ' }], // whitespace-only content, trims to ''
    [{}],
  ])('400 {success:false, error:"User ID and content are required"} for body=%j, no DB queries issued', async (body) => {
    const queries = wireFakeDb();
    const res = await POST(req(body));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID and content are required' });
    expect(queries).toHaveLength(0);
  });

  it('INSERT INTO user_notes (user_id, note, created_by, created_at) VALUES (?, ?, ?, NOW()), content trimmed, created_by = session.adminUserId', async () => {
    const queries = wireFakeDb(() => ({ insertId: 501, affectedRows: 1 }));

    const res = await POST(req({ user_id: 10, content: '  แพ้ยาปฏิชีวนะ  ' }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Note added successfully', note_id: 501 });

    expect(queries).toHaveLength(1);
    const insertQuery = queries[0]!;
    expect(insertQuery.sql.toLowerCase()).toContain('insert into');
    expect(insertQuery.sql.toLowerCase()).toContain('user_notes');
    expect(insertQuery.sql.toLowerCase()).toContain('now()');
    expect(insertQuery.params).toEqual([10, 'แพ้ยาปฏิชีวนะ', 42]);
  });

  it("NOT the same route as the already-merged actions/notes/route.ts ('save_note', root inbox-v2.php) — this INSERT carries a created_by column that save_note's own 3-column INSERT does not; the two are separately-owned, unrelated code paths (see _lib/addCustomerNote.ts's module doc for the SQL-text diff)", async () => {
    const queries = wireFakeDb(() => ({ insertId: 501, affectedRows: 1 }));
    await POST(req({ user_id: 10, content: 'note text' }));

    const insertQuery = queries[0]!;
    // 4-column INSERT (user_id, note, created_by, created_at) — 3 bound params + NOW().
    expect(insertQuery.params).toHaveLength(3);
    expect(insertQuery.sql.toLowerCase()).toMatch(/user_id.*note.*created_by.*created_at/s);
  });

  it('DB failure -> 400 {success:false, error: "Failed to add note: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 1, content: 'hi' }));
    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body).toEqual({ success: false, error: 'Failed to add note: boom' });
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
