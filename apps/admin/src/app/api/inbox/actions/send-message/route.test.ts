/**
 * @jest-environment node
 */
const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

const mockLineSendMessage = jest.fn();
jest.mock('@reya/line', () => ({
  sendMessage: (...args: unknown[]) => mockLineSendMessage(...args),
}));

import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { POST } from './route';

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
    createdAt: '2026-07-01T00:00:00.000Z',
    lastSeenAt: '2026-07-14T00:00:00.000Z',
    expiresAt: '2026-07-15T00:00:00.000Z',
    ...overrides,
  };
}

/** Wires resolveInboxApiContext() to a fake Kysely<TenantDB> answering `queryImpl`; returns the recorded queries for assertions. */
function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const USER_ROW_LINE = {
  id: 42,
  line_user_id: 'Uabc123',
  line_account_id: 9,
  platform: 'line',
  reply_token: 'replytok',
  reply_token_expires_str: '2026-07-14 12:00:00',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/send-message', () => {
  it('401 JSON when unauthenticated, LINE API never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ user_id: 1, message: 'hi' }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockLineSendMessage).not.toHaveBeenCalled();
  });

  it.each([
    [{ message: 'hi' }], // missing user_id
    [{ user_id: 0, message: 'hi' }], // falsy user_id
    [{ user_id: 1, message: '' }], // empty message
    [{ user_id: 1, message: '   ' }], // whitespace-only message trims to empty
    [{ user_id: 1 }], // missing message
    [{}], // both missing
  ])('400 {success:false, error:"Invalid data"} for body=%j, no DB queries issued', async (body) => {
    const queries = wireFakeDb();

    const res = await POST(req(body));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid data' });
    expect(queries).toHaveLength(0);
    expect(mockLineSendMessage).not.toHaveBeenCalled();
  });

  it('404 {success:false, error:"User not found"} when the user row does not exist', async () => {
    wireFakeDb(() => []);

    const res = await POST(req({ user_id: 999, message: 'hello' }));

    expect(res.status).toBe(404);
    expect(await res.json()).toEqual({ success: false, error: 'User not found' });
    expect(mockLineSendMessage).not.toHaveBeenCalled();
  });

  it.each(['facebook', 'tiktok'])(
    '400 with the distinct "not migrated yet" message for platform=%s (not a byte-for-byte PHP port)',
    async (platform) => {
      wireFakeDb((sqlText) => (sqlText.includes('FROM users WHERE id') ? [{ ...USER_ROW_LINE, platform }] : []));

      const res = await POST(req({ user_id: 42, message: 'hello' }));

      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({
        success: false,
        error: 'ยังไม่รองรับการส่งข้อความ Facebook/TikTok จากหน้านี้ (ยังไม่ได้ย้ายมา Next.js)',
      });
      expect(mockLineSendMessage).not.toHaveBeenCalled();
    }
  );

  it('400 when the user has no matching line_accounts row (Next-side addition: no legacy config fallback)', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE id')) return [USER_ROW_LINE];
      if (sqlText.includes('FROM line_accounts WHERE id')) return [];
      return [];
    });

    const res = await POST(req({ user_id: 42, message: 'hello' }));

    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
    expect(typeof body.error).toBe('string');
    expect(mockLineSendMessage).not.toHaveBeenCalled();
  });

  it('happy path: line send via reply — persists messages + activity_logs rows, onReplyTokenUsed clears the token, returns the exact response envelope', async () => {
    mockLineSendMessage.mockResolvedValue({ code: 200, method: 'reply', body: {} });
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE id')) return [USER_ROW_LINE];
      if (sqlText.includes('FROM line_accounts WHERE id')) return [{ channel_access_token: 'token-abc' }];
      const lower = sqlText.toLowerCase();
      if (lower.includes('insert into `messages`')) return { insertId: 555, affectedRows: 1 };
      if (lower.includes('insert into `activity_logs`')) return { insertId: 999, affectedRows: 1 };
      if (lower.includes('update `users`')) return { affectedRows: 1 };
      return [];
    });

    const res = await POST(req({ user_id: 42, message: 'สวัสดีครับ', reply_to_id: 12 }));
    const body = await res.json();

    // --- @reya/line sendMessage() call shape ---
    expect(mockLineSendMessage).toHaveBeenCalledTimes(1);
    const [params, options] = mockLineSendMessage.mock.calls[0] as [Record<string, unknown>, Record<string, unknown>];
    expect(params.userId).toBe('Uabc123');
    expect(params.messages).toBe('สวัสดีครับ');
    expect(params.replyToken).toBe('replytok');
    expect(params.tokenExpires).toBe('2026-07-14 12:00:00'); // DATE_FORMAT string, not a Date/.toISOString()
    expect(params.internalUserId).toBe(42);
    expect(options).toEqual({ channelAccessToken: 'token-abc' });

    // --- onReplyTokenUsed callback: invoke it (mirroring what @reya/line's real
    // sendMessage() does internally) and assert the resulting UPDATE ---
    await (params.onReplyTokenUsed as (info: unknown) => Promise<void>)({ lineUserId: 'Uabc123', internalUserId: 42 });
    const updateQuery = queries.find((q) => q.sql.toLowerCase().includes('update `users`'));
    expect(updateQuery).toBeDefined();
    expect(updateQuery?.params).toEqual([null, null, 42]);

    // --- messages INSERT ---
    const messagesInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into `messages`'));
    expect(messagesInsert?.params).toEqual([9, 42, 'outgoing', 'text', 'สวัสดีครับ', 'admin:pharmacist1', 12, 0]);

    // --- activity_logs INSERT: log_type='message'/action='send' (TYPE_MESSAGE/ACTION_SEND), not 'data'/'update' ---
    const activityInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into `activity_logs`'));
    expect(activityInsert?.params).toEqual([
      'message',
      'send',
      'ส่งข้อความถึงลูกค้า',
      42,
      7,
      'pharmacist1',
      'message',
      555,
      JSON.stringify({ message: 'สวัสดีครับ' }),
      9,
    ]);

    // --- response envelope ---
    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      message_id: 555,
      content: 'สวัสดีครับ',
      time: expect.stringMatching(/^\d{2}:\d{2}$/),
      sent_by: 'admin:pharmacist1',
      method: 'reply',
      method_label: '✓ Reply (ฟรี)',
    });
  });

  it('happy path via push -> method_label is "💰 Push", reply_to_id omitted binds null', async () => {
    mockLineSendMessage.mockResolvedValue({ code: 200, method: 'push', body: {} });
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE id')) return [{ ...USER_ROW_LINE, reply_token: null, reply_token_expires_str: null }];
      if (sqlText.includes('FROM line_accounts WHERE id')) return [{ channel_access_token: 'token-abc' }];
      const lower = sqlText.toLowerCase();
      if (lower.includes('insert into `messages`')) return { insertId: 601, affectedRows: 1 };
      if (lower.includes('insert into `activity_logs`')) return { insertId: 602, affectedRows: 1 };
      return [];
    });

    const res = await POST(req({ user_id: 42, message: 'no reply token here' }));
    const body = await res.json();

    const messagesInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into `messages`'));
    expect(messagesInsert?.params).toEqual([9, 42, 'outgoing', 'text', 'no reply token here', 'admin:pharmacist1', null, 0]);

    expect(res.status).toBe(200);
    expect(body.method).toBe('push');
    expect(body.method_label).toBe('💰 Push');
  });

  it('non-200 LINE result -> 400 with the literal "LINE API Error (HTTP ..., ...): ..." shape, no messages/activity_logs rows written', async () => {
    mockLineSendMessage.mockResolvedValue({ code: 400, method: 'push', body: { message: 'invalid reply token' } });
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE id')) return [USER_ROW_LINE];
      if (sqlText.includes('FROM line_accounts WHERE id')) return [{ channel_access_token: 'token-abc' }];
      return [];
    });

    const res = await POST(req({ user_id: 42, message: 'hello' }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({
      success: false,
      error: `LINE API Error (HTTP 400, push): ${JSON.stringify({ message: 'invalid reply token' })}`,
    });
    expect(queries.some((q) => q.sql.toLowerCase().includes('insert into `messages`'))).toBe(false);
    expect(queries.some((q) => q.sql.toLowerCase().includes('insert into `activity_logs`'))).toBe(false);
  });
});
