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

function req(url: string): NextRequest {
  return { nextUrl: new URL(url, 'http://localhost') } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 42,
    tenantId: 1,
    currentBotId: 7,
    role: 'admin',
    username: 'admin',
    displayName: 'Admin',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
    ...overrides,
  };
}

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown, sessionOverrides: Partial<TenantSession> = {}): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const METRIC_TYPES = ['page_load', 'conversation_switch', 'message_render', 'api_call', 'scroll_performance'];

// Per-metric-type fixture data. `message_render`'s stats query is made to throw
// (its own independent try/catch degrades it), while its error-rate query still
// succeeds independently — proving the two catches are genuinely separate.
const FIXTURES: Record<string, { count: number; average: number | null; min: number | null; max: number | null; durations: number[]; total: number; errors: number } | 'FAIL_STATS'> = {
  page_load: { count: 10, average: 1500, min: 100, max: 3000, durations: [100, 200, 300, 400, 500, 600, 700, 800, 900, 3000], total: 10, errors: 1 },
  conversation_switch: { count: 0, average: null, min: null, max: null, durations: [], total: 0, errors: 0 },
  message_render: 'FAIL_STATS',
  api_call: { count: 3, average: 250, min: 100, max: 500, durations: [100, 300, 500], total: 3, errors: 0 },
  scroll_performance: { count: 2, average: 20, min: 10, max: 30, durations: [10, 30], total: 2, errors: 1 },
};
// message_render's error-rate query (independent of its failed stats query) still succeeds:
const MESSAGE_RENDER_ERROR_RATE = { total: 5, errors: 2 };

function findMetricType(params: unknown[]): string | undefined {
  return params.find((p): p is string => typeof p === 'string' && METRIC_TYPES.includes(p));
}

