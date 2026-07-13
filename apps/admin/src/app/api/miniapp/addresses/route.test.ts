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

const ADDRESS_ROW = {
  label: 'primary',
  name: 'สมชาย ใจดี',
  phone: '0812345678',
  address: '123/45 หมู่บ้านสุขใจ',
  subdistrict: 'บางรัก',
  district: 'บางรัก',
  province: 'กรุงเทพมหานคร',
  postcode: '10500',
  // Real mysql2 Date object, not a literal string — proves the row-normalizer's formatting actually runs.
  updated_at: sqlDate('2026-07-01 12:00:00'),
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('OPTIONS /api/miniapp/addresses', () => {
  it('answers 204 with CORS headers', async () => {
    const response = await OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
  });
});

describe('GET action=list', () => {
  it('returns addresses for a resolved (line_user_id, line_account_id)', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('FROM user_addresses')) return [ADDRESS_ROW];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/addresses?action=list&line_user_id=U1&line_account_id=1');
    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toMatchObject({ success: true, addresses: [expect.objectContaining({ label: 'primary' })] });
    // Contract-drift regression: updated_at must be a MySQL-shaped string, not a `Z`-suffixed ISO string.
    expect(body.addresses[0].updated_at).toBe('2026-07-01 12:00:00');
  });

  it('missing line_user_id -> {success:false, addresses:[]}', async () => {
    setupTenant(() => {
      throw new Error('no query should run without a line_user_id');
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/addresses?action=list');
    const response = await GET(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'LINE User ID required', addresses: [] });
  });
});

describe('POST action=upsert', () => {
  it('inserts (or updates) the row, then returns the resulting address', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('INSERT INTO user_addresses')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('SELECT') && sqlText.includes('FROM user_addresses')) return [ADDRESS_ROW];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/addresses', {
      method: 'POST',
      body: JSON.stringify({
        action: 'upsert',
        line_user_id: 'U1',
        line_account_id: 1,
        label: 'primary',
        name: 'สมชาย ใจดี',
        phone: '0812345678',
        address: '123/45 หมู่บ้านสุขใจ',
        subdistrict: 'บางรัก',
        district: 'บางรัก',
        province: 'กรุงเทพมหานคร',
        postcode: '10500',
      }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toMatchObject({ success: true, message: 'บันทึกที่อยู่แล้ว', address: { label: 'primary' } });
    expect(queries.some((q) => q.sql.includes('ON DUPLICATE KEY UPDATE'))).toBe(true);
  });

  it('invalid label -> {success:false} without touching the DB', async () => {
    setupTenant(() => {
      throw new Error('no query should run for an invalid label');
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/addresses', {
      method: 'POST',
      body: JSON.stringify({ action: 'upsert', line_user_id: 'U1', label: 'not-a-real-label' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'Invalid label' });
  });
});

describe('POST action=delete', () => {
  it('always succeeds, even when no row matched (idempotent by design)', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('DELETE FROM user_addresses')) return { insertId: 0, affectedRows: 0 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/addresses', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete', line_user_id: 'U1', line_account_id: 1, label: 'secondary_1' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, message: 'ลบที่อยู่แล้ว' });
    expect(queries.some((q) => q.sql.includes('DELETE FROM user_addresses'))).toBe(true);
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/addresses?action=list&line_user_id=U1') as unknown as import('next/server').NextRequest;

    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
