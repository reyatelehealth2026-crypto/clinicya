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

const USER_COLUMNS_ROW = { Field: '' };
function columnsResult(fields: string[]): Array<typeof USER_COLUMNS_ROW> {
  return fields.map((Field) => ({ Field }));
}

const FULL_USER_COLUMNS = [
  'id',
  'line_account_id',
  'line_user_id',
  'first_name',
  'last_name',
  'real_name',
  'birthday',
  'gender',
  'phone',
  'weight',
  'height',
  'medical_conditions',
  'drug_allergies',
  'member_id',
  'is_registered',
  'member_tier',
  'points',
  'registered_at',
  'created_at',
  'updated_at',
  'display_name',
  'picture_url',
  'email',
  'address',
  'district',
  'province',
  'postal_code',
  'available_points',
  'total_points',
  'used_points',
  'total_spent',
  'total_orders',
];

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

describe('OPTIONS /api/miniapp/member', () => {
  it('answers 204 with CORS headers', async () => {
    const response = await OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
  });
});

describe('GET action=check', () => {
  it('auto-register branch: brand-new LINE user -> INSERT users + welcome bonus written to points_history (NOT points_transactions)', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SHOW COLUMNS FROM users')) return columnsResult(FULL_USER_COLUMNS);
      if (sqlText.includes('SELECT id, member_id, is_registered, first_name, last_name, points, display_name')) return [];
      if (sqlText.includes('SELECT member_id FROM users')) return [];
      if (sqlText.includes('INSERT INTO `users`')) return { insertId: 501, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO points_history')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('FROM tier_settings')) return [];
      if (sqlText.includes('FROM member_tiers')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/member?action=check&line_user_id=Unew123&line_account_id=1');
    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toMatchObject({
      success: true,
      exists: true,
      is_registered: true,
      has_profile: false,
      points: 50,
      auto_registered: true,
      tier: 'bronze',
    });

    const welcomeBonusInsert = queries.find((q) => q.sql.includes('INSERT INTO points_history'));
    expect(welcomeBonusInsert).toBeDefined();
    expect(queries.some((q) => q.sql.includes('points_transactions'))).toBe(false);
  });

  it('auto-upgrade branch: existing-but-unregistered user -> UPDATE + re-SELECT, points COALESCE(points,0)+50', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id, member_id, is_registered, first_name, last_name, points, display_name')) {
        if (sqlText.includes('AND line_account_id')) {
          return [{ id: 77, member_id: null, is_registered: 0, first_name: null, last_name: null, points: 30, display_name: 'Guest' }];
        }
      }
      if (sqlText.includes('SHOW COLUMNS FROM users')) return columnsResult(FULL_USER_COLUMNS);
      if (sqlText.includes('SELECT member_id FROM users')) return [];
      if (sqlText.includes('UPDATE users SET')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO points_history')) return { insertId: 2, affectedRows: 1 };
      if (sqlText.includes('SELECT id, member_id, is_registered, first_name, last_name, display_name, points FROM users')) {
        return [{ id: 77, member_id: 'M2600009', is_registered: 1, first_name: null, last_name: null, display_name: 'Guest', points: 80 }];
      }
      if (sqlText.includes('FROM tier_settings')) return [];
      if (sqlText.includes('FROM member_tiers')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/member?action=check&line_user_id=Uexisting&line_account_id=1');
    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toMatchObject({ success: true, is_registered: true, points: 80, auto_registered: true });
    expect(queries.some((q) => q.sql.includes('COALESCE(points, 0) + 50'))).toBe(true);
  });

  it('missing line_user_id -> flat failure, no auto-register attempted', async () => {
    const { queries } = setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/member?action=check');
    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'Missing line_user_id' });
    expect(queries.some((q) => q.sql.includes('INSERT INTO `users`'))).toBe(false);
  });
});

