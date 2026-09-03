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
import { DATA_RIGHTS_CONFIRMATION_CODE_REGEX } from '@reya/contracts';
import { makeFakeKyselyDb, sqlDate, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { OPTIONS, POST } from './route';

const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;
const mockRouteByLineAccount = routeByLineAccount as jest.MockedFunction<typeof routeByLineAccount>;

function setupTenant(queryImpl: QueryImpl) {
  const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText, params) => {
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

const USER_ROW = {
  id: 42,
  line_account_id: 1,
  line_user_id: 'U1',
  display_name: 'สมชาย',
  real_name: 'สมชาย ใจดี',
  first_name: 'สมชาย',
  last_name: 'ใจดี',
  phone: '0812345678',
  email: null,
  birthday: sqlDate('1990-01-15'),
  gender: 'male',
  address: null,
  district: null,
  province: null,
  postal_code: null,
  member_id: 'M2600001',
  is_registered: 1,
  total_orders: 3,
  total_spent: '1590.00',
  available_points: 120,
  medical_conditions: null,
  drug_allergies: null,
  current_medications: null,
  blood_type: 'unknown',
  weight: '65.00',
  height: '170.00',
  created_at: sqlDate('2026-01-15 10:00:00'),
  registered_at: sqlDate('2026-01-15 10:05:00'),
  consent_privacy: 1,
  consent_terms: 1,
  consent_health_data: 0,
  consent_date: sqlDate('2026-01-15 10:05:00'),
  deletion_status: 'none',
  deletion_requested_at: null,
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('OPTIONS /api/miniapp/data-rights', () => {
  it('answers 204 with CORS headers', async () => {
    const response = await OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
  });
});

describe('POST action=withdraw_consent', () => {
  it('ok', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('FROM users WHERE line_user_id') && sqlText.includes('line_account_id')) return [USER_ROW];
      if (sqlText.includes('UPDATE user_consents')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO consent_logs')) return { insertId: 0, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/data-rights', {
      method: 'POST',
      body: JSON.stringify({ action: 'withdraw_consent', line_user_id: 'U1', line_account_id: 1 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toEqual({ success: true, message: 'ถอนความยินยอมเรียบร้อยแล้ว', consent_type: 'health_data' });
  });
});

describe('POST action=request_deletion', () => {
  it('ok — SOFT flag only, confirmation_code matches FORMAT_CHECKS regex', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('FROM users WHERE line_user_id') && sqlText.includes('line_account_id')) return [USER_ROW];
      if (sqlText.includes("UPDATE users SET deletion_status")) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO data_deletion_requests')) return { insertId: 0, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/data-rights', {
      method: 'POST',
      body: JSON.stringify({ action: 'request_deletion', line_user_id: 'U1', line_account_id: 1, reason: 'ไม่ได้ใช้แล้ว' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toMatchObject({
      success: true,
      message: 'รับคำขอลบข้อมูลแล้ว เราจะดำเนินการภายใน 30 วัน',
      status: 'requested',
    });
    expect(body.confirmation_code).toMatch(DATA_RIGHTS_CONFIRMATION_CODE_REGEX);
    expect(queries.some((q) => q.sql === 'begin')).toBe(true);
    expect(queries.some((q) => q.sql === 'commit')).toBe(true);
    expect(queries.some((q) => q.sql.includes('DELETE FROM users'))).toBe(false);
  });
});

describe('POST action=export_data', () => {
  it('ok — profile allowlist + best-effort lists, one failing read does not fail the export', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('FROM users WHERE line_user_id') && sqlText.includes('line_account_id')) return [USER_ROW];
      if (sqlText.includes('FROM user_consents')) return [{ consent_type: 'health_data', consent_version: '1.0', is_accepted: 0, accepted_at: null, withdrawn_at: sqlDate('2026-07-13 10:00:00'), updated_at: sqlDate('2026-07-13 10:00:00') }];
      if (sqlText.includes('FROM consent_logs')) {
        throw new Error('simulated consent_logs read failure');
      }
      if (sqlText.includes('FROM ai_conversation_history')) return [{ role: 'user', content: 'ปวดหัว', session_id: 'sess-1', created_at: sqlDate('2026-07-01 08:00:00') }];
      if (sqlText.includes('FROM transactions')) return [{ id: 501, order_number: 'ORD-2026-0501', total_amount: '590.00', status: 'delivered', created_at: sqlDate('2026-06-01 09:00:00'), products: 'พาราเซตามอล' }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/data-rights', {
      method: 'POST',
      body: JSON.stringify({ action: 'export_data', line_user_id: 'U1', line_account_id: 1 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body.success).toBe(true);
    expect(body.message).toBe('ส่งออกข้อมูลเรียบร้อยแล้ว');
    expect(body.data.export_meta.user_id).toBe(42);
    expect(body.data.profile.deletion_status).toBe('none');
    expect(body.data.profile.id).toBe(42);
    // consent_logs read failed -> [] (per-query isolation), not a whole-export failure.
    expect(body.data.consent_history).toEqual([]);
    expect(body.data.consents).toHaveLength(1);
    expect(body.data.orders).toHaveLength(1);
    // `total_amount` is a DECIMAL column: PHP's fetchOwnOrders() is a raw
    // fetchAll(PDO::FETCH_ASSOC) passthrough (no cast), so PDO returns the decimal
    // string "590.00" verbatim, not a float. The mocked DB row above already returns
    // it as the string '590.00' (matching mysql2's default, no `decimalNumbers` set) —
    // assert the same raw string comes out the other end, not a coerced JS number.
    expect(body.data.orders[0].total_amount).toBe('590.00');
  });
});

describe('identity validation ordering (matches api/data-rights.php exactly)', () => {
  it('missing line_user_id -> "LINE User ID required", even for an unrecognized action', async () => {
    setupTenant(() => {
      throw new Error('no query should run without a line_user_id');
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/data-rights', {
      method: 'POST',
      body: JSON.stringify({ action: 'not_a_real_action', line_user_id: '' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'LINE User ID required' });
  });

  it('user not found -> "User not found", even for an unrecognized action (checked BEFORE the switch)', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('FROM users')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/data-rights', {
      method: 'POST',
      body: JSON.stringify({ action: 'not_a_real_action', line_user_id: 'Uunknown', line_account_id: 1 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'User not found' });
  });

  it('unknown action WITH a resolved user -> "Invalid action"', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('FROM users')) return [USER_ROW];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/data-rights', {
      method: 'POST',
      body: JSON.stringify({ action: 'not_a_real_action', line_user_id: 'U1', line_account_id: 1 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'Invalid action' });
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/data-rights', {
      method: 'POST',
      body: JSON.stringify({ action: 'export_data', line_user_id: 'U1' }),
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
