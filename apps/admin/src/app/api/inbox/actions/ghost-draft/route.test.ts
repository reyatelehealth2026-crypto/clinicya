/**
 * @jest-environment node
 */

// Belt-and-suspenders guard: if any code path slips past the intended
// `./_lib/geminiTextClient` mock below, a real `fetch()` call fails the
// test loudly instead of attempting a live network request.
global.fetch = jest.fn(() => {
  throw new Error('unexpected real network call in ghost-draft test');
}) as unknown as typeof fetch;

const mockCallGeminiTextApi = jest.fn();
jest.mock('./_lib/geminiTextClient', () => ({
  callGeminiTextApi: (...args: unknown[]) => mockCallGeminiTextApi(...args),
}));

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';

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

const AI_SETTINGS_ROW = { gemini_api_key: 'test-key-123', model: 'gemini-2.0-flash' };

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown = () => []): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

/** Wires ai_settings to a configured key by default; other tables answer `extra` or `[]`. */
function wireConfiguredDb(extra: (sqlText: string, params: unknown[]) => unknown = () => []): RecordedQuery[] {
  return wireFakeDb((sqlText, params) => {
    if (sqlText.includes('FROM ai_settings')) return [AI_SETTINGS_ROW];
    return extra(sqlText, params);
  });
}

const ORIGINAL_GEMINI_API_KEY = process.env.GEMINI_API_KEY;

beforeEach(() => {
  jest.clearAllMocks();
  delete process.env.GEMINI_API_KEY;
});

afterAll(() => {
  if (ORIGINAL_GEMINI_API_KEY === undefined) {
    delete process.env.GEMINI_API_KEY;
  } else {
    process.env.GEMINI_API_KEY = ORIGINAL_GEMINI_API_KEY;
  }
});

