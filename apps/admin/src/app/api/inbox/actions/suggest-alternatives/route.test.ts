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

/** price=100, cost=60 (from cost_price) -> maxDiscount at 10% min margin = 100 - 60/0.9 = 33.333... -> 33.33 */
const DRUG_ROW = { id: 7, name: 'Paracetamol', price: '100.00', sale_price: null, cost_price: '60.00' };

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/suggest-alternatives', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ drug_id: 7, discount: 50 }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Drug ID is required" when drug_id is absent, no DB queries issued', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ discount: 50 }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Discount amount must be greater than 0" when discount is 0', async () => {
    wireFakeDb();
    const res = await POST(req({ drug_id: 7, discount: 0 }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Discount amount must be greater than 0' });
  });

  it('400 when discount is negative', async () => {
    wireFakeDb();
    const res = await POST(req({ drug_id: 7, discount: -5 }));
    expect(res.status).toBe(400);
  });

  it('within threshold: requestedDiscount <= maxDiscount -> exceedsThreshold:false, empty alternatives, "can give as requested" message', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [DRUG_ROW] : []));

    const res = await POST(req({ drug_id: 7, discount: 20 }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        drugId: 7,
        requestedDiscount: 20,
        maxAllowableDiscount: 33.33,
        exceedsThreshold: false,
        excessAmount: 0,
        alternatives: [],
        recommendation: 'สามารถให้ส่วนลดได้ตามที่ขอ',
      },
    });
  });

  it('exceeds threshold: builds the 4 alternatives with exact value formulas', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('FROM business_items') ? [DRUG_ROW] : []));

    // maxDiscount = 33.33; requestedDiscount = 50 -> excessAmount = 16.67
    const res = await POST(req({ drug_id: 7, discount: 50 }));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.exceedsThreshold).toBe(true);
    expect(body.data.excessAmount).toBe(16.67);
    expect(body.data.maxAllowableDiscount).toBe(33.33);
    expect(body.data.alternatives).toEqual([
      { type: 'free_delivery', name: 'ส่งฟรี', description: 'ฟรีค่าจัดส่ง', value: 50.0, icon: 'fa-truck' },
      { type: 'bonus_vitamins', name: 'แถมวิตามิน', description: 'แถมวิตามินซี 10 เม็ด', value: Math.round(16.67 * 0.8 * 100) / 100, icon: 'fa-pills' },
      {
        type: 'loyalty_points',
        name: 'แต้มพิเศษ',
        description: `รับแต้มสะสมเพิ่ม ${Math.ceil(16.67 * 2)} แต้ม`,
        value: Math.ceil(16.67 * 2),
        icon: 'fa-star',
      },
      {
        type: 'next_purchase_discount',
        name: 'ส่วนลดครั้งหน้า',
        description: `รับส่วนลด ฿${Math.round(16.67 * 1.2 * 100) / 100} สำหรับการซื้อครั้งถัดไป`,
        value: Math.round(16.67 * 1.2 * 100) / 100,
        icon: 'fa-ticket',
      },
    ]);
    expect(body.data.recommendation).toBe('ส่วนลดที่ขอเกินกว่าที่กำหนด แนะนำให้เสนอทางเลือกอื่นแทน');
  });

  it('drug not found: success:false but HTTP 200 (literal PHP parity — no explicit status on error payload)', async () => {
    wireFakeDb(() => []);
    const res = await POST(req({ drug_id: 999, discount: 50 }));
    const body = await res.json();
    expect(res.status).toBe(200);
    expect(body).toEqual({ success: false, data: { alternatives: [], exceedsThreshold: false, error: 'Drug not found' } });
  });

  it('GET is method-not-allowed (405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
