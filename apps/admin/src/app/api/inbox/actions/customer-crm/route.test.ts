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

function getReq(search = ''): NextRequest {
  const url = `https://tenant.re-ya.com/api/inbox/actions/customer-crm${search}`;
  return { nextUrl: new URL(url), json: async () => ({}) } as unknown as NextRequest;
}

function postReq(body: unknown): NextRequest {
  return {
    nextUrl: new URL('https://tenant.re-ya.com/api/inbox/actions/customer-crm'),
    json: async () => body,
  } as unknown as NextRequest;
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

const USER_ROW = { id: 42, display_name: 'คุณสมชาย', line_user_id: 'U123' };

interface Fixtures {
  user?: unknown[] | (() => unknown[]);
  pointsAgg?: unknown[] | (() => unknown[]);
  usersPoints?: unknown[] | (() => unknown[]);
  txStats?: unknown[] | (() => unknown[]);
  messageCount?: unknown[] | (() => unknown[]);
  tags?: unknown[] | (() => unknown[]);
  allTags?: unknown[] | (() => unknown[]);
  notes?: unknown[] | (() => unknown[]);
  transactions?: unknown[] | (() => unknown[]);
}

function resolveFixture(v: unknown[] | (() => unknown[]) | undefined, fallback: unknown[]): unknown[] {
  if (v === undefined) return fallback;
  return typeof v === 'function' ? v() : v;
}

/** Discriminates every SQL text this route's dependency chain can issue, most-specific-first. */
function wireFakeDb(
  fixtures: Fixtures = {},
  sessionOverrides: Partial<TenantSession> = {}
): { queries: RecordedQuery[] } {
  const queryImpl = (sqlText: string) => {
    const s = sqlText.trim();
    if (s.startsWith('SELECT * FROM users')) return resolveFixture(fixtures.user, [USER_ROW]);
    if (s.includes('FROM points_transactions')) return resolveFixture(fixtures.pointsAgg, []);
    if (s.includes('available_points, used_points, points FROM users')) {
      return resolveFixture(fixtures.usersPoints, []);
    }
    if (s.includes('as cnt') && s.includes('FROM transactions')) return resolveFixture(fixtures.txStats, []);
    if (s.includes('COUNT(*) as cnt FROM messages')) return resolveFixture(fixtures.messageCount, []);
    if (s.includes('JOIN user_tag_assignments')) return resolveFixture(fixtures.tags, []);
    if (s.includes('FROM user_tags WHERE line_account_id')) return resolveFixture(fixtures.allTags, []);
    if (s.includes('FROM user_notes')) return resolveFixture(fixtures.notes, []);
    if (s.includes('grand_total, status, created_at')) return resolveFixture(fixtures.transactions, []);
    return [];
  };
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return { queries };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET+POST /api/inbox/actions/customer-crm', () => {
  it('401 JSON when unauthenticated (GET), DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(getReq('?user_id=42'));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('401 JSON when unauthenticated (POST), DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(postReq({ user_id: 42 }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "User ID is required" on GET with no user_id, no DB queries issued', async () => {
    const { queries } = wireFakeDb();
    const res = await GET(getReq());
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('400 "User ID is required" on POST with no user_id, no DB queries issued', async () => {
    const { queries } = wireFakeDb();
    const res = await POST(postReq({}));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('400 for user_id=0 (falsy, matches PHP `!$userId`)', async () => {
    wireFakeDb();
    const res = await GET(getReq('?user_id=0'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
  });

  it('404 "User not found" short-circuits BEFORE any of the 6 best-effort blocks run any query (only the `SELECT * FROM users` query is issued)', async () => {
    const { queries } = wireFakeDb({ user: [] });
    const res = await GET(getReq('?user_id=999'));
    expect(res.status).toBe(404);
    expect(await res.json()).toEqual({ success: false, error: 'User not found' });
    expect(queries).toHaveLength(1);
    expect(queries[0]!.sql.trim()).toMatch(/^SELECT \* FROM users/);
  });

  it('happy path via GET query string: full envelope assembled from all 6 blocks + user', async () => {
    const { queries } = wireFakeDb({
      pointsAgg: [{ total_points: 500, available_points: 500, used_points: 0 }],
      txStats: [{ cnt: 3, total: '1500.00' }],
      messageCount: [{ cnt: 12 }],
      tags: [{ id: 1, name: 'VIP', color: '#ff0000' }],
      allTags: [{ id: 1, name: 'VIP', color: '#ff0000' }, { id: 2, name: 'New', color: '#00ff00' }],
      notes: [{ id: 5, user_id: 42, content: 'โน้ตทดสอบ', created_by: 7, created_at: new Date(2026, 6, 1) }],
      transactions: [{ id: 900, grand_total: '250.00', status: 'completed', created_at: new Date(2026, 6, 2) }],
    });

    const res = await GET(getReq('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        user: USER_ROW,
        points: { total_points: 500, available_points: 500, used_points: 0 },
        tier: { name: 'Member', icon: '🥉', color: '#9CA3AF' }, // 500 < 1000
        stats: { order_count: 3, total_spent: 1500, message_count: 12 },
        tags: [{ id: 1, name: 'VIP', color: '#ff0000' }],
        all_tags: [{ id: 1, name: 'VIP', color: '#ff0000' }, { id: 2, name: 'New', color: '#00ff00' }],
        notes: [{ id: 5, user_id: 42, content: 'โน้ตทดสอบ', created_by: 7, created_at: '2026-07-01T00:00:00.000Z' }],
        transactions: [{ id: 900, grand_total: '250.00', status: 'completed', created_at: '2026-07-02T00:00:00.000Z' }],
      },
    });

    // all_tags is the ONLY block bound to line_account_id (session.currentBotId ?? 1 = 3).
    const allTagsQuery = queries.find((q) => q.sql.includes('FROM user_tags WHERE line_account_id'));
    expect(allTagsQuery!.params).toEqual([3]);

    // stats/tags/notes/transactions are NOT line_account_id-scoped.
    const statsQuery = queries.find((q) => q.sql.includes('as cnt') && q.sql.includes('FROM transactions'));
    expect(statsQuery!.params).toEqual([42]);
  });

  it('happy path via POST JSON body reaches the same handler as GET', async () => {
    wireFakeDb({
      pointsAgg: [{ total_points: 0, available_points: 0, used_points: 0 }],
    });

    const res = await POST(postReq({ user_id: 42 }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.user).toEqual(USER_ROW);
    expect(body.data.tier).toEqual({ name: 'Member', icon: '🥉', color: '#9CA3AF' });
  });

  describe('tier thresholds (PHP lines 1927-1935, exact boundaries)', () => {
    it.each([
      [999, 'Member', '🥉', '#9CA3AF'],
      [1000, 'Silver', '🥈', '#6B7280'],
      [4999, 'Silver', '🥈', '#6B7280'],
      [5000, 'Gold', '🥇', '#F59E0B'],
      [9999, 'Gold', '🥇', '#F59E0B'],
      [10000, 'Platinum', '💎', '#6366F1'],
    ])('total_points=%i -> tier %s', async (totalPoints, name, icon, color) => {
      wireFakeDb({
        pointsAgg: [{ total_points: totalPoints, available_points: totalPoints, used_points: 0 }],
      });
      const res = await GET(getReq('?user_id=42'));
      const body = await res.json();
      expect(body.data.tier).toEqual({ name, icon, color });
    });
  });

  describe('each of the 6 best-effort blocks independently degrades to its default on throw, without failing the whole response', () => {
    it('(a) points+tier: points_transactions throws -> default points + Member tier', async () => {
      const { db } = makeFakeTenantDb((sqlText: string) => {
        if (sqlText.trim().startsWith('SELECT * FROM users')) return [USER_ROW];
        if (sqlText.includes('FROM points_transactions')) throw new Error('boom');
        return [];
      });
      mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

      const res = await GET(getReq('?user_id=42'));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body.success).toBe(true);
      expect(body.data.points).toEqual({ available_points: 0, total_points: 0, used_points: 0 });
      expect(body.data.tier).toEqual({ name: 'Member', icon: '🥉', color: '#9CA3AF' });
      // The rest of the response is still fully assembled.
      expect(body.data.stats).toEqual({ order_count: 0, total_spent: 0, message_count: 0 });
    });

    it('(b) stats: transactions COUNT/SUM throws -> default stats, rest unaffected', async () => {
      const { db } = makeFakeTenantDb((sqlText: string) => {
        if (sqlText.trim().startsWith('SELECT * FROM users')) return [USER_ROW];
        if (sqlText.includes('as cnt') && sqlText.includes('FROM transactions')) throw new Error('boom');
        if (sqlText.includes('FROM points_transactions')) return [{ total_points: 0, available_points: 0, used_points: 0 }];
        return [];
      });
      mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

      const res = await GET(getReq('?user_id=42'));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body.data.stats).toEqual({ order_count: 0, total_spent: 0, message_count: 0 });
      expect(body.data.points).toEqual({ total_points: 0, available_points: 0, used_points: 0 });
    });

    it('(c) tags: user_tags JOIN throws -> default []', async () => {
      const { db } = makeFakeTenantDb((sqlText: string) => {
        if (sqlText.trim().startsWith('SELECT * FROM users')) return [USER_ROW];
        if (sqlText.includes('JOIN user_tag_assignments')) throw new Error('boom');
        return [];
      });
      mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

      const res = await GET(getReq('?user_id=42'));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body.data.tags).toEqual([]);
    });

    it('(d) all_tags: user_tags selector query throws -> default []', async () => {
      const { db } = makeFakeTenantDb((sqlText: string) => {
        if (sqlText.trim().startsWith('SELECT * FROM users')) return [USER_ROW];
        if (sqlText.includes('FROM user_tags WHERE line_account_id')) throw new Error('boom');
        return [];
      });
      mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

      const res = await GET(getReq('?user_id=42'));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body.data.all_tags).toEqual([]);
    });

    it('(e) notes: user_notes throws -> default []', async () => {
      const { db } = makeFakeTenantDb((sqlText: string) => {
        if (sqlText.trim().startsWith('SELECT * FROM users')) return [USER_ROW];
        if (sqlText.includes('FROM user_notes')) throw new Error('boom');
        return [];
      });
      mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

      const res = await GET(getReq('?user_id=42'));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body.data.notes).toEqual([]);
    });

    it('(f) transactions: recent-transactions query throws -> default []', async () => {
      const { db } = makeFakeTenantDb((sqlText: string) => {
        if (sqlText.trim().startsWith('SELECT * FROM users')) return [USER_ROW];
        if (sqlText.includes('grand_total, status, created_at')) throw new Error('boom');
        return [];
      });
      mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

      const res = await GET(getReq('?user_id=42'));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body.data.transactions).toEqual([]);
    });
  });

  it('the outer generic catch-all: `SELECT * FROM users` itself throwing -> 400 "Failed to load CRM data: ..."', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('connection lost');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await GET(getReq('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'Failed to load CRM data: connection lost' });
  });
});
