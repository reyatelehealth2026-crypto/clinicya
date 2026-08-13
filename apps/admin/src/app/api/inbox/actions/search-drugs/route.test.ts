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
  const url = `https://tenant.re-ya.com/api/inbox/actions/search-drugs${search}`;
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

describe('GET /api/inbox/actions/search-drugs', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?query=amox'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Search query is required" when query is absent or blank/whitespace-only, no DB queries issued', async () => {
    for (const search of ['', '?query=', '?query=%20%20']) {
      const queries = wireFakeDb();
      const res = await GET(req(search));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Search query is required' });
      expect(queries).toHaveLength(0);
    }
  });

  it('400 "Query must be at least 2 characters" for a 1-character query', async () => {
    const queries = wireFakeDb();

    const res = await GET(req('?query=a'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Query must be at least 2 characters' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Query is too long (max 100 characters)" for a 101-character query', async () => {
    const queries = wireFakeDb();
    const longQuery = 'a'.repeat(101);

    const res = await GET(req(`?query=${longQuery}`));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Query is too long (max 100 characters)' });
    expect(queries).toHaveLength(0);
  });

  it('accepts exactly 2 and exactly 100 characters (boundary), issuing the DB query', async () => {
    wireFakeDb(() => []);
    const res2 = await GET(req('?query=ab'));
    expect(res2.status).toBe(200);

    wireFakeDb(() => []);
    const res100 = await GET(req(`?query=${'a'.repeat(100)}`));
    expect(res100.status).toBe(200);
  });

  it('happy path: searches name/sku/generic_name/name_en with is_active=1 and the line_account_id OR-NULL clause, ordered by stock DESC, name ASC LIMIT 10', async () => {
    const rows = [{ id: 1, name: 'Amoxicillin', sku: 'AMX-1', price: '50.00', sale_price: '45.00', stock: 20, description: null, generic_name: 'Amoxicillin', name_en: 'Amoxicillin EN' }];
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? rows : []));

    const res = await GET(req('?query=amox'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: [{ id: 1, name: 'Amoxicillin', name_en: 'Amoxicillin EN', generic_name: 'Amoxicillin', sku: 'AMX-1', price: 45, stock: 20 }],
      count: 1,
      query: 'amox',
    });

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(drugQuery).toBeDefined();
    expect(drugQuery!.sql).toContain('SELECT id, name, sku, price, sale_price, stock, description, generic_name, name_en');
    expect(drugQuery!.sql).toContain('WHERE is_active = 1');
    expect(drugQuery!.sql).toContain('name LIKE ?');
    expect(drugQuery!.sql).toContain('sku LIKE ?');
    expect(drugQuery!.sql).toContain('generic_name LIKE ?');
    expect(drugQuery!.sql).toContain('name_en LIKE ?');
    expect(drugQuery!.sql).toContain('line_account_id = ?');
    expect(drugQuery!.sql).toContain('line_account_id IS NULL');
    expect(drugQuery!.sql).toContain('ORDER BY stock DESC, name ASC LIMIT 10');
    // search term bound 4 times (name, sku, generic_name, name_en) + lineAccountId once
    expect(drugQuery!.params).toEqual(['%amox%', '%amox%', '%amox%', '%amox%', 3]);
  });

  it('price falls back to sale_price ?? price ?? 0', async () => {
    wireFakeDb((sqlText) =>
      sqlText.includes('FROM business_items') ? [{ id: 2, name: 'X', sku: null, price: '30.00', sale_price: null, stock: null, description: null, generic_name: null, name_en: null }] : []
    );

    const res = await GET(req('?query=xx'));
    const body = await res.json();

    expect(body.data[0]).toEqual({ id: 2, name: 'X', name_en: '', generic_name: '', sku: null, price: 30, stock: 0 });
  });

  it('trims the query before validating/searching', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [] : []));

    const res = await GET(req('?query=%20%20amox%20%20'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.query).toBe('amox');
    expect(queries.find((q) => q.sql.includes('FROM business_items'))!.params[0]).toBe('%amox%');
  });

  it('500 "Database error: ..." on a thrown DB failure', async () => {
    wireFakeDb(() => {
      throw new Error('deadlock');
    });

    const res = await GET(req('?query=amox'));
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