describe('POST /api/inbox/actions/ghost-draft', () => {
  it('401 JSON when unauthenticated, Gemini never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ user_id: 1, message: 'hi' }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockCallGeminiTextApi).not.toHaveBeenCalled();
  });

  it('400 "User ID is required" when user_id is 0 (the only value that fails `!$userId`)', async () => {
    const queries = wireFakeDb();

    const res = await POST(req({ user_id: 0, message: 'hi' }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
    expect(mockCallGeminiTextApi).not.toHaveBeenCalled();
  });

  it('a NEGATIVE user_id is accepted at the user-id gate (no `<= 0` guard here, unlike customer-health/classify-customer) — reaches the message check next', async () => {
    wireFakeDb();

    const res = await POST(req({ user_id: -5 }));

    expect(res.status).toBe(400);
    // Reaching "Message is required" (not "User ID is required") proves -5 passed the `!$userId` gate.
    expect(await res.json()).toEqual({ success: false, error: 'Message is required' });
  });

  it('400 "Message is required" when message is missing/empty', async () => {
    wireFakeDb();

    for (const body of [{ user_id: 1 }, { user_id: 1, message: '' }, { user_id: 1, message: '0' }]) {
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Message is required' });
    }
  });

  it('503 "AI API key not configured" (exact short string, no Thai suffix) when isConfigured() is false', async () => {
    const queries = wireFakeDb(() => []); // ai_settings: no row; process.env.GEMINI_API_KEY unset (beforeEach)

    const res = await POST(req({ user_id: 1, message: 'สวัสดีค่ะ' }));

    expect(res.status).toBe(503);
    expect(await res.json()).toEqual({ success: false, error: 'AI API key not configured' });
    expect(mockCallGeminiTextApi).not.toHaveBeenCalled();
    expect(queries.some((q) => q.sql.includes('FROM ai_settings'))).toBe(true);
  });

  it('200-with-success:false envelope when geminiTextClient returns success:false — HTTP status stays 200 (unconditional-200 contract)', async () => {
    wireConfiguredDb();
    mockCallGeminiTextApi.mockResolvedValue({ success: false, error: 'API Error (500): server error' });

    const res = await POST(req({ user_id: 1, message: 'สวัสดีค่ะ' }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(false);
    expect(body.data).toEqual({
      success: false,
      error: 'API Error (500): server error',
      draft: null,
      confidence: 0.0,
      alternatives: [],
      disclaimer: null,
      generationTimeMs: expect.any(Number),
    });
  });

  it('200-with-success:true happy path — full response shape, no prescription drug mentioned -> disclaimer:null', async () => {
    wireConfiguredDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) return []; // no matching product rows
      return [];
    });
    mockCallGeminiTextApi.mockResolvedValue({
      success: true,
      text: JSON.stringify({
        draft: 'ทานยาลดไข้ครบตามที่แนะนำนะคะ',
        confidence: 0.9,
        alternatives: ['ยาอีกตัว'],
        mentionedDrugs: [],
      }),
    });

    const res = await POST(req({ user_id: 1, message: 'ปวดหัวค่ะ' }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    // learning history is empty -> getPredictionConfidence() base 0.5;
    // combined: 0.9*0.5 + 0.5*0.5 = 0.7
    expect(body.data).toEqual({
      success: true,
      draft: 'ทานยาลดไข้ครบตามที่แนะนำนะคะ',
      confidence: 0.7,
      alternatives: ['ยาอีกตัว'],
      disclaimer: null,
      mentionedDrugs: [],
      communicationType: 'A',
      draftStyle: {
        type: 'A',
        typeName: 'Direct',
        typeNameTh: 'ตรงประเด็น',
        maxWords: 50,
        useEmoji: false,
        includeDetails: false,
        tone: 'professional',
        toneTh: 'มืออาชีพ',
      },
      generationTimeMs: expect.any(Number),
      withinTimeout: true,
    });
  });

  it('disclaimer-appended case: a mentioned drug matches the prescriptionDrugs list -> disclaimer set, draft field itself left unmodified', async () => {
    wireConfiguredDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) return [{ name: 'Amoxicillin', sku: 'AMX100' }];
      return [];
    });
    const draft = 'แนะนำ Amoxicillin 500mg รับประทานวันละ 3 ครั้งค่ะ';
    mockCallGeminiTextApi.mockResolvedValue({
      success: true,
      text: JSON.stringify({ draft, confidence: 0.85, alternatives: ['Augmentin'], mentionedDrugs: ['Amoxicillin'] }),
    });

    const res = await POST(req({ user_id: 1, message: 'มีไข้ค่ะ' }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.draft).toBe(draft); // NOT the disclaimer-appended text — draft field stays the raw AI draft
    expect(body.data.disclaimer).toBe('⚠️ หมายเหตุ: ยานี้เป็นยาที่ต้องใช้ตามคำสั่งแพทย์ กรุณาปรึกษาแพทย์หรือเภสัชกรก่อนใช้ยา');
    expect(body.data.mentionedDrugs).toEqual([{ name: 'Amoxicillin', sku: 'AMX100' }]);
    // 0.85*0.5 + 0.5*0.5 = 0.675 -> round(2) = 0.68
    expect(body.data.confidence).toBe(0.68);
  });

  it('context-as-JSON-string parsing: a JSON-encoded string `context` is decoded and threaded into the prompt', async () => {
    wireConfiguredDb();
    mockCallGeminiTextApi.mockResolvedValue({ success: true, text: JSON.stringify({ draft: 'โอเคค่ะ', confidence: 0.6 }) });

    const res = await POST(req({ user_id: 1, message: 'จะซื้อยาตัวนี้ค่ะ', context: JSON.stringify({ stage: 'purchase' }) }));

    expect(res.status).toBe(200);
    expect(mockCallGeminiTextApi).toHaveBeenCalledTimes(1);
    const [{ prompt }] = mockCallGeminiTextApi.mock.calls[0] as [{ prompt: string }];
    expect(prompt).toContain('[ขั้นตอนปัจจุบัน]: ตัดสินใจซื้อ');
  });

  it('invalid JSON string context falls back to {} rather than throwing', async () => {
    wireConfiguredDb();
    mockCallGeminiTextApi.mockResolvedValue({ success: true, text: JSON.stringify({ draft: 'โอเคค่ะ', confidence: 0.6 }) });

    const res = await POST(req({ user_id: 1, message: 'สวัสดี', context: '{not valid json' }));

    expect(res.status).toBe(200);
    expect((await res.json()).success).toBe(true);
  });

  it('GET is method-not-allowed (405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
