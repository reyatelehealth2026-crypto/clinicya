/**
 * @jest-environment node
 */
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from '../_lib/testHelpers/fakeTenantDb';

const mockResolveInboxApiContext = jest.fn();
jest.mock('../_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { DELETE } from './route';

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
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ insertId: 0, affectedRows: 1 }),
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

function callDelete(noteId: string) {
  return DELETE({} as Request, { params: Promise.resolve({ noteId }) });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('DELETE /api/inbox/actions/notes/[noteId]', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await callDelete('123');
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('DELETE FROM user_notes WHERE id = ? using the noteId route segment (intval-cast)', async () => {
    const queries = wireFakeDb();
    const res = await callDelete('501');
    expect(res.status).toBe(200);

    const deleteQuery = queries.find((q) => /delete/i.test(q.sql));
    expect(deleteQuery).toBeDefined();
    expect(deleteQuery!.sql.toLowerCase()).toContain('delete from');
    expect(deleteQuery!.sql.toLowerCase()).toContain('user_notes');
    expect(deleteQuery!.params).toEqual([501]);
  });

  it('always returns {success: true} even when the delete matched zero rows (PHP does not check rowCount)', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 0 }));
    const res = await callDelete('999999');
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true });
    expect(queries.some((q) => /delete/i.test(q.sql))).toBe(true);
  });

  it('writes exactly one activity_logs row: log_type=data, action=delete, entity_type=user_note, entity_id=noteId, admin_id/admin_name/line_account_id set', async () => {
    const queries = wireFakeDb();
    await callDelete('501');

    const logInsert = queries.find((q) => q.sql.toLowerCase().includes('activity_logs'));
    expect(logInsert).toBeDefined();
    expect(logInsert!.params).toEqual(
      expect.arrayContaining(['data', 'delete', 'ลบโน้ตลูกค้า', 'user_note', 501, 42, 'pharmacist_a', 7])
    );
  });

  it('CRITICAL: the activity_logs INSERT does NOT bind a user_id anywhere (bound-params array, and not smuggled into any other field) — deliberate asymmetry with save_note', async () => {
    const queries = wireFakeDb();
    await callDelete('501');

    const logInsert = queries.find((q) => q.sql.toLowerCase().includes('activity_logs'));
    expect(logInsert).toBeDefined();
    // Exactly 7 bound params: log_type, action, description, entity_type, entity_id, admin_id, admin_name, line_account_id = 8.
    expect(logInsert!.params).toHaveLength(8);
    // The deleted note's id (501) must not appear anywhere except as entity_id — and specifically,
    // the compiled column list must not include `user_id` at all.
    expect(logInsert!.sql).not.toMatch(/`?user_id`?\s*[,)]/i);
  });

  it('falls back to session.currentBotId ?? null for activity_logs.line_account_id when currentBotId is null', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }), { currentBotId: null });
    await callDelete('501');

    const logInsert = queries.find((q) => q.sql.toLowerCase().includes('activity_logs'));
    expect(logInsert!.params[logInsert!.params.length - 1]).toBeNull();
  });

  it('DB failure -> 400 {success:false, error}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await callDelete('501');
    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
  });
});
