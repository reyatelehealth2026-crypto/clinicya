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
import { OPTIONS, POST } from './route';

const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;
const mockRouteByLineAccount = routeByLineAccount as jest.MockedFunction<typeof routeByLineAccount>;

function setupTenant(queryImpl: QueryImpl) {
  const { db } = makeFakeKyselyDb<TenantDB>(queryImpl);
  mockGetTenantDb.mockResolvedValue(db);
}

function requestWithTenantHeader(url: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers);
  headers.set('x-tenant-id', '2');
  return new Request(url, { ...init, headers }) as unknown as import('next/server').NextRequest;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('OPTIONS /api/miniapp/checkout/pricing', () => {
  it('answers 204 with CORS headers', () => {
    const response = OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Origin')).toBe('*');
  });
});

describe('POST action=validate_promo', () => {
  it('hardcoded WELCOME10 -> matches fixtures/checkout-cart/validate-promo-hardcoded-welcome10.json', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/checkout/pricing', {
      method: 'POST',
      body: JSON.stringify({ action: 'validate_promo', code: 'welcome10', line_user_id: 'U1', subtotal: 200 }),
    });
    const response = await POST(request);
    const body = await response.json();
    expect(response.status).toBe(200);
    expect(body).toEqual({ success: true, message: 'โค้ดถูกต้อง', valid: true, discount: 20, discount_type: 'fixed', code: 'WELCOME10' });
  });

  it('unknown action -> Invalid action, HTTP 200', async () => {
    setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/checkout/pricing', {
      method: 'POST',
      body: JSON.stringify({ action: 'not_real' }),
    });
    const response = await POST(request);
    const body = await response.json();
    expect(response.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'Invalid action' });
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/checkout/pricing', {
      method: 'POST',
      body: JSON.stringify({ action: 'validate_promo', code: 'X' }),
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
