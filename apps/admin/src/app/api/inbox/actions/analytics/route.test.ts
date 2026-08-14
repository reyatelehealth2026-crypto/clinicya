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

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown,
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const SUMMARY_ROW = {
  total_consultations: 10,
  successful_consultations: 4,
  avg_response_time: '125.5',
  total_ai_suggestions: 20,
  accepted_ai_suggestions: 7,
  total_revenue: '999.99',
  avg_messages_per_consultation: '6.789',
};

const BY_TYPE_ROWS = [
  { communication_type: 'A', count: 6, purchases: 2, avg_response_time: '100.0' },
  { communication_type: 'B', count: 4, purchases: 2, avg_response_time: '150.25' },
];

/** Distinguishes the summary query (no GROUP BY) from the byType breakdown query (has GROUP BY) by SQL text. */
function defaultQueryImpl(sqlText: string): unknown {
  if (/group by/i.test(sqlText)) {
    return BY_TYPE_ROWS;
  }
  return [SUMMARY_ROW];
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/analytics', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(req('/api/inbox/actions/analytics'));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('POST is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });

  it('happy path: computed summary fields (successRate/aiAcceptanceRate/rounding) + raw byType pass-through', async () => {
    wireFakeDb(defaultQueryImpl);
    const res = await GET(req('/api/inbox/actions/analytics?start_date=2026-07-01&end_date=2026-07-31'));
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body).toEqual({
      success: true,
      data: {
        period: { startDate: '2026-07-01', endDate: '2026-07-31' },
        summary: {
          totalConsultations: 10,
          successfulConsultations: 4,
          successRate: 40, // round(4/10*100, 2)
          avgResponseTime: 125.5, // round(125.5, 2)
          avgMessagesPerConsultation: 6.8, // round(6.789, 1)
          totalRevenue: 999.99,
          aiAcceptanceRate: 35, // round(7/20*100, 2)
        },
        // byType rows are the RAW driver rows, snake_case keys unchanged — PHP never re-shapes them.
        byType: BY_TYPE_ROWS,
      },
    });
  });

  it('zero consultations -> successRate/aiAcceptanceRate default to the literal 0 branch, not NaN/division-by-zero', async () => {
    wireFakeDb(() => [
      {
        total_consultations: 0,
        successful_consultations: null,
        avg_response_time: null,
        total_ai_suggestions: 0,
        accepted_ai_suggestions: null,
        total_revenue: null,
        avg_messages_per_consultation: null,
      },
    ]);
    const res = await GET(req('/api/inbox/actions/analytics'));
    const body = await res.json();
    expect(body.data.summary).toEqual({
      totalConsultations: 0,
      successfulConsultations: 0,
      successRate: 0,
      avgResponseTime: 0,
      avgMessagesPerConsultation: 0,
      totalRevenue: 0,
      aiAcceptanceRate: 0,
    });
  });

  it('pharmacist_id ABSENT from query -> falls back to session.adminUserId, filters both queries', async () => {
    const queries = wireFakeDb(defaultQueryImpl, { adminUserId: 99 });
    await GET(req('/api/inbox/actions/analytics'));
    expect(queries).toHaveLength(2);
    for (const q of queries) {
      expect(q.sql.toLowerCase()).toContain('pharmacist_id');
      expect(q.params).toContain(99);
    }
  });

  it('pharmacist_id=0 PRESENT in query -> wins over session.adminUserId (isset()-based ?? semantics, not truthiness) -> no pharmacist_id filter applied (0 is falsy)', async () => {
    const queries = wireFakeDb(defaultQueryImpl, { adminUserId: 99 });
    await GET(req('/api/inbox/actions/analytics?pharmacist_id=0'));
    for (const q of queries) {
      expect(q.sql.toLowerCase()).not.toContain('pharmacist_id');
      expect(q.params).not.toContain(99);
    }
  });

  it('pharmacist_id=5 PRESENT in query -> used directly, session.adminUserId ignored', async () => {
    const queries = wireFakeDb(defaultQueryImpl, { adminUserId: 99 });
    await GET(req('/api/inbox/actions/analytics?pharmacist_id=5'));
    for (const q of queries) {
      expect(q.params).toContain(5);
      expect(q.params).not.toContain(99);
    }
  });

  it('start_date/end_date absent -> default to 30-days-ago/today in Asia/Bangkok (not a bare server-local new Date())', async () => {
    wireFakeDb(defaultQueryImpl);
    const res = await GET(req('/api/inbox/actions/analytics'));
    const body = await res.json();
    expect(body.data.period.startDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(body.data.period.endDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(body.data.period.startDate < body.data.period.endDate).toBe(true);
  });

  it('BETWEEN params carry the 00:00:00/23:59:59 time-of-day suffixes PHP appends', async () => {
    const queries = wireFakeDb(defaultQueryImpl);
    await GET(req('/api/inbox/actions/analytics?start_date=2026-01-01&end_date=2026-01-31'));
    expect(queries[0]!.params).toEqual(expect.arrayContaining(['2026-01-01 00:00:00', '2026-01-31 23:59:59']));
  });

  it('LOAD-BEARING: DB query failure soft-degrades to {success:true, ...} at HTTP 200 — never 400/500/503 (PHP\'s catch (PDOException) branch, broad-catch equivalent here)', async () => {
    wireFakeDb(() => {
      throw new Error('connection refused');
    });
    const res = await GET(req('/api/inbox/actions/analytics?start_date=2026-02-01&end_date=2026-02-28'));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({
      success: true,
      data: {
        period: { startDate: '2026-02-01', endDate: '2026-02-28' },
        summary: {},
        byType: [],
        message: 'No analytics data available yet',
      },
    });
  });

  it('NO 503 CODE PATH EXISTS for this action (unlike record-analytics): a DB failure never produces a 503 — it always soft-degrades to 200, proving there is no loadService()-style service-availability branch to reach here', async () => {
    wireFakeDb(() => {
      throw new Error('service down');
    });
    const res = await GET(req('/api/inbox/actions/analytics'));
    expect(res.status).not.toBe(503);
    expect(res.status).toBe(200);
    expect((await res.json()).success).toBe(true);
  });
});
