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
  const url = `https://tenant.re-ya.com/api/inbox/actions/classify-customer${search}`;
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

/** Wires COUNT(*) -> `count` and recent-messages -> `messages` (array of `{content}`). */
function wireMessages(count: number, messages: string[]): RecordedQuery[] {
  return wireFakeDb((sqlText) => {
    if (sqlText.includes('COUNT(*)')) return [{ count }];
    if (sqlText.includes('FROM messages')) return messages.map((content) => ({ content, message_type: 'text', created_at: new Date() }));
    return [];
  });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/classify-customer', () => {
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

    const res = await GET(req('?user_id=0'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('insufficient-data branch: messageCount < minMessages -> type A, confidence 0.0, insufficientData:true, still detects emotion from the latest message', async () => {
    wireMessages(2, ['หัวร้อนมากเลยค่ะ']); // "angry" keyword present ('หัวร้อน')

    const res = await GET(req('?user_id=1')); // default min_messages=5, messageCount=2 < 5

    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body).toEqual({
      success: true,
      data: {
        type: 'A',
        confidence: 0.0,
        tips: getDraftStyle('A').tips,
        messageCount: 2,
        minRequired: 5,
        insufficientData: true,
        emotion: 'angry',
      },
    });
  });

  it('insufficient-data branch: zero messages at all -> emotion stays neutral (no message to detect from)', async () => {
    wireMessages(0, []);

    const res = await GET(req('?user_id=1&min_messages=1'));
    const body = await res.json();

    expect(body.data.insufficientData).toBe(true);
    expect(body.data.emotion).toBe('neutral');
    expect(body.data.messageCount).toBe(0);
    expect(body.data.minRequired).toBe(1);
  });

  it('respects an explicit min_messages query param', async () => {
    wireMessages(3, ['สวัสดีค่ะ', 'สวัสดีค่ะ', 'สวัสดีค่ะ']);

    const res = await GET(req('?user_id=1&min_messages=10'));
    const body = await res.json();

    expect(body.data.insufficientData).toBe(true);
    expect(body.data.minRequired).toBe(10);
  });

  it('type A corpus: short, urgent, non-polite messages deterministically classify as Type A via the real scoring formula', async () => {
    const messages = ['ด่วน', 'รีบ', 'เร็ว', 'ตอนนี้', 'ทันที'];
    const queries = wireMessages(5, messages);

    const res = await GET(req('?user_id=77'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.insufficientData).toBe(false);
    expect(body.data.type).toBe('A');
    expect(body.data.messageCount).toBe(5);
    expect(body.data.tips).toEqual(getDraftStyle('A').tips);
    // Hand-computed: every message hits an A-positive keyword (+1.0 each) and 3 of
    // the 5 also hit B/C's shared negative list (-0.3 each) -> raw A=6.0 (5 keyword
    // hits + 1.0 short-message-length bonus), raw B=raw C=-0.9; normalized against
    // maxScore=6.0 -> A=1.0, B=C=-0.15; confidence: diff=1.0-(-0.15)=1.15 -> capped at 1.0.
    expect(body.data.scores).toEqual({ A: 1, B: -0.15, C: -0.15 });
    expect(body.data.confidence).toBe(1);

    // saveProfile() INSERT ... ON DUPLICATE KEY UPDATE — exact column values.
    const saveQuery = queries.find((q) => q.sql.toLowerCase().includes('insert into `customer_health_profiles`'));
    expect(saveQuery).toBeDefined();
    const tipsJson = JSON.stringify(getDraftStyle('A').tips);
    expect(saveQuery!.params).toEqual([77, 'A', 1, tipsJson, 5, 'A', 1, tipsJson, 5]);
  });

  it('type B corpus: concerned, polite, question-heavy messages deterministically classify as Type B', async () => {
    const messages = [
      'กังวลเรื่องนี้ค่ะ',
      'กลัวว่าจะแพ้ยาค่ะ',
      'ไม่แน่ใจว่าปลอดภัยไหมคะ',
      'ขอบคุณที่ช่วยตอบค่ะ',
      'เป็นอะไรหรือเปล่าคะ',
    ];
    wireMessages(5, messages);

    const res = await GET(req('?user_id=88'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.insufficientData).toBe(false);
    expect(body.data.type).toBe('B');
    expect(body.data.tips).toEqual(getDraftStyle('B').tips);
    // B's raw keyword score (~8.0 from concern/reassurance keywords + 1.5 politeness
    // bonus, all 5 messages carry a polite marker) dwarfs A's (-0.6 from shared
    // negative-keyword hits) and C's (0) -> B is the normalized max (1.0).
    expect(body.data.scores.B).toBe(1);
    expect(body.data.scores.B).toBeGreaterThan(body.data.scores.A);
    expect(body.data.scores.B).toBeGreaterThan(body.data.scores.C);
  });

  it('type C corpus: detail/comparison-heavy messages deterministically classify as Type C', async () => {
    const messages = [
      'อยากทราบรายละเอียดและกลไกการออกฤทธิ์ของยา',
      'เปรียบเทียบยาสองตัวนี้ต่างกันอย่างไร',
      'ยี่ห้อไหนดีกว่ากันคะ ขอข้อมูลส่วนประกอบด้วย',
      'มีหลักฐานงานวิจัยรองรับไหม อยากรู้ข้อมูลเพิ่มเติม',
      'ตัวไหนเหมาะกับอาการนี้มากกว่ากัน ขอทราบรายละเอียด',
    ];
    wireMessages(5, messages);

    const res = await GET(req('?user_id=99'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.insufficientData).toBe(false);
    expect(body.data.type).toBe('C');
    expect(body.data.tips).toEqual(getDraftStyle('C').tips);
    expect(body.data.scores.C).toBe(1);
    expect(body.data.scores.C).toBeGreaterThan(body.data.scores.A);
    expect(body.data.scores.C).toBeGreaterThan(body.data.scores.B);
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
