/**
 * @jest-environment node
 */
import { createHash } from 'crypto';
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery, type QueryImpl } from './_lib/testHelpers/fakeTenantDb';

/**
 * route.test.ts — covers `POST /api/inbox/actions/analyze-symptom`. Two
 * network seams are jest.mock()'d — `./_lib/geminiVisionClient` and
 * `./_lib/imageResolver` — so the REAL `analyzeSymptom()` orchestration in
 * `./_lib/imageAnalyzer.ts` runs end-to-end against a fake DB, with no path
 * to a real network call of either kind. `global.fetch` is additionally
 * stubbed to throw, as a loud guard against anything slipping past the two
 * intended mocks.
 */
global.fetch = jest.fn(() => {
  throw new Error('unexpected real network call in analyze-symptom test');
}) as unknown as typeof fetch;

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

const mockCallGeminiVisionApi = jest.fn();
jest.mock('./_lib/geminiVisionClient', () => ({
  callGeminiVisionApi: (...args: unknown[]) => mockCallGeminiVisionApi(...args),
}));

const mockGetImageData = jest.fn();
jest.mock('./_lib/imageResolver', () => ({
  getImageData: (...args: unknown[]) => mockGetImageData(...args),
}));

import { POST, GET } from './route';

const AI_NOT_CONFIGURED_MESSAGE = 'AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings';
const DEFAULT_FAILURE_MESSAGE = 'การวิเคราะห์อาการล้มเหลว';
const IMAGE_URL = 'https://cdn.example.com/uploads/rash.jpg';

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

/** Default DB behavior: ai_settings has a configured key, no cache hit, cache-writes succeed. */
const defaultQueryImpl: QueryImpl = (sqlText) => {
  if (sqlText.includes('FROM ai_settings')) {
    return [{ gemini_api_key: 'test-gemini-key', model: 'gemini-2.5-flash' }];
  }
  if (sqlText.includes('FROM symptom_analysis_cache')) {
    return [];
  }
  if (sqlText.includes('INSERT INTO symptom_analysis_cache')) {
    return { insertId: 1, affectedRows: 1 };
  }
  return [];
};

function wireFakeDb(queryImpl: QueryImpl = defaultQueryImpl, sessionOverrides: Partial<TenantSession> = {}): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const SYMPTOM_JSON_TEXT = JSON.stringify({
  condition: 'ผื่นแพ้ผิวหนัง',
  conditionEn: 'Allergic dermatitis',
  description: 'มีผื่นแดงคันที่บริเวณแขน',
  severity: 'moderate',
  possibleCauses: ['แพ้อาหาร', 'แพ้สารเคมี'],
  recommendations: [{ type: 'medication', name: 'ยาแก้แพ้ chlorpheniramine', usage: 'รับประทานวันละ 1 เม็ดก่อนนอน' }],
  warnings: ['หากอาการไม่ดีขึ้นภายใน 3 วันให้พบแพทย์'],
  needsDoctor: false,
  doctorReason: null,
});

beforeEach(() => {
  jest.clearAllMocks();
  mockGetImageData.mockResolvedValue({ success: true, base64: 'ZmFrZS1pbWFnZS1ieXRlcw==', mimeType: 'image/jpeg' });
  mockCallGeminiVisionApi.mockResolvedValue({ success: true, text: SYMPTOM_JSON_TEXT });
});

