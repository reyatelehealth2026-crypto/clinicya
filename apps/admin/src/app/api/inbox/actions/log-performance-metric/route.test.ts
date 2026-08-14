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

function req(body: unknown, headers: Record<string, string> = {}): NextRequest {
  return {
    json: async () => {
      if (body === '__MALFORMED__') {
        throw new SyntaxError('Unexpected end of JSON input');
      }
      return body;
    },
    headers: new Headers(headers),
  } as unknown as NextRequest;
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

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ insertId: 1, affectedRows: 1 }),
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

let consoleErrorSpy: jest.SpyInstance;
let consoleWarnSpy: jest.SpyInstance;

beforeEach(() => {
  jest.clearAllMocks();
  consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
  consoleWarnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
});

afterEach(() => {
  consoleErrorSpy.mockRestore();
  consoleWarnSpy.mockRestore();
});

describe('POST /api/inbox/actions/log-performance-metric', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ metric_type: 'page_load', duration_ms: 100 }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });

  it('LOAD-BEARING PHP QUIRK: an empty JSON object {} -> 400 "Invalid JSON input" (PHP json_decode(\'{}\') === [] which is falsy)', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({}));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid JSON input' });
    expect(queries).toHaveLength(0);
  });

  it('malformed JSON body -> 400 "Invalid JSON input"', async () => {
    wireFakeDb();
    const res = await POST(req('__MALFORMED__'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid JSON input' });
  });

  it('null / empty array body -> 400 "Invalid JSON input" (also PHP-falsy)', async () => {
    wireFakeDb();
    for (const body of [null, []]) {
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Invalid JSON input' });
    }
  });

  it('NO 503 CODE PATH EXISTS for this action: PerformanceMetricsService is instantiated directly in PHP, no loadService() guard — a DB failure never produces 503, only a per-metric failure count at 200', async () => {
    wireFakeDb(() => {
      throw new Error('connection refused');
    });
    const res = await POST(req({ metric_type: 'page_load', duration_ms: 100 }));
    expect(res.status).not.toBe(503);
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body).toEqual({ success: true, message: 'Logged 0 metrics, 1 failed', logged: 0, failed: 1 });
  });

  it('single-metric shorthand: body without a "metrics" key is treated as [body] itself', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ metric_type: 'page_load', duration_ms: 250 }));
    const body = await res.json();
    expect(body).toEqual({ success: true, message: 'Logged 1 metrics', logged: 1, failed: 0 });
    expect(queries).toHaveLength(1);
    expect(queries[0]!.sql.toLowerCase()).toContain('insert into');
    expect(queries[0]!.sql.toLowerCase()).toContain('performance_metrics');
    expect(queries[0]!.params).toEqual([7, 'page_load', 250, null, null]);
  });

  it('batch via "metrics" array: aggregates successCount/failCount, exact message string (with failed suffix)', async () => {
    const queries = wireFakeDb();
    const res = await POST(
      req({
        metrics: [
          { metric_type: 'page_load', duration_ms: 100 },
          { metric_type: 'not_a_real_type', duration_ms: 50 }, // fails logMetric()'s own whitelist check
          { metric_type: 'api_call', duration_ms: 300 },
        ],
      })
    );
    const body = await res.json();
    expect(body).toEqual({ success: true, message: 'Logged 2 metrics, 1 failed', logged: 2, failed: 1 });
    expect(queries).toHaveLength(2); // the invalid-type metric never reaches the INSERT
  });

  it('message string omits the ", N failed" suffix entirely when failCount is 0', async () => {
    wireFakeDb();
    const res = await POST(req({ metrics: [{ metric_type: 'page_load', duration_ms: 1 }] }));
    expect((await res.json()).message).toBe('Logged 1 metrics');
  });

  it('LOAD-BEARING: an all-failed batch still returns success:true at HTTP 200, never an error status', async () => {
    wireFakeDb();
    const res = await POST(
      req({
        metrics: [
          { metric_type: null, duration_ms: 100 }, // falsy metricType -> route-level skip
          { metric_type: 'page_load', duration_ms: null }, // durationMs === null -> route-level skip
          { metric_type: 'page_load', duration_ms: -5 }, // logMetric()'s own >= 0 check fails
        ],
      })
    );
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Logged 0 metrics, 3 failed', logged: 0, failed: 3 });
  });

  it('durationMs === 0 is NOT skipped by the route-level `durationMs === null` check (strict null check, not falsy)', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ metric_type: 'page_load', duration_ms: 0 }));
    expect((await res.json()).logged).toBe(1);
    expect(queries[0]!.params[2]).toBe(0);
  });

  it('metricType=0 (falsy but not null) IS skipped by the route-level `!$metricType` truthiness check', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ metric_type: 0, duration_ms: 100 }));
    expect((await res.json()).failed).toBe(1);
    expect(queries).toHaveLength(0);
  });

  it('userAgent: metric.user_agent wins when present; falls back to the request User-Agent header when absent', async () => {
    const queries = wireFakeDb();
    await POST(req({ metric_type: 'page_load', duration_ms: 10, user_agent: 'CustomUA/1.0' }, { 'user-agent': 'Mozilla/5.0' }));
    expect(queries[0]!.params[3]).toBe('CustomUA/1.0');

    const queries2 = wireFakeDb();
    await POST(req({ metric_type: 'page_load', duration_ms: 10 }, { 'user-agent': 'Mozilla/5.0' }));
    expect(queries2[0]!.params[3]).toBe('Mozilla/5.0');
  });

  it('operation_details is JSON-stringified and bound; absent -> null bound', async () => {
    const queries = wireFakeDb();
    await POST(req({ metric_type: 'api_call', duration_ms: 10, operation_details: { endpoint: '/x', ok: true } }));
    expect(queries[0]!.params[4]).toBe('{"endpoint":"/x","ok":true}');

    const queries2 = wireFakeDb();
    await POST(req({ metric_type: 'api_call', duration_ms: 10 }));
    expect(queries2[0]!.params[4]).toBeNull();
  });

  it('non-whitelisted metric_type fails inside logMetric() (7-value whitelist), counted as a failure, no INSERT issued', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ metric_type: 'bogus_type', duration_ms: 10 }));
    expect((await res.json())).toEqual({ success: true, message: 'Logged 0 metrics, 1 failed', logged: 0, failed: 1 });
    expect(queries).toHaveLength(0);
  });

  it('threshold-exceeded warning (5-entry LOG_THRESHOLDS_MS map, includes scroll_performance:17) logs a console.warn but does not affect success', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ metric_type: 'scroll_performance', duration_ms: 50 })); // > 17ms threshold
    expect((await res.json()).logged).toBe(1);
    expect(queries).toHaveLength(1);
    expect(consoleWarnSpy).toHaveBeenCalledWith(expect.stringContaining('scroll_performance exceeded threshold (50ms > 17ms)'));
  });

  it('genuinely thrown/unexpected error (not a per-metric validation failure) -> 400 {success:false, error:"Failed to log performance metrics: ..."}', async () => {
    const { db } = makeFakeTenantDb();
    mockResolveInboxApiContext.mockResolvedValue({
      ok: true,
      value: {
        db,
        session: fakeSession(),
      },
    });
    // Force request.json() itself to throw something OTHER than parse failure is already covered;
    // here we simulate an unexpected failure inside the per-metric loop by making `metrics` a Proxy
    // that throws on iteration — genuinely unexpected, not a validation failure.
    const throwingRequest = {
      json: async () => ({
        metrics: new Proxy([{ metric_type: 'page_load', duration_ms: 1 }], {
          get(target, prop) {
            if (prop === Symbol.iterator) {
              throw new Error('boom');
            }
            return Reflect.get(target, prop);
          },
        }),
      }),
      headers: new Headers(),
    } as unknown as NextRequest;

    const res = await POST(throwingRequest);
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to log performance metrics: boom' });
  });
});
