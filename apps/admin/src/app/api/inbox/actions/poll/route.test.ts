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
  const url = `https://tenant.re-ya.com/api/inbox/actions/poll${search}`;
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

beforeEach(() => {
  jest.clearAllMocks();
});

const CREATED_AT = new Date(2026, 7, 14, 10, 0, 0);
const LAST_INTERACTION = new Date(2026, 7, 14, 10, 5, 0);

describe('GET /api/inbox/actions/poll', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req());

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('happy path: joins messages+users scoped to line_account_id, filters created_at > FROM_UNIXTIME(since), and returns exactly {new_messages, conversation_updates} (no top-level "count")', async () => {
    const messageRow = {
      id: 501,
      user_id: 10,
      direction: 'incoming',
      message_type: 'text',
      content: 'Hello there',
      is_read: 0,
      created_at: CREATED_AT,
      display_name: 'Somchai',
      picture_url: 'https://example.com/pic.jpg',
      last_interaction: LAST_INTERACTION,
    };
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('JOIN users u')) {
        return [messageRow];
      }
      if (sqlText.includes('unread_count')) {
        return [{ unread_count: 4 }];
      }
      return [];
    });

    const res = await GET(req('?since=1700000000'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        new_messages: [
          {
            id: 501,
            user_id: 10,
            direction: 'incoming',
            message_type: 'text',
            content: 'Hello there',
            is_read: 0,
            created_at: '2026-08-14 10:00:00',
            display_name: 'Somchai',
            picture_url: 'https://example.com/pic.jpg',
            last_interaction: '2026-08-14 10:05:00',
          },
        ],
        conversation_updates: [
          {
            user_id: 10,
            display_name: 'Somchai',
            picture_url: 'https://example.com/pic.jpg',
            last_message_at: '2026-08-14 10:05:00',
            last_message_preview: 'Hello there',
            unread_count: 4,
          },
        ],
      },
    });
    // response never carries a "count" key anywhere — pollUpdates()'s own count is dropped.
    expect(body.data.count).toBeUndefined();
    expect(body.count).toBeUndefined();

    // Exact SQL text + params on both queries.
    expect(queries).toHaveLength(2);
    const [messagesQuery, unreadQuery] = queries;
    expect(messagesQuery.sql).toContain('FROM messages m');
    expect(messagesQuery.sql).toContain('JOIN users u ON u.id = m.user_id');
    expect(messagesQuery.sql).toContain('WHERE u.line_account_id = ?');
    expect(messagesQuery.sql).toContain('AND m.created_at > FROM_UNIXTIME(?)');
    expect(messagesQuery.sql).toContain('ORDER BY m.created_at ASC');
    expect(messagesQuery.params).toEqual([3, 1700000000]); // [lineAccountId (session.currentBotId), since]

    expect(unreadQuery.sql).toContain('SELECT COUNT(*) as unread_count');
    expect(unreadQuery.sql).toContain('FROM messages');
    expect(unreadQuery.sql).toContain("WHERE user_id = ?");
    expect(unreadQuery.sql).toContain("AND direction = 'incoming'");
    expect(unreadQuery.sql).toContain('AND is_read = 0');
    expect(unreadQuery.params).toEqual([10]); // no line_account_id bound — matches PHP's own un-scoped query
  });

  it('`since` defaults to 0 when the query param is missing/non-numeric (PHP `(int)($_GET[\'since\'] ?? 0)`)', async () => {
    const queries = wireFakeDb((sqlText) => (sqlText.includes('JOIN users u') ? [] : []));

    await GET(req()); // no ?since=
    let messagesQuery = queries.find((q) => q.sql.includes('JOIN users u'));
    expect(messagesQuery!.params[1]).toBe(0);

    queries.length = 0;
    await GET(req('?since=not-a-number'));
    messagesQuery = queries.find((q) => q.sql.includes('JOIN users u'));
    expect(messagesQuery!.params[1]).toBe(0);
  });

  it('dedupe: 2 new_messages rows sharing one user_id collapse to exactly 1 conversation_updates entry, and the unread-count query fires exactly once (not twice)', async () => {
    const sharedUser = {
      user_id: 20,
      display_name: 'Malee',
      picture_url: null,
      last_interaction: LAST_INTERACTION,
    };
    const rows = [
      { id: 1, ...sharedUser, direction: 'incoming', message_type: 'text', content: 'First message', is_read: 0, created_at: CREATED_AT },
      { id: 2, ...sharedUser, direction: 'incoming', message_type: 'text', content: 'Second message', is_read: 0, created_at: CREATED_AT },
    ];
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('JOIN users u')) {
        return rows;
      }
      if (sqlText.includes('unread_count')) {
        return [{ unread_count: 2 }];
      }
      return [];
    });

    const res = await GET(req());
    const body = await res.json();

    expect(body.data.new_messages).toHaveLength(2); // both raw messages are still surfaced
    expect(body.data.conversation_updates).toHaveLength(1); // but only one conversation-level update
    expect(body.data.conversation_updates[0]).toMatchObject({ user_id: 20, unread_count: 2 });

    const unreadQueries = queries.filter((q) => q.sql.includes('unread_count'));
    expect(unreadQueries).toHaveLength(1); // fired exactly once, not once per duplicate row
  });

  it('a thrown DB error -> 500 {success:false, error: "Database error: ..."} (defensive, not a literal PHP catch)', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('connection refused');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await GET(req());
    const body = await res.json();

    expect(res.status).toBe(500);
    expect(body).toEqual({ success: false, error: 'Database error: connection refused' });
  });

  it('POST is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
