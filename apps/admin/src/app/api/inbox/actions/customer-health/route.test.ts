/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { getDraftStyle } from '../draft-style/_lib/draftStyle';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { GET, POST } from './route';

function req(search = ''): NextRequest {
  const url = `https://tenant.re-ya.com/api/inbox/actions/customer-health${search}`;
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

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown = () => []): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/customer-health', () => {
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

    const res = await GET(req('?user_id=-5'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('default-profile fallback: no customer_health_profiles row -> type A defaults, type-A draftStyle tips', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('blood_type')) return [{ line_user_id: null, weight: null, height: null, blood_type: null, medical_conditions: null, drug_allergies: null, current_medications: null }];
      return [];
    });

    const res = await GET(req('?user_id=1'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.communicationType).toBe('A');
    expect(body.data.communicationTypeLabel).toBe('ตรงประเด็น (Type A)');
    expect(body.data.confidence).toBe(0);
    expect(body.data.tips).toEqual(getDraftStyle('A').tips);
    expect(body.data.allergies).toEqual([]);
    expect(body.data.medications).toEqual([]);
    expect(body.data.hasAllergyWarning).toBe(false);
  });

  it('purchase-history / prescription-drug queries reference requires_prescription, not is_prescription', async () => {
    const queries = wireFakeDb();

    await GET(req('?user_id=1'));

    const txQuery = queries.find((q) => q.sql.includes('FROM transactions'));
    expect(txQuery).toBeDefined();
    expect(txQuery!.sql).toContain('requires_prescription');
    // The literal column name "is_prescription" must never appear anywhere
    // (note "requires_prescription" does NOT contain "is_prescription" as a
    // substring — "requires" ends "...i-r-e-s", not "...i-s-").
    expect(queries.some((q) => q.sql.includes('is_prescription'))).toBe(false);
  });

  it('happy path: full users + customer_health_profiles + user_health_profiles + user_drug_allergies + user_current_medications fixture — exercises every merge/dedupe rule', async () => {
    const usersHealthRow = {
      line_user_id: 'Uabc123',
      weight: '70.00',
      height: '175.00',
      blood_type: 'A',
      medical_conditions: 'เบาหวาน, ความดัน',
      drug_allergies: null,
      current_medications: null,
    };
    const miniAppHealthRow = {
      weight: '68.00',
      height: null,
      blood_type: 'AB',
      medical_conditions: JSON.stringify(['เบาหวาน', { name: 'ไทรอยด์' }]),
    };
    const profileRow = {
      communication_type: 'B',
      confidence: '0.75',
      communication_tips: JSON.stringify(['tip1', 'tip2']),
      last_analyzed_at_str: '2026-08-01 10:00:00',
      message_count_analyzed: 12,
      chronic_conditions: null,
    };
    const miniAllergyRows = [
      { drug_name: 'Aspirin', severity: 'severe', reaction_type: 'rash', reaction_notes: 'ผื่นแดง' },
      { drug_name: 'Sulfa', severity: 'moderate', reaction_type: 'other', reaction_notes: null },
      { drug_name: 'ไม่มี', severity: null, reaction_type: null, reaction_notes: null },
    ];
    const miniMedRows = [
      { medication_name: 'Metformin', dosage: '500mg', frequency: '2 times/day', notes: 'after meal' },
      { medication_name: 'Vitamin C', dosage: null, frequency: null, notes: null },
    ];
    const purchasedRows = [{ name: 'Paracetamol', product_id: 5, last_purchased: '2026-07-01 00:00:00' }];

    const queries = wireFakeDb((sqlText) => {
      // NOTE: order matters — `FROM user_health_profiles`'s own SELECT also
      // contains the substring "blood_type", so the more specific FROM-table
      // checks must be tried before the generic "blood_type" users-query check.
      if (sqlText.includes('FROM user_health_profiles')) return [miniAppHealthRow];
      if (sqlText.includes('blood_type')) return [usersHealthRow];
      if (sqlText.includes('SELECT drug_allergies FROM users')) return [{ drug_allergies: 'Penicillin, Aspirin' }];
      if (sqlText.includes('SELECT current_medications FROM users')) return [{ current_medications: 'Metformin' }];
      if (sqlText.includes('SELECT line_user_id FROM users')) return [{ line_user_id: 'Uabc123' }];
      if (sqlText.includes('FROM customer_health_profiles')) return [profileRow];
      if (sqlText.includes('FROM user_drug_allergies')) return miniAllergyRows;
      if (sqlText.includes('FROM user_current_medications')) return miniMedRows;
      if (sqlText.includes('FROM transactions')) return purchasedRows;
      return [];
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    const data = body.data;

    // --- allergies: text-sourced entries, one merged by mini-app (Aspirin), one
    // net-new from mini-app (Sulfa), the "ไม่มี" none-sentinel excluded entirely ---
    expect(data.allergies).toEqual([
      { name: 'Penicillin', severity: 'unknown', source: 'user_profile', isActive: true },
      { name: 'Aspirin', severity: 'severe', reaction: 'rash', notes: 'ผื่นแดง', source: 'miniapp', isActive: true },
      { name: 'Sulfa', severity: 'moderate', reaction: 'other', notes: null, source: 'miniapp', isActive: true },
    ]);
    expect(data.hasAllergyWarning).toBe(true);

    // --- medications: text-sourced Metformin overridden by mini-app row,
    // purchase-history Paracetamol appended, net-new mini-app Vitamin C appended ---
    expect(data.medications).toEqual([
      { name: 'Metformin', dosage: '500mg', frequency: '2 times/day', notes: 'after meal', source: 'miniapp', isActive: true },
      { name: 'Paracetamol', productId: 5, lastPurchased: '2026-07-01 00:00:00', source: 'purchase_history', isActive: true },
      { name: 'Vitamin C', dosage: null, frequency: null, notes: null, source: 'miniapp', isActive: true },
    ]);

    // --- conditions: mini-app row overrides the text-derived list (non-empty JSON wins) ---
    expect(data.conditions).toEqual(['เบาหวาน', 'ไทรอยด์']);

    // --- weight/height/bloodType: mini-app overlay overrides weight+bloodType,
    // leaves height untouched (mini-app height was null) ---
    expect(data.weight).toBe(68);
    expect(data.height).toBe(175);
    expect(data.bloodType).toBe('AB');

    // --- communication profile ---
    expect(data.communicationType).toBe('B');
    expect(data.communicationTypeLabel).toBe('ห่วงใย (Type B)');
    expect(data.confidence).toBe(0.75);
    expect(data.tips).toEqual(['tip1', 'tip2']);
    expect(data.draftStyle).toEqual(getDraftStyle('B'));
    expect(data.lastAnalyzedAt).toBe('2026-08-01 10:00:00');
    expect(data.messageCountAnalyzed).toBe(12);

    // sanity: the fixed-forward purchase-history query used the real column.
    const txQuery = queries.find((q) => q.sql.includes('FROM transactions'));
    expect(txQuery!.sql).toContain('requires_prescription');
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
