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
  const url = `https://tenant.re-ya.com/api/inbox/actions/medical-history${search}`;
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

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const FULL_USER_ROW = {
  id: 42,
  display_name: 'คุณสมชาย',
  first_name: 'สมชาย',
  last_name: 'ใจดี',
  weight: '70.50',
  height: '175.00',
  birth_date: new Date(1990, 4, 20), // sourced from `birthday AS birth_date` (fix A)
  gender: 'male',
  drug_allergies: 'Penicillin, Aspirin',
  current_medications: 'Metformin',
  medical_conditions: 'เบาหวาน, ความดันโลหิตสูง',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/medical-history', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?user_id=42'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Invalid user ID" when user_id is missing', async () => {
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

  it('happy path: SELECT reads birthday AS birth_date, no chronic_diseases/birth_date column reference; success mirrors found', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM users') ? [FULL_USER_ROW] : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        userId: 42,
        found: true,
        displayName: 'คุณสมชาย',
        firstName: 'สมชาย',
        lastName: 'ใจดี',
        allergies: ['Penicillin', 'Aspirin'],
        conditions: ['เบาหวาน', 'ความดันโลหิตสูง'],
        currentMedications: ['Metformin'],
        weight: 70.5,
        height: 175,
        age: expect.any(Number),
        gender: 'male',
        hasAllergies: true,
        hasConditions: true,
        hasMedications: true,
      },
    });

    const userQuery = queries.find((q) => q.sql.includes('FROM users'));
    expect(userQuery).toBeDefined();
    // FIX (A): reads the real `birthday` column, aliased back to `birth_date`.
    expect(userQuery!.sql).toContain('birthday AS birth_date');
    // FIX (B): chronic_diseases must never appear in the compiled SQL.
    expect(userQuery!.sql).not.toContain('chronic_diseases');
    expect(userQuery!.params).toEqual([42]);
  });

  it('user not found: found:false with the empty-but-present shape, success:false', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?user_id=999'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: false,
      data: {
        userId: 999,
        found: false,
        allergies: [],
        conditions: [],
        currentMedications: [],
        weight: null,
        height: null,
        age: null,
        gender: null,
      },
    });
  });

  it('conditions dedupe: medical_conditions with a repeated entry collapses via array_unique-equivalent', async () => {
    wireFakeDb((sqlText) =>
      sqlText.includes('FROM users')
        ? [{ ...FULL_USER_ROW, medical_conditions: 'เบาหวาน, เบาหวาน, ความดัน' }]
        : []
    );

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.conditions).toEqual(['เบาหวาน', 'ความดัน']);
  });

  // NOTE: no "Database error: ..." 500 test here, deliberately — see route.ts's
  // own doc: getUserMedicalHistory() has its own `catch (PDOException $e)` (a
  // literal port of the PHP method's own swallow-to-degraded-result behavior)
  // and never throws, so route.ts's defensive try/catch is genuinely
  // unreachable through any DB-level failure. Same precedent as
  // ../drug-inventory/route.test.ts and ../low-stock-drugs/route.test.ts
  // (Phase 4 batch 4a), neither of which has an equivalent test either, for
  // the identical reason.

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
