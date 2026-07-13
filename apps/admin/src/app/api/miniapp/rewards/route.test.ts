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

const REWARD_ROW = {
  id: 10,
  line_account_id: 1,
  name: 'ส่วนลด 50 บาท',
  description: null,
  image_url: null,
  points_required: 500,
  reward_type: 'discount',
  reward_value: '50',
  stock: -1,
  max_per_user: 0,
  is_active: 1,
  sort_order: 1,
  start_date: null,
  end_date: null,
  terms: null,
  // Real mysql2 Date object (no `dateStrings: true` configured — see sqlDate()'s doc comment), not a
  // literal string: proves normalizeRewardRowDates()'s formatting actually runs on this fixture.
  created_at: sqlDate('2026-01-01 00:00:00'),
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('OPTIONS /api/miniapp/rewards', () => {
  it('answers 204 with CORS headers', async () => {
    const response = await OPTIONS();
    expect(response.status).toBe(204);
  });
});

describe('GET action=list', () => {
  it('returns rewards for the tenant own account when non-empty', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes("SHOW COLUMNS FROM \`rewards\` LIKE 'line_account_id'")) return [{ Field: 'line_account_id' }];
      if (sqlText.includes("SHOW COLUMNS FROM \`rewards\` LIKE 'is_active'")) return [{ Field: 'is_active' }];
      if (sqlText.includes('FROM rewards')) return [REWARD_ROW];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/rewards?action=list&line_account_id=1');
    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.rewards).toHaveLength(1);
    expect(body.rewards[0]).toMatchObject({ id: 10, stock: -1 });
    // Contract-drift regression: a mysql2 `Date` object serialized via bare JSON.stringify would produce
    // a `Z`-suffixed ISO string here, NOT PHP PDO's raw "YYYY-MM-DD HH:MM:SS" string.
    expect(body.rewards[0].created_at).toBe('2026-01-01 00:00:00');
  });

  it('default-account fallback: own account has zero rewards -> falls back to is_default=1 account', async () => {
    let rewardsCallCount = 0;
    setupTenant((sqlText) => {
      if (sqlText.includes('SHOW COLUMNS')) return [{ Field: 'line_account_id' }, { Field: 'is_active' }];
      if (sqlText.includes('FROM rewards')) {
        rewardsCallCount += 1;
        return rewardsCallCount === 1 ? [] : [REWARD_ROW];
      }
      if (sqlText.includes('FROM line_accounts')) return [{ id: 1 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/rewards?action=list&line_account_id=7');
    const response = await GET(request);
    const body = await response.json();

    expect(body.success).toBe(true);
    expect(body.rewards).toHaveLength(1);
    expect(rewardsCallCount).toBe(2);
  });

  it('no fallback loop when the default account IS the requested account', async () => {
    let rewardsCallCount = 0;
    setupTenant((sqlText) => {
      if (sqlText.includes('SHOW COLUMNS')) return [{ Field: 'line_account_id' }, { Field: 'is_active' }];
      if (sqlText.includes('FROM rewards')) {
        rewardsCallCount += 1;
        return [];
      }
      if (sqlText.includes('FROM line_accounts')) return [{ id: 1 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/rewards?action=list&line_account_id=1');
    const response = await GET(request);
    const body = await response.json();

    expect(body.rewards).toEqual([]);
    expect(rewardsCallCount).toBe(1);
  });
});

describe('POST action=redeem', () => {
  it('success: deducts points, inserts redemption, returns full shape', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id, display_name FROM users')) return [{ id: 42, display_name: 'สมชาย' }];
      if (sqlText.includes('FROM rewards WHERE id')) return [REWARD_ROW];
      if (sqlText.includes('FROM points_transactions')) return [{ total_points: 1120, available_points: 1120, used_points: 0 }];
      if (sqlText.includes('UPDATE users SET available_points')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('UPDATE users SET member_tier')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO points_transactions')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('SELECT COUNT(*) as count FROM reward_redemptions')) return [{ count: 0 }];
      if (sqlText.includes('INSERT INTO reward_redemptions')) return { insertId: 501, affectedRows: 1 };
      if (sqlText.includes('SELECT id, display_name, picture_url, total_points, available_points, used_points, line_user_id'))
        return [{ id: 42, display_name: 'สมชาย', picture_url: null, total_points: 1120, available_points: 620, used_points: 500, line_user_id: 'U1' }];
      if (sqlText.includes('SELECT points, total_points, available_points FROM users')) return [{ points: 620, total_points: 620, available_points: 620 }];
      if (sqlText.includes('FROM tier_settings')) return [];
      if (sqlText.includes('FROM member_tiers')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/rewards', {
      method: 'POST',
      body: JSON.stringify({ action: 'redeem', line_user_id: 'U1', line_account_id: 1, reward_id: 10 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toMatchObject({ success: true, message: 'แลกรางวัลสำเร็จ!', redemption_id: 501, new_balance: 620 });
    expect(typeof body.redemption_code).toBe('string');
    expect(queries.some((q) => q.sql.includes('INSERT INTO reward_redemptions'))).toBe(true);
    // Contract-drift regression: reward.created_at must be PHP PDO's raw string, not a `Z`-suffixed ISO
    // string from a bare-serialized mysql2 Date.
    expect(body.reward.created_at).toBe('2026-01-01 00:00:00');
    // Contract-drift regression: member.tier must be LoyaltyPoints::getUserTier()'s NARROW shape
    // (packages/contracts/src/rewards.ts's RedeemMemberSchema.tier) — no `tier_name`/`next_tier_code`,
    // which only the WIDER TierService TierInfo (used by member:get_card/check) carries.
    expect(body.member.tier).toEqual({
      name: 'Bronze',
      tier_code: 'bronze',
      color: '#CD7F32',
      icon: '🥉',
      current_points: 620,
      min_points: 0,
      next_tier_name: 'Silver',
      next_tier_points: 1000,
      points_to_next: 380,
      progress_percent: 62,
      discount_percent: 0,
    });
    expect(body.member.tier).not.toHaveProperty('tier_name');
    expect(body.member.tier).not.toHaveProperty('next_tier_code');
  });

  it('insufficient points -> failure, message only, no deduction', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id, display_name FROM users')) return [{ id: 43, display_name: null }];
      if (sqlText.includes('FROM rewards WHERE id')) return [REWARD_ROW];
      if (sqlText.includes('FROM points_transactions')) return [{ total_points: 100, available_points: 100, used_points: 0 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/rewards', {
      method: 'POST',
      body: JSON.stringify({ action: 'redeem', line_user_id: 'Ulow', line_account_id: 1, reward_id: 10 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'แต้มไม่เพียงพอ' });
    expect(queries.some((q) => q.sql.includes('UPDATE users SET available_points'))).toBe(false);
  });

  it('missing line_user_id -> validation failure', async () => {
    setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/rewards', {
      method: 'POST',
      body: JSON.stringify({ action: 'redeem', reward_id: 10 }),
    });
    const response = await POST(request);
    const body = await response.json();
    expect(body).toEqual({ success: false, message: 'กรุณาเข้าสู่ระบบ' });
  });

  it('user not found -> failure', async () => {
    setupTenant((sqlText) => (sqlText.includes('SELECT id, display_name FROM users') ? [] : []));
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/rewards', {
      method: 'POST',
      body: JSON.stringify({ action: 'redeem', line_user_id: 'Ughost', reward_id: 10 }),
    });
    const response = await POST(request);
    const body = await response.json();
    expect(body).toEqual({ success: false, message: 'ไม่พบข้อมูลผู้ใช้' });
  });
});

describe('GET action=my_redemptions', () => {
  it('returns the redemption list', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('FROM reward_redemptions')) {
        return [
          {
            id: 501,
            user_id: 42,
            reward_id: 10,
            line_account_id: 1,
            points_used: 500,
            redemption_code: 'RW9K3XA1B2C3',
            status: 'pending',
            expires_at: null,
            // Real mysql2 Date object (no `dateStrings: true` configured — see sqlDate()'s doc comment),
            // not a literal string: proves getUserRedemptions()'s date formatting actually runs.
            created_at: sqlDate('2026-07-10 09:12:00'),
            approved_at: null,
            approved_by: null,
            delivered_at: null,
            notes: null,
            reward_name: 'ส่วนลด 50 บาท',
            reward_image: null,
          },
        ];
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/rewards?action=my_redemptions&line_user_id=U1&limit=20');
    const response = await GET(request);
    const body = await response.json();

    expect(body.success).toBe(true);
    expect(body.redemptions).toHaveLength(1);
    // Contract-drift regression: created_at must be PHP PDO's raw string, not a `Z`-suffixed ISO string.
    expect(body.redemptions[0].created_at).toBe('2026-07-10 09:12:00');
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/rewards?action=list') as unknown as import('next/server').NextRequest;

    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
