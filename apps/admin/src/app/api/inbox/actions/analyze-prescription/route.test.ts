/**
 * @jest-environment node
 */
import { createHash } from 'crypto';
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery, type QueryImpl } from './_lib/testHelpers/fakeTenantDb';

/**
 * route.test.ts — covers `POST /api/inbox/actions/analyze-prescription`.
 * Mocks the two network seams at their SHARED home,
 * `../analyze-symptom/_lib/{geminiVisionClient,imageResolver}` — the real
 * `ocrPrescription()` orchestration (imported from
 * `../analyze-symptom/_lib/imageAnalyzer`) runs end-to-end against a fake
 * DB, with no path to a real network call of either kind.
 */
global.fetch = jest.fn(() => {
  throw new Error('unexpected real network call in analyze-prescription test');
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
const DEFAULT_FAILURE_MESSAGE = 'การอ่านใบสั่งยาล้มเหลว';
const IMAGE_URL = 'https://cdn.example.com/uploads/prescription.jpg';

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
  if (sqlText.includes('FROM users')) {
    return [{ drug_allergies: null, current_medications: null }];
  }
  if (sqlText.includes('FROM drug_interactions')) {
    return [];
  }
  if (sqlText.includes('INSERT INTO prescription_ocr_results')) {
    return { insertId: 1, affectedRows: 1 };
  }
  return [];
};

function wireFakeDb(queryImpl: QueryImpl = defaultQueryImpl, sessionOverrides: Partial<TenantSession> = {}): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const SINGLE_DRUG_JSON_TEXT = JSON.stringify({
  doctor: 'นพ. สมชาย ใจดี',
  hospital: 'โรงพยาบาลตัวอย่าง',
  date: '2026-08-10',
  patientName: 'คุณทดสอบ',
  diagnosis: 'ไข้หวัด',
  drugs: [{ name: 'Amoxicillin', genericName: 'Amoxicillin', dosage: '500mg', frequency: 'วันละ 3 ครั้ง', duration: '5 วัน', quantity: '15 เม็ด', instructions: 'หลังอาหาร' }],
  notes: null,
  confidence: 0.88,
});

beforeEach(() => {
  jest.clearAllMocks();
  mockGetImageData.mockResolvedValue({ success: true, base64: 'ZmFrZS1yeC1ieXRlcw==', mimeType: 'image/jpeg' });
  mockCallGeminiVisionApi.mockResolvedValue({ success: true, text: SINGLE_DRUG_JSON_TEXT });
});

describe('POST /api/inbox/actions/analyze-prescription', () => {
  it('401 JSON when unauthenticated', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ image_url: IMAGE_URL, user_id: 42 }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it("400 'Invalid user ID' fires BEFORE image_url checks — asserted by omitting image_url too and confirming 'Invalid user ID' still wins", async () => {
    wireFakeDb();

    const res = await POST(req({ user_id: 0 }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'Invalid user ID' });
    expect(mockGetImageData).not.toHaveBeenCalled();
  });

  it("400 'Invalid user ID' for a negative user_id, even with a well-formed image_url present", async () => {
    wireFakeDb();

    const res = await POST(req({ user_id: -5, image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it("400 'Invalid image URL format' for a malformed URL once user_id is valid", async () => {
    wireFakeDb();

    const res = await POST(req({ user_id: 42, image_url: 'not-a-url' }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'Invalid image URL format' });
  });

  it("400 'Image URL is required' once user_id is valid but image_url is empty", async () => {
    wireFakeDb();

    const res = await POST(req({ user_id: 42, image_url: '' }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'Image URL is required' });
  });

  it('503 with the exact long Thai message when AI is not configured', async () => {
    wireFakeDb(() => []);
    const savedEnv = process.env.GEMINI_API_KEY;
    delete process.env.GEMINI_API_KEY;

    try {
      const res = await POST(req({ user_id: 42, image_url: IMAGE_URL }));
      const body = await res.json();

      expect(res.status).toBe(503);
      expect(body).toEqual({ success: false, error: AI_NOT_CONFIGURED_MESSAGE });
    } finally {
      if (savedEnv === undefined) delete process.env.GEMINI_API_KEY;
      else process.env.GEMINI_API_KEY = savedEnv;
    }
  });

  it('happy path (single drug): interactions[] stays empty (only 1 drug), allergyWarnings[] empty (no allergies on file)', async () => {
    wireFakeDb();

    const res = await POST(req({ user_id: 42, image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        success: true,
        doctor: 'นพ. สมชาย ใจดี',
        hospital: 'โรงพยาบาลตัวอย่าง',
        date: '2026-08-10',
        patientName: 'คุณทดสอบ',
        diagnosis: 'ไข้หวัด',
        drugs: [{ name: 'Amoxicillin', genericName: 'Amoxicillin', dosage: '500mg', frequency: 'วันละ 3 ครั้ง', duration: '5 วัน', quantity: '15 เม็ด', instructions: 'หลังอาหาร' }],
        notes: null,
        confidence: 0.88,
        rawResponse: SINGLE_DRUG_JSON_TEXT,
        interactions: [],
        allergyWarnings: [],
        imageHash: sha256(IMAGE_URL),
      },
    });
  });

  it('interactions[] populated only when >1 drug is parsed', async () => {
    mockCallGeminiVisionApi.mockResolvedValue({
      success: true,
      text: JSON.stringify({
        doctor: null,
        hospital: null,
        date: null,
        patientName: null,
        diagnosis: null,
        drugs: [
          { name: 'Warfarin', genericName: 'Warfarin' },
          { name: 'Aspirin', genericName: 'Aspirin' },
        ],
        notes: null,
        confidence: 0.7,
      }),
    });
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM ai_settings')) return [{ gemini_api_key: 'k', model: 'gemini-2.5-flash' }];
      if (sqlText.includes('FROM users')) return [{ drug_allergies: null, current_medications: null }];
      if (sqlText.includes('FROM drug_interactions')) {
        return [{ severity: 'severe', description: 'เพิ่มความเสี่ยงเลือดออก', recommendation: 'หลีกเลี่ยงการใช้ร่วมกัน' }];
      }
      return [];
    });

    const res = await POST(req({ user_id: 42, image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.interactions).toEqual([
      { drug1: 'Warfarin', drug2: 'Aspirin', severity: 'severe', description: 'เพิ่มความเสี่ยงเลือดออก', recommendation: 'หลีกเลี่ยงการใช้ร่วมกัน' },
    ]);
  });

  it('allergyWarnings[] populated with the exact Thai warning template when the user has a matching drug_allergies entry', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM ai_settings')) return [{ gemini_api_key: 'k', model: 'gemini-2.5-flash' }];
      if (sqlText.includes('FROM users')) return [{ drug_allergies: 'amoxicillin, penicillin', current_medications: null }];
      return [];
    });

    const res = await POST(req({ user_id: 42, image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.allergyWarnings).toEqual([
      {
        drug: 'Amoxicillin',
        allergy: 'amoxicillin',
        message: '⚠️ ลูกค้าแพ้ยา amoxicillin - ยา Amoxicillin อาจไม่ปลอดภัย',
      },
    ]);
  });

  it('failure from Gemini -> 400 with result.error passed through verbatim', async () => {
    wireFakeDb();
    mockCallGeminiVisionApi.mockResolvedValue({ success: false, error: 'API Error (400): invalid image' });

    const res = await POST(req({ user_id: 42, image_url: IMAGE_URL }));
    const body = await res.json();

    expect(res.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'API Error (400): invalid image' });
  });

  it('route-level DEFAULT_FAILURE_MESSAGE fires only when result.error is truly absent', async () => {
    let isolatedPost!: typeof POST;
    jest.isolateModules(() => {
      jest.doMock('./_lib/session', () => ({ resolveInboxApiContext: () => mockResolveInboxApiContext() }));
      jest.doMock('../analyze-symptom/_lib/imageAnalyzer', () => ({
        isConfigured: jest.fn().mockResolvedValue(true),
        ocrPrescription: jest.fn().mockResolvedValue({ success: false }),
      }));
      // eslint-disable-next-line @typescript-eslint/no-var-requires
      isolatedPost = (require('./route') as typeof import('./route')).POST;
    });

    wireFakeDb();
    const res = await isolatedPost(req({ user_id: 42, image_url: IMAGE_URL }));
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
