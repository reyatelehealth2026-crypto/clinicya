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
  const url = `https://tenant.re-ya.com/api/inbox/actions/max-discount${search}`;
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

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/max-discount', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?drug_id=1'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Drug ID is required" when neither drug_id nor id is present, no DB queries issued', async () => {
    const queries = wireFakeDb();

    const res = await GET(req());

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('happy path: queries business_items by id and applies the default 10% minimum margin', async () => {
    const queries = wireFakeDb((sqlText) =>
      sqlText.includes('FROM business_items') ? [{ id: 42, name: 'Paracetamol', price: '100.00', sale_price: null, cost_price: '60.00' }] : []
    );

    const res = await GET(req('?drug_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    // price=100, cost=60 -> minMarginDecimal=0.1 -> floorPrice=60/0.9=66.666... -> maxDiscount=33.33, maxDiscountPercent=33.33
    expect(body).toEqual({
      success: true,
      data: {
        drugId: 42,
        maxDiscount: 33.33,
        maxDiscountPercent: 33.33,
        floorPrice: 66.67,
        currentPrice: 100,
        cost: 60,
        minMarginPercent: 10,
      },
    });

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery).toBeDefined();
    expect(drugQuery!.sql).toContain('SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id');
    expect(drugQuery!.params).toEqual([42]);
  });

  it('supports "id" as a fallback for "drug_id" and a custom min_margin', async () => {
    const queries = wireFakeDb((sqlText) =>
      sqlText.includes('FROM business_items') ? [{ id: 7, name: 'X', price: '50.00', sale_price: null, cost_price: '25.00' }] : []
    );

    const res = await GET(req('?id=7&min_margin=20'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.minMarginPercent).toBe(20);

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery!.params).toEqual([7]);
  });

  it('drug_id takes precedence over id when both are present (isset()-based, not truthiness)', async () => {
    const queries = wireFakeDb((sqlText) =>
      sqlText.includes('FROM business_items') ? [{ id: 1, name: 'A', price: '10.00', sale_price: null, cost_price: '5.00' }] : []
    );

    await GET(req('?drug_id=1&id=999'));

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery!.params).toEqual([1]);
  });

  it('drug not found -> success:false with error payload, but HTTP status is still 200 (literal PHP sendResponse-with-no-status-arg parity)', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?drug_id=999'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: false,
      data: { maxDiscount: 0, maxDiscountPercent: 0, floorPrice: 0, error: 'Drug not found' },
    });
  });

  it('min_margin >= 100 -> "Invalid minimum margin percentage" error payload, HTTP status still 200', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [{ id: 1, name: 'A', price: '100.00', sale_price: null, cost_price: '50.00' }] : []));

    const res = await GET(req('?drug_id=1&min_margin=100'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: false,
      data: {
        maxDiscount: 0,
        maxDiscountPercent: 0,
        floorPrice: 100,
        currentPrice: 100,
        cost: 50,
        minMarginPercent: 100,
        error: 'Invalid minimum margin percentage',
      },
    });
  });

  it('500 "Database error: ..." on a thrown DB failure', async () => {
    wireFakeDb(() => {
      throw new Error('connection lost');
    });

    const res = await GET(req('?drug_id=1'));
    const body = await res.json();

    expect(res.status).toBe(500);
    expect(body.success).toBe(false);
    expect(body.error).toBe('Database error: connection lost');
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
