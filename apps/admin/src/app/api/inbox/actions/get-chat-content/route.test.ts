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
  const url = `https://tenant.re-ya.com/api/inbox/actions/get-chat-content${search}`;
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

const USER_ROW = {
  id: 42,
  custom_display_name: null,
  display_name: 'Somchai',
  picture_url: 'https://example.com/pic.jpg',
  phone: '0812345678',
  chat_status: 'open',
  unread_count: 2,
};

/** Standard dispatcher: user found, 3 total messages (id 3 newest .. id 1 oldest), no tags/assignees. Override per test via `overrides`. */
function baseQueryImpl(overrides: Record<string, (params: unknown[]) => unknown> = {}) {
  return (sqlText: string, params: unknown[]): unknown => {
    const lower = sqlText.toLowerCase();
    if (overrides.user && lower.includes('from users u')) return overrides.user(params);
    if (lower.includes('from users u')) return [USER_ROW];

    if (overrides.messages && lower.includes('from messages') && lower.includes('order by id desc')) {
      return overrides.messages(params);
    }
    if (lower.includes('from messages') && lower.includes('order by id desc')) {
      return [
        { id: 3, direction: 'incoming', message_type: 'text', content: 'third', created_at: new Date(2026, 7, 14, 10, 2, 0), is_read: 0, sent_by: null },
        { id: 2, direction: 'outgoing', message_type: 'text', content: 'second', created_at: new Date(2026, 7, 14, 10, 1, 0), is_read: 1, sent_by: 'admin:pharmacist1' },
      ];
    }

    if (overrides.count && lower.includes('select count(*) as total')) return overrides.count(params);
    if (lower.includes('select count(*) as total')) return [{ total: 3 }];

    if (overrides.tags && lower.includes('from user_tag_assignments')) return overrides.tags(params);
    if (lower.includes('from user_tag_assignments')) return [];

    if (overrides.assignees && lower.includes('from conversation_multi_assignees')) return overrides.assignees(params);
    if (lower.includes('from conversation_multi_assignees')) return [];

    if (lower.includes('update `messages`')) return { affectedRows: 1 };

    return [];
  };
}

