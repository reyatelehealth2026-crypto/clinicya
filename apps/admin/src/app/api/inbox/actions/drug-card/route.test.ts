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
  const url = `https://tenant.re-ya.com/api/inbox/actions/drug-card${search}`;
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

function wireFakeDb(rows: unknown[] = []): RecordedQuery[] {
  const queryImpl = (sqlText: string) => (sqlText.includes('FROM business_items') ? rows : []);
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
  return queries;
}

const BASE_ROW = {
  id: 7,
  name: 'Paracetamol 500mg',
  generic_name: null,
  price: '30.00',
  sale_price: null,
  stock: 20,
  image_url: null,
  is_prescription: 0,
  dosage: null,
  usage_instructions: null,
  side_effects: null,
  contraindications: null,
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/drug-card', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(req('?drug_id=7'));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Drug ID is required" when drug_id/id both absent, no DB queries issued', async () => {
    const queries = wireFakeDb();
    const res = await GET(req());
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Drug ID is required' });
    expect(queries).toHaveLength(0);
  });

  it('drug-not-found: the literal "not found" bubble', async () => {
    wireFakeDb([]);
    const res = await GET(req('?drug_id=999'));
    const body = await res.json();
    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        type: 'bubble',
        body: {
          type: 'box',
          layout: 'vertical',
          contents: [{ type: 'text', text: '❌ ไม่พบข้อมูลยา', weight: 'bold', color: '#EF4444' }],
        },
      },
    });
  });

  it('in-stock, non-prescription: cart button, no prescription badge, no interaction with buttons beyond add+check', async () => {
    wireFakeDb([BASE_ROW]);
    const res = await GET(req('?drug_id=7'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        type: 'bubble',
        size: 'mega',
        body: {
          type: 'box',
          layout: 'vertical',
          paddingAll: 'lg',
          contents: [
            { type: 'text', text: 'Paracetamol 500mg', weight: 'bold', size: 'lg', wrap: true },
            {
              type: 'box',
              layout: 'horizontal',
              margin: 'lg',
              contents: [{ type: 'text', text: '฿30.00', size: 'xl', weight: 'bold', color: '#06C755' }],
            },
            { type: 'text', text: '📦 เหลือ 20 ชิ้น', size: 'xs', color: '#888888', margin: 'md' },
          ],
        },
        footer: {
          type: 'box',
          layout: 'vertical',
          paddingAll: 'lg',
          contents: [
            {
              type: 'button',
              action: { type: 'message', label: '🛒 เพิ่มลงตะกร้า', text: 'add 7' },
              style: 'primary',
              color: '#06C755',
            },
            {
              type: 'button',
              action: { type: 'message', label: '🔍 ตรวจสอบยาตีกัน', text: 'check interaction 7' },
              style: 'secondary',
              margin: 'sm',
            },
          ],
        },
      },
    });
  });

  it('in-stock, prescription: consult button (not cart), prescription badge shown', async () => {
    wireFakeDb([{ ...BASE_ROW, is_prescription: 1 }]);
    const res = await GET(req('?drug_id=7'));
    const body = await res.json();

    expect(body.data.body.contents).toContainEqual({
      type: 'box',
      layout: 'horizontal',
      contents: [{ type: 'text', text: '💊 ยาควบคุมพิเศษ', size: 'xs', color: '#FFFFFF', align: 'center' }],
      backgroundColor: '#EF4444',
      cornerRadius: 'md',
      paddingAll: 'xs',
      margin: 'md',
      width: '120px',
    });
    expect(body.data.footer.contents).toEqual([
      { type: 'button', action: { type: 'message', label: '💬 ปรึกษาเภสัชกร', text: 'consult 7' }, style: 'primary', color: '#3B82F6' },
      { type: 'button', action: { type: 'message', label: '🔍 ตรวจสอบยาตีกัน', text: 'check interaction 7' }, style: 'secondary', margin: 'sm' },
    ]);
  });

  it('out-of-stock, non-prescription: stock text/color flip, no cart button (only the check-interaction button)', async () => {
    wireFakeDb([{ ...BASE_ROW, stock: 0 }]);
    const res = await GET(req('?drug_id=7'));
    const body = await res.json();

    expect(body.data.body.contents).toContainEqual({ type: 'text', text: '❌ สินค้าหมด', size: 'xs', color: '#EF4444', margin: 'md' });
    expect(body.data.footer.contents).toEqual([
      { type: 'button', action: { type: 'message', label: '🔍 ตรวจสอบยาตีกัน', text: 'check interaction 7' }, style: 'secondary', margin: 'sm' },
    ]);
  });

  it('has-discount: sale_price truthy and lower than price -> strikethrough original price shown', async () => {
    wireFakeDb([{ ...BASE_ROW, price: '50.00', sale_price: '30.00' }]);
    const res = await GET(req('?drug_id=7'));
    const body = await res.json();

    const priceBox = body.data.body.contents.find((c: { layout?: string; margin?: string }) => c.layout === 'horizontal' && c.margin === 'lg');
    expect(priceBox.contents).toEqual([
      { type: 'text', text: '฿30.00', size: 'xl', weight: 'bold', color: '#06C755' },
      { type: 'text', text: '฿50.00', size: 'sm', color: '#AAAAAA', decoration: 'line-through', margin: 'sm' },
    ]);
  });

  it('sale_price equal to price is NOT a discount (hasDiscount requires strictly greater)', async () => {
    wireFakeDb([{ ...BASE_ROW, price: '30.00', sale_price: '30.00' }]);
    const res = await GET(req('?drug_id=7'));
    const body = await res.json();
    const priceBox = body.data.body.contents.find((c: { layout?: string; margin?: string }) => c.layout === 'horizontal' && c.margin === 'lg');
    expect(priceBox.contents).toHaveLength(1);
  });

  it('hero-image-present: hero block added when image_url is set', async () => {
    wireFakeDb([{ ...BASE_ROW, image_url: 'https://cdn.example.com/drug.jpg' }]);
    const res = await GET(req('?drug_id=7'));
    const body = await res.json();
    expect(body.data.hero).toEqual({ type: 'image', url: 'https://cdn.example.com/drug.jpg', size: 'full', aspectRatio: '4:3', aspectMode: 'cover' });
  });

  it('no hero image when image_url is absent', async () => {
    wireFakeDb([BASE_ROW]);
    const res = await GET(req('?drug_id=7'));
    const body = await res.json();
    expect(body.data.hero).toBeUndefined();
  });

  it('info rows (dosage/usage/side effects/contraindications) appended after a separator, in literal order', async () => {
    wireFakeDb([
      {
        ...BASE_ROW,
        dosage: '500mg ทุก 6 ชั่วโมง',
        usage_instructions: 'รับประทานหลังอาหาร',
        side_effects: 'คลื่นไส้',
        contraindications: 'แพ้พาราเซตามอล',
      },
    ]);
    const res = await GET(req('?drug_id=7'));
    const body = await res.json();

    const contents = body.data.body.contents;
    const separatorIdx = contents.findIndex((c: { type: string }) => c.type === 'separator');
    expect(separatorIdx).toBeGreaterThan(-1);
    expect(contents.slice(separatorIdx + 1)).toEqual([
      {
        type: 'box',
        layout: 'vertical',
        contents: [
          { type: 'text', text: '💊 ขนาดยา', size: 'xs', color: '#888888' },
          { type: 'text', text: '500mg ทุก 6 ชั่วโมง', size: 'sm', wrap: true, margin: 'xs' },
        ],
        margin: 'lg',
      },
      {
        type: 'box',
        layout: 'vertical',
        contents: [
          { type: 'text', text: '📋 วิธีใช้', size: 'xs', color: '#888888' },
          { type: 'text', text: 'รับประทานหลังอาหาร', size: 'sm', wrap: true, margin: 'xs' },
        ],
        margin: 'lg',
      },
      {
        type: 'box',
        layout: 'vertical',
        contents: [
          { type: 'text', text: '⚠️ ผลข้างเคียง', size: 'xs', color: '#888888' },
          { type: 'text', text: 'คลื่นไส้', size: 'sm', wrap: true, margin: 'xs' },
        ],
        margin: 'lg',
      },
      {
        type: 'box',
        layout: 'vertical',
        contents: [
          { type: 'text', text: '🚫 ข้อห้ามใช้', size: 'xs', color: '#888888' },
          { type: 'text', text: 'แพ้พาราเซตามอล', size: 'sm', wrap: true, margin: 'xs' },
        ],
        margin: 'lg',
      },
    ]);
  });

  it('generic name shown in parentheses when present', async () => {
    wireFakeDb([{ ...BASE_ROW, generic_name: 'Acetaminophen' }]);
    const res = await GET(req('?drug_id=7'));
    const body = await res.json();
    expect(body.data.body.contents).toContainEqual({ type: 'text', text: '(Acetaminophen)', size: 'sm', color: '#888888', margin: 'sm' });
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
