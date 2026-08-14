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

// Only `loadConsultationAnalyzerService` is overridden per-test; `recordAnalytics`
// (and every other export) stays the REAL implementation via `jest.requireActual`,
// so ordinary tests still exercise the real DB-hitting code path against the
// fake Kysely db below. `beforeEach` wires the default passthrough behavior —
// only the dedicated 503 test overrides it to return `null`.
const mockLoadConsultationAnalyzerService = jest.fn();
jest.mock('./_lib/recordAnalytics', () => {
  const actual = jest.requireActual('./_lib/recordAnalytics') as typeof import('./_lib/recordAnalytics');
  return {
    ...actual,
    loadConsultationAnalyzerService: (...args: Parameters<typeof actual.loadConsultationAnalyzerService>) =>
      mockLoadConsultationAnalyzerService(...args),
  };
});

import { GET, POST } from './route';

function req(body: unknown): NextRequest {
  return { json: async () => body } as unknown as NextRequest;
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

beforeEach(() => {
  jest.clearAllMocks();
  // Default: route straight through to the REAL loadConsultationAnalyzerService (a
  // real handle whose recordAnalytics() hits the fake DB below). Only the dedicated
  // 503 test overrides this per-test via mockReturnValueOnce(null).
  const actual = jest.requireActual('./_lib/recordAnalytics') as typeof import('./_lib/recordAnalytics');
  mockLoadConsultationAnalyzerService.mockImplementation(actual.loadConsultationAnalyzerService);
});

describe('POST /api/inbox/actions/record-analytics', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 5 }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockLoadConsultationAnalyzerService).not.toHaveBeenCalled();
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });

  it.each([[{}], [{ user_id: 0 }], [{ user_id: null }]])(
    '400 {success:false, error:"User ID is required"} for body=%j, no DB queries issued, service never loaded',
    async (body) => {
      const queries = wireFakeDb();
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
      expect(queries).toHaveLength(0);
      expect(mockLoadConsultationAnalyzerService).not.toHaveBeenCalled();
    }
  );

  it('503 {success:false, error:"Consultation analyzer service not available"} when loadConsultationAnalyzerService() returns null (explicit unit-test mock only, never reachable on real traffic)', async () => {
    const queries = wireFakeDb();
    mockLoadConsultationAnalyzerService.mockReturnValueOnce(null);

    const res = await POST(req({ user_id: 5 }));
    expect(res.status).toBe(503);
    expect(await res.json()).toEqual({ success: false, error: 'Consultation analyzer service not available' });
    expect(queries).toHaveLength(0);
  });

  it('happy path: INSERT INTO consultation_analytics with pharmacistId defaulting to session.adminUserId, exact response envelope', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }), { adminUserId: 42 });

    const res = await POST(req({ user_id: 7, communication_type: 'A', stage_at_close: 'purchase' }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Analytics recorded successfully' });

    expect(queries).toHaveLength(1);
    const insertQuery = queries[0]!;
    expect(insertQuery.sql.toLowerCase()).toContain('insert into');
    expect(insertQuery.sql.toLowerCase()).toContain('consultation_analytics');
    expect(insertQuery.params).toEqual([
      7, // user_id
      42, // pharmacist_id <- session.adminUserId (pharmacist_id absent from body)
      'A', // communication_type
      'purchase', // stage_at_close
      null, // response_time_avg (absent -> null)
      null, // message_count (absent -> null)
      0, // ai_suggestions_shown (absent -> 0)
      0, // ai_suggestions_accepted (absent -> 0)
      0, // resulted_in_purchase (absent -> filter_var(false, ...) -> 0)
      null, // purchase_amount (absent -> null)
      '[]', // symptom_categories (absent -> [])
      '[]', // drugs_recommended (absent -> [])
      '[]', // successful_patterns (absent -> [])
    ]);
  });

  it('LOAD-BEARING QUIRK: pharmacist_id ABSENT from body -> forced to session.adminUserId as an int, never null', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }), { adminUserId: 99 });
    await POST(req({ user_id: 7 }));
    const params = queries[0]!.params;
    expect(params[1]).toBe(99);
    expect(params[1]).not.toBeNull();
  });

  it('pharmacist_id explicitly present in body overrides session.adminUserId', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }), { adminUserId: 99 });
    await POST(req({ user_id: 7, pharmacist_id: 15 }));
    expect(queries[0]!.params[1]).toBe(15);
  });

  it('responseTimeAvg/messageCount: absent -> null; present (including 0) -> (int) cast, never treated as "absent"', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));
    await POST(req({ user_id: 7, response_time_avg: 0, message_count: 12 }));
    const params = queries[0]!.params;
    expect(params[4]).toBe(0); // response_time_avg present as 0 -> 0, not null
    expect(params[5]).toBe(12); // message_count
  });

  it('purchaseAmount: isset-ternary -> (float) cast when present, null when absent', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));
    await POST(req({ user_id: 7, purchase_amount: '199.5' }));
    expect(queries[0]!.params[9]).toBe(199.5);
  });

  it.each([
    [true, 1],
    ['1', 1],
    ['true', 1],
    ['TRUE', 1],
    ['on', 1],
    ['yes', 1],
    [1, 1],
    [false, 0],
    ['0', 0],
    ['false', 0],
    ['off', 0],
    ['no', 0],
    ['', 0],
    ['random-string', 0],
    [0, 0],
    [2, 0],
    [null, 0],
    [undefined, 0],
  ])('resultedInPurchase: filter_var(%p, FILTER_VALIDATE_BOOLEAN) -> %i', async (input, expected) => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));
    await POST(req({ user_id: 7, resulted_in_purchase: input }));
    expect(queries[0]!.params[8]).toBe(expected);
  });

  it('aiSuggestionsShown/aiSuggestionsAccepted: absent -> 0 (never null)', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));
    await POST(req({ user_id: 7 }));
    const params = queries[0]!.params;
    expect(params[6]).toBe(0);
    expect(params[7]).toBe(0);
  });

  it('symptomCategories/drugsRecommended/successfulPatterns: JSON-stringified, Thai text round-trips un-mangled (unescaped unicode, not \\uXXXX)', async () => {
    const queries = wireFakeDb(() => ({ insertId: 0, affectedRows: 1 }));
    await POST(
      req({
        user_id: 7,
        symptom_categories: ['ปวดหัว', 'ไข้'],
        drugs_recommended: ['พาราเซตามอล'],
        successful_patterns: ['เห็นใจลูกค้า'],
      })
    );
    const params = queries[0]!.params;
    expect(params[10]).toBe('["ปวดหัว","ไข้"]');
    expect(params[11]).toBe('["พาราเซตามอล"]');
    expect(params[12]).toBe('["เห็นใจลูกค้า"]');
    // None of these contain an escaped \uXXXX unicode sequence.
    expect(params[10]).not.toMatch(/\\u[0-9a-fA-F]{4}/);
  });

  it('LOAD-BEARING: recordAnalytics() returning false (its own internal DB-error swallow) still yields HTTP 200 with success:false — never a 400/500', async () => {
    const queries = wireFakeDb(() => {
      throw new Error('insert failed');
    });
    const res = await POST(req({ user_id: 7 }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: false, message: 'Failed to record analytics' });
    expect(queries).toHaveLength(1); // the INSERT was attempted (and failed) — not skipped.
  });

  // NOTE: no "Database error: ..." 500 test here, deliberately — recordAnalytics()
  // never throws (its own `catch (PDOException $e) { return false; }` is ported
  // faithfully — see _lib/recordAnalytics.ts), so route.ts's defensive try/catch
  // around the service call is genuinely unreachable through any DB-level
  // failure. Same precedent as ../detect-urgency/route.test.ts.
});
