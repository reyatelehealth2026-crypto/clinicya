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
  const url = `https://tenant.re-ya.com/api/inbox/actions/drug-pricing-data${search}`;
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

describe('GET /api/inbox/actions/drug-pricing-data', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?product_id=1'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Product ID is required" when product_id/drug_id/id are all absent, no DB queries issued', async () => {
    const queries = wireFakeDb();

    const res = await GET(req());

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Product ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('happy path: queries id, name, price, sale_price, cost_price from business_items WHERE id = ? and computes margin', async () => {
    const queries = wireFakeDb((sqlText) =>
      sqlText.includes('FROM business_items') ? [{ id: 11, name: 'Ibuprofen', price: '50.00', sale_price: '45.00', cost_price: '30.00' }] : []
    );

    const res = await GET(req('?product_id=11'));
    const body = await res.json();

    expect(res.status).toBe(200);
    // effectivePrice=45, margin=45-30=15, marginPercent=(15/45)*100=33.333...->33.33
    expect(body).toEqual({
      success: true,
      data: {
        found: true,
        productId: 11,
        name: 'Ibuprofen',
        price: 50,
        salePrice: 45,
        costPrice: 30,
        effectivePrice: 45,
        margin: 15,
        marginPercent: 33.33,
        hasCostData: true,
      },
    });

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery).toBeDefined();
    expect(drugQuery!.sql).toContain('SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id');
    expect(drugQuery!.params).toEqual([11]);
  });

  it('product_id/drug_id/id fallback order — product_id wins when present', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [{ id: 1, name: 'A', price: '10.00', sale_price: null, cost_price: null }] : []));

    await GET(req('?product_id=1&drug_id=2&id=3'));

    expect(queries.find((q) => q.sql.includes('FROM business_items'))!.params).toEqual([1]);
  });

  it('falls back to drug_id then id when product_id is absent', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [{ id: 2, name: 'B', price: '10.00', sale_price: null, cost_price: null }] : []));

    await GET(req('?drug_id=2&id=3'));

    expect(queries.find((q) => q.sql.includes('FROM business_items'))!.params).toEqual([2]);
  });

  it('treats sale_price/cost_price of 0 as null (PHP truthiness, not a null check)', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [{ id: 4, name: 'Zero', price: '20.00', sale_price: '0.00', cost_price: 0 }] : []));

    const res = await GET(req('?product_id=4'));
    const body = await res.json();

    expect(body.data).toEqual({
      found: true,
      productId: 4,
      name: 'Zero',
      price: 20,
      salePrice: null,
      costPrice: null,
      effectivePrice: 20,
      margin: null,
      marginPercent: null,
      hasCostData: false,
    });
  });

  it('not found -> success:false, HTTP status still 200 (no explicit status arg in PHP sendResponse)', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?product_id=999'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: false, data: { found: false, productId: 999 } });
  });

  it('a thrown DB error is swallowed into the result (literal PHP catch(PDOException)) -> success:false, HTTP status still 200, not 500', async () => {
    wireFakeDb(() => {
      throw new Error('connection refused');
    });

    const res = await GET(req('?product_id=1'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: false, data: { found: false, productId: 1, error: 'connection refused' } });
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