describe('POST /api/inbox/actions/analyze-symptom', () => {
  it('401 JSON when unauthenticated, no image resolver / Gemini calls made', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ image_url: IMAGE_URL }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockGetImageData).not.toHaveBeenCalled();
    expect(mockCallGeminiVisionApi).not.toHaveBeenCalled();
  });

  it("400 'Invalid image URL format' for a non-empty malformed URL", async () => {
    wireFakeDb();

    const res = await POST(req({ image_url: 'not-a-valid-url' }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'Invalid image URL format' });
    expect(mockGetImageData).not.toHaveBeenCalled();
  });

  it("400 'Image URL is required' when image_url is empty string", async () => {
    wireFakeDb();

    const res = await POST(req({ image_url: '' }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'Image URL is required' });
  });

  it("400 'Image URL is required' when image_url is absent from the body", async () => {
    wireFakeDb();

    const res = await POST(req({}));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'Image URL is required' });
  });

  it('503 with the exact long Thai message when AI is not configured', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM ai_settings') ? [] : []));
    const savedEnv = process.env.GEMINI_API_KEY;
    delete process.env.GEMINI_API_KEY;

    try {
      const res = await POST(req({ image_url: IMAGE_URL }));
      const body = await res.json();

      expect(res.status).toBe(503);
      expect(body).toEqual({ success: false, error: AI_NOT_CONFIGURED_MESSAGE });
      expect(mockGetImageData).not.toHaveBeenCalled();
      expect(mockCallGeminiVisionApi).not.toHaveBeenCalled();
    } finally {
      if (savedEnv === undefined) delete process.env.GEMINI_API_KEY;
      else process.env.GEMINI_API_KEY = savedEnv;
    }
  });

  it('cache-hit short-circuit: image resolver / Gemini never called when getCachedSymptomAnalysis returns a row', async () => {
    const cachedAnalysis = {
      condition: 'ผื่นแพ้เดิม (จากแคช)',
      conditionEn: 'Cached allergic rash',
      description: 'ผลวิเคราะห์เดิมจากแคช',
      severity: 'mild',
      possibleCauses: [],
      recommendations: [],
      warnings: [],
      needsDoctor: false,
      doctorReason: null,
      rawResponse: SYMPTOM_JSON_TEXT,
      urgency: false,
      urgencyReason: null,
      urgencyRecommendation: null,
    };

    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM ai_settings')) {
        return [{ gemini_api_key: 'test-gemini-key', model: 'gemini-2.5-flash' }];
      }
      if (sqlText.includes('FROM symptom_analysis_cache')) {
        return [{ analysis_result: JSON.stringify(cachedAnalysis), is_urgent: 0, created_at: '2026-08-01 10:00:00' }];
      }
      return [];
    });

    const res = await POST(req({ image_url: IMAGE_URL }));
    const body = await res.json();

    expect(mockGetImageData).not.toHaveBeenCalled();
    expect(mockCallGeminiVisionApi).not.toHaveBeenCalled();

    // FIX-FORWARD (see _lib/imageAnalyzer.ts's module doc): a cache hit is a
    // functioning success short-circuit, not PHP's literal missing-`success` bug.
    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.success).toBe(true);
    expect(body.data.cached).toBe(true);
    expect(body.data.condition).toBe('ผื่นแพ้เดิม (จากแคช)');
    expect(body.data.imageHash).toBe(sha256(IMAGE_URL));
  });

  it('happy path: full response shape incl. urgency fields and the redundant nested data.success', async () => {
    const queries = wireFakeDb();

    const res = await POST(req({ image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        success: true,
        condition: 'ผื่นแพ้ผิวหนัง',
        conditionEn: 'Allergic dermatitis',
        description: 'มีผื่นแดงคันที่บริเวณแขน',
        severity: 'moderate',
        possibleCauses: ['แพ้อาหาร', 'แพ้สารเคมี'],
        recommendations: [{ type: 'medication', name: 'ยาแก้แพ้ chlorpheniramine', usage: 'รับประทานวันละ 1 เม็ดก่อนนอน' }],
        warnings: ['หากอาการไม่ดีขึ้นภายใน 3 วันให้พบแพทย์'],
        needsDoctor: false,
        doctorReason: null,
        rawResponse: SYMPTOM_JSON_TEXT,
        urgency: false,
        urgencyReason: null,
        urgencyRecommendation: null,
        imageHash: sha256(IMAGE_URL),
      },
    });

    expect(mockGetImageData).toHaveBeenCalledWith(expect.anything(), 3, IMAGE_URL);
    expect(mockCallGeminiVisionApi).toHaveBeenCalledWith({
      apiKey: 'test-gemini-key',
      model: 'gemini-2.5-flash',
      base64: 'ZmFrZS1pbWFnZS1ieXRlcw==',
      mimeType: 'image/jpeg',
      prompt: expect.stringContaining('เภสัชกรผู้เชี่ยวชาญ'),
    });

    const cacheWrite = queries.find((q) => q.sql.includes('INSERT INTO symptom_analysis_cache'));
    expect(cacheWrite).toBeDefined();
  });

  it('urgentConditions keyword match drives isUrgent:true (severity itself stays mild)', async () => {
    wireFakeDb();
    mockCallGeminiVisionApi.mockResolvedValue({
      success: true,
      text: JSON.stringify({
        condition: 'สงสัยหายใจลำบาก',
        conditionEn: 'suspected respiratory distress',
        description: 'ผู้ป่วยเริ่มมีอาการเหนื่อย',
        severity: 'mild',
        possibleCauses: [],
        recommendations: [],
        warnings: [],
        needsDoctor: false,
        doctorReason: null,
      }),
    });

    const res = await POST(req({ image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.urgency).toBe(true);
    expect(body.data.urgencyReason).toContain('หายใจลำบาก');
    expect(body.data.urgencyRecommendation).toBe('⚠️ กรุณาไปพบแพทย์หรือห้องฉุกเฉินทันที');
  });

  it('failure from Gemini -> 400 with result.error passed through verbatim', async () => {
    wireFakeDb();
    mockCallGeminiVisionApi.mockResolvedValue({ success: false, error: 'API Error (500): quota exceeded' });

    const res = await POST(req({ image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'API Error (500): quota exceeded' });
  });

  it('failure from image resolver -> 400 with the resolver error passed through verbatim (Gemini never called)', async () => {
    wireFakeDb();
    mockGetImageData.mockResolvedValue({ success: false, error: 'ไม่พบไฟล์รูปภาพ (404): rash.jpg' });

    const res = await POST(req({ image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'ไม่พบไฟล์รูปภาพ (404): rash.jpg' });
    expect(mockCallGeminiVisionApi).not.toHaveBeenCalled();
  });

  it("route-level DEFAULT_FAILURE_MESSAGE fires only when result.error is truly absent (defensive fallback; analyzeSymptom() itself never omits `error` on a real failure — mirrors PHP's own outer sendError($result['error'] ?? '...') safety net)", async () => {
    let isolatedPost!: typeof POST;
    jest.isolateModules(() => {
      jest.doMock('./_lib/session', () => ({ resolveInboxApiContext: () => mockResolveInboxApiContext() }));
      jest.doMock('./_lib/imageAnalyzer', () => ({
        isConfigured: jest.fn().mockResolvedValue(true),
        analyzeSymptom: jest.fn().mockResolvedValue({ success: false }),
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
