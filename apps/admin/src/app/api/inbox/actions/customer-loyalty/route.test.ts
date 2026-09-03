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
  const url = `https://tenant.re-ya.com/api/inbox/actions/customer-loyalty${search}`;
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

interface Fixtures {
  userPoints?: unknown[];
  memberTiersThrows?: boolean;
  memberTiers?: unknown[];
  pointsTiersThrows?: boolean;
  pointsTiers?: unknown[];
  txStats?: unknown[];
  ordersStats?: unknown[];
  avgDiscount?: unknown[];
}

function wireFakeDb(fixtures: Fixtures = {}, sessionOverrides: Partial<TenantSession> = {}): RecordedQuery[] {
  const queryImpl = (sqlText: string) => {
    const s = sqlText.trim();
    if (s.includes('total_points, available_points, points FROM users')) return fixtures.userPoints ?? [];
    if (s.includes('FROM member_tiers')) {
      if (fixtures.memberTiersThrows) throw new Error('Unknown column');
      return fixtures.memberTiers ?? [];
    }
    if (s.includes('FROM points_tiers')) {
      if (fixtures.pointsTiersThrows) throw new Error('Unknown column');
      return fixtures.pointsTiers ?? [];
    }
    if (s.includes('FROM transactions') && s.includes('order_count')) return fixtures.txStats ?? [];
    if (s.includes('FROM orders') && s.includes('order_count')) return fixtures.ordersStats ?? [];
    if (s.includes('avg_discount')) return fixtures.avgDiscount ?? [];
    return [];
  };
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/customer-loyalty', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(req('?user_id=42'));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Invalid user ID" when user_id is missing, no DB queries issued', async () => {
    const queries = wireFakeDb();
    const res = await GET(req());
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Invalid user ID" when user_id <= 0', async () => {
    wireFakeDb();
    const res = await GET(req('?user_id=0'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('happy path: success:true always (no `found` flag to derive it from), full data assembled, FIXED member_tiers SQL text proven (tier_name/color present, badge_color absent)', async () => {
    const queries = wireFakeDb({
      userPoints: [{ total_points: 2000, available_points: 2000, points: 2000 }],
      memberTiers: [
        { tier_name: 'Starter', min_points: 0, color: '#111111' },
        { tier_name: 'Champion', min_points: 1500, color: '#222222' },
      ],
      txStats: [{ order_count: 4, total_spent: '2000.00', avg_order: '500.00', last_purchase: new Date(2026, 6, 1) }],
      avgDiscount: [{ avg_discount: '50.00' }],
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data).toEqual({
      userId: 42,
      loyaltyTier: 'Champion',
      tierColor: '#222222',
      totalPoints: 2000,
      pointsToNextTier: 0, // no next tier after Champion
      nextTierName: null,
      avgDiscount: 50,
      avgDiscountPercent: 10, // 50 / 500 * 100
      totalPurchases: 2000,
      orderCount: 4,
      avgOrderValue: 500,
      lastPurchaseDate: new Date(2026, 6, 1).toISOString(),
      discountExpectation: {
        min: 0,
        max: 5,
        typical: 0,
        historical: 50,
        recommendation: 'ลูกค้าเคยได้รับส่วนลดเฉลี่ย ฿50.00',
      },
    });

    // FIX (1) evidence: the member_tiers query uses tier_name/color, never badge_color.
    const memberTiersQuery = queries.find((q) => q.sql.includes('FROM member_tiers'));
    expect(memberTiersQuery!.sql).toContain('tier_name');
    expect(memberTiersQuery!.sql).toContain('color');
    expect(memberTiersQuery!.sql).not.toContain('badge_color');
    expect(memberTiersQuery!.sql).not.toMatch(/select\s+name,\s*tier_name/i);

    // FIX (2) evidence: no query anywhere references a bare `discount` column.
    expect(queries.some((q) => /\bdiscount\b(?!_amount)/i.test(q.sql.replace(/discount_amount/g, '')))).toBe(false);
  });

  it('member_tiers throws (schema drift on an un-fixed hypothetical tenant, or a genuinely empty custom-tier config elsewhere) -> falls back to points_tiers', async () => {
    const queries = wireFakeDb({
      userPoints: [{ total_points: 100, available_points: 100, points: 100 }],
      memberTiersThrows: true,
      pointsTiers: [
        { name: 'Basic', min_points: 0, color: '#333333' },
        { name: 'Plus', min_points: 500, color: '#444444' },
      ],
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.loyaltyTier).toBe('Basic');
    expect(body.data.tierColor).toBe('#333333');
    expect(body.data.nextTierName).toBe('Plus');
    expect(body.data.pointsToNextTier).toBe(400); // 500 - 100

    expect(queries.some((q) => q.sql.includes('FROM member_tiers'))).toBe(true);
    expect(queries.some((q) => q.sql.includes('FROM points_tiers'))).toBe(true);
  });

  it('both member_tiers AND points_tiers throw -> falls back to the hardcoded default 4-tier ladder', async () => {
    wireFakeDb({
      userPoints: [{ total_points: 100, available_points: 100, points: 100 }],
      memberTiersThrows: true,
      pointsTiersThrows: true,
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.loyaltyTier).toBe('Bronze');
    expect(body.data.tierColor).toBe('#CD7F32');
    expect(body.data.nextTierName).toBe('Silver');
    expect(body.data.pointsToNextTier).toBe(900); // 1000 - 100
  });

  it('getAverageDiscount: avg_discount is 0 -> returns 0.0 directly, no second query referencing a bare `discount` column is ever issued', async () => {
    const queries = wireFakeDb({
      userPoints: [{ total_points: 0, available_points: 0, points: 0 }],
      avgDiscount: [{ avg_discount: 0 }],
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.avgDiscount).toBe(0);
    expect(body.data.discountExpectation).toEqual({
      min: 0,
      max: 5,
      typical: 0,
      historical: 0,
      recommendation: 'ลูกค้าใหม่ แนะนำส่วนลด 0%',
    });

    // Exactly ONE query touches discount_amount/avg_discount — no fallback query issued.
    const discountQueries = queries.filter((q) => q.sql.includes('avg_discount'));
    expect(discountQueries).toHaveLength(1);
    expect(discountQueries[0]!.sql).toContain('discount_amount');
    expect(discountQueries[0]!.sql).not.toMatch(/\bdiscount\b(?!_amount)/i);
  });

  it('getAverageDiscount: the primary query itself throwing -> caught, returns 0.0', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText: string) => {
      const s = sqlText.trim();
      if (s.includes('total_points, available_points, points FROM users')) return [{ total_points: 0, available_points: 0, points: 0 }];
      if (s.includes('avg_discount')) throw new Error('connection reset');
      return [];
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.avgDiscount).toBe(0);
    expect(queries.filter((q) => q.sql.includes('avg_discount'))).toHaveLength(1);
  });

  describe('tier-boundary math', () => {
    it('points exactly at a custom tier min_points boundary qualifies for that tier (>=, not >)', async () => {
      wireFakeDb({
        userPoints: [{ total_points: 500, available_points: 500, points: 500 }],
        memberTiers: [
          { tier_name: 'Basic', min_points: 0, color: '#111111' },
          { tier_name: 'Elite', min_points: 500, color: '#222222' },
        ],
      });
      const res = await GET(req('?user_id=42'));
      const body = await res.json();
      expect(body.data.loyaltyTier).toBe('Elite');
      expect(body.data.pointsToNextTier).toBe(0);
      expect(body.data.nextTierName).toBeNull();
    });

    it('1 point below a custom tier boundary does not qualify; pointsToNextTier is the exact gap', async () => {
      wireFakeDb({
        userPoints: [{ total_points: 499, available_points: 499, points: 499 }],
        memberTiers: [
          { tier_name: 'Basic', min_points: 0, color: '#111111' },
          { tier_name: 'Elite', min_points: 500, color: '#222222' },
        ],
      });
      const res = await GET(req('?user_id=42'));
      const body = await res.json();
      expect(body.data.loyaltyTier).toBe('Basic');
      expect(body.data.nextTierName).toBe('Elite');
      expect(body.data.pointsToNextTier).toBe(1);
    });

    it('a tier name with no TIER_EXPECTATIONS entry falls back to the Bronze expectation entry', async () => {
      wireFakeDb({
        userPoints: [{ total_points: 100, available_points: 100, points: 100 }],
        memberTiers: [{ tier_name: 'CustomSpecialTier', min_points: 0, color: '#abcabc' }],
      });
      const res = await GET(req('?user_id=42'));
      const body = await res.json();
      expect(body.data.loyaltyTier).toBe('CustomSpecialTier');
      expect(body.data.discountExpectation).toMatchObject({ min: 0, max: 5, typical: 0 });
    });
  });

  it('DB failure (SELECT total_points... itself throwing outside all inner try/catches — simulated via userPoints query never reached, tier lookups all rejecting) still resolves to a 200 via internal fallbacks, not a 500, because every _lib query has its own try/catch', async () => {
    // getUserTierInfo's own users-points SELECT has its own local try/catch,
    // so even a hard DB failure there degrades to points=0, not a 500.
    const { db } = makeFakeTenantDb(() => {
      throw new Error('connection lost');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();
    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.loyaltyTier).toBe('Bronze');
    expect(body.data.totalPoints).toBe(0);
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
