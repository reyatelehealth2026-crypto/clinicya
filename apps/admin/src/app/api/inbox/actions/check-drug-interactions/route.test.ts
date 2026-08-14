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
  businessItems?: unknown[];
  users?: unknown[];
  /** Function of (params) => rows, so a test can vary the interaction by drug-name pair. */
  interactions?: (params: unknown[]) => unknown[];
}

function wireFakeDb(fixtures: Fixtures = {}): RecordedQuery[] {
  const queryImpl = (sqlText: string, params: unknown[]) => {
    if (sqlText.includes('FROM business_items')) return fixtures.businessItems ?? [];
    if (sqlText.includes('FROM users')) return fixtures.users ?? [];
    if (sqlText.includes('FROM drug_interactions')) return fixtures.interactions ? fixtures.interactions(params) : [];
    return [];
  };
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/check-drug-interactions', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, drug_ids: [1] }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "User ID is required" when user_id is absent (checked BEFORE drug_ids)', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ drug_ids: [1, 2] }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Drug IDs array is required" when drug_ids is empty and user_id is present', async () => {
    wireFakeDb();
    const res = await POST(req({ user_id: 42, drug_ids: [] }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug IDs array is required' });
  });

  it('parses a CSV drug_ids string with intval-mapping', async () => {
    const queries = wireFakeDb({
      businessItems: [
        { id: 1, name: 'Warfarin', generic_name: null },
        { id: 2, name: 'Aspirin', generic_name: null },
      ],
    });
    const res = await POST(req({ user_id: 42, drug_ids: '1,2' }));
    expect(res.status).toBe(200);
    const bizQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(bizQuery!.params).toEqual([1, 2]);
  });

  it('< 2 total drugs (1 new drug, no current medications) short-circuits with hasInteractions:false, no drug_interactions query', async () => {
    const queries = wireFakeDb({
      businessItems: [{ id: 1, name: 'Warfarin', generic_name: null }],
      users: [{ current_medications: null, drug_allergies: null }],
    });
    const res = await POST(req({ user_id: 42, drug_ids: [1] }));
    const body = await res.json();
    expect(body.data).toEqual({ hasInteractions: false, interactions: [], severity: null, checkedDrugs: ['Warfarin'] });
    expect(queries.some((q) => q.sql.includes('FROM drug_interactions'))).toBe(false);
  });

  it('severity is the MAX across both passes (contraindicated found in pass 2 overrides mild from pass 1); current-meds x current-meds pairs are never queried', async () => {
    const queries = wireFakeDb({
      businessItems: [
        { id: 1, name: 'DrugA', generic_name: null },
        { id: 2, name: 'DrugB', generic_name: null },
      ],
      users: [{ current_medications: 'CurrentMed', drug_allergies: null }],
      interactions: (params) => {
        const p = params.map((x) => String(x).toLowerCase());
        // Pass 1: DrugA/DrugB x CurrentMed -> mild.
        if (p.some((x) => x.includes('druga')) && p.some((x) => x.includes('currentmed'))) {
          return [{ drug1_name: 'DrugA', drug1_generic: null, drug2_name: 'CurrentMed', drug2_generic: null, severity: 'mild', description: 'd1', recommendation: 'r1' }];
        }
        if (p.some((x) => x.includes('drugb')) && p.some((x) => x.includes('currentmed'))) {
          return [{ drug1_name: 'DrugB', drug1_generic: null, drug2_name: 'CurrentMed', drug2_generic: null, severity: 'mild', description: 'd2', recommendation: 'r2' }];
        }
        // Pass 2: DrugA x DrugB -> contraindicated.
        if (p.some((x) => x.includes('druga')) && p.some((x) => x.includes('drugb'))) {
          return [{ drug1_name: 'DrugA', drug1_generic: null, drug2_name: 'DrugB', drug2_generic: null, severity: 'contraindicated', description: 'd3', recommendation: 'r3' }];
        }
        return [];
      },
    });

    const res = await POST(req({ user_id: 42, drug_ids: [1, 2] }));
    const body = await res.json();

    expect(body.data.severity).toBe('contraindicated');
    expect(body.data.hasInteractions).toBe(true);
    expect(body.data.interactions).toHaveLength(3);
    expect(body.data.newDrugs).toEqual(['DrugA', 'DrugB']);
    expect(body.data.currentMedications).toEqual(['CurrentMed']);
    expect(body.data.checkedDrugs).toEqual(['DrugA', 'DrugB', 'CurrentMed']);

    // Assert no query pair combines two "current medication" params without either
    // 'druga' or 'drugb' present — i.e. current-meds x current-meds was never queried.
    // (There's only one current medication here, so this also holds trivially; the
    // real assertion is the exact interaction count above: 2 (pass 1) + 1 (pass 2) = 3,
    // which is impossible if a 4th, meds-x-meds pair had also been queried and matched.)
    const interactionQueries = queries.filter((q) => q.sql.includes('FROM drug_interactions'));
    expect(interactionQueries).toHaveLength(3); // DrugA-CurrentMed, DrugB-CurrentMed, DrugA-DrugB — no CurrentMed-CurrentMed pair.
  });

  it('GET is method-not-allowed (405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
