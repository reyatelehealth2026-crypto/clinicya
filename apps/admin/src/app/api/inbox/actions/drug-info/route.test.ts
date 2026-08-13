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

const mockCalculateMargin = jest.fn();
jest.mock('../max-discount/_lib/drugPricingEngine', () => ({
  calculateMargin: (...args: unknown[]) => mockCalculateMargin(...args),
}));

import { GET, POST } from './route';

function req(search = ''): NextRequest {
  const url = `https://tenant.re-ya.com/api/inbox/actions/drug-info${search}`;
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

const FULL_DRUG_ROW = {
  id: 42,
  name: 'Amoxicillin 500mg',
  name_en: 'Amoxicillin EN',
  generic_name: 'Amoxicillin',
  manufacturer: 'ACME',
  unit: 'กล่อง',
  base_unit: 'เม็ด',
  sku: 'AMX-42',
  description: 'Antibiotic',
  price: '100.00',
  sale_price: '90.00',
  category_name: 'Antibiotics',
  image_url: 'https://example.com/amx.png',
  stock: 15,
  is_active: 1,
  requires_prescription: 1,
  contraindications: 'Penicillin allergy',
  dosage: '500mg',
  usage_instructions: 'TID',
  active_ingredient: 'Amoxicillin trihydrate',
  dosage_form: 'capsule',
  barcode: '1234567890',
};

beforeEach(() => {
  jest.clearAllMocks();
  mockCalculateMargin.mockResolvedValue({ drugId: 42, drugName: 'Amoxicillin 500mg', cost: 60, price: 90, margin: 30, marginPercent: 33.33 });
});

describe('GET /api/inbox/actions/drug-info', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?drug_id=42'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Drug ID or name is required" when neither drug_id/id nor name is present, no DB queries issued', async () => {
    const queries = wireFakeDb();

    const res = await GET(req());

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug ID or name is required' });
    expect(queries).toHaveLength(0);
  });

  it('happy path by drug_id: SELECT bi.*-equivalent JOIN item_categories, isPrescription reads requires_prescription, pricing attached from calculateMargin', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items bi') ? [FULL_DRUG_ROW] : []));

    const res = await GET(req('?drug_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        id: 42,
        name: 'Amoxicillin 500mg',
        nameEn: 'Amoxicillin EN',
        genericName: 'Amoxicillin',
        manufacturer: 'ACME',
        unit: 'กล่อง',
        sku: 'AMX-42',
        description: 'Antibiotic',
        price: 100,
        salePrice: 90,
        effectivePrice: 90,
        category: 'Antibiotics',
        imageUrl: 'https://example.com/amx.png',
        stock: 15,
        isActive: true,
        isPrescription: true,
        contraindications: 'Penicillin allergy',
        dosage: '500mg',
        usageInstructions: 'TID',
        activeIngredient: 'Amoxicillin trihydrate',
        dosageForm: 'capsule',
        barcode: '1234567890',
        pricing: { drugId: 42, drugName: 'Amoxicillin 500mg', cost: 60, price: 90, margin: 30, marginPercent: 33.33 },
      },
    });

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items bi'));
    expect(drugQuery).toBeDefined();
    expect(drugQuery!.sql).toContain('LEFT JOIN item_categories ic ON bi.category_id = ic.id');
    expect(drugQuery!.sql).toContain('WHERE bi.id = ?');
    expect(drugQuery!.sql).toContain('bi.requires_prescription');
    expect(drugQuery!.sql).not.toContain('bi.is_prescription');
    expect(drugQuery!.params).toEqual([42]);
    expect(mockCalculateMargin).toHaveBeenCalledWith(expect.anything(), 42);
  });

  it('drug_id takes precedence over id when both present (isset()-based)', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items bi') ? [FULL_DRUG_ROW] : []));

    await GET(req('?drug_id=42&id=999'));

    expect(queries.find((q) => q.sql.includes('FROM business_items bi'))!.params).toEqual([42]);
  });

  it('lookup by name: searches (name LIKE ? OR sku LIKE ?) AND (line_account_id = ? OR line_account_id IS NULL) LIMIT 1, search term bound twice', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items bi') ? [FULL_DRUG_ROW] : []));

    const res = await GET(req('?name=Amox'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);

    const drugQuery = queries.find((q) => q.sql.includes('FROM business_items bi'));
    expect(drugQuery!.sql).toContain('bi.name LIKE ?');
    expect(drugQuery!.sql).toContain('bi.sku LIKE ?');
    expect(drugQuery!.sql).toContain('bi.line_account_id = ?');
    expect(drugQuery!.sql).toContain('bi.line_account_id IS NULL');
    expect(drugQuery!.sql).toContain('LIMIT 1');
    expect(drugQuery!.params).toEqual(['%Amox%', '%Amox%', 3]);
  });

  it('effectivePrice falls back to price when sale_price is 0/absent', async () => {
    wireFakeDb((sqlText) =>
      sqlText.includes('FROM business_items bi') ? [{ ...FULL_DRUG_ROW, sale_price: null, price: '75.00' }] : []
    );

    const res = await GET(req('?drug_id=42'));
    const body = await res.json();

    expect(body.data.price).toBe(75);
    expect(body.data.salePrice).toBe(0);
    expect(body.data.effectivePrice).toBe(75);
  });

  it('unit falls back to base_unit when unit is null', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM business_items bi') ? [{ ...FULL_DRUG_ROW, unit: null }] : []));

    const res = await GET(req('?drug_id=42'));
    const body = await res.json();

    expect(body.data.unit).toBe('เม็ด');
  });

  it('404 "Drug not found" when no matching row', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?drug_id=999'));
    const body = await res.json();

    expect(res.status).toBe(404);
    expect(body).toEqual({ success: false, error: 'Drug not found' });
    expect(mockCalculateMargin).not.toHaveBeenCalled();
  });

  it('pricing stays null (outer request still succeeds) when calculateMargin throws, matching PHP swallow-and-continue', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM business_items bi') ? [FULL_DRUG_ROW] : []));
    mockCalculateMargin.mockRejectedValue(new Error('pricing engine exploded'));

    const res = await GET(req('?drug_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.pricing).toBeNull();
  });

  it('500 "Database error: ..." on a thrown DB failure', async () => {
    wireFakeDb(() => {
      throw new Error('deadlock');
    });

    const res = await GET(req('?drug_id=42'));
    const body = await res.json();

    expect(res.status).toBe(500);
    expect(body).toEqual({ success: false, error: 'Database error: deadlock' });
    expect(mockCalculateMargin).not.toHaveBeenCalled();
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