describe('GET /api/inbox/actions/get-chat-content', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?user_id=42'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('405 for POST — method-not-allowed guard', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });

  it.each(['', '?user_id=0', '?user_id=', '?user=0'])(
    '400 "User ID is required" for missing/falsy user id (search=%s), no DB queries issued',
    async (search) => {
      const queries = wireFakeDb(baseQueryImpl());

      const res = await GET(req(search));

      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'User ID is required' });
      expect(queries).toHaveLength(0);
    }
  );

  it('?user_id= resolves the user', async () => {
    wireFakeDb(baseQueryImpl());
    const res = await GET(req('?user_id=42'));
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body.data.user.id).toBe(42);
  });

  it('?user= (no user_id key at all) resolves the same user — the PHP $_GET[user_id] ?? $_GET[user] fallback', async () => {
    wireFakeDb(baseQueryImpl());
    const res = await GET(req('?user=42'));
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body.data.user.id).toBe(42);
  });

  it('user_id wins over user when BOTH are present (isset() semantics: user_id key present -> user_id used even if it differs)', async () => {
    const queries = wireFakeDb(baseQueryImpl());
    await GET(req('?user_id=42&user=999'));
    // the users lookup query is bound with 42, not 999
    const userQuery = queries.find((q) => q.sql.toLowerCase().includes('from users u'));
    expect(userQuery?.params[0]).toBe(42);
  });

  it('404 "User not found" (scoped by id AND line_account_id) when no user row matches', async () => {
    wireFakeDb(baseQueryImpl({ user: () => [] }));

    const res = await GET(req('?user_id=999'));

    expect(res.status).toBe(404);
    expect(await res.json()).toEqual({ success: false, error: 'User not found' });
  });

  it('user lookup is scoped by both id and line_account_id (session.currentBotId ?? 1)', async () => {
    const queries = wireFakeDb(baseQueryImpl(), { currentBotId: 9 });
    await GET(req('?user_id=42'));
    const userQuery = queries.find((q) => q.sql.toLowerCase().includes('from users u'));
    expect(userQuery?.params).toEqual([42, 9]);
  });

  it('lineAccountId falls back to 1 when session.currentBotId is null', async () => {
    const queries = wireFakeDb(baseQueryImpl(), { currentBotId: null });
    await GET(req('?user_id=42'));
    const userQuery = queries.find((q) => q.sql.toLowerCase().includes('from users u'));
    expect(userQuery?.params).toEqual([42, 1]);
  });

  it('limit defaults to 50 and offset defaults to 0 when absent', async () => {
    const queries = wireFakeDb(baseQueryImpl());
    await GET(req('?user_id=42'));
    const msgQuery = queries.find((q) => q.sql.toLowerCase().includes('order by id desc'));
    expect(msgQuery?.params).toEqual([42, 3, 50, 0]);
  });

  it('limit is clamped with min(limit, 100) — a huge requested limit caps at 100', async () => {
    const queries = wireFakeDb(baseQueryImpl());
    await GET(req('?user_id=42&limit=99999'));
    const msgQuery = queries.find((q) => q.sql.toLowerCase().includes('order by id desc'));
    expect(msgQuery?.params).toEqual([42, 3, 100, 0]);
  });

  it('limit/offset below the cap pass through as requested', async () => {
    const queries = wireFakeDb(baseQueryImpl());
    await GET(req('?user_id=42&limit=10&offset=5'));
    const msgQuery = queries.find((q) => q.sql.toLowerCase().includes('order by id desc'));
    expect(msgQuery?.params).toEqual([42, 3, 10, 5]);
  });

  it('messages come back oldest-first (DESC fetch, reversed in application code) — not ORDER BY id ASC', async () => {
    wireFakeDb(baseQueryImpl());
    const res = await GET(req('?user_id=42'));
    const body = await res.json();
    // base fixture returns [id:3 (newest), id:2] from the DESC query -> reversed to [id:2, id:3]
    expect(body.data.messages.map((m: { id: number }) => m.id)).toEqual([2, 3]);
    expect(body.data.messages[0].created_at).toBe('2026-08-14 10:01:00');
    expect(body.data.messages[1].created_at).toBe('2026-08-14 10:02:00');
  });

  it('has_more = (offset + messages.length) < total_messages', async () => {
    // base fixture: 2 rows returned, total=3, offset=0 -> 0+2 < 3 -> true
    wireFakeDb(baseQueryImpl());
    const res = await GET(req('?user_id=42'));
    const body = await res.json();
    expect(body.data.total_messages).toBe(3);
    expect(body.data.has_more).toBe(true);
  });

  it('has_more is false once the returned window reaches the total', async () => {
    wireFakeDb(
      baseQueryImpl({
        messages: () => [
          { id: 3, direction: 'incoming', message_type: 'text', content: 'third', created_at: new Date(2026, 7, 14, 10, 2, 0), is_read: 0, sent_by: null },
        ],
        count: () => [{ total: 1 }],
      })
    );
    const res = await GET(req('?user_id=42&limit=1&offset=0'));
    const body = await res.json();
    expect(body.data.has_more).toBe(false);
  });

  it('assignees is queried unconditionally from conversation_multi_assignees (no table-existence probe) and returned as-is', async () => {
    const queries = wireFakeDb(
      baseQueryImpl({
        assignees: () => [{ admin_id: 7, username: 'pharmacist1', display_name: 'Pharmacist One' }],
      })
    );
    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    const assigneesQuery = queries.find((q) => q.sql.toLowerCase().includes('from conversation_multi_assignees'));
    expect(assigneesQuery).toBeDefined();
    expect(assigneesQuery?.sql.toLowerCase()).toContain('left join admin_users');
    expect(assigneesQuery?.sql).not.toMatch(/show tables/i);
    expect(body.data.assignees).toEqual([{ admin_id: 7, username: 'pharmacist1', display_name: 'Pharmacist One' }]);
  });

  it('every call marks that user\'s unread incoming messages as read as a side effect', async () => {
    const queries = wireFakeDb(baseQueryImpl());
    await GET(req('?user_id=42'));

    const updateQuery = queries.find((q) => q.sql.toLowerCase().includes('update `messages`'));
    expect(updateQuery).toBeDefined();
    expect(updateQuery?.params).toEqual([1, 42, 3, 'incoming', 0]);
  });

  it('display_name prefers custom_display_name when set (non-empty, non-"0")', async () => {
    wireFakeDb(baseQueryImpl({ user: () => [{ ...USER_ROW, custom_display_name: 'ลูกค้า VIP' }] }));
    const res = await GET(req('?user_id=42'));
    const body = await res.json();
    expect(body.data.user.display_name).toBe('ลูกค้า VIP');
  });

  it('display_name falls back to display_name when custom_display_name is empty string', async () => {
    wireFakeDb(baseQueryImpl({ user: () => [{ ...USER_ROW, custom_display_name: '' }] }));
    const res = await GET(req('?user_id=42'));
    const body = await res.json();
    expect(body.data.user.display_name).toBe('Somchai');
  });

  it('happy path: exact response envelope shape', async () => {
    wireFakeDb(
      baseQueryImpl({
        tags: () => [{ id: 1, name: 'VIP', color: '#ff0000' }],
      })
    );

    const res = await GET(req('?user_id=42&limit=10&offset=0'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      data: {
        user: {
          id: 42,
          display_name: 'Somchai',
          picture_url: 'https://example.com/pic.jpg',
          phone: '0812345678',
          chat_status: 'open',
          unread_count: 2,
        },
        messages: [
          { id: 2, direction: 'outgoing', message_type: 'text', content: 'second', created_at: '2026-08-14 10:01:00', is_read: 1, sent_by: 'admin:pharmacist1' },
          { id: 3, direction: 'incoming', message_type: 'text', content: 'third', created_at: '2026-08-14 10:02:00', is_read: 0, sent_by: null },
        ],
        total_messages: 3,
        tags: [{ id: 1, name: 'VIP', color: '#ff0000' }],
        assignees: [],
        has_more: true,
      },
    });
  });

  it('an unhandled error inside the handler returns "Failed to get chat content: {message}" at HTTP 400 (case-level catch, NOT poll.ts\'s 500 "Database error" shape)', async () => {
    wireFakeDb(
      baseQueryImpl({
        assignees: () => {
          throw new Error('Table admin_users does not exist');
        },
      })
    );

    const res = await GET(req('?user_id=42'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({
      success: false,
      error: 'Failed to get chat content: Table admin_users does not exist',
    });
  });
});
