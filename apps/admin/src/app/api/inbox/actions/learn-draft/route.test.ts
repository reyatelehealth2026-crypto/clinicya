/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { calculateEditDistance, unicodeLevenshtein } from './_lib/learnDraft';

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

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown = () => []): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('calculateEditDistance() / unicodeLevenshtein() — multi-byte Thai fixtures', () => {
  it('Thai strings correctly route through the Unicode code-point DP, not the byte-length ASCII fast path', () => {
    // "สวัสดีครับ" = "สวัสดี" + "ครับ" (4 trailing code points appended: ค,ร,ั,บ).
    // Hand-computed: distance(A, A+suffix(N)) is provably exactly N (append-only
    // edit achieves it; the string-length difference N is also a hard lower bound).
    const a = 'สวัสดี';
    const b = 'สวัสดีครับ';

    expect(unicodeLevenshtein(a, b)).toBe(4);
    expect(calculateEditDistance(a, b)).toBe(4);
  });

  it('identical Thai strings -> distance 0', () => {
    expect(calculateEditDistance('ขอบคุณค่ะ', 'ขอบคุณค่ะ')).toBe(0);
  });

  it('pure-ASCII short strings use the fast path and agree with the Unicode path', () => {
    expect(calculateEditDistance('kitten', 'sitting')).toBe(3); // classic textbook fixture
    expect(unicodeLevenshtein('kitten', 'sitting')).toBe(3);
  });
});

describe('POST /api/inbox/actions/learn-draft', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ user_id: 1, original_draft: 'a', final_message: 'a' }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "User ID is required" when user_id is 0 (the only value that fails `!$userId`)', async () => {
    const queries = wireFakeDb();

    const res = await POST(req({ user_id: 0, original_draft: 'a', final_message: 'b' }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('a NEGATIVE user_id is accepted at the user-id gate (no `<= 0` guard) — reaches the draft/message check next', async () => {
    wireFakeDb();

    const res = await POST(req({ user_id: -5, original_draft: '', final_message: '' }));

    expect(res.status).toBe(400);
    // Reaching the draft/message validation error (not "User ID is required") proves -5 passed the `!$userId` gate.
    expect(await res.json()).toEqual({ success: false, error: 'Original draft and final message are required' });
  });

  it.each([
    [{ user_id: 1, original_draft: '', final_message: 'x' }],
    [{ user_id: 1, original_draft: 'x', final_message: '' }],
    [{ user_id: 1, original_draft: '0', final_message: 'x' }], // PHP empty('0') === true
    [{ user_id: 1, original_draft: 'x', final_message: '0' }],
    [{ user_id: 1, original_draft: undefined, final_message: 'x' }],
  ])('400 "Original draft and final message are required" for body=%j', async (body) => {
    wireFakeDb();

    const res = await POST(req(body));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Original draft and final message are required' });
  });

  it('happy path (identical strings -> edit distance 0 -> was_accepted true): exact INSERT column values, always-200', async () => {
    const queries = wireFakeDb();
    const draft = 'ทานยาตามที่แนะนำค่ะ';

    const res = await POST(req({ user_id: 42, original_draft: draft, final_message: draft }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, message: 'Learning data saved successfully' });
    expect(body.data).toBeUndefined(); // no `data` key at all, unlike every sibling action

    const insertQuery = queries.find((q) => q.sql.toLowerCase().includes('insert into `pharmacy_ghost_learning`'));
    expect(insertQuery).toBeDefined();
    expect(insertQuery!.params).toEqual([
      42,
      '', // customerMessage: no context.customerMessage given, getLastCustomerMessage() found no rows -> ''
      draft,
      draft,
      0, // edit distance
      1, // was_accepted (identical strings -> 0/len < 0.2 -> true)
      JSON.stringify({ stage: null, healthProfile: null, symptoms: null, communicationType: null }),
      JSON.stringify([]), // mentioned_drugs: no business_items rows configured
    ]);
  });

  it('wildly different strings -> was_accepted false (edit distance / length ratio >= 0.2)', async () => {
    const queries = wireFakeDb();

    const res = await POST(
      req({
        user_id: 42,
        original_draft: 'สวัสดีค่ะ',
        final_message: 'ขอบคุณสำหรับคำถามนะคะ ยินดีให้บริการค่ะ มีอะไรให้ช่วยเพิ่มเติมไหมคะ',
      })
    );
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, message: 'Learning data saved successfully' });

    const insertQuery = queries.find((q) => q.sql.toLowerCase().includes('insert into `pharmacy_ghost_learning`'));
    expect(insertQuery!.params[5]).toBe(0); // was_accepted = false
  });

  it('always-200 on failure too: a DB error on the INSERT itself surfaces as success:false, status still 200', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.toLowerCase().includes('insert into `pharmacy_ghost_learning`')) {
        throw new Error('connection lost');
      }
      return [];
    });

    const res = await POST(req({ user_id: 42, original_draft: 'a', final_message: 'b' }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'Failed to save learning data' });
  });

  it('context.customerMessage, when provided, is used verbatim instead of querying getLastCustomerMessage()', async () => {
    const queries = wireFakeDb();

    await POST(
      req({
        user_id: 42,
        original_draft: 'a',
        final_message: 'b',
        context: { customerMessage: 'ลูกค้าถามว่าเป็นอะไร' },
      })
    );

    const insertQuery = queries.find((q) => q.sql.toLowerCase().includes('insert into `pharmacy_ghost_learning`'));
    expect(insertQuery!.params[1]).toBe('ลูกค้าถามว่าเป็นอะไร');
    expect(queries.some((q) => q.sql.toLowerCase().includes('direction = \'incoming\''))).toBe(false);
  });

  it('context-as-JSON-string parsing is supported (same is_string($context) handling as ghost-draft)', async () => {
    wireFakeDb();

    const res = await POST(
      req({
        user_id: 42,
        original_draft: 'a',
        final_message: 'b',
        context: JSON.stringify({ stage: 'follow_up' }),
      })
    );

    expect(res.status).toBe(200);
    expect((await res.json()).success).toBe(true);
  });

  it('GET is method-not-allowed (405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
