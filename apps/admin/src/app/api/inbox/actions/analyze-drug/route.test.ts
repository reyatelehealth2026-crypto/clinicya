/**
 * @jest-environment node
 */
import { createHash } from 'crypto';
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery, type QueryImpl } from './_lib/testHelpers/fakeTenantDb';

/**
 * route.test.ts — covers `POST /api/inbox/actions/analyze-drug`. Mocks the
 * two network seams at their SHARED home,
 * `../analyze-symptom/_lib/{geminiVisionClient,imageResolver}` — the real
 * `identifyDrug()` orchestration (imported from
 * `../analyze-symptom/_lib/imageAnalyzer`) runs end-to-end against a fake
 * DB, with no path to a real network call of either kind.
 */
global.fetch = jest.fn(() => {
  throw new Error('unexpected real network call in analyze-drug test');
}) as unknown as typeof fetch;

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

const mockCallGeminiVisionApi = jest.fn();
jest.mock('../analyze-symptom/_lib/geminiVisionClient', () => ({
  callGeminiVisionApi: (...args: unknown[]) => mockCallGeminiVisionApi(...args),
}));

const mockGetImageData = jest.fn();
jest.mock('../analyze-symptom/_lib/imageResolver', () => ({
  getImageData: (...args: unknown[]) => mockGetImageData(...args),
}));

import { POST, GET } from './route';

const AI_NOT_CONFIGURED_MESSAGE = 'AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings';
const DEFAULT_FAILURE_MESSAGE = 'การวิเคราะห์รูปภาพล้มเหลว';
const IMAGE_URL = 'https://cdn.example.com/uploads/pill-box.jpg';

function sha256(value: string): string {
  return createHash('sha256').update(value, 'utf8').digest('hex');
}

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

const defaultQueryImpl: QueryImpl = (sqlText) => {
  if (sqlText.includes('FROM ai_settings')) {
    return [{ gemini_api_key: 'test-gemini-key', model: 'gemini-2.5-flash' }];
  }
  if (sqlText.includes('FROM drug_recognition_cache')) {
    return [];
  }
  if (sqlText.includes('FROM business_items')) {
    return [];
  }
  if (sqlText.includes('INSERT INTO drug_recognition_cache')) {
    return { insertId: 1, affectedRows: 1 };
  }
  return [];
};

function wireFakeDb(queryImpl: QueryImpl = defaultQueryImpl, sessionOverrides: Partial<TenantSession> = {}): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const DRUG_JSON_TEXT = JSON.stringify({
  drugName: 'Paracetamol 500mg',
  genericName: 'Paracetamol',
  manufacturer: 'GPO',
  dosageForm: 'เม็ด',
  strength: '500mg',
  usage: 'รับประทานครั้งละ 1-2 เม็ด ทุก 4-6 ชั่วโมง',
  indications: ['ลดไข้', 'บรรเทาปวด'],
  contraindications: ['แพ้พาราเซตามอล'],
  sideEffects: ['คลื่นไส้'],
  warnings: ['ห้ามเกิน 4000mg/วัน'],
  drugCategory: 'ยาสามัญประจำบ้าน',
  isPrescriptionRequired: false,
  confidence: 0.92,
});

beforeEach(() => {
  jest.clearAllMocks();
  mockGetImageData.mockResolvedValue({ success: true, base64: 'ZmFrZS1kcnVnLWJ5dGVz', mimeType: 'image/jpeg' });
  mockCallGeminiVisionApi.mockResolvedValue({ success: true, text: DRUG_JSON_TEXT });
});

