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
  const url = `https://tenant.re-ya.com/api/inbox/actions/drug-inventory${search}`;
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

describe('GET /api/inbox/actions/drug-inventory', () => {
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

  it('SCHEMA-DRIFT FIX: queries requires_prescription aliased to is_prescription, NOT the (nonexistent) is_prescription column directly', async () => {
    const queries = wireFakeDb((sqlText) =>
      sqlText.includes('FROM business_items')
        ? [
            {
              id: 20,
              sku: 'SKU-20',
              name: 'Amoxicillin 500mg',
              generic_name: 'Amoxicillin',
              stock: 30,
              min_stock: 10,
              price: '120.00',
              sale_price: '110.00',
              cost_price: '70.00',
              is_active: 1,
              is_prescription: 1,
              drug_category: 'controlled',
              storage_condition: 'cool, dry',
              storage_zone_type: 'A',
              requires_batch_tracking: 1,
              requires_expiry_tracking: 1,
            },
          ]
        : []
    );

    const res = await GET(req('?product_id=20'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.isPrescription).toBe(true);

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery).toBeDefined();
    expect(drugQuery!.sql).toContain('requires_prescription AS is_prescription');
    // The literal, unaliased PHP shape (`is_active, is_prescription, drug_category`) must NOT appear —
    // is_prescription only ever appears as the alias target of requires_prescription.
    expect(drugQuery!.sql).not.toContain('is_active, is_prescription,');
    expect(drugQuery!.sql.split('is_prescription')).toHaveLength(2); // exactly one occurrence
    expect(drugQuery!.params).toEqual([20]);
  });

  it('happy path: full inventory shape, in-stock / low-stock / out-of-stock flags derived from stock vs min_stock', async () => {
    wireFakeDb((sqlText) =>
      sqlText.includes('FROM business_items')
        ? [
            {
              id: 21,
              sku: 'SKU-21',
              name: 'Paracetamol 500mg',
              generic_name: 'Paracetamol',
              stock: 5,
              min_stock: 10,
              price: '30.00',
              sale_price: null,
              cost_price: null,
              is_active: 1,
              is_prescription: 0,
              drug_category: 'otc',
              storage_condition: null,
              storage_zone_type: null,
              requires_batch_tracking: 0,
              requires_expiry_tracking: 1,
            },
          ]
        : []
    );

    const res = await GET(req('?product_id=21'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        found: true,
        productId: 21,
        sku: 'SKU-21',
        name: 'Paracetamol 500mg',
        genericName: 'Paracetamol',
        stock: 5,
        minStock: 10,
        inStock: true,
        isLowStock: true,
        isOutOfStock: false,
        price: 30,
        salePrice: null,
        costPrice: null,
        isActive: true,
        isPrescription: false,
        drugCategory: 'otc',
        storageCondition: null,
        storageZoneType: null,
        requiresBatchTracking: false,
        requiresExpiryTracking: true,
      },
    });
  });

  it('product_id/drug_id/id fallback order — product_id wins when present', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [] : []));

    await GET(req('?product_id=1&drug_id=2&id=3'));

    expect(queries.find((q) => q.sql.includes('FROM business_items'))!.params).toEqual([1]);
  });

  it('not found -> {found:false, productId, inStock:false, stock:0}, success:false, HTTP status still 200', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?product_id=999'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: false, data: { found: false, productId: 999, inStock: false, stock: 0 } });
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
