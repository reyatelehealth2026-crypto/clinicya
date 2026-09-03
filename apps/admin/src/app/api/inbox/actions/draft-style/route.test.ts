/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb } from './_lib/testHelpers/fakeTenantDb';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { GET, POST } from './route';

function req(search = ''): NextRequest {
  const url = `https://tenant.re-ya.com/api/inbox/actions/draft-style${search}`;
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

function wireFakeDb(sessionOverrides: Partial<TenantSession> = {}): void {
  const { db } = makeFakeTenantDb();
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/draft-style', () => {
  it('401 JSON when unauthenticated', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?type=A'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it.each(['D', 'a', 'b', 'c', 'ABC', ''])(
    '400 "Invalid communication type. Must be A, B, or C" for type=%j (strict case-sensitive match)',
    async (type) => {
      wireFakeDb();

      const res = await GET(req(`?type=${encodeURIComponent(type)}`));

      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Invalid communication type. Must be A, B, or C' });
    }
  );

  it('defaults to type A when the `type` query param is absent entirely', async () => {
    wireFakeDb();

    const res = await GET(req());
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.type).toBe('A');
  });

  it('type A: exact shape including verbatim Thai tips/opening/closing', async () => {
    wireFakeDb();

    const res = await GET(req('?type=A'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        type: 'A',
        typeName: 'Direct',
        typeNameTh: 'ตรงประเด็น',
        maxWords: 50,
        useEmoji: false,
        includeDetails: false,
        includePrice: true,
        tone: 'professional',
        toneTh: 'มืออาชีพ',
        responseStyle: 'concise',
        tips: [
          'ตอบสั้น กระชับ ตรงประเด็น',
          'บอกชื่อยา ราคา วิธีใช้ ชัดเจน',
          'ไม่ต้องอธิบายรายละเอียดมาก',
          'เสนอทางเลือกไม่เกิน 2-3 ตัว',
        ],
        sampleOpening: 'แนะนำ',
        sampleClosing: 'สนใจตัวไหนแจ้งได้เลยค่ะ',
      },
    });
  });

  it('type B: exact shape including verbatim Thai tips/opening/closing', async () => {
    wireFakeDb();

    const res = await GET(req('?type=B'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        type: 'B',
        typeName: 'Concerned',
        typeNameTh: 'ห่วงใย',
        maxWords: 150,
        useEmoji: true,
        includeDetails: true,
        includePrice: false,
        tone: 'empathetic',
        toneTh: 'เห็นอกเห็นใจ',
        responseStyle: 'reassuring',
        tips: [
          'แสดงความเข้าใจและห่วงใย',
          'อธิบายความปลอดภัยของยา',
          'ให้ความมั่นใจว่าอาการจะดีขึ้น',
          'เปิดโอกาสให้ถามเพิ่มเติม',
        ],
        sampleOpening: 'เข้าใจความกังวลค่ะ 🙏',
        sampleClosing: 'มีอะไรสงสัยถามได้เลยนะคะ ยินดีช่วยเหลือค่ะ 😊',
      },
    });
  });

  it('type C: exact shape including includeComparison/includeScientific and verbatim Thai strings', async () => {
    wireFakeDb();

    const res = await GET(req('?type=C'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        type: 'C',
        typeName: 'Detail-oriented',
        typeNameTh: 'ใส่ใจรายละเอียด',
        maxWords: 300,
        useEmoji: false,
        includeDetails: true,
        includePrice: true,
        includeComparison: true,
        includeScientific: true,
        tone: 'informative',
        toneTh: 'ให้ข้อมูล',
        responseStyle: 'detailed',
        tips: [
          'ให้ข้อมูลครบถ้วน ละเอียด',
          'เปรียบเทียบยาหลายตัว',
          'อธิบายกลไกการออกฤทธิ์',
          'แนบข้อมูลทางวิทยาศาสตร์',
        ],
        sampleOpening: 'ขอให้ข้อมูลเปรียบเทียบดังนี้ค่ะ',
        sampleClosing: 'หากต้องการข้อมูลเพิ่มเติมยินดีค่ะ',
      },
    });
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