describe('POST /api/inbox/actions/analyze-drug', () => {
  it('401 JSON when unauthenticated', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ image_url: IMAGE_URL }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockGetImageData).not.toHaveBeenCalled();
  });

  it("400 'Invalid image URL format' for a non-empty malformed URL", async () => {
    wireFakeDb();

    const res = await POST(req({ image_url: 'definitely not a url' }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'Invalid image URL format' });
  });

  it("400 'Image URL is required' for empty/absent image_url", async () => {
    wireFakeDb();

    expect((await (await POST(req({ image_url: '' }))).json())).toEqual({ success: false, error: 'Image URL is required' });
    expect((await (await POST(req({}))).json())).toEqual({ success: false, error: 'Image URL is required' });
  });

  it('503 with the exact long Thai message when AI is not configured', async () => {
    wireFakeDb(() => []);
    const savedEnv = process.env.GEMINI_API_KEY;
    delete process.env.GEMINI_API_KEY;

    try {
      const res = await POST(req({ image_url: IMAGE_URL }));
      const body = await res.json();

      expect(res.status).toBe(503);
      expect(body).toEqual({ success: false, error: AI_NOT_CONFIGURED_MESSAGE });
    } finally {
      if (savedEnv === undefined) delete process.env.GEMINI_API_KEY;
      else process.env.GEMINI_API_KEY = savedEnv;
    }
  });

  it('cache-hit short-circuit: image resolver / Gemini never called when getCachedDrugRecognition returns a row', async () => {
    const cachedDrug = {
      drugName: 'Paracetamol 500mg (cached)',
      genericName: 'Paracetamol',
      manufacturer: null,
      dosageForm: null,
      strength: null,
      dosage: null,
      usage: null,
      indications: [],
      contraindications: [],
      sideEffects: [],
      warnings: [],
      drugCategory: null,
      isPrescriptionRequired: false,
      confidence: 0.9,
      rawResponse: DRUG_JSON_TEXT,
      matchedProductId: null,
      matchedProductName: null,
      inStock: false,
      price: null,
    };

    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM ai_settings')) {
        return [{ gemini_api_key: 'test-gemini-key', model: 'gemini-2.5-flash' }];
      }
      if (sqlText.includes('FROM drug_recognition_cache')) {
        return [{ recognition_result: JSON.stringify(cachedDrug), drug_name: cachedDrug.drugName, generic_name: 'Paracetamol', matched_product_id: null, created_at: '2026-08-01 09:00:00' }];
      }
      return [];
    });

    const res = await POST(req({ image_url: IMAGE_URL }));
    const body = await res.json();

    expect(mockGetImageData).not.toHaveBeenCalled();
    expect(mockCallGeminiVisionApi).not.toHaveBeenCalled();
    expect(res.status).toBe(200);
    expect(body.data.success).toBe(true);
    expect(body.data.cached).toBe(true);
    expect(body.data.drugName).toBe('Paracetamol 500mg (cached)');
  });

  it('happy path: full response shape with matched-product fields, price/stock schema-drift fix-forward (stock, not stock_quantity)', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM ai_settings')) {
        return [{ gemini_api_key: 'test-gemini-key', model: 'gemini-2.5-flash' }];
      }
      if (sqlText.includes('FROM drug_recognition_cache')) {
        return [];
      }
      if (sqlText.includes('FROM business_items')) {
        return [{ id: 501, name: 'Paracetamol 500mg', price: '15.00', stock: 40 }];
      }
      return [];
    });

    const res = await POST(req({ image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        success: true,
        drugName: 'Paracetamol 500mg',
        genericName: 'Paracetamol',
        manufacturer: 'GPO',
        dosageForm: 'เม็ด',
        strength: '500mg',
        dosage: '500mg',
        usage: 'รับประทานครั้งละ 1-2 เม็ด ทุก 4-6 ชั่วโมง',
        indications: ['ลดไข้', 'บรรเทาปวด'],
        contraindications: ['แพ้พาราเซตามอล'],
        sideEffects: ['คลื่นไส้'],
        warnings: ['ห้ามเกิน 4000mg/วัน'],
        drugCategory: 'ยาสามัญประจำบ้าน',
        isPrescriptionRequired: false,
        confidence: 0.92,
        rawResponse: DRUG_JSON_TEXT,
        matchedProductId: 501,
        matchedProductName: 'Paracetamol 500mg',
        inStock: true,
        price: 15,
        imageHash: sha256(IMAGE_URL),
      },
    });

    const matchQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(matchQuery).toBeDefined();
    expect(matchQuery!.sql).toContain('stock');
    expect(matchQuery!.sql).not.toContain('stock_quantity');
  });

  it('failure from Gemini -> 400 with result.error passed through verbatim', async () => {
    wireFakeDb();
    mockCallGeminiVisionApi.mockResolvedValue({ success: false, error: 'API Error (503): model overloaded' });

    const res = await POST(req({ image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'API Error (503): model overloaded' });
  });

  it("route-level DEFAULT_FAILURE_MESSAGE fires only when result.error is truly absent", async () => {
    let isolatedPost!: typeof POST;
    jest.isolateModules(() => {
      jest.doMock('./_lib/session', () => ({ resolveInboxApiContext: () => mockResolveInboxApiContext() }));
      jest.doMock('../analyze-symptom/_lib/imageAnalyzer', () => ({
        isConfigured: jest.fn().mockResolvedValue(true),
        identifyDrug: jest.fn().mockResolvedValue({ success: false }),
      }));
      // eslint-disable-next-line @typescript-eslint/no-var-requires
      isolatedPost = (require('./route') as typeof import('./route')).POST;
    });

    wireFakeDb();
    const res = await isolatedPost(req({ image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: DEFAULT_FAILURE_MESSAGE });
  });

  it('GET is method-not-allowed (405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
