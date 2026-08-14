/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { similarTextPercent, calculateSimilarity, checkAllergyMatch } from './_lib/safeAlternatives';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { GET, POST } from './route';

function req(search = ''): NextRequest {
  const url = `https://tenant.re-ya.com/api/inbox/actions/safe-alternatives${search}`;
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

interface Fixtures {
  original?: unknown[];
  similar?: unknown[];
  users?: unknown[];
}

function wireFakeDb(fixtures: Fixtures = {}): RecordedQuery[] {
  let businessItemsCallCount = 0;
  const queryImpl = (sqlText: string) => {
    if (sqlText.includes('FROM business_items')) {
      businessItemsCallCount++;
      // 1st call: getDrugDetails (originalDrug); 2nd call: getSimilarDrugs.
      return businessItemsCallCount === 1 ? (fixtures.original ?? []) : (fixtures.similar ?? []);
    }
    if (sqlText.includes('FROM users')) return fixtures.users ?? [];
    return [];
  };
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('similarTextPercent (PHP similar_text() port)', () => {
  it('reproduces the PHP manual\'s own documented World/Word example: 4 matched chars, percent = 800/9 ≈ 88.888...', () => {
    expect(similarTextPercent('World', 'Word')).toBe(800 / 9);
    expect(similarTextPercent('World', 'Word')).toBeCloseTo(88.888888888888886, 10);
  });

  it('identical strings -> 100%', () => {
    expect(similarTextPercent('Paracetamol', 'Paracetamol')).toBe(100);
  });

  it('completely disjoint strings -> 0%', () => {
    expect(similarTextPercent('abc', 'xyz')).toBe(0);
  });
});

describe('calculateSimilarity', () => {
  it('same category (+40) + close price within 30% + name similarity, rounded to 2dp', () => {
    const drug1 = { id: 1, name: 'World', category_id: 5, price: '100', sale_price: null, stock: 10, generic_name: null, description: null, image_url: null };
    const drug2 = { id: 2, name: 'Word', category_id: 5, price: '105', sale_price: null, stock: 10, generic_name: null, description: null, image_url: null };
    // category match: +40; priceDiff = 5/105 = 0.047619... <=0.3 -> +30*(1-0.047619)=28.5714...;
    // name similarity: similarTextPercent('world','word') = 800/9 -> *0.3 = 26.666...
    const expected = Math.round((40 + 30 * (1 - 5 / 105) + (800 / 9) * 0.3) * 100) / 100;
    expect(calculateSimilarity(drug1, drug2)).toBe(expected);
  });

  it('different category, no price score contributes only name similarity', () => {
    const drug1 = { id: 1, name: 'abc', category_id: 1, price: '0', sale_price: null, stock: 10, generic_name: null, description: null, image_url: null };
    const drug2 = { id: 2, name: 'xyz', category_id: 2, price: '0', sale_price: null, stock: 10, generic_name: null, description: null, image_url: null };
    expect(calculateSimilarity(drug1, drug2)).toBe(0);
  });
});

describe('checkAllergyMatch (preserved PHP empty-needle stripos() quirk)', () => {
  it('a drug with an empty generic_name unconditionally "matches" any on-file allergy (PHP 8 stripos("x","") === 0, not false)', () => {
    const drug = { name: 'Paracetamol', generic_name: null, description: null };
    const result = checkAllergyMatch(drug, [{ name: 'Penicillin', severity: 'unknown', source: 'user_profile', isActive: true }]);
    expect(result.hasMatch).toBe(true);
  });

  it('no on-file allergies -> no match regardless of empty generic_name/description', () => {
    const drug = { name: 'Paracetamol', generic_name: null, description: null };
    expect(checkAllergyMatch(drug, []).hasMatch).toBe(false);
  });

  it('a real substring match (non-empty fields) is a genuine match, independent of the quirk', () => {
    const drug = { name: 'Amoxil', generic_name: 'Penicillin', description: null };
    const result = checkAllergyMatch(drug, [{ name: 'Penicillin', severity: 'unknown', source: 'user_profile', isActive: true }]);
    expect(result).toEqual({ hasMatch: true, matchedAllergies: ['Penicillin'] });
  });
});

describe('GET /api/inbox/actions/safe-alternatives', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(req('?drug_id=1&user_id=1'));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Invalid user ID" when user_id is missing (checked BEFORE drug_id)', async () => {
    const queries = wireFakeDb();
    const res = await GET(req('?drug_id=1'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Invalid user ID" when user_id <= 0', async () => {
    wireFakeDb();
    const res = await GET(req('?drug_id=1&user_id=0'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('400 "Drug ID is required" when drug_id is missing but user_id is valid', async () => {
    wireFakeDb();
    const res = await GET(req('?user_id=42'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug ID is required' });
  });

  it('original drug not found -> {alternatives: [], originalDrug: null, reason}', async () => {
    wireFakeDb({ original: [] });
    const res = await GET(req('?drug_id=999&user_id=42'));
    const body = await res.json();
    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: { alternatives: [], originalDrug: null, reason: 'Original drug not found' },
    });
  });

  it('happy path (no allergies on file): alternatives sorted by similarity desc, out-of-stock excluded', async () => {
    // No allergies on file — deliberately avoids checkAllergyMatch's documented
    // "empty generic_name always matches" PHP landmine (see _lib/safeAlternatives.ts's
    // module doc), which would otherwise confound this ordering/exclusion assertion.
    wireFakeDb({
      original: [{ id: 1, name: 'Paracetamol', generic_name: null, description: null, price: '50', sale_price: null, stock: 10, image_url: null, category_id: 9 }],
      similar: [
        // Out of stock -> excluded.
        { id: 2, name: 'OutOfStockDrug', generic_name: null, description: null, price: '50', sale_price: null, stock: 0, image_url: null, category_id: 9 },
        // Low similarity (different category, distant price, dissimilar name).
        { id: 4, name: 'Zzz', generic_name: null, description: null, price: '9999', sale_price: null, stock: 5, image_url: null, category_id: 1 },
        // High similarity (same category, close price, similar-ish name).
        { id: 5, name: 'Paracet', generic_name: null, description: null, price: '52', sale_price: null, stock: 5, image_url: null, category_id: 9 },
      ],
      users: [{ drug_allergies: null, current_medications: null }],
    });

    const res = await GET(req('?drug_id=1&user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.originalDrug).toEqual({ id: 1, name: 'Paracetamol', isSafe: true, unsafeReasons: [] });
    const ids = body.data.alternatives.map((a: { drugId: number }) => a.drugId);
    expect(ids).toEqual([5, 4]); // id 2 (out of stock) excluded; 5 (closer name/price/category) ranks above 4.
    expect(body.data.alternatives[0].similarity).toBeGreaterThan(body.data.alternatives[1].similarity);
  });

  it('genuine allergy match (non-empty generic_name) excludes the drug and flags the original as unsafe', async () => {
    wireFakeDb({
      original: [{ id: 1, name: 'Amoxil', generic_name: 'Penicillin', description: null, price: '50', sale_price: null, stock: 10, image_url: null, category_id: 9 }],
      similar: [{ id: 3, name: 'Ampicillin', generic_name: 'Penicillin', description: null, price: '50', sale_price: null, stock: 5, image_url: null, category_id: 9 }],
      users: [{ drug_allergies: 'Penicillin', current_medications: null }],
    });

    const res = await GET(req('?drug_id=1&user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.originalDrug).toEqual({ id: 1, name: 'Amoxil', isSafe: false, unsafeReasons: ['แพ้ยา: Penicillin'] });
    expect(body.data.alternatives).toEqual([]);
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
