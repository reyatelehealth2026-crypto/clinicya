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
  const url = `https://tenant.re-ya.com/api/inbox/actions/detect-urgency${search}`;
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

function messageRow(content: string) {
  return { content, message_type: 'text', direction: 'incoming', created_at: new Date('2026-08-13T10:00:00Z') };
}

/** Routes fake queries: `FROM messages` -> the given message rows; `FROM consultation_stages` -> the given stage row (or none). */
function wireMessages(messages: string[], stageRow: { has_urgent_symptoms: number } | null = null) {
  return wireFakeDb((sqlText) => {
    if (sqlText.includes('FROM messages')) return messages.map(messageRow);
    if (sqlText.includes('FROM consultation_stages')) return stageRow ? [stageRow] : [];
    return [];
  });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/detect-urgency', () => {
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

  it('0 messages -> the SHORT-circuit shape: exactly {needsReferral, reason, urgency, detectedKeywords} — no urgencyLabel/recommendation keys at all', async () => {
    wireMessages([]);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: { needsReferral: false, reason: null, urgency: 'normal', detectedKeywords: [] },
    });
    expect(body.data).not.toHaveProperty('urgencyLabel');
    expect(body.data).not.toHaveProperty('recommendation');
  });

  it('no urgent keywords, no consultation_stages row -> normal, reason null, needsReferral false, urgencyLabel ปกติ, recommendation null', async () => {
    wireMessages(['สวัสดีค่ะ อยากซื้อวิตามิน']);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data).toEqual({
      needsReferral: false,
      reason: null,
      urgency: 'normal',
      urgencyLabel: 'ปกติ',
      detectedKeywords: [],
      recommendation: null,
    });
  });

  it('critical keyword alone (เจ็บหน้าอก, part of the critical subset) -> urgency critical, needsReferral true, urgencyLabel ฉุกเฉิน, exact reason string', async () => {
    wireMessages(['เจ็บหน้าอกมากค่ะ']);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.urgency).toBe('critical');
    expect(body.data.needsReferral).toBe(true);
    expect(body.data.urgencyLabel).toBe('ฉุกเฉิน');
    expect(body.data.detectedKeywords).toEqual(['เจ็บหน้าอก']);
    expect(body.data.reason).toBe('ตรวจพบอาการฉุกเฉิน: เจ็บหน้าอก');
    expect(body.data.recommendation).toBe('แนะนำให้พบแพทย์โดยเร็ว');
  });

  it('exactly one NON-critical urgent keyword (ผื่นทั้งตัว) -> urgency moderate, needsReferral false, full (non-sliced) reason join', async () => {
    wireMessages(['มีผื่นทั้งตัวค่ะ']);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.urgency).toBe('moderate');
    expect(body.data.needsReferral).toBe(false);
    expect(body.data.urgencyLabel).toBe('ควรระวัง');
    expect(body.data.detectedKeywords).toEqual(['ผื่นทั้งตัว']);
    expect(body.data.reason).toBe('ตรวจพบอาการที่ควรระวัง: ผื่นทั้งตัว');
    expect(body.data.recommendation).toBe('ควรติดตามอาการอย่างใกล้ชิด');
  });

  it('a full-list-only keyword outside the critical subset (เลือดไหล, NOT เลือดออก) -> moderate, not critical — proves the critical subset is strictly shorter', async () => {
    wireMessages(['เลือดไหลค่ะ']);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.detectedKeywords).toEqual(['เลือดไหล']);
    expect(body.data.urgency).toBe('moderate');
    expect(body.data.needsReferral).toBe(false);
  });

  it('two NON-critical urgent keywords in one message -> urgency high, needsReferral true, sliced-to-3 reason join in detection order', async () => {
    wireMessages(['ผื่นทั้งตัวและปากบวมค่ะ']);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.detectedKeywords).toEqual(['ผื่นทั้งตัว', 'ปากบวม']);
    expect(body.data.urgency).toBe('high');
    expect(body.data.needsReferral).toBe(true);
    expect(body.data.urgencyLabel).toBe('รุนแรง');
    expect(body.data.reason).toBe('ตรวจพบอาการรุนแรงหลายอย่าง: ผื่นทั้งตัว, ปากบวม');
  });

  it('array_unique dedup: the same keyword repeated across messages appears once in detectedKeywords', async () => {
    wireMessages(['เลือดออกค่ะ', 'ยังเลือดออกอยู่เลย']);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    // เลือดออก is itself in the critical subset.
    expect(body.data.detectedKeywords).toEqual(['เลือดออก']);
    expect(body.data.urgency).toBe('critical');
  });

  it('consultation_stages.has_urgent_symptoms fallback bump: 0 keywords detected but a prior urgent-symptoms flag bumps normal -> moderate with the exact Thai reason', async () => {
    wireMessages(['สวัสดีค่ะ'], { has_urgent_symptoms: 1 });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.urgency).toBe('moderate');
    expect(body.data.needsReferral).toBe(false);
    expect(body.data.reason).toBe('มีประวัติอาการที่ควรระวังก่อนหน้านี้');
    expect(body.data.detectedKeywords).toEqual([]);
    expect(body.data.recommendation).toBe('ควรติดตามอาการอย่างใกล้ชิด');
  });

  it('the fallback bump does NOT apply when urgency is already non-normal (already high stays high, not overwritten)', async () => {
    wireMessages(['ผื่นทั้งตัวและปากบวมค่ะ'], { has_urgent_symptoms: 1 });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.urgency).toBe('high');
    expect(body.data.reason).toBe('ตรวจพบอาการรุนแรงหลายอย่าง: ผื่นทั้งตัว, ปากบวม');
  });

  it('a consultation_stages lookup failure is silently ignored (matches PHP\'s empty catch block) — urgency stays as computed from keywords', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM messages')) return [messageRow('สวัสดีค่ะ')];
      if (sqlText.includes('FROM consultation_stages')) {
        throw new Error('table missing');
      }
      return [];
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.urgency).toBe('normal');
    expect(queries.some((q) => q.sql.includes('FROM consultation_stages'))).toBe(true);
  });

  // NOTE: no "Database error: ..." 500 test here, deliberately — detectUrgency()
  // never throws (getRecentMessages() and the consultation_stages lookup both
  // swallow their own DB errors), so route.ts's defensive try/catch is
  // genuinely unreachable through any DB-level failure. Same precedent as
  // ../check-allergy/route.test.ts.

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
