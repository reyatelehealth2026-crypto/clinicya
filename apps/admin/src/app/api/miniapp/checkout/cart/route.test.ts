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
import { makeFakeKyselyDb, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
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

beforeEach(() => {
  jest.clearAllMocks();
});

describe('OPTIONS /api/miniapp/checkout/cart', () => {
  it('answers 204 with CORS headers', () => {
    const response = OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Origin')).toBe('*');
  });
});

describe('GET action=cart', () => {
  it('dispatches to handleGetCart and always answers HTTP 200', async () => {
    setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/checkout/cart?action=cart');
    const response = await GET(request);
    const body = await response.json();
    expect(response.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'User not found' });
  });

  it('unknown action -> {success:false, message:"Invalid action"}, HTTP 200 (checkout.php never calls http_response_code)', async () => {
    setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/checkout/cart?action=nope');
    const response = await GET(request);
    const body = await response.json();
    expect(response.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'Invalid action' });
  });
});

describe('POST add_to_cart/update_cart/remove_from_cart/clear_cart dispatch', () => {
  it('add_to_cart', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM shop_settings')) return [{ order_data_source: 'shop' }];
      if (sqlText.includes('FROM business_items WHERE id')) return [{ id: 1, name: 'X', price: '1', sale_price: null, stock: 5 }];
      if (sqlText.includes('INSERT INTO cart_items')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('SUM(quantity)')) return [{ total: 1 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/checkout/cart', {
      method: 'POST',
      body: JSON.stringify({ action: 'add_to_cart', line_user_id: 'U1', product_id: 1, quantity: 1 }),
    });
    const response = await POST(request);
    const body = await response.json();
    expect(body).toMatchObject({ success: true, message: 'Added to cart' });
  });

  it('clear_cart', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      return [];
    });
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/checkout/cart', {
      method: 'POST',
      body: JSON.stringify({ action: 'clear_cart', line_user_id: 'U1' }),
    });
    const response = await POST(request);
    const body = await response.json();
    expect(body).toEqual({ success: true, message: 'Cart cleared', cart_count: 0 });
  });

  it('unknown action -> Invalid action, HTTP 200', async () => {
    setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/checkout/cart', {
      method: 'POST',
      body: JSON.stringify({ action: 'not_a_real_action' }),
    });
    const response = await POST(request);
    const body = await response.json();
    expect(response.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'Invalid action' });
  });

  it('an unexpected thrown error surfaces as a flat {success:false, message}, HTTP 200 (no error_details, unlike rewards.php)', async () => {
    setupTenant(() => {
      throw new Error('DB exploded');
    });
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/checkout/cart', {
      method: 'POST',
      body: JSON.stringify({ action: 'clear_cart', line_user_id: 'U1' }),
    });
    const response = await POST(request);
    const body = await response.json();
    expect(response.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'DB exploded' });
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/checkout/cart?action=cart') as unknown as import('next/server').NextRequest;

    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
