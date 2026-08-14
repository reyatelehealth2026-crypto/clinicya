/**
 * @jest-environment node
 */

// ═══════════════════════════════════════════════════════════════════════
// HARD SAFETY GATE: this is a full module-boundary mock of '@reya/line' —
// exactly matching send-message/route.test.ts's own established pattern.
// No test in this file can reach @reya/line's real defaultFetch/
// globalThis.fetch: pushMessage() is the ONLY export this route's code
// path ever calls from '@reya/line' (see _lib/sendBatchMessages.ts's
// module doc), and it is fully replaced by mockPushMessage below — jest's
// module factory means the real implementation in packages/line/src/api.ts
// is never evaluated for this test file at all.
// ═══════════════════════════════════════════════════════════════════════
const mockPushMessage = jest.fn();
jest.mock('@reya/line', () => ({
  pushMessage: (...args: unknown[]) => mockPushMessage(...args),
}));

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { POST, GET } from './route';

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

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

/** Standard dispatcher: line_user_id resolves to 'Uabc123', channel token resolves, inserts/updates ack. */
function baseQueryImpl(overrides: Record<string, (params: unknown[]) => unknown> = {}) {
  return (sqlText: string, params: unknown[]): unknown => {
    const lower = sqlText.toLowerCase();
    if (overrides.lineUser && lower.includes('line_user_id from users')) return overrides.lineUser(params);
    if (lower.includes('line_user_id from users')) return [{ line_user_id: 'Uabc123' }];

    if (overrides.account && lower.includes('from line_accounts')) return overrides.account(params);
    if (lower.includes('from line_accounts')) return [{ channel_access_token: 'token-abc' }];

    if (lower.includes('insert into `messages`')) return { insertId: 1, affectedRows: 1 };
    if (lower.includes('update `users`')) return { affectedRows: 1 };

    return [];
  };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('POST /api/inbox/actions/send-batch-messages', () => {
  it('401 JSON when unauthenticated, LINE API never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req({ user_id: 1, messages: [{ type: 'text', content: 'hi' }] }));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockPushMessage).not.toHaveBeenCalled();
  });

  it('405 for GET — method-not-allowed guard', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
    expect(mockPushMessage).not.toHaveBeenCalled();
  });

  it.each([
    [{ messages: [{ type: 'text', content: 'hi' }] }], // missing user_id
    [{ user_id: 0, messages: [{ type: 'text', content: 'hi' }] }], // falsy user_id
  ])('400 "User ID is required" for body=%j, no DB queries, LINE never touched', async (body) => {
    const queries = wireFakeDb(baseQueryImpl());

    const res = await POST(req(body));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
    expect(queries).toHaveLength(0);
    expect(mockPushMessage).not.toHaveBeenCalled();
  });

  it.each([[{ user_id: 1 }], [{ user_id: 1, messages: [] }], [{ user_id: 1, messages: '[]' }]])(
    '400 "Messages array is required" for empty/missing messages=%j',
    async (body) => {
      const queries = wireFakeDb(baseQueryImpl());

      const res = await POST(req(body));

      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Messages array is required' });
      expect(queries).toHaveLength(0);
      expect(mockPushMessage).not.toHaveBeenCalled();
    }
  );

  it('a JSON-string messages value is parsed like PHP\'s is_string()->json_decode() path', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    wireFakeDb(baseQueryImpl());

    const res = await POST(req({ user_id: 1, messages: JSON.stringify([{ type: 'text', content: 'hi' }]) }));

    expect(res.status).toBe(200);
    expect(mockPushMessage).toHaveBeenCalledTimes(1);
  });

  it('400 "Maximum 5 messages allowed per batch" for more than 5 messages', async () => {
    const queries = wireFakeDb(baseQueryImpl());
    const messages = Array.from({ length: 6 }, (_, i) => ({ type: 'text', content: `msg ${i}` }));

    const res = await POST(req({ user_id: 1, messages }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Maximum 5 messages allowed per batch' });
    expect(queries).toHaveLength(0);
    expect(mockPushMessage).not.toHaveBeenCalled();
  });

  it('exactly 5 messages is allowed (boundary)', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    wireFakeDb(baseQueryImpl());
    const messages = Array.from({ length: 5 }, (_, i) => ({ type: 'text', content: `msg ${i}` }));

    const res = await POST(req({ user_id: 1, messages }));

    expect(res.status).toBe(200);
  });

  it('400 "LINE User ID not found for user" when line_user_id is omitted and the DB lookup returns nothing', async () => {
    const queries = wireFakeDb(baseQueryImpl({ lineUser: () => [] }));

    const res = await POST(req({ user_id: 1, messages: [{ type: 'text', content: 'hi' }] }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'LINE User ID not found for user' });
    expect(mockPushMessage).not.toHaveBeenCalled();
    // the users lookup is scoped by BOTH id and line_account_id
    const lookupQuery = queries.find((q) => q.sql.toLowerCase().includes('line_user_id from users'));
    expect(lookupQuery?.params).toEqual([1, 3]);
  });

  it('an explicit line_user_id in the body skips the DB lookup entirely', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    const queries = wireFakeDb(baseQueryImpl());

    await POST(req({ user_id: 1, messages: [{ type: 'text', content: 'hi' }], line_user_id: 'Udirect999' }));

    expect(queries.some((q) => q.sql.toLowerCase().includes('line_user_id from users'))).toBe(false);
    expect(mockPushMessage.mock.calls[0][0]).toBe('Udirect999');
  });

  it('400 "LINE account token not found" when the line_accounts row is missing/empty token', async () => {
    wireFakeDb(baseQueryImpl({ account: () => [] }));

    const res = await POST(req({ user_id: 1, messages: [{ type: 'text', content: 'hi' }] }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'LINE account token not found' });
    expect(mockPushMessage).not.toHaveBeenCalled();
  });

  it('a messages array whose items are all unrecognized-type/empty-content silently yields 400 "No valid messages to send" — no per-item error', async () => {
    wireFakeDb(baseQueryImpl());
    const messages = [
      { type: 'text', content: '' }, // empty content, dropped
      { type: 'video', originalContentUrl: 'https://x/y.mp4' }, // unrecognized type, dropped
      { type: 'image', originalContentUrl: 'https://x/y.png' }, // missing previewImageUrl, dropped
      { type: 'file' }, // missing originalContentUrl, dropped
    ];

    const res = await POST(req({ user_id: 1, messages }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'No valid messages to send' });
    expect(mockPushMessage).not.toHaveBeenCalled();
  });

  it('type:"text" content==="{{PAYMENT_TEMPLATE_V1}}" switches to the payment Flex card, not a literal text bubble', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    wireFakeDb(baseQueryImpl());

    await POST(req({ user_id: 1, messages: [{ type: 'text', content: '{{PAYMENT_TEMPLATE_V1}}', amount: 250 }] }));

    const sentMessages = mockPushMessage.mock.calls[0][1] as Array<Record<string, unknown>>;
    expect(sentMessages).toHaveLength(1);
    expect(sentMessages[0].type).toBe('flex');
    expect(sentMessages[0].altText).toBe('แจ้งยอดชำระ: 250.00 บาท');
  });

  it('type:"image" requires BOTH originalContentUrl and previewImageUrl or the item is silently dropped', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    wireFakeDb(baseQueryImpl());

    await POST(
      req({
        user_id: 1,
        messages: [
          { type: 'image', originalContentUrl: 'https://x/full.png' }, // missing preview -> dropped
          { type: 'image', originalContentUrl: 'https://x/full2.png', previewImageUrl: 'https://x/prev2.png' },
        ],
      })
    );

    const sentMessages = mockPushMessage.mock.calls[0][1] as Array<Record<string, unknown>>;
    expect(sentMessages).toHaveLength(1);
    expect(sentMessages[0]).toEqual({
      type: 'image',
      originalContentUrl: 'https://x/full2.png',
      previewImageUrl: 'https://x/prev2.png',
    });
  });

  it('type:"file" requires originalContentUrl and produces the exact hardcoded-icon Flex bubble (byte-identical structure to the PHP literal)', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    wireFakeDb(baseQueryImpl());

    await POST(
      req({
        user_id: 1,
        messages: [
          { type: 'file' }, // missing url -> dropped
          { type: 'file', originalContentUrl: 'https://x/report.pdf', fileName: 'report.pdf' },
        ],
      })
    );

    const sentMessages = mockPushMessage.mock.calls[0][1] as Array<Record<string, unknown>>;
    expect(sentMessages).toHaveLength(1);
    expect(sentMessages[0]).toEqual({
      type: 'flex',
      altText: 'Sent a file: report.pdf',
      contents: {
        type: 'bubble',
        hero: {
          type: 'image',
          url: 'https://cny.re-ya.com/uploads/chat_images/chat_1769145030_697302c699ee0.png',
          size: 'full',
          aspectRatio: '20:13',
          aspectMode: 'fit',
          action: { type: 'uri', uri: 'https://x/report.pdf' },
        },
        body: {
          type: 'box',
          layout: 'vertical',
          contents: [
            { type: 'text', text: 'report.pdf', weight: 'bold', size: 'md', wrap: true },
            { type: 'text', text: 'PDF Document', size: 'xs', color: '#aaaaaa', margin: 'sm' },
            { type: 'separator', margin: 'lg' },
          ],
        },
        footer: {
          type: 'box',
          layout: 'vertical',
          contents: [
            {
              type: 'button',
              style: 'primary',
              color: '#1DB446',
              height: 'sm',
              action: { type: 'uri', label: 'Download File', uri: 'https://x/report.pdf' },
            },
          ],
        },
      },
    });
  });

  it('type:"payment" has NO required-field guard — always produces a message even with amount absent, defaulting to 0.00, bank details byte-for-byte', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    wireFakeDb(baseQueryImpl());

    await POST(req({ user_id: 1, messages: [{ type: 'payment' }] }));

    const sentMessages = mockPushMessage.mock.calls[0][1] as Array<Record<string, unknown>>;
    expect(sentMessages).toHaveLength(1);
    const flex = sentMessages[0];
    expect(flex.type).toBe('flex');
    expect(flex.altText).toBe('แจ้งยอดชำระ: 0.00 บาท');

    const contents = flex.contents as Record<string, unknown>;
    expect(contents.size).toBe('kilo');
    const body = contents.body as { contents: Array<Record<string, unknown>> };
    expect((body.contents[1] as Record<string, unknown>).text).toBe('0.00 THB');

    const detailsBox = body.contents[3] as { contents: Array<Record<string, unknown>> };
    expect((detailsBox.contents[0] as Record<string, unknown>).text).toBe('KBANK (กสิกรไทย)');
    expect((detailsBox.contents[1] as Record<string, unknown>).text).toBe('068-3-84622-8');
    expect(((detailsBox.contents[1] as Record<string, unknown>).action as Record<string, unknown>).clipboardText).toBe('0683846228');
    expect((detailsBox.contents[2] as Record<string, unknown>).text).toBe('บจก.ซี เอ็น วาย เฮลท์แคร์');

    const footer = contents.footer as { contents: Array<Record<string, unknown>> };
    expect(((footer.contents[0] as Record<string, unknown>).action as Record<string, unknown>).clipboardText).toBe('0683846228');
    expect(((footer.contents[0] as Record<string, unknown>).action as Record<string, unknown>).label).toBe('คัดลอกเลขบัญชี');
  });

  it('payment amount formats with thousands separators + 2 decimals (number_format semantics)', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    wireFakeDb(baseQueryImpl());

    await POST(req({ user_id: 1, messages: [{ type: 'payment', amount: 1234.5 }] }));

    const sentMessages = mockPushMessage.mock.calls[0][1] as Array<Record<string, unknown>>;
    expect(sentMessages[0].altText).toBe('แจ้งยอดชำระ: 1,234.50 บาท');
  });

  it('on a non-200 pushMessage() result, response is "Failed to send messages via LINE: {message}" at 400 — no DB writes happen', async () => {
    mockPushMessage.mockResolvedValue({ code: 400, body: { message: 'invalid recipient' } });
    const queries = wireFakeDb(baseQueryImpl());

    const res = await POST(req({ user_id: 1, messages: [{ type: 'text', content: 'hi' }] }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to send messages via LINE: invalid recipient' });
    expect(queries.some((q) => q.sql.toLowerCase().includes('insert into `messages`'))).toBe(false);
    expect(queries.some((q) => q.sql.toLowerCase().includes('update `users`'))).toBe(false);
  });

  it('non-200 pushMessage() result with no body.message falls back to "Unknown error"', async () => {
    mockPushMessage.mockResolvedValue({ code: 500, body: {} });
    wireFakeDb(baseQueryImpl());

    const res = await POST(req({ user_id: 1, messages: [{ type: 'text', content: 'hi' }] }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to send messages via LINE: Unknown error' });
  });

  it('happy path: pushMessage() called with the LINE user id + built messages array; every dbRecords entry becomes its own messages INSERT with is_read=1 and sent_by=raw admin id (NOT "admin:username"); users.last_message_at touched; exact response envelope', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    const queries = wireFakeDb(baseQueryImpl());

    const res = await POST(
      req({
        user_id: 1,
        messages: [
          { type: 'text', content: 'สวัสดีครับ' },
          { type: 'image', originalContentUrl: 'https://x/a.png', previewImageUrl: 'https://x/a_prev.png' },
        ],
      })
    );
    const body = await res.json();

    // pushMessage call shape.
    expect(mockPushMessage).toHaveBeenCalledTimes(1);
    const [lineUserIdArg, messagesArg, optionsArg] = mockPushMessage.mock.calls[0];
    expect(lineUserIdArg).toBe('Uabc123');
    expect(messagesArg).toEqual([
      { type: 'text', text: 'สวัสดีครับ' },
      { type: 'image', originalContentUrl: 'https://x/a.png', previewImageUrl: 'https://x/a_prev.png' },
    ]);
    expect(optionsArg).toEqual({ channelAccessToken: 'token-abc' });

    // Two messages INSERTs — one per dbRecords entry.
    const messageInserts = queries.filter((q) => q.sql.toLowerCase().includes('insert into `messages`'));
    expect(messageInserts).toHaveLength(2);
    // sent_by is the RAW admin id string ("7"), NOT "admin:pharmacist1"; is_read is 1.
    for (const insert of messageInserts) {
      expect(insert.params).toContain('7');
      expect(insert.params).not.toContain('admin:pharmacist1');
      expect(insert.params).toContain(1); // is_read = 1 (also user_id=1 -- both are the literal integer 1, expected)
    }
    expect(messageInserts[0]?.params).toEqual([1, 3, 'outgoing', 'text', 'สวัสดีครับ', 1, '7']);
    expect(messageInserts[1]?.params).toEqual([1, 3, 'outgoing', 'image', 'https://x/a.png', 1, '7']);

    // users.last_message_at touched.
    const userUpdate = queries.find((q) => q.sql.toLowerCase().includes('update `users`'));
    expect(userUpdate).toBeDefined();
    expect(userUpdate?.params).toEqual([1]);

    // Response envelope.
    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, message: 'Sent 2 messages successfully', count: 2 });
  });

  it('sent_by is null when session.adminUserId is null/undefined (defensive — TenantSession types it non-nullable, but preserved as documented)', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: {} });
    const queries = wireFakeDb(baseQueryImpl(), { adminUserId: null as unknown as number });

    await POST(req({ user_id: 1, messages: [{ type: 'text', content: 'hi' }] }));

    const insert = queries.find((q) => q.sql.toLowerCase().includes('insert into `messages`'));
    expect(insert?.params?.[6]).toBeNull();
  });

  it('an unhandled error inside the handler returns "Error sending batch messages: {message}" at 400 (the case-level catch\'s own literal text)', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.toLowerCase().includes('line_user_id from users')) return [{ line_user_id: 'Uabc123' }];
      if (sqlText.toLowerCase().includes('from line_accounts')) {
        throw new Error('connection reset');
      }
      return [];
    });

    const res = await POST(req({ user_id: 1, messages: [{ type: 'text', content: 'hi' }] }));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Error sending batch messages: connection reset' });
    expect(mockPushMessage).not.toHaveBeenCalled();
  });
});
