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
  const url = `https://tenant.re-ya.com/api/inbox/actions/quick-actions${search}`;
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

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

function messageRow(content: string, direction: 'incoming' | 'outgoing' = 'incoming') {
  return { content, message_type: 'text', direction, created_at: new Date('2026-08-13T10:00:00Z') };
}

function wireMessages(messages: ReturnType<typeof messageRow>[]) {
  return wireFakeDb((sqlText) => {
    if (sqlText.toLowerCase().includes('from messages')) return messages;
    if (sqlText.toLowerCase().includes('insert into consultation_stages')) return { insertId: 1, affectedRows: 1 };
    return [];
  });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/quick-actions', () => {
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

  it('stage explicitly provided -> detectStage() is NEVER called (zero DB queries at all)', async () => {
    const queries = wireFakeDb();

    const res = await GET(req('?user_id=42&stage=purchase'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.stage).toBe('purchase');
    expect(queries).toHaveLength(0);
  });

  it('stage empty + userId set -> calls detectStage(), adopts its stage AND its hasUrgentSymptoms (ignoring a has_urgent=true query param)', async () => {
    // Detected stage: purchase, no urgent keywords -> hasUrgentSymptoms false from detection.
    const queries = wireMessages([messageRow('อยากสั่งซื้อยาค่ะ ราคาเท่าไหร่')]);

    const res = await GET(req('?user_id=42&has_urgent=true'));
    const body = await res.json();

    expect(body.data.stage).toBe('purchase');
    expect(body.data.hasUrgentSymptoms).toBe(false); // adopted from detectStage(), NOT the has_urgent=true param
    expect(body.data.actions[0].id).not.toBe('recommend_hospital');
    expect(queries.some((q) => q.sql.toLowerCase().includes('from messages'))).toBe(true);
  });

  it('stage empty, detectStage finds 0 messages -> falls back to symptom_assessment (detectStage\'s own default)', async () => {
    wireMessages([]);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.stage).toBe('symptom_assessment');
    expect(body.data.hasUrgentSymptoms).toBe(false);
  });

  it('stage empty AND detectStage detects real urgent symptoms -> hasUrgentSymptoms true is adopted, recommend_hospital is unshifted first', async () => {
    wireMessages([messageRow('เจ็บหน้าอกมากค่ะ')]);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.hasUrgentSymptoms).toBe(true);
    expect(body.data.actions[0].id).toBe('recommend_hospital');
    expect(body.data.actions[0].priority).toBe(100);
  });

  it('has_urgent=true (with stage explicitly provided, no detection) unshifts recommend_hospital with priority 100 and the exact Thai template, re-sorted first', async () => {
    const queries = wireFakeDb();

    const res = await GET(req('?user_id=42&stage=purchase&has_urgent=true'));
    const body = await res.json();

    expect(queries).toHaveLength(0); // stage provided -> detectStage never called
    expect(body.data.hasUrgentSymptoms).toBe(true);
    expect(body.data.actions[0]).toEqual({
      id: 'recommend_hospital',
      label: '🚨 แนะนำพบแพทย์ด่วน',
      labelEn: '🚨 Recommend Hospital Visit',
      icon: '🏥',
      action: 'recommend_hospital',
      template: '⚠️ จากอาการที่แจ้งมา แนะนำให้พบแพทย์โดยเร็วค่ะ เพื่อความปลอดภัย กรุณาไปโรงพยาบาลหรือคลินิกใกล้บ้านค่ะ',
      isUrgent: true,
      priority: 100,
      highlight: true,
    });
    // The rest of the purchase-stage list follows, still priority-sorted.
    expect(body.data.actions.map((a: { id: string }) => a.id)).toEqual([
      'recommend_hospital',
      'create_order',
      'send_payment_link',
      'schedule_delivery',
      'apply_points',
    ]);
  });

  describe('has_urgent FILTER_VALIDATE_BOOLEAN parsing (stage always explicit so detectStage never overrides it)', () => {
    const truthy = ['1', 'true', 'TRUE', 'True', 'on', 'ON', 'yes', 'YES'];
    const falsy = ['0', 'false', 'off', 'no', '', 'garbage', '2', 'truee'];

    it.each(truthy)('has_urgent=%s -> true', async (value) => {
      wireFakeDb();
      const res = await GET(req(`?user_id=42&stage=purchase&has_urgent=${encodeURIComponent(value)}`));
      const body = await res.json();
      expect(body.data.hasUrgentSymptoms).toBe(true);
    });

    it.each(falsy)('has_urgent=%s -> false', async (value) => {
      wireFakeDb();
      const res = await GET(req(`?user_id=42&stage=purchase&has_urgent=${encodeURIComponent(value)}`));
      const body = await res.json();
      expect(body.data.hasUrgentSymptoms).toBe(false);
    });

    it('has_urgent absent entirely -> defaults to false ("false" string default, isset()-based)', async () => {
      wireFakeDb();
      const res = await GET(req('?user_id=42&stage=purchase'));
      const body = await res.json();
      expect(body.data.hasUrgentSymptoms).toBe(false);
    });
  });

  describe('exact PHP-literal action lists per stage (stage explicit, no detection)', () => {
    it('symptom_assessment', async () => {
      wireFakeDb();
      const res = await GET(req('?user_id=42&stage=symptom_assessment'));
      const body = await res.json();

      expect(body.data.stageLabel).toBe('ประเมินอาการ');
      expect(body.data.actions).toEqual([
        {
          id: 'ask_followup',
          label: 'ถามอาการเพิ่มเติม',
          labelEn: 'Ask Follow-up',
          icon: '❓',
          action: 'ask_followup',
          template: 'อาการเป็นมานานแค่ไหนแล้วคะ? มีอาการอื่นร่วมด้วยไหมคะ?',
          priority: 10,
        },
        { id: 'suggest_otc', label: 'แนะนำยา OTC', labelEn: 'Suggest OTC', icon: '💊', action: 'suggest_otc', priority: 9 },
        { id: 'check_history', label: 'ดูประวัติ', labelEn: 'Check History', icon: '📋', action: 'check_history', priority: 8 },
        { id: 'analyze_image', label: 'วิเคราะห์รูป', labelEn: 'Analyze Image', icon: '📷', action: 'analyze_image', priority: 7 },
      ]);
    });

    it('drug_recommendation', async () => {
      wireFakeDb();
      const res = await GET(req('?user_id=42&stage=drug_recommendation'));
      const body = await res.json();

      expect(body.data.stageLabel).toBe('แนะนำยา');
      expect(body.data.actions).toEqual([
        { id: 'send_drug_info', label: 'ส่งข้อมูลยา', labelEn: 'Send Drug Info', icon: '💊', action: 'send_drug_info', priority: 10 },
        { id: 'check_interactions', label: 'ตรวจยาตีกัน', labelEn: 'Check Interactions', icon: '⚠️', action: 'check_interactions', priority: 9 },
        { id: 'compare_drugs', label: 'เปรียบเทียบยา', labelEn: 'Compare Drugs', icon: '📊', action: 'compare_drugs', priority: 8 },
        { id: 'apply_discount', label: 'ให้ส่วนลด', labelEn: 'Apply Discount', icon: '💰', action: 'apply_discount', priority: 7 },
      ]);
    });

    it('purchase', async () => {
      wireFakeDb();
      const res = await GET(req('?user_id=42&stage=purchase'));
      const body = await res.json();

      expect(body.data.stageLabel).toBe('ตัดสินใจซื้อ');
      expect(body.data.actions).toEqual([
        { id: 'create_order', label: 'สร้างออเดอร์', labelEn: 'Create Order', icon: '🛒', action: 'create_order', priority: 10 },
        { id: 'send_payment_link', label: 'ส่งลิงก์ชำระเงิน', labelEn: 'Send Payment Link', icon: '💳', action: 'send_payment_link', priority: 9 },
        { id: 'schedule_delivery', label: 'นัดส่งสินค้า', labelEn: 'Schedule Delivery', icon: '🚚', action: 'schedule_delivery', priority: 8 },
        { id: 'apply_points', label: 'ใช้แต้มสะสม', labelEn: 'Apply Points', icon: '⭐', action: 'apply_points', priority: 7 },
      ]);
    });

    it('follow_up', async () => {
      wireFakeDb();
      const res = await GET(req('?user_id=42&stage=follow_up'));
      const body = await res.json();

      expect(body.data.stageLabel).toBe('ติดตามผล');
      expect(body.data.actions).toEqual([
        {
          id: 'check_progress',
          label: 'ถามความคืบหน้า',
          labelEn: 'Check Progress',
          icon: '📈',
          action: 'check_progress',
          template: 'อาการเป็นอย่างไรบ้างคะ? ดีขึ้นไหมคะ?',
          priority: 10,
        },
        { id: 'suggest_refill', label: 'แนะนำเติมยา', labelEn: 'Suggest Refill', icon: '🔄', action: 'suggest_refill', priority: 9 },
        { id: 'schedule_followup', label: 'นัดติดตาม', labelEn: 'Schedule Follow-up', icon: '📅', action: 'schedule_followup', priority: 8 },
        { id: 'refer_doctor', label: 'แนะนำพบแพทย์', labelEn: 'Refer to Doctor', icon: '🏥', action: 'refer_doctor', priority: 7 },
      ]);
    });

    it('an unknown stage string falls through to the default branch (2 actions, one carrying a template key)', async () => {
      wireFakeDb();
      const res = await GET(req('?user_id=42&stage=some_unknown_stage'));
      const body = await res.json();

      expect(body.data.stageLabel).toBe('ไม่ระบุ');
      expect(body.data.actions).toEqual([
        {
          id: 'ask_symptoms',
          label: 'ถามอาการ',
          labelEn: 'Ask Symptoms',
          icon: '❓',
          action: 'ask_symptoms',
          template: 'สวัสดีค่ะ มีอาการอะไรให้ช่วยเหลือคะ?',
          priority: 10,
        },
        { id: 'check_history', label: 'ดูประวัติ', labelEn: 'Check History', icon: '📋', action: 'check_history', priority: 8 },
      ]);
    });
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
