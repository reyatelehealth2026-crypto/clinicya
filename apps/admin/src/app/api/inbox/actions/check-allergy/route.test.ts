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
  const url = `https://tenant.re-ya.com/api/inbox/actions/check-allergy${search}`;
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

const USER_ROW = {
  id: 42,
  display_name: 'คุณสมชาย',
  first_name: 'สมชาย',
  last_name: 'ใจดี',
  weight: '70.50',
  height: '175.00',
  birth_date: null,
  gender: 'male',
  drug_allergies: 'Penicillin, Sulfa drugs',
  current_medications: '',
  medical_conditions: '',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/check-allergy', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?user_id=42&drug_name=Penicillin'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Invalid user ID" when user_id is missing', async () => {
    const queries = wireFakeDb();

    const res = await GET(req('?drug_name=Penicillin'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Invalid user ID" when user_id <= 0', async () => {
    wireFakeDb();

    const res = await GET(req('?user_id=0&drug_name=Penicillin'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('400 "Drug name is required" when neither drug_name nor drug is present', async () => {
    const queries = wireFakeDb();

    const res = await GET(req('?user_id=42'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug name is required' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Drug name is required" when drug_name is the literal string "0" (PHP empty() quirk)', async () => {
    wireFakeDb();

    const res = await GET(req('?user_id=42&drug_name=0'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug name is required' });
  });

  it('drug_name takes precedence over drug when both present (isset()-based)', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM users') ? [USER_ROW] : []));

    const res = await GET(req('?user_id=42&drug_name=Penicillin&drug=Aspirin'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.matchedAllergies[0].drug).toBe('Penicillin');
  });

  it('falls back to drug when drug_name is absent', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM users') ? [USER_ROW] : []));

    const res = await GET(req('?user_id=42&drug=Sulfa'));
    const body = await res.json();

    expect(body.data.hasAllergy).toBe(true);
    expect(body.data.matchedAllergies[0].allergy).toBe('Sulfa drugs');
  });

  it('happy path: substring match either direction, success is always true (not tied to found)', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM users') ? [USER_ROW] : []));

    const res = await GET(req('?user_id=42&drug_name=Penicillin'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        hasAllergy: true,
        matchedAllergies: [{ allergy: 'Penicillin', drug: 'Penicillin', matchType: 'direct' }],
        allUserAllergies: ['Penicillin', 'Sulfa drugs'],
      },
    });
  });

  it('no match: hasAllergy false, but success is still true (user not found in DB too)', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?user_id=999&drug_name=Ibuprofen'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: { hasAllergy: false, matchedAllergies: [], allUserAllergies: [] },
    });
  });

  // NOTE: no "Database error: ..." 500 test here, deliberately — see route.ts's
  // own doc: checkUserAllergy() delegates entirely to getUserMedicalHistory(),
  // which never throws, so route.ts's defensive try/catch is genuinely
  // unreachable through any DB-level failure. Same precedent as
  // ../drug-inventory/route.test.ts and ../low-stock-drugs/route.test.ts
  // (Phase 4 batch 4a).

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
