/**
 * @jest-environment node
 */
jest.mock('@reya/tenant', () => ({
  createMasterLineAccountRouteRepository: jest.fn(),
  routeByLineAccount: jest.fn(),
}));
jest.mock('@reya/db', () => ({
  getTenantDb: jest.fn(),
}));
jest.mock('@reya/auth', () => {
  const actual = jest.requireActual('@reya/auth');
  return { ...actual, runWithTenantDb: actual.runWithTenantDb };
});

import type { TenantDB } from '@reya/db';
import { getTenantDb } from '@reya/db';
import { routeByLineAccount } from '@reya/tenant';
import { makeFakeKyselyDb, sqlDate, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { GET, OPTIONS, POST } from './route';

const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;
const mockRouteByLineAccount = routeByLineAccount as jest.MockedFunction<typeof routeByLineAccount>;

function setupTenant(queryImpl: QueryImpl) {
  const { db, queries } = makeFakeKyselyDb<TenantDB>(queryImpl);
  mockGetTenantDb.mockResolvedValue(db);
  return { db, queries };
}

function requestWithTenantHeader(url: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers);
  headers.set('x-tenant-id', '2');
  return new Request(url, { ...init, headers }) as unknown as import('next/server').NextRequest;
}

const WISHLIST_ROW = {
  id: 1,
  user_id: 42,
  line_user_id: 'U1',
  product_id: 200,
  line_account_id: 1,
  price_when_added: '120.00',
  notify_on_sale: 1,
  notify_on_restock: 0,
  notified_at: null,
  // Real mysql2 Date object (no `dateStrings: true` configured — see sqlDate()'s doc comment), not a
  // literal string: proves normalizeWishlistItemDates()'s formatting actually runs on this fixture.
  created_at: sqlDate('2026-07-01 12:00:00'),
  name: 'พาราเซตามอล 500 มก.',
  sku: 'MED-0001',
  price: '120.00',
  sale_price: '99.00',
  image_url: null,
  stock: 42,
  is_on_sale: 1,
  discount_percent: 18,
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('OPTIONS /api/miniapp/wishlist', () => {
  it('answers 204 with CORS headers', async () => {
    const response = await OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
  });
});

describe('GET action=list (also the default)', () => {
  it('returns items + count for a resolved user', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('CREATE TABLE IF NOT EXISTS')) return { insertId: 0, affectedRows: 0 };
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('FROM user_wishlist')) return [WISHLIST_ROW];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/wishlist?line_user_id=U1&line_account_id=1');
    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toMatchObject({ success: true, count: 1 });
    expect(body.items).toHaveLength(1);
    // Contract-drift regression: created_at must be PHP PDO's raw string, not a `Z`-suffixed ISO string
    // from a bare-serialized mysql2 Date.
    expect(body.items[0].created_at).toBe('2026-07-01 12:00:00');
  });

  it('no user resolved -> {success:true, items:[]} with NO count key', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('CREATE TABLE IF NOT EXISTS')) return { insertId: 0, affectedRows: 0 };
      if (sqlText.includes('SELECT id FROM users')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/wishlist');
    const response = await GET(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, items: [] });
    expect('count' in body).toBe(false);
  });
});

describe('POST action=toggle', () => {
  it('add: item not yet in wishlist -> inserted, is_favorite:true', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('CREATE TABLE IF NOT EXISTS')) return { insertId: 0, affectedRows: 0 };
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('SELECT id FROM user_wishlist')) return [];
      if (sqlText.includes('SELECT price, sale_price FROM business_items')) return [{ price: '120.00', sale_price: '99.00' }];
      if (sqlText.includes('INSERT INTO user_wishlist')) return { insertId: 5, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/wishlist', {
      method: 'POST',
      body: JSON.stringify({ action: 'toggle', line_user_id: 'U1', product_id: 201, line_account_id: 1 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, is_favorite: true, message: 'เพิ่มรายการโปรดแล้ว' });
    expect(queries.some((q) => q.sql.includes('INSERT INTO user_wishlist'))).toBe(true);
  });

  it('remove: item already in wishlist -> deleted, is_favorite:false', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('CREATE TABLE IF NOT EXISTS')) return { insertId: 0, affectedRows: 0 };
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('SELECT id FROM user_wishlist')) return [{ id: 1 }];
      if (sqlText.includes('DELETE FROM user_wishlist')) return { insertId: 0, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/wishlist', {
      method: 'POST',
      body: JSON.stringify({ action: 'toggle', line_user_id: 'U1', product_id: 200, line_account_id: 1 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, is_favorite: false, message: 'ลบออกจากรายการโปรดแล้ว' });
    expect(queries.some((q) => q.sql.includes('DELETE FROM user_wishlist'))).toBe(true);
  });

  it('missing user/product -> {success:false, error} (not `message`)', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('CREATE TABLE IF NOT EXISTS')) return { insertId: 0, affectedRows: 0 };
      if (sqlText.includes('SELECT id FROM users')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/wishlist', {
      method: 'POST',
      body: JSON.stringify({ action: 'toggle', line_user_id: '', product_id: 0 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, error: 'Missing user or product' });
    expect('message' in body).toBe(false);
  });
});

describe('POST action=remove', () => {
  it('success', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('CREATE TABLE IF NOT EXISTS')) return { insertId: 0, affectedRows: 0 };
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('DELETE FROM user_wishlist')) return { insertId: 0, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/wishlist', {
      method: 'POST',
      body: JSON.stringify({ action: 'remove', line_user_id: 'U1', product_id: 200, line_account_id: 1 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, message: 'ลบออกจากรายการโปรดแล้ว' });
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/wishlist') as unknown as import('next/server').NextRequest;

    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
