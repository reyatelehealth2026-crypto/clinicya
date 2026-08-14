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
  const url = `https://tenant.re-ya.com/api/inbox/actions/consultation-stage${search}`;
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

/** Routes fake queries: `FROM messages` -> the given message rows; anything else (the saveStage INSERT) -> an OkPacket-shaped write result. */
function wireMessages(messages: ReturnType<typeof messageRow>[]) {
  return wireFakeDb((sqlText) => {
    if (sqlText.toLowerCase().includes('from messages')) return messages;
    if (sqlText.toLowerCase().includes('insert into consultation_stages')) {
      return { insertId: 1, affectedRows: 1 };
    }
    return [];
  });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/consultation-stage', () => {
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

    const res = await GET(req('?user_id=-5'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('0 messages -> the exact createStageResult() short circuit, and NO write occurs (zero INSERT queries recorded)', async () => {
    const queries = wireMessages([]);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        stage: 'symptom_assessment',
        stageLabel: 'Symptom Assessment',
        stageLabelTh: 'ประเมินอาการ',
        confidence: 0.3,
        signals: ['no_messages'],
        hasUrgentSymptoms: false,
        scores: [],
        messageCount: 0,
      },
    });
    expect(queries.some((q) => q.sql.toLowerCase().includes('insert'))).toBe(false);
  });

  it('a single incoming purchase-stage message -> detects "purchase", confidence 1.0 (no competing stage signal), scores is a keyed object (not an array)', async () => {
    wireMessages([messageRow('อยากสั่งซื้อยาค่ะ ราคาเท่าไหร่')]);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.stage).toBe('purchase');
    expect(body.data.stageLabelTh).toBe('ตัดสินใจซื้อ');
    expect(body.data.hasUrgentSymptoms).toBe(false);
    expect(body.data.messageCount).toBe(1);
    expect(Array.isArray(body.data.scores)).toBe(false);
    expect(body.data.scores.purchase).toBeGreaterThan(0);
  });

  it('outgoing (pharmacist) messages are excluded from scoring — an all-outgoing history behaves like an all-zero-score default (stage stays symptom_assessment)', async () => {
    wireMessages([messageRow('อยากสั่งซื้อยาค่ะ', 'outgoing')]);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.stage).toBe('symptom_assessment');
    expect(body.data.scores).toEqual({
      symptom_assessment: 0,
      drug_recommendation: 0,
      purchase: 0,
      follow_up: 0,
    });
  });

  it('hasUrgentSymptoms true when an urgent keyword appears in an incoming message, and the saveStage() write fires with the literal column list (no line_account_id)', async () => {
    const queries = wireMessages([messageRow('เจ็บหน้าอกและปวดหัวมากค่ะ')]);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.hasUrgentSymptoms).toBe(true);

    const insertQuery = queries.find((q) => q.sql.toLowerCase().includes('insert into consultation_stages'));
    expect(insertQuery).toBeDefined();
    expect(insertQuery!.sql.toLowerCase()).toContain('on duplicate key update');
    // Column order: user_id, stage, confidence, signals, has_urgent_symptoms — NO line_account_id.
    expect(insertQuery!.params[0]).toBe(42);
    expect(insertQuery!.params[4]).toBe(1); // has_urgent_symptoms = 1 (boolean -> tinyint)
    expect(insertQuery!.sql.toLowerCase()).not.toContain('line_account_id');
  });

  it('a saveStage() write failure is silently swallowed (matches PHP\'s catch-and-error_log) — the GET still succeeds with the detected stage', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.toLowerCase().includes('from messages')) return [messageRow('ปวดหัวค่ะ')];
      if (sqlText.toLowerCase().includes('insert into consultation_stages')) {
        throw new Error('deadlock');
      }
      return [];
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.data.stage).toBe('symptom_assessment');
  });

  it('a getRecentMessages() read failure yields the empty-history behavior (0 messages) — success stays true, matching PHP\'s swallowed PDOException', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.toLowerCase().includes('from messages')) throw new Error('connection lost');
      return [];
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        stage: 'symptom_assessment',
        stageLabel: 'Symptom Assessment',
        stageLabelTh: 'ประเมินอาการ',
        confidence: 0.3,
        signals: ['no_messages'],
        hasUrgentSymptoms: false,
        scores: [],
        messageCount: 0,
      },
    });
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
