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

function req(body: unknown): NextRequest {
  return { json: async () => body } as unknown as NextRequest;
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
  users?: unknown[];
  /** Only returned when the query's bound LIKE params mention both 'warfarin' and 'aspirin'. */
  interactions?: unknown[];
}

function wireFakeDb(fixtures: Fixtures = {}): RecordedQuery[] {
  const queryImpl = (sqlText: string, params: unknown[]) => {
    if (sqlText.includes('FROM users')) return fixtures.users ?? [];
    if (sqlText.includes('FROM drug_interactions')) {
      const paramsLower = params.map((p) => String(p).toLowerCase());
      const mentionsWarfarin = paramsLower.some((p) => p.includes('warfarin'));
      const mentionsAspirin = paramsLower.some((p) => p.includes('aspirin'));
      return mentionsWarfarin && mentionsAspirin ? (fixtures.interactions ?? []) : [];
    }
    return [];
  };
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

const INTERACTION_ROW = {
  id: 9,
  drug1_name: 'Warfarin',
  drug1_generic: 'Warfarin sodium',
  drug2_name: 'Aspirin',
  drug2_generic: 'Acetylsalicylic acid',
  severity: 'severe',
  description: 'Increased bleeding risk',
  recommendation: 'Monitor closely',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/check-interactions', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ drugs: ['Aspirin'] }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Drug names array is required" when drugs is absent (empty array default)', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({}));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug names array is required' });
    expect(queries).toHaveLength(0);
  });

  it('400 when drugs is an empty array', async () => {
    wireFakeDb();
    const res = await POST(req({ drugs: [] }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug names array is required' });
  });

  it('400 when drugs is the falsy string "0" (PHP empty() on strings)', async () => {
    wireFakeDb();
    const res = await POST(req({ drugs: '0' }));
    expect(res.status).toBe(400);
  });

  it('200 (not a 400) for a non-empty array whose only element is the falsy string "0" — PHP empty() on arrays is length-only, not element-truthiness', async () => {
    // Passes the route's own 400 guard; checkDrugInteractions() itself then filters
    // '0' out of allDrugs (array_filter semantics), so no drug_interactions query
    // is issued for a single '0' element — asserting THAT filter is patient-profile's
    // own concern, not this route's. Only the 400-vs-200 boundary is this route's.
    wireFakeDb({ interactions: [INTERACTION_ROW] });
    const res = await POST(req({ drugs: ['0'] }));
    const body = await res.json();
    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
  });

  it('parses a JSON-array-encoded drugs string', async () => {
    const queries = wireFakeDb({ interactions: [INTERACTION_ROW] });
    const res = await POST(req({ drugs: '["Warfarin","Aspirin"]' }));
    const body = await res.json();
    expect(res.status).toBe(200);
    expect(body.data.drugsChecked).toEqual(['Warfarin', 'Aspirin']);
    expect(queries.filter((q) => q.sql.includes('FROM drug_interactions'))).toHaveLength(1);
  });

  it('falls back to CSV-split when drugs string is not valid JSON', async () => {
    wireFakeDb({ interactions: [] });
    const res = await POST(req({ drugs: 'Warfarin,Aspirin' }));
    const body = await res.json();
    expect(res.status).toBe(200);
    expect(body.data.drugsChecked).toEqual(['Warfarin', 'Aspirin']);
  });

  it('happy path: array drugs + user_id, calls checkDrugInteractions and returns its result verbatim', async () => {
    const queries = wireFakeDb({
      users: [{ current_medications: 'Metformin' }],
      interactions: [INTERACTION_ROW],
    });

    const res = await POST(req({ drugs: ['Warfarin', 'Aspirin'], user_id: 42 }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        hasInteractions: true,
        interactions: [
          {
            id: 9,
            drug1: 'Warfarin',
            drug1Generic: 'Warfarin sodium',
            drug2: 'Aspirin',
            drug2Generic: 'Acetylsalicylic acid',
            severity: 'severe',
            description: 'Increased bleeding risk',
            recommendation: 'Monitor closely',
            source: 'database',
          },
        ],
        severity: 'severe',
        severityLabel: 'รุนแรง',
        drugsChecked: ['Warfarin', 'Aspirin', 'Metformin'],
        interactionCount: 1,
      },
    });
    // userId ?: null truthiness — a real user_id triggers the medical-history lookup.
    expect(queries.some((q) => q.sql.includes('FROM users'))).toBe(true);
  });

  it('user_id of 0 (absent) is passed as null — no medical-history lookup issued', async () => {
    const queries = wireFakeDb({ interactions: [] });
    const res = await POST(req({ drugs: ['Aspirin', 'Ibuprofen'] }));
    expect(res.status).toBe(200);
    expect(queries.some((q) => q.sql.includes('FROM users'))).toBe(false);
  });

  it('GET is method-not-allowed (405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
