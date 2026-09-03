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
  const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText, params) => {
    // Kysely's `.transaction().execute()` issues raw `begin`/`commit` through the same connection —
    // always answer them so tests don't have to special-case every branch's queryImpl.
    if (sqlText === 'begin' || sqlText === 'commit' || sqlText === 'rollback') {
      return { insertId: 0, affectedRows: 0 };
    }
    return queryImpl(sqlText, params);
  });
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

describe('OPTIONS /api/miniapp/consent', () => {
  it('answers 204 with CORS headers', async () => {
    const response = await OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
  });
});

describe('POST action=save', () => {
  it('existing user: upserts consents in a transaction, returns {success:true, message:"Consent saved", user_id}', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes("SHOW COLUMNS FROM shop_settings")) return [];
      if (sqlText.includes('INSERT INTO user_consents')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes("SHOW COLUMNS FROM users")) return [{ Field: 'consent_privacy' }];
      if (sqlText.includes('UPDATE users SET')) return { insertId: 0, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/consent', {
      method: 'POST',
      body: JSON.stringify({ action: 'save', line_user_id: 'U1', consents: { health_data: true } }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toEqual({ success: true, message: 'Consent saved', user_id: 42 });
    expect(queries.some((q) => q.sql.includes('INSERT INTO user_consents'))).toBe(true);
    expect(queries.some((q) => q.sql === 'begin')).toBe(true);
    expect(queries.some((q) => q.sql === 'commit')).toBe(true);
  });

  it('brand-new user: auto-creates a users row from the tenant default line_account, then saves', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [];
      if (sqlText.includes('SELECT id FROM line_accounts')) return [{ id: 3 }];
      if (sqlText.includes('INSERT INTO users')) return { insertId: 99, affectedRows: 1 };
      if (sqlText.includes('SHOW COLUMNS FROM shop_settings')) return [];
      if (sqlText.includes('INSERT INTO user_consents')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('SHOW COLUMNS FROM users')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/consent', {
      method: 'POST',
      body: JSON.stringify({ action: 'save', line_user_id: 'Ubrandnew', consents: { privacy_policy: true } }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, message: 'Consent saved', user_id: 99 });
    expect(queries.some((q) => q.sql.includes('INSERT INTO users'))).toBe(true);
  });

  it('missing line_user_id -> {success:false, message:"LINE User ID required"}, no DB writes', async () => {
    setupTenant(() => {
      throw new Error('no query should run without a line_user_id');
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/consent', {
      method: 'POST',
      body: JSON.stringify({ action: 'save', line_user_id: '', consents: { health_data: true } }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'LINE User ID required' });
  });

  it('unknown action -> {success:false, message:"Invalid action"}', async () => {
    setupTenant(() => {
      throw new Error('no query should run for an unknown action');
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/consent', {
      method: 'POST',
      body: JSON.stringify({ action: 'withdraw', line_user_id: 'U1' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'Invalid action' });
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/consent', {
      method: 'POST',
      body: JSON.stringify({ action: 'save', line_user_id: 'U1', consents: {} }),
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