describe('GET action=get_card', () => {
  it('returns member + tier + shop for a registered user', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT * FROM users WHERE line_user_id')) {
        return [
          {
            id: 42,
            member_id: 'M2600001',
            is_registered: 1,
            first_name: 'สมชาย',
            last_name: 'ใจดี',
            display_name: 'สมชาย ใจดี',
            picture_url: null,
            phone: '0812345678',
            email: null,
            // Real mysql2 Date objects (no `dateStrings: true` configured — see sqlDate()'s doc comment),
            // not literal strings: proves handleGetCard's asDateString()/asDateTimeString() formatting
            // actually runs, not just its string passthrough branch.
            birthday: sqlDate('1990-05-12'),
            gender: 'male',
            address: null,
            district: null,
            province: null,
            postal_code: null,
            weight: null,
            height: null,
            medical_conditions: null,
            drug_allergies: null,
            total_spent: '1250.50',
            total_orders: 3,
            registered_at: sqlDate('2026-01-15 10:22:03'),
          },
        ];
      }
      if (sqlText.includes('FROM points_transactions')) return [{ total_points: 0, available_points: 0, used_points: 0 }];
      if (sqlText.includes('SELECT total_points, available_points, used_points, points FROM users')) {
        return [{ total_points: 120, available_points: 120, used_points: 0, points: 120 }];
      }
      if (sqlText.includes('FROM tier_settings')) return [];
      if (sqlText.includes('FROM member_tiers')) return [];
      if (sqlText.includes("SHOW COLUMNS FROM shop_settings LIKE 'logo_url'")) return [];
      if (sqlText.includes("SELECT shop_name, '' as logo_url")) return [{ shop_name: 'ร้านยาคลินิกยา', logo_url: '' }];
      if (sqlText.includes('SELECT name FROM line_accounts')) return [{ name: 'คลินิกยา OA' }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/member?action=get_card&line_user_id=U1&line_account_id=1');
    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.member).toMatchObject({ id: 42, member_id: 'M2600001', points: 120, total_spent: 1250.5, total_orders: 3 });
    expect(body.tier.tier_code).toBe('bronze');
    expect(body.shop).toEqual({ name: 'ร้านยาคลินิกยา', logo: '' });
    // Contract-drift regression: a mysql2 `Date` object serialized via bare JSON.stringify would produce
    // a `Z`-suffixed ISO string here (e.g. "1990-05-11T17:00:00.000Z" for Asia/Bangkok local midnight),
    // NOT PHP PDO's raw "YYYY-MM-DD"/"YYYY-MM-DD HH:MM:SS" string — assert the exact raw form.
    expect(body.member.birthday).toBe('1990-05-12');
    expect(body.member.registered_at).toBe('2026-01-15 10:22:03');
  });
});

describe('POST action=register', () => {
  it('success: new user, welcome bonus written to points_history', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SHOW COLUMNS FROM users')) return columnsResult(FULL_USER_COLUMNS);
      if (sqlText.includes('SELECT id, member_id, is_registered, line_account_id FROM users')) return [];
      if (sqlText.includes('SELECT member_id FROM users')) return [];
      if (sqlText.includes('INSERT INTO `users`')) return { insertId: 900, affectedRows: 1 };
      if (sqlText.includes('UPDATE users SET points')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO points_history')) return { insertId: 3, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/member', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        action: 'register',
        line_user_id: 'Unewreg',
        line_account_id: 1,
        first_name: 'วิภา',
        last_name: 'สุขใจ',
        birthday: '1995-08-20',
        gender: 'female',
      }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toMatchObject({ success: true, message: 'สมัครสมาชิกสำเร็จ!', welcome_bonus: 50, tier: 'bronze' });
    expect(typeof body.member_id).toBe('string');
    expect(queries.some((q) => q.sql.includes('INSERT INTO points_history'))).toBe(true);
  });

  it('validation: missing first_name -> flat failure, no DB writes', async () => {
    const { queries } = setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/member', {
      method: 'POST',
      body: JSON.stringify({ action: 'register', line_user_id: 'U1', birthday: '2000-01-01', gender: 'male' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'กรุณากรอกชื่อ' });
    expect(queries).toHaveLength(0);
  });

  it('already a member -> short-circuits with existing member_id, no re-registration', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id, member_id, is_registered, line_account_id FROM users')) {
        return [{ id: 42, member_id: 'M2600001', is_registered: 1, line_account_id: 1 }];
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/member', {
      method: 'POST',
      body: JSON.stringify({ action: 'register', line_user_id: 'U1', first_name: 'สมชาย', birthday: '1990-01-01', gender: 'male' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'คุณเป็นสมาชิกอยู่แล้ว', member_id: 'M2600001' });
  });
});

describe('POST action=update_profile', () => {
  it('success', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('UPDATE users SET')) return { insertId: 0, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/member', {
      method: 'POST',
      body: JSON.stringify({ action: 'update_profile', line_user_id: 'U1', phone: '0899999999' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, message: 'อัพเดทข้อมูลสำเร็จ' });
    expect(queries.some((q) => q.sql.includes('UPDATE users SET'))).toBe(true);
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/member?action=check&line_user_id=U1') as unknown as import('next/server').NextRequest;

    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
