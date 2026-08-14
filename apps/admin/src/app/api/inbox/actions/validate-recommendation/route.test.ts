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
  interactions?: unknown[];
}

function wireFakeDb(fixtures: Fixtures = {}): RecordedQuery[] {
  const queryImpl = (sqlText: string) => {
    if (sqlText.includes('FROM business_items')) return fixtures.businessItems ?? [];
    if (sqlText.includes('FROM users')) return fixtures.users ?? [];
    if (sqlText.includes('FROM drug_interactions')) return fixtures.interactions ?? [];
    return [];
  };
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

const IN_STOCK_DRUG_ROW = {
  id: 7,
  sku: 'SKU-7',
  name: 'Amoxicillin',
  generic_name: 'Amoxicillin trihydrate',
  stock: 10,
  min_stock: 2,
  price: '50.00',
  sale_price: null,
  cost_price: '30.00',
  is_active: 1,
  is_prescription: 1,
  drug_category: 'controlled',
  storage_condition: null,
  storage_zone_type: null,
  requires_batch_tracking: 0,
  requires_expiry_tracking: 0,
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/validate-recommendation', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ user_id: 1, product_id: 7 }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "User ID is required" when user_id is absent, no DB queries issued', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ product_id: 7 }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Product ID is required" when both product_id and drug_id are absent', async () => {
    wireFakeDb();
    const res = await POST(req({ user_id: 42 }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Product ID is required' });
  });

  it('product_id falls back to drug_id when product_id is absent', async () => {
    const queries = wireFakeDb({ businessItems: [IN_STOCK_DRUG_ROW], users: [{ drug_allergies: null, current_medications: null, medical_conditions: null }] });
    const res = await POST(req({ user_id: 42, drug_id: 7 }));
    expect(res.status).toBe(200);
    const bizQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(bizQuery!.params).toEqual([7]);
  });

  it('drug not found -> canRecommend:false, single not_found issue, no further queries', async () => {
    const queries = wireFakeDb({ businessItems: [] });
    const res = await POST(req({ user_id: 42, product_id: 999 }));
    const body = await res.json();
    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: { canRecommend: false, issues: [{ type: 'not_found', message: 'ไม่พบข้อมูลยา' }] } });
    expect(queries.some((q) => q.sql.includes('FROM users'))).toBe(false);
  });

  it('out of stock -> out_of_stock issue, canRecommend:false', async () => {
    wireFakeDb({
      businessItems: [{ ...IN_STOCK_DRUG_ROW, stock: 0 }],
      users: [{ drug_allergies: null, current_medications: null, medical_conditions: null }],
    });
    const res = await POST(req({ user_id: 42, product_id: 7 }));
    const body = await res.json();
    expect(body.data.canRecommend).toBe(false);
    expect(body.data.issues).toEqual([{ type: 'out_of_stock', message: 'ยาหมดสต็อก', severity: 'high' }]);
    expect(body.data.hasCriticalIssues).toBe(true);
  });

  it('allergy match against drug name/generic name -> critical allergy issue, canRecommend:false', async () => {
    wireFakeDb({
      businessItems: [IN_STOCK_DRUG_ROW],
      users: [{ drug_allergies: 'Amoxicillin', current_medications: null, medical_conditions: null }],
    });
    const res = await POST(req({ user_id: 42, product_id: 7 }));
    const body = await res.json();
    expect(body.data.canRecommend).toBe(false);
    expect(body.data.issues).toContainEqual({ type: 'allergy', message: 'ลูกค้าแพ้ยา: Amoxicillin', severity: 'critical' });
  });

  it('contraindicated drug interaction with a current medication -> canRecommend:false', async () => {
    wireFakeDb({
      businessItems: [IN_STOCK_DRUG_ROW],
      users: [{ drug_allergies: null, current_medications: 'Warfarin', medical_conditions: null }],
      interactions: [
        {
          id: 1,
          drug1_name: 'Amoxicillin',
          drug1_generic: 'Amoxicillin trihydrate',
          drug2_name: 'Warfarin',
          drug2_generic: null,
          severity: 'contraindicated',
          description: 'Severe bleeding risk',
          recommendation: 'Avoid combination',
        },
      ],
    });
    const res = await POST(req({ user_id: 42, product_id: 7 }));
    const body = await res.json();
    expect(body.data.canRecommend).toBe(false);
    expect(body.data.issues).toContainEqual({
      type: 'interaction',
      message: 'ยาตีกับ Warfarin: Severe bleeding risk',
      severity: 'contraindicated',
      recommendation: 'Avoid combination',
    });
  });

  it('clean case: in stock, no allergy, no current medications -> canRecommend:true, no issues', async () => {
    wireFakeDb({
      businessItems: [IN_STOCK_DRUG_ROW],
      users: [{ drug_allergies: null, current_medications: null, medical_conditions: null }],
    });
    const res = await POST(req({ user_id: 42, product_id: 7 }));
    const body = await res.json();
    expect(body.data).toEqual({
      canRecommend: true,
      drugInfo: expect.objectContaining({ found: true, productId: 7, inStock: true }),
      issues: [],
      issueCount: 0,
      hasCriticalIssues: false,
    });
  });

  it('GET is method-not-allowed (405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
