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
  const url = `https://tenant.re-ya.com/api/inbox/actions/context-widgets${search}`;
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

interface KeywordFixture {
  keyword: string;
  keyword_type: 'action' | 'condition' | 'drug' | 'symptom';
  widget_type: 'allergy' | 'drug_info' | 'interaction' | 'pregnancy' | 'pricing' | 'symptom';
  related_data: string | null;
  priority: number;
}

interface ContextFixtures {
  keywords?: KeywordFixture[] | 'throw';
  drugAllergies?: string | null;
  currentMedications?: string | null;
  popularDrugsRows?: unknown[];
  symptomRecRows?: unknown[];
  searchMessageRows?: unknown[];
  exactMatchRows?: unknown[];
}

/**
 * Routes the fake pool by distinctive SQL substrings across every query
 * `getContextWidgets()` can issue — see contextWidgets.ts's own queries for
 * why each marker is unique (LIMIT 500 only appears on the
 * searchDrugsFromMessage() query, LIMIT 200 only on checkForDrugNames()'s
 * exact-name-match query, `bi.name ASC` only on getPopularDrugs(), etc).
 */
function wireContext(fixtures: ContextFixtures = {}): { queries: RecordedQuery[]; session?: Partial<TenantSession> } {
  const { db, queries } = makeFakeTenantDb((sqlText) => {
    const s = sqlText.toLowerCase();
    if (s.includes('from pharmacy_context_keywords')) {
      if (fixtures.keywords === 'throw') throw new Error('keyword table missing');
      return fixtures.keywords ?? [];
    }
    if (s.includes('select drug_allergies')) {
      return fixtures.drugAllergies !== undefined ? [{ drug_allergies: fixtures.drugAllergies }] : [];
    }
    if (s.includes('select current_medications')) {
      return fixtures.currentMedications !== undefined ? [{ current_medications: fixtures.currentMedications }] : [];
    }
    if (s.includes('limit 500')) {
      return fixtures.searchMessageRows ?? [];
    }
    if (s.includes('limit 200')) {
      return fixtures.exactMatchRows ?? [];
    }
    if (s.includes('left join item_categories')) {
      return s.includes('bi.name asc') ? (fixtures.popularDrugsRows ?? []) : (fixtures.symptomRecRows ?? []);
    }
    return [];
  });
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return { queries };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/context-widgets', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?user_id=42&message=ปวดหัว'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Invalid user ID" when user_id is missing', async () => {
    const { queries } = wireContext();

    const res = await GET(req('?message=ปวดหัว'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Invalid user ID" when user_id <= 0', async () => {
    wireContext();

    const res = await GET(req('?user_id=0&message=ปวดหัว'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('empty message (absent) -> {widgets: [], count: 0} at HTTP 200 — NOT the 400 error path, DB never touched', async () => {
    const { queries } = wireContext();

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: { widgets: [], count: 0 } });
    expect(queries).toHaveLength(0);
  });

  it('empty message (message="0", PHP empty() quirk) -> also {widgets: [], count: 0} at 200', async () => {
    const { queries } = wireContext();

    const res = await GET(req('?user_id=42&message=0'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: { widgets: [], count: 0 } });
    expect(queries).toHaveLength(0);
  });

  it('a single matched keyword builds a pure pregnancy widget (no allergies, no drug matches)', async () => {
    wireContext({
      keywords: [{ keyword: 'ตั้งครรภ์', keyword_type: 'condition', widget_type: 'pregnancy', related_data: '{"alert":true}', priority: 25 }],
    });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('กำลังตั้งครรภ์อยู่ค่ะ')));
    const body = await res.json();

    expect(body.data.count).toBe(1);
    expect(body.data.widgets).toEqual([
      {
        type: 'pregnancy',
        title: '🤰 ยาปลอดภัยสำหรับคนท้อง',
        titleEn: 'Pregnancy-Safe Drugs',
        icon: '🤰',
        isAlert: true,
        message: 'กรุณาตรวจสอบความปลอดภัยของยาก่อนแนะนำ',
        actions: [
          { label: 'ดูยาที่ปลอดภัย', action: 'view_safe_drugs' },
          { label: 'ปรึกษาเภสัชกร', action: 'consult_pharmacist' },
        ],
      },
    ]);
  });

  it('interaction widget pulls getUserMedications() (filter-then-trim: comma+newline split)', async () => {
    wireContext({
      keywords: [{ keyword: 'ยาตีกัน', keyword_type: 'action', widget_type: 'interaction', related_data: '{}', priority: 20 }],
      currentMedications: 'Paracetamol,\nIbuprofen',
    });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('เช็คยาตีกันหน่อยค่ะ')));
    const body = await res.json();

    expect(body.data.widgets[0].type).toBe('interaction');
    expect(body.data.widgets[0].currentMedications).toEqual(['Paracetamol', 'Ibuprofen']);
    expect(body.data.widgets[0].medicationCount).toBe(2);
  });

  it("getUserAllergies()'s filter-BEFORE-trim order: a whitespace-only piece between commas survives as an empty string, not dropped", async () => {
    wireContext({
      keywords: [],
      drugAllergies: 'Aspirin, ,Penicillin',
    });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('สวัสดีค่ะ')));
    const body = await res.json();

    expect(body.data.widgets[0].type).toBe('allergy_warning');
    expect(body.data.widgets[0].allergies).toEqual(['Aspirin', '', 'Penicillin']);
    expect(body.data.widgets[0].allergyCount).toBe(3);
  });

  it('keyword priority sort + same-widget-type dedup: two drug_info keyword matches -> only the HIGHER-priority one builds', async () => {
    wireContext({
      keywords: [
        { keyword: 'ยาA', keyword_type: 'drug', widget_type: 'drug_info', related_data: '{}', priority: 5 },
        { keyword: 'ยาB', keyword_type: 'drug', widget_type: 'drug_info', related_data: '{}', priority: 50 },
      ],
    });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('มียาAและยาBไหมคะ')));
    const body = await res.json();

    expect(body.data.count).toBe(1);
    expect(body.data.widgets[0].type).toBe('drug_info');
    expect(body.data.widgets[0].drugName).toBe('ยาB');
  });

  it('malformed related_data JSON degrades to {} rather than throwing', async () => {
    wireContext({
      keywords: [{ keyword: 'ราคา', keyword_type: 'action', widget_type: 'pricing', related_data: 'not-json{{{', priority: 5 }],
    });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('ราคาเท่าไหร่คะ')));
    const body = await res.json();

    expect(body.data.widgets[0].type).toBe('pricing');
    expect(body.data.widgets[0].relatedData).toEqual({});
  });

  it('getActiveKeywords() DB error falls back to getDefaultKeywords() (literal 6-entry list)', async () => {
    wireContext({ keywords: 'throw' });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('มีไข้ค่ะ')));
    const body = await res.json();

    // "ไข้" is in the default keyword list -> symptom widget, category "fever".
    expect(body.data.widgets[0].type).toBe('symptom');
    expect(body.data.widgets[0].keyword).toBe('ไข้');
    expect(body.data.widgets[0].category).toBe('fever');
  });

  it('SAFETY-CRITICAL: a matched allergy triggers array_unshift — allergy_warning is always FIRST even when 4 other widgets already matched, and the cap drops the lowest-priority one', async () => {
    wireContext({
      keywords: [
        { keyword: 'ปวดหัว', keyword_type: 'symptom', widget_type: 'symptom', related_data: '{"category":"pain"}', priority: 50 },
        { keyword: 'พารา', keyword_type: 'drug', widget_type: 'drug_info', related_data: '{}', priority: 40 },
        { keyword: 'ยาตีกัน', keyword_type: 'action', widget_type: 'interaction', related_data: '{}', priority: 30 },
        { keyword: 'ราคา', keyword_type: 'action', widget_type: 'pricing', related_data: '{}', priority: 20 },
      ],
      drugAllergies: 'Aspirin',
      currentMedications: null,
    });

    const message = 'ปวดหัว พารา ยาตีกัน ราคา';
    const res = await GET(req('?user_id=42&message=' + encodeURIComponent(message)));
    const body = await res.json();

    expect(body.data.count).toBe(4);
    expect(body.data.widgets.map((w: { type: string }) => w.type)).toEqual([
      'allergy_warning',
      'symptom',
      'drug_info',
      'interaction',
    ]);
    // "pricing" (lowest priority of the 4 matched, 5th overall once allergy_warning is unshifted) is dropped by the array_slice(0, 4) cap.
    expect(body.data.widgets.some((w: { type: string }) => w.type === 'pricing')).toBe(false);
  });

  it('widgets capped at 4 total (array_slice(0, 4)) even with no allergy involved — 5 matched keywords, lowest priority dropped', async () => {
    wireContext({
      keywords: [
        { keyword: 'ปวดหัว', keyword_type: 'symptom', widget_type: 'symptom', related_data: '{"category":"pain"}', priority: 50 },
        { keyword: 'พารา', keyword_type: 'drug', widget_type: 'drug_info', related_data: '{}', priority: 40 },
        { keyword: 'ยาตีกัน', keyword_type: 'action', widget_type: 'interaction', related_data: '{}', priority: 30 },
        { keyword: 'ราคา', keyword_type: 'action', widget_type: 'pricing', related_data: '{}', priority: 20 },
        { keyword: 'ตั้งครรภ์', keyword_type: 'condition', widget_type: 'pregnancy', related_data: '{}', priority: 10 },
      ],
    });

    const message = 'ปวดหัว พารา ยาตีกัน ราคา ตั้งครรภ์';
    const res = await GET(req('?user_id=42&message=' + encodeURIComponent(message)));
    const body = await res.json();

    expect(body.data.count).toBe(4);
    expect(body.data.widgets.map((w: { type: string }) => w.type)).toEqual(['symptom', 'drug_info', 'interaction', 'pricing']);
    expect(body.data.widgets.some((w: { type: string }) => w.type === 'pregnancy')).toBe(false);
  });

  it('checkForDrugNames(): searchDrugsFromMessage() match produces a "symptom" widget with the matched drug in recommendations (with costPrice/margin computed)', async () => {
    wireContext({
      keywords: [],
      searchMessageRows: [
        {
          id: 7,
          name: 'พาราเซตามอล',
          sku: 'PARA-500',
          price: '10.00',
          sale_price: '8.00',
          stock: 50,
          description: 'ลดไข้ แก้ปวด',
          image_url: null,
          generic_name: null,
          name_en: null,
          active_ingredient: null,
          manufacturer: null,
          unit: null,
        },
      ],
    });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('มีพาราเซตามอล')));
    const body = await res.json();

    expect(body.data.count).toBe(1);
    const widget = body.data.widgets[0];
    expect(widget.type).toBe('symptom');
    expect(widget.keyword).toBe('ค้นหาจากข้อความ');
    expect(widget.category).toBe('search');
    expect(widget.recommendations).toHaveLength(1);
    expect(widget.recommendations[0].name).toBe('พาราเซตามอล');
    expect(widget.recommendations[0].price).toBe(8);
    expect(widget.recommendations[0].costPrice).toBeCloseTo(5.6, 5); // 8 * 0.7
    expect(widget.recommendations[0].margin).toBeCloseTo(30, 5); // ((8-5.6)/8)*100
    expect(widget.recommendations[0].matchScore).toBeGreaterThanOrEqual(100);
  });

  it('checkForDrugNames(): exact product-name match (LIMIT 200 query) produces a "drug_info" widget, inStock reflects stock=0', async () => {
    wireContext({
      keywords: [],
      searchMessageRows: [],
      exactMatchRows: [
        { id: 9, name: 'ไอบูโพรเฟน', sku: 'IBU-01', price: '25.00', sale_price: null, stock: 0, description: 'ลดปวด' },
      ],
    });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('มีไอบูโพรเฟนไหม')));
    const body = await res.json();

    expect(body.data.count).toBe(1);
    const widget = body.data.widgets[0];
    expect(widget.type).toBe('drug_info');
    expect(widget.title).toBe('ข้อมูลยา: ไอบูโพรเฟน');
    expect(widget.drugId).toBe(9);
    expect(widget.price).toBe(25);
    expect(widget.stock).toBe(0);
    expect(widget.inStock).toBe(false);
    expect(widget.drug).toEqual({ id: 9, name: 'ไอบูโพรเฟน', sku: 'IBU-01', price: 25, stock: 0, description: 'ลดปวด' });
  });

  it('no keyword match, no drug match, no allergies -> {widgets: [], count: 0} but the message was non-empty (DB was queried)', async () => {
    const { queries } = wireContext({ keywords: [] });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('สวัสดีค่ะ')));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: { widgets: [], count: 0 } });
    expect(queries.length).toBeGreaterThan(0);
  });

  it('a getActiveKeywords()/getUserAllergies()/business_items query failure elsewhere is swallowed per-helper — overall request still succeeds', async () => {
    const { db } = (() => {
      const { db: fakeDb } = makeFakeTenantDb((sqlText) => {
        if (sqlText.toLowerCase().includes('select drug_allergies')) {
          throw new Error('users table connection lost');
        }
        return [];
      });
      return { db: fakeDb };
    })();
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('สวัสดีค่ะ')));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: { widgets: [], count: 0 } });
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
