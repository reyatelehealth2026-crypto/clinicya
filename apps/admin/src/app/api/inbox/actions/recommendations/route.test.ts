/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { extractSearchTerms } from './_lib/recommendations';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { GET, POST } from './route';

function req(search = ''): NextRequest {
  const url = `https://tenant.re-ya.com/api/inbox/actions/recommendations${search}`;
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

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('extractSearchTerms', () => {
  it('extracts the availability-query capture, quantity patterns, and standalone significant words', () => {
    expect(extractSearchTerms('ขอพาราเซตามอล 10 กล่องหน่อยครับ')).toEqual(['10 กล่อง', 'พาราเซตามอล', 'กล่อง']);
  });
});

describe('GET /api/inbox/actions/recommendations', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(req('?user_id=42'));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Invalid user ID" when user_id is missing, no DB queries issued', async () => {
    const queries = wireFakeDb(() => []);
    const res = await GET(req());
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
    expect(queries).toHaveLength(0);
  });

  it('branch 1 (chat-history hit): type=context finds a match in recent messages, returns type:"chat_history"', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM messages')) {
        return [{ content: 'อยากได้ยา Amoxicillin 500mg สักกล่อง', message_type: 'text', created_at: new Date() }];
      }
      if (sqlText.includes('FROM business_items')) {
        return [
          {
            id: 5,
            name: 'Amoxicillin 500mg',
            sku: 'AMX-500',
            price: '80.00',
            sale_price: null,
            stock: 15,
            description: 'Antibiotic',
            image_url: null,
            generic_name: 'Amoxicillin',
            active_ingredient: 'Amoxicillin',
          },
        ];
      }
      return [];
    });

    const res = await GET(req('?user_id=42&type=context'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.type).toBe('chat_history');
    expect(body.data.userId).toBe(42);
    expect(body.data.count).toBe(1);
    expect(body.data.recommendations).toHaveLength(1);
    expect(body.data.recommendations[0]).toMatchObject({ id: 5, drugId: 5, name: 'Amoxicillin 500mg', matchType: 'exact' });
    // Priority 2/3 never reached — message search and popular-drugs queries never fired.
    expect(queries.some((q) => q.sql.includes('FROM messages'))).toBe(true);
  });

  it('branch 2 (message-search hit): non-context type with a message finds a match, returns type:"message_search"', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) {
        return [
          {
            id: 9,
            name: 'Paracetamol 500mg',
            sku: 'PARA-500',
            price: '20.00',
            sale_price: null,
            stock: 30,
            description: 'Pain relief',
            image_url: null,
            generic_name: 'Paracetamol',
            name_en: 'Paracetamol',
            active_ingredient: 'Paracetamol',
            manufacturer: 'GPO',
            unit: 'เม็ด',
          },
        ];
      }
      return [];
    });

    const res = await GET(req('?user_id=42&message=' + encodeURIComponent('มี Paracetamol ไหม')));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.type).toBe('message_search');
    expect(body.data.userId).toBe(42);
    expect(body.data.message).toBe('มี Paracetamol ไหม');
    expect(body.data.originalMessage).toBe('มี Paracetamol ไหม');
    expect(body.data.recommendations).toHaveLength(1);
    expect(body.data.recommendations[0]).toMatchObject({ id: 9, drugId: 9, name: 'Paracetamol 500mg' });
    expect(Array.isArray(body.data.searchTerms)).toBe(true);
  });

  it('branch 3 (popular fallback): type=context with no matching chat history falls through to popular drugs', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM messages')) return []; // no chat history at all
      if (sqlText.includes('FROM business_items')) {
        return [
          {
            id: 3,
            name: 'Vitamin C',
            sku: 'VITC',
            price: '150.00',
            sale_price: '120.00',
            stock: 40,
            description: 'Supplement',
            image_url: 'https://cdn.example.com/vitc.jpg',
            category: 'วิตามิน',
          },
        ];
      }
      return [];
    });

    const res = await GET(req('?user_id=42&type=context'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.type).toBe('popular');
    expect(body.data.userId).toBe(42);
    expect(body.data.recommendations).toEqual([
      {
        id: 3,
        drugId: 3,
        name: 'Vitamin C',
        sku: 'VITC',
        price: 120,
        originalPrice: 150,
        stock: 40,
        category: 'วิตามิน',
        description: 'Supplement',
        imageUrl: 'https://cdn.example.com/vitc.jpg',
      },
    ]);
    // Chat-history's own messages query was issued (Priority 1 still runs when type==='context').
    expect(queries.some((q) => q.sql.includes('FROM messages'))).toBe(true);
  });

  it('branch 3b (popular fallback): empty symptoms + non-context type + no message also falls through to popular drugs', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) return [];
      return [];
    });
    const res = await GET(req('?user_id=42'));
    const body = await res.json();
    expect(body.data.type).toBe('popular');
    expect(body.data.recommendations).toEqual([]);
  });

  it('branch 4 (symptom-based): non-context type with non-empty symptoms calls getForSymptoms', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM users')) return [];
      if (sqlText.includes('FROM business_items')) {
        return [
          {
            id: 11,
            name: 'Paracetamol',
            generic_name: null,
            sku: 'PARA-1',
            dosage: null,
            usage_instructions: null,
            sale_price: null,
            price: '25.00',
            stock: 50,
            image_url: null,
            is_prescription: 0,
            description: null,
            category_name: 'ยาแก้ปวด',
          },
        ];
      }
      if (sqlText.includes('FROM drug_interactions')) return [];
      return [];
    });

    const res = await GET(req('?user_id=42&symptoms=' + encodeURIComponent('ปวดหัว')));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.symptoms).toEqual(['ปวดหัว']);
    expect(body.data.categories).toEqual(['pain_relief']);
    expect(body.data.userId).toBe(42);
    expect(body.data.allergiesChecked).toBe(0);
    expect(body.data.currentMedicationsChecked).toBe(0);
    expect(body.data.recommendations).toHaveLength(1);
    expect(body.data.recommendations[0]).toMatchObject({
      drugId: 11,
      name: 'Paracetamol',
      category: 'ยาแก้ปวด',
      dosage: '500-1000 mg ทุก 4-6 ชั่วโมง (ไม่เกิน 4000 mg/วัน)', // getDefaultDosage() fallback (dosage column is null)
      usage: 'รับประทานหลังอาหารหรือเมื่อมีอาการ', // getDefaultUsage() fallback
      price: 25,
      isPrescription: false,
      hasInteractions: false,
    });
  });

  it('branch 4: comma-separated symptoms string is parsed and trimmed', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM users')) return [];
      return [];
    });
    const res = await GET(req('?user_id=42&symptoms=' + encodeURIComponent('ไข้, ไอ')));
    const body = await res.json();
    expect(body.data.symptoms).toEqual(['ไข้', 'ไอ']);
  });

  it('limit query param overrides the default of 10 (reflected in the popular-fallback LIMIT param)', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [] : []));
    const res = await GET(req('?user_id=42&limit=3'));
    expect(res.status).toBe(200);
    const bizQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(bizQuery!.params).toContain(3);
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
