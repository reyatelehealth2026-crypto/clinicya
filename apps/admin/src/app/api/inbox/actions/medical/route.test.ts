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
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ insertId: 0, affectedRows: 1 }),
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/medical', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, medical_conditions: 'asthma' }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('UPDATE users SET medical_conditions = ?, drug_allergies = ?, current_medications = ? WHERE id = ?', async () => {
    const queries = wireFakeDb();
    const res = await POST(
      req({
        user_id: 55,
        medical_conditions: '  asthma  ',
        drug_allergies: ' penicillin ',
        current_medications: ' ventolin ',
      })
    );
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true });

    const updateQuery = queries.find((q) => /update/i.test(q.sql));
    expect(updateQuery).toBeDefined();
    expect(updateQuery!.sql.toLowerCase()).toContain('update');
    expect(updateQuery!.sql.toLowerCase()).toContain('users');
    expect(updateQuery!.params).toEqual(['asthma', 'penicillin', 'ventolin', 55]);
  });

  it('missing fields default to empty string (trim($_POST[x] ?? \'\')), not null/undefined, and overwrite existing values', async () => {
    const queries = wireFakeDb();
    await POST(req({ user_id: 5 }));

    const updateQuery = queries.find((q) => /update/i.test(q.sql));
    expect(updateQuery!.params).toEqual(['', '', '', 5]);
  });

  it('writes exactly one activity_logs row: log_type=data, action=update, user_id, entity_type=user, entity_id=userId, new_value={medical_conditions,drug_allergies,current_medications}, admin_id/admin_name/line_account_id set', async () => {
    const queries = wireFakeDb();
    await POST(req({ user_id: 55, medical_conditions: 'asthma', drug_allergies: 'penicillin', current_medications: 'ventolin' }));

    const logInsert = queries.find((q) => q.sql.toLowerCase().includes('activity_logs'));
    expect(logInsert).toBeDefined();
    expect(logInsert!.params).toEqual(
      expect.arrayContaining([
        'data',
        'update',
        'อัพเดทข้อมูลทางการแพทย์',
        55,
        'user',
        JSON.stringify({ medical_conditions: 'asthma', drug_allergies: 'penicillin', current_medications: 'ventolin' }),
        42,
        'pharmacist_a',
        7,
      ])
    );
  });

  it('falls back to session.currentBotId ?? null for activity_logs.line_account_id when currentBotId is null', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }), { currentBotId: null });
    await POST(req({ user_id: 1 }));

    const logInsert = queries.find((q) => q.sql.toLowerCase().includes('activity_logs'));
    expect(logInsert!.params[logInsert!.params.length - 1]).toBeNull();
  });

  it('DB failure -> 400 {success:false, error}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ user_id: 1 }));
    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
  });
});
