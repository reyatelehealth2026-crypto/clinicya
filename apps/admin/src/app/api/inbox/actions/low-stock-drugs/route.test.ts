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
  const url = `https://tenant.re-ya.com/api/inbox/actions/low-stock-drugs${search}`;
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

describe('GET /api/inbox/actions/low-stock-drugs', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req());

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('SCHEMA-DRIFT FIX: queries requires_prescription aliased to is_prescription, NOT the (nonexistent) is_prescription column directly', async () => {
    const rows = [{ id: 1, sku: 'A', name: 'Drug A', generic_name: 'a', stock: 2, min_stock: 10, drug_category: 'otc', is_prescription: 0 }];
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? rows : []));

    const res = await GET(req());
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: rows, count: 1 });

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery).toBeDefined();
    expect(drugQuery!.sql).toContain('requires_prescription AS is_prescription');
    expect(drugQuery!.sql).not.toContain('drug_category, is_prescription');
    expect(drugQuery!.sql.split('is_prescription')).toHaveLength(2); // exactly one occurrence
  });

  it('happy path: filters is_active=1 AND stock<=min_stock AND stock>0, orders by stock/GREATEST(min_stock,1) ASC, defaults limit to 50', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [] : []));

    await GET(req());

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery!.sql).toContain('WHERE is_active = 1');
    expect(drugQuery!.sql).toContain('AND stock <= min_stock');
    expect(drugQuery!.sql).toContain('AND stock > 0');
    expect(drugQuery!.sql).toContain('ORDER BY (stock / GREATEST(min_stock, 1)) ASC');
    expect(drugQuery!.sql).toContain('LIMIT');
    expect(drugQuery!.params).toEqual([50]);
  });

  it('honors a custom "limit" query param', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [] : []));

    await GET(req('?limit=5'));

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery!.params).toEqual([5]);
  });

  it('returns an empty array with success:true when nothing is low on stock, not an error', async () => {
    wireFakeDb(() => []);

    const res = await GET(req());
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: [], count: 0 });
  });

  it('a thrown DB error is swallowed into an empty array (literal PHP catch(PDOException){return [];}) -> success:true, count:0, not an error response', async () => {
    wireFakeDb(() => {
      throw new Error('connection refused');
    });

    const res = await GET(req());
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: [], count: 0 });
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