function defaultQueryImpl(sqlText: string, params: unknown[]): unknown {
  const metricType = findMetricType(params);
  if (!metricType) return [];
  const fixture = FIXTURES[metricType]!;

  if (sqlText.includes('ORDER BY duration_ms ASC')) {
    // Percentile query.
    if (fixture === 'FAIL_STATS') return [];
    return fixture.durations.map((d) => ({ duration_ms: d }));
  }
  if (sqlText.includes('SUM(CASE WHEN')) {
    // Error-rate query — independent of the stats query's own outcome.
    if (metricType === 'message_render') {
      return [{ total: MESSAGE_RENDER_ERROR_RATE.total, errors: MESSAGE_RENDER_ERROR_RATE.errors }];
    }
    if (fixture === 'FAIL_STATS') return [{ total: 0, errors: 0 }];
    return [{ total: fixture.total, errors: fixture.errors }];
  }
  // Stats query (COUNT/AVG/MIN/MAX).
  if (fixture === 'FAIL_STATS') {
    throw new Error('stats query failed');
  }
  return [{ count: fixture.count, average: fixture.average, min: fixture.min, max: fixture.max }];
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/get-performance-metrics', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(req('/api/inbox/actions/get-performance-metrics'));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('POST is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });

  it('happy path: exactly 5 metric-type keys, correct count/average/min/max/p50/p95/p99', async () => {
    wireFakeDb(defaultQueryImpl);
    const res = await GET(req('/api/inbox/actions/get-performance-metrics'));
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(Object.keys(body.data).sort()).toEqual(METRIC_TYPES.slice().sort());

    expect(body.data.page_load).toEqual({ count: 10, average: 1500, min: 100, max: 3000, p50: 500, p95: 3000, p99: 3000, error_rate: 10 });
    expect(body.data.api_call).toEqual({ count: 3, average: 250, min: 100, max: 500, p50: 300, p95: 500, p99: 500, error_rate: 0 });
  });

  it('LOAD-BEARING: scroll_performance has NO error_rate key at all (excluded from the 4-entry error-rate threshold map), unlike the other 4 types which all have one', async () => {
    wireFakeDb(defaultQueryImpl);
    const res = await GET(req('/api/inbox/actions/get-performance-metrics'));
    const body = await res.json();

    expect('error_rate' in body.data.scroll_performance).toBe(false);
    expect(Object.keys(body.data.scroll_performance).sort()).toEqual(['average', 'count', 'max', 'min', 'p50', 'p95', 'p99'].sort());
    expect(body.data.scroll_performance).toEqual({ count: 2, average: 20, min: 10, max: 30, p50: 10, p95: 30, p99: 30 });

    for (const type of ['page_load', 'conversation_switch', 'message_render', 'api_call']) {
      expect('error_rate' in body.data[type]).toBe(true);
    }
  });

  it('count===0 (query succeeded, zero matching rows): average/min/max are null (NOT 0), p50/p95/p99 are 0', async () => {
    wireFakeDb(defaultQueryImpl);
    const res = await GET(req('/api/inbox/actions/get-performance-metrics'));
    const body = await res.json();
    expect(body.data.conversation_switch).toEqual({ count: 0, average: null, min: null, max: null, p50: 0, p95: 0, p99: 0, error_rate: 0 });
  });

  it('a stats-query FAILURE degrades to the literal-0s DEGRADED_STATS shape (average/min/max = 0, NOT null) — a DIFFERENT shape than the "0 matching rows" case — while that type\'s error_rate query still succeeds independently', async () => {
    wireFakeDb(defaultQueryImpl);
    const res = await GET(req('/api/inbox/actions/get-performance-metrics'));
    const body = await res.json();
    expect(body.data.message_render).toEqual({ count: 0, average: 0, min: 0, max: 0, p50: 0, p95: 0, p99: 0, error_rate: 40 });
  });

  it('NO 503 CODE PATH EXISTS for this action: PerformanceMetricsService is instantiated directly in PHP, no loadService() guard — every internal query failure degrades silently, never producing a 503', async () => {
    wireFakeDb(() => {
      throw new Error('connection refused');
    });
    const res = await GET(req('/api/inbox/actions/get-performance-metrics'));
    expect(res.status).not.toBe(503);
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    // Every type degrades to its own catch-block shape; the whole response still succeeds.
    expect(body.data.page_load).toEqual({ count: 0, average: 0, min: 0, max: 0, p50: 0, p95: 0, p99: 0, error_rate: 0 });
  });

  it('start_date/end_date ABSENT from query -> stay null, no defaulting (unlike ../analytics), date-range WHERE clauses omitted from the SQL text', async () => {
    const queries = wireFakeDb(defaultQueryImpl);
    await GET(req('/api/inbox/actions/get-performance-metrics'));
    for (const q of queries) {
      expect(q.sql.toLowerCase()).not.toContain('date(created_at)');
    }
  });

  it('start_date/end_date PRESENT -> passed through verbatim into DATE(created_at) >=/<= clauses', async () => {
    const queries = wireFakeDb(defaultQueryImpl);
    await GET(req('/api/inbox/actions/get-performance-metrics?start_date=2026-01-01&end_date=2026-01-31'));
    const statsQuery = queries.find((q) => q.sql.includes('AVG(duration_ms)'))!;
    expect(statsQuery.sql.toLowerCase()).toContain('date(created_at) >=');
    expect(statsQuery.sql.toLowerCase()).toContain('date(created_at) <=');
    expect(statsQuery.params).toEqual(expect.arrayContaining(['2026-01-01', '2026-01-31']));
  });

  it('getErrorRate: total=0 -> error_rate is exactly 0 (not a division-by-zero NaN)', async () => {
    wireFakeDb(defaultQueryImpl);
    const res = await GET(req('/api/inbox/actions/get-performance-metrics'));
    const body = await res.json();
    expect(body.data.conversation_switch.error_rate).toBe(0);
    expect(Number.isNaN(body.data.conversation_switch.error_rate)).toBe(false);
  });

  it('genuinely thrown/unexpected error (not a per-query degrade) -> 400 {success:false, error:"Failed to get performance metrics: ..."}', async () => {
    const { db } = makeFakeTenantDb(defaultQueryImpl);
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const throwingSearchParams = {
      get: () => {
        throw new Error('boom');
      },
    };
    const throwingRequest = { nextUrl: { searchParams: throwingSearchParams } } as unknown as NextRequest;

    const res = await GET(throwingRequest);
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to get performance metrics: boom' });
  });
});
