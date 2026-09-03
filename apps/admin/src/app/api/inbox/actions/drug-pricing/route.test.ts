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
  const url = `https://tenant.re-ya.com/api/inbox/actions/drug-pricing${search}`;
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

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown = () => []): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/drug-pricing', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?drug_id=1'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Drug ID is required" when neither drug_id nor id present, no DB queries issued', async () => {
    const queries = wireFakeDb();

    const res = await GET(req());

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('happy path: queries id, name, price, sale_price, cost_price from business_items WHERE id = ?', async () => {
    const queries = wireFakeDb((sqlText) =>
      sqlText.includes('FROM business_items') ? [{ id: 5, name: 'Amoxicillin', price: '80.00', sale_price: '70.00', cost_price: '40.00' }] : []
    );

    const res = await GET(req('?drug_id=5'));
    const body = await res.json();

    expect(res.status).toBe(200);
    // price = sale_price=70, cost=40, margin=30, marginPercent=(30/70)*100=42.857...->42.86
    expect(body).toEqual({
      success: true,
      data: { drugId: 5, drugName: 'Amoxicillin', cost: 40, price: 70, margin: 30, marginPercent: 42.86, estimated: false },
    });

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery).toBeDefined();
    expect(drugQuery!.sql).toContain('SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id');
    expect(drugQuery!.params).toEqual([5]);
  });

  it('estimates cost as 70% of price when cost_price is absent/zero, flags estimated:true', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [{ id: 9, name: 'Vitamin C', price: '100.00', sale_price: null, cost_price: null }] : []));

    const res = await GET(req('?drug_id=9'));
    const body = await res.json();

    expect(res.status).toBe(200);
    // price=100, cost=100*0.7=70, margin=30, marginPercent=30
    expect(body.data).toEqual({ drugId: 9, drugName: 'Vitamin C', cost: 70, price: 100, margin: 30, marginPercent: 30, estimated: true });
  });

  it('supports "id" as a fallback for "drug_id"', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [{ id: 3, name: 'Y', price: '10.00', sale_price: null, cost_price: '5.00' }] : []));

    await GET(req('?id=3'));

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery!.params).toEqual([3]);
  });

  it('404 "Drug not found" when no matching row', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?drug_id=999'));
    const body = await res.json();

    expect(res.status).toBe(404);
    expect(body).toEqual({ success: false, error: 'Drug not found' });
  });

  it('500 "Database error: ..." on a thrown DB failure', async () => {
    wireFakeDb(() => {
      throw new Error('deadlock');
    });

    const res = await GET(req('?drug_id=1'));
    const body = await res.json();

    expect(res.status).toBe(500);
    expect(body).toEqual({ success: false, error: 'Database error: deadlock' });
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
