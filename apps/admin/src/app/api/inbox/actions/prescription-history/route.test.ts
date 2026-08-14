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

function req(search = ''): NextRequest {
  const url = `https://tenant.re-ya.com/api/inbox/actions/prescription-history${search}`;
  return { nextUrl: new URL(url) } as unknown as NextRequest;
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

const ROW = {
  transaction_id: 501,
  order_number: 'ORD-501',
  created_at: new Date(2026, 6, 1, 10, 30, 0),
  status: 'completed',
  product_name: 'Amoxicillin 500mg',
  quantity: 2,
  generic_name: 'Amoxicillin',
  is_prescription: 1,
  drug_category: 'controlled',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/prescription-history', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?user_id=42'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Invalid user ID" when user_id is missing', async () => {
    const queries = wireFakeDb();

    const res = await GET(req());

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Invalid user ID" when user_id <= 0', async () => {
    wireFakeDb();

    const res = await GET(req('?user_id=-5'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('happy path: default limit=20, WHERE clause uses requires_prescription (not is_prescription), success/data/count envelope', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM transactions t') ? [ROW] : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: [
        {
          transaction_id: 501,
          order_number: 'ORD-501',
          created_at: '2026-07-01 10:30:00',
          status: 'completed',
          product_name: 'Amoxicillin 500mg',
          quantity: 2,
          generic_name: 'Amoxicillin',
          is_prescription: 1,
          drug_category: 'controlled',
        },
      ],
      count: 1,
    });

    const q = queries.find((r) => r.sql.includes('FROM transactions t'));
    expect(q).toBeDefined();
    expect(q!.sql).toContain('bi.requires_prescription = 1');
    expect(q!.sql).toContain('bi.requires_prescription AS is_prescription');
    expect(q!.sql).not.toContain('bi.is_prescription');
    expect(q!.params).toEqual([42, 20]);
  });

  it('limit query param is honored, no clamp applied', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM transactions t') ? [] : []));

    await GET(req('?user_id=42&limit=5'));

    const q = queries.find((r) => r.sql.includes('FROM transactions t'));
    expect(q!.params).toEqual([42, 5]);
  });

  it('empty result: success:true, data:[], count:0', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?user_id=999'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: [], count: 0 });
  });

  // NOTE: no "Database error: ..." 500 test here, deliberately — see route.ts's
  // own doc: getUserPrescriptionHistory() has its own `catch (PDOException $e)`
  // (a literal port of the PHP method's own swallow-to-empty-array behavior)
  // and never throws, so route.ts's defensive try/catch is genuinely
  // unreachable through any DB-level failure. Same precedent as
  // ../drug-inventory/route.test.ts and ../low-stock-drugs/route.test.ts
  // (Phase 4 batch 4a).

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
