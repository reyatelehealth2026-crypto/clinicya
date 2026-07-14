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
    adminUserId: 42,
    tenantId: 1,
    currentBotId: 7,
    role: 'admin',
    username: 'pharmacist_a',
    displayName: 'Admin',
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

describe('POST /api/inbox/actions/notes', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, note: 'hello' }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('INSERT INTO user_notes (user_id, note, created_at=NOW()) — no line_account_id bound', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/insert into `?user_notes`?/i.test(sqlText)) return { insertId: 501, affectedRows: 1 };
      return { insertId: 0, affectedRows: 1 };
    });

    const res = await POST(req({ user_id: 10, note: '  hello customer  ' }));
    expect(res.status).toBe(200);

    const noteInsert = queries.find((q) => /user_notes/i.test(q.sql));
    expect(noteInsert).toBeDefined();
    expect(noteInsert!.sql.toLowerCase()).toContain('insert into');
    expect(noteInsert!.sql.toLowerCase()).toContain('user_notes');
    expect(noteInsert!.sql.toLowerCase()).toContain('now()');
    // Trimmed note, bound params contain userId + trimmed note only (created_at is a raw NOW(), not bound).
    expect(noteInsert!.params).toEqual([10, 'hello customer']);

    const body = await res.json();
    expect(body).toEqual({ success: true, id: 501 });
  });

  it('writes exactly one activity_logs row: log_type=data, action=create, entity_type=user_note, entity_id=<new note id>, user_id set, new_value={note: first 100 chars}', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/user_notes/i.test(sqlText)) return { insertId: 900, affectedRows: 1 };
      return { insertId: 0, affectedRows: 1 };
    });

    await POST(req({ user_id: 77, note: 'a short note' }));

    const logInsert = queries.find((q) => q.sql.toLowerCase().includes('activity_logs'));
    expect(logInsert).toBeDefined();
    expect(logInsert!.sql.toLowerCase()).toContain('insert into');
    expect(logInsert!.params).toEqual(
      expect.arrayContaining(['data', 'create', 'เพิ่มโน้ตลูกค้า', 77, 'user_note', 900, JSON.stringify({ note: 'a short note' }), 42, 'pharmacist_a', 7])
    );
    expect(logInsert!.params).toHaveLength(10);
  });

  it('truncates new_value.note to the first 100 code points (mb_substr semantics, not UTF-16 slicing)', async () => {
    const longNote = 'x'.repeat(150);
    const queries = wireFakeDb((sqlText) => {
      if (/user_notes/i.test(sqlText)) return { insertId: 1, affectedRows: 1 };
      return { insertId: 0, affectedRows: 1 };
    });

    await POST(req({ user_id: 1, note: longNote }));

    const logInsert = queries.find((q) => q.sql.toLowerCase().includes('activity_logs'));
    const newValueParam = logInsert!.params.find((p) => typeof p === 'string' && p.startsWith('{"note"'));
    expect(newValueParam).toBe(JSON.stringify({ note: 'x'.repeat(100) }));
  });

  it('falls back to session.currentBotId ?? null for activity_logs.line_account_id when currentBotId is null', async () => {
    const queries = wireFakeDb(
      (sqlText) => {
        if (/user_notes/i.test(sqlText)) return { insertId: 1, affectedRows: 1 };
        return { insertId: 0, affectedRows: 1 };
      },
      { currentBotId: null }
    );

    await POST(req({ user_id: 1, note: 'hi' }));

    const logInsert = queries.find((q) => q.sql.toLowerCase().includes('activity_logs'));
    expect(logInsert!.params[logInsert!.params.length - 1]).toBeNull();
  });

  it('missing/undefined note defaults to empty string (trim($_POST[\'note\'] ?? \'\'))', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/user_notes/i.test(sqlText)) return { insertId: 1, affectedRows: 1 };
      return { insertId: 0, affectedRows: 1 };
    });

    await POST(req({ user_id: 1 }));

    const noteInsert = queries.find((q) => /user_notes/i.test(q.sql));
    expect(noteInsert!.params).toEqual([1, '']);
  });

  it('DB failure -> 400 {success:false, error}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 1, note: 'x' }));
    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
  });
});
