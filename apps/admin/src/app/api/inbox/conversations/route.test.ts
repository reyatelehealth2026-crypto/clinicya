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

function req(search: string): NextRequest {
  const url = `https://admin.re-ya.com/api/inbox/conversations${search}`;
  return { url } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 1,
    tenantId: 1,
    currentBotId: 7,
    role: 'admin',
    username: 'admin',
    displayName: 'Admin',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
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

function rawRow(id: number, lastMessageAt: string) {
  return {
    id,
    display_name: `User ${id}`,
    picture_url: null,
    chat_status: null,
    platform: 'line',
    platform_user_id: null,
    last_message_at: lastMessageAt,
    assigned_to: null,
    assignment_status: null,
  };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/conversations', () => {
  it('401 JSON when unauthenticated (no page redirect — this is a fetch()-consumed API)', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(req(''));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('200, empty conversations, filters=[] (PHP empty-array-encodes-as-array quirk) when no filters/search given', async () => {
    wireFakeDb(() => []);
    const res = await GET(req(''));
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body).toEqual({
      success: true,
      data: { conversations: [], next_cursor: null, has_more: false, count: 0 },
      search: null,
      filters: [],
    });
  });

  it('byte-identical shape for a representative seeded fixture: snake_case keys, correct nesting, row field order', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('LEFT JOIN conversation_assignments')) {
        return [rawRow(5, '2026-07-10 09:00:00')];
      }
      if (sqlText.includes('MAX(id) as max_id')) {
        return [{ user_id: 5, preview: 'hello there', type: 'text' }];
      }
      if (sqlText.includes('is_read = 0')) {
        return [{ user_id: 5, unread: 2 }];
      }
      if (sqlText.includes('user_tags ut')) {
        return [{ user_id: 5, id: 1, name: 'VIP', color: '#ff0000' }];
      }
      if (sqlText.includes('conversation_multi_assignees')) {
        return [{ user_id: 5, admin_id: 9 }];
      }
      return [];
    });

    const res = await GET(req('?limit=10'));
    const body = await res.json();

    const expected = {
      success: true,
      data: {
        conversations: [
          {
            id: 5,
            display_name: 'User 5',
            picture_url: null,
            chat_status: null,
            platform: 'line',
            platform_user_id: null,
            last_message_at: '2026-07-10 09:00:00',
            assigned_to: null,
            assignment_status: null,
            unread_count: 2,
            last_message_preview: 'hello there',
            last_message_type: 'text',
            tags: [{ id: 1, name: 'VIP', color: '#ff0000' }],
            assignees: [9],
          },
        ],
        next_cursor: null,
        has_more: false,
        count: 1,
      },
      search: null,
      filters: [],
    };

    // Full-body deep equality (shape/nesting) ...
    expect(body).toEqual(expected);
    // ... AND a literal serialized-text comparison, so key ORDER is asserted too
    // (both objects were built with the same insertion order deliberately).
    expect(JSON.stringify(body)).toBe(JSON.stringify(expected));
  });

  it('echoes filters as an object (not []) once at least one filter is present, preserving chatStatus/unreadOnly/tagId/assigneeId/platform insertion order', async () => {
    wireFakeDb(() => []);
    const res = await GET(req('?chatStatus=pending&unreadOnly=true&tagId=3&assigneeId=unassigned&platform=line'));
    const body = await res.json();
    expect(Object.keys(body.filters)).toEqual(['chatStatus', 'unreadOnly', 'tagId', 'assigneeId', 'platform']);
    expect(body.filters).toEqual({ chatStatus: 'pending', unreadOnly: true, tagId: 3, assigneeId: 'unassigned', platform: 'line' });
  });

  it('echoes a trimmed search string', async () => {
    wireFakeDb(() => []);
    const res = await GET(req('?search=%20%20somsri%20%20'));
    const body = await res.json();
    expect(body.search).toBe('somsri');
  });

  it.each(['0', '-1', '501', 'abc', ''])('limit=%s out of [1,500] silently falls back to 200 (not an error)', async (rawLimit) => {
    const queries = wireFakeDb(() => []);
    const res = await GET(req(`?limit=${rawLimit}`));
    expect(res.status).toBe(200);
    const mainQuery = queries.find((q) => q.sql.includes('LEFT JOIN conversation_assignments'));
    // getConversationsDelta's own internal cap: min(100, 200) = 100 -> LIMIT bound to 101.
    expect(mainQuery?.params[mainQuery.params.length - 1]).toBe(101);
  });

  it('platform=facebook scopes the query by u.platform, using session.currentBotId as the default line account id otherwise', async () => {
    const queries = wireFakeDb(() => [], { currentBotId: 42 });
    await GET(req('?platform=facebook'));
    const mainQuery = queries.find((q) => q.sql.includes('LEFT JOIN conversation_assignments'));
    expect(mainQuery?.sql).toContain("u.platform = 'facebook'");
    expect(mainQuery?.params).not.toContain(42);
  });

  it('defaults line_account_id scoping to session.currentBotId ?? 1', async () => {
    const queries = wireFakeDb(() => [], { currentBotId: null });
    await GET(req(''));
    const mainQuery = queries.find((q) => q.sql.includes('LEFT JOIN conversation_assignments'));
    expect(mainQuery?.params[0]).toBe(1);
  });

  it('DB failure -> 400 {success:false, error: "Failed to get conversations: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });
    const res = await GET(req(''));
    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
    expect(body.error).toContain('Failed to get conversations:');
  });
});

describe('POST /api/inbox/conversations', () => {
  it('405 Method not allowed, matching PHP sendError shape', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
