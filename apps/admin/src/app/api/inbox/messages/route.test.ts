/**
 * @jest-environment node
 */
jest.mock('next/headers', () => ({
  cookies: jest.fn(),
}));
jest.mock('@reya/auth', () => {
  const actual = jest.requireActual('@reya/auth');
  return {
    ...actual,
    getSession: jest.fn(),
  };
});
jest.mock('@reya/db', () => ({
  getTenantDb: jest.fn(),
}));
jest.mock('./_lib/query', () => ({
  getMessagesCursor: jest.fn(),
  phpIntCast: jest.requireActual('./_lib/query').phpIntCast,
}));

import { cookies } from 'next/headers';
import type { NextRequest } from 'next/server';
import { getSession, type TenantSession } from '@reya/auth';
import { getTenantDb } from '@reya/db';
import { getMessagesCursor } from './_lib/query';
import { GET } from './route';

const mockCookies = cookies as jest.MockedFunction<typeof cookies>;
const mockGetSession = getSession as jest.MockedFunction<typeof getSession>;
const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;
const mockGetMessagesCursor = getMessagesCursor as jest.MockedFunction<typeof getMessagesCursor>;

const FAKE_DB = { __fakeTenantDb: true };

const SESSION: TenantSession = {
  realm: 'tenant',
  sid: 'sid-123',
  adminUserId: 1,
  tenantId: 5,
  currentBotId: 1,
  role: 'admin',
  username: 'admin1',
  displayName: 'Admin',
  createdAt: '2026-07-01T00:00:00.000Z',
  lastSeenAt: '2026-07-14T00:00:00.000Z',
  expiresAt: '2026-07-15T00:00:00.000Z',
};

function fakeCookieStore(sid: string | undefined) {
  return {
    get: jest.fn(() => (sid ? { name: 'reya_sid', value: sid } : undefined)),
  } as unknown as Awaited<ReturnType<typeof cookies>>;
}

function req(search: string): NextRequest {
  const url = `https://tenant.re-ya.com/api/inbox/messages${search}`;
  return { nextUrl: new URL(url), headers: new Headers(), url } as unknown as NextRequest;
}

describe('GET /api/inbox/messages', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockCookies.mockResolvedValue(fakeCookieStore('sid-123'));
    mockGetTenantDb.mockResolvedValue(FAKE_DB as never);
  });

  it('no session -> 401 Unauthorized, DB never touched', async () => {
    mockGetSession.mockResolvedValue(null);

    const res = await GET(req('?user_id=1'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockGetTenantDb).not.toHaveBeenCalled();
    expect(mockGetMessagesCursor).not.toHaveBeenCalled();
  });

  it('a platform session under the tenant cookie is rejected (defensive realm narrowing)', async () => {
    mockGetSession.mockResolvedValue({
      realm: 'platform',
      sid: 'x',
      platformUserId: 1,
      platformRole: 'support',
      email: 'a@b.com',
      name: 'A',
      impersonatedTenantId: null,
      createdAt: '',
      lastSeenAt: '',
      expiresAt: '',
    });

    const res = await GET(req('?user_id=1'));
    expect(res.status).toBe(401);
  });

  it('a session with no active tenant (super_admin who never entered one) -> 401', async () => {
    mockGetSession.mockResolvedValue({ ...SESSION, tenantId: null });
    const res = await GET(req('?user_id=1'));
    expect(res.status).toBe(401);
  });

  it('missing/zero/negative user_id -> 400 Invalid user ID', async () => {
    mockGetSession.mockResolvedValue(SESSION);

    for (const search of ['', '?user_id=0', '?user_id=-5']) {
      const res = await GET(req(search));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
    }
    expect(mockGetMessagesCursor).not.toHaveBeenCalled();
  });

  it('resolves the tenant DB from the session (not any client-supplied value) and forwards parsed params', async () => {
    mockGetSession.mockResolvedValue(SESSION);
    mockGetMessagesCursor.mockResolvedValue({ messages: [], next_cursor: null, has_more: false, count: 0 });

    const res = await GET(req('?user_id=42&cursor=100&limit=20'));

    expect(mockGetTenantDb).toHaveBeenCalledWith(5);
    expect(mockGetMessagesCursor).toHaveBeenCalledWith(FAKE_DB, 42, '100', 20);
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({
      success: true,
      data: { messages: [], next_cursor: null, has_more: false, count: 0 },
    });
  });

  it('defaults limit to 50 when absent', async () => {
    mockGetSession.mockResolvedValue(SESSION);
    mockGetMessagesCursor.mockResolvedValue({ messages: [], next_cursor: null, has_more: false, count: 0 });

    await GET(req('?user_id=1'));

    expect(mockGetMessagesCursor).toHaveBeenCalledWith(FAKE_DB, 1, null, 50);
  });

  it('cursor absent -> null; cursor present-but-empty -> "" (distinct from absent, matches PHP isset() semantics)', async () => {
    mockGetSession.mockResolvedValue(SESSION);
    mockGetMessagesCursor.mockResolvedValue({ messages: [], next_cursor: null, has_more: false, count: 0 });

    await GET(req('?user_id=1'));
    expect(mockGetMessagesCursor).toHaveBeenLastCalledWith(FAKE_DB, 1, null, 50);

    await GET(req('?user_id=1&cursor='));
    expect(mockGetMessagesCursor).toHaveBeenLastCalledWith(FAKE_DB, 1, '', 50);
  });

  it('an out-of-range limit RESETS to 50 (not clamped to the boundary) — api/inbox-v2.php lines 2806-2809', async () => {
    mockGetSession.mockResolvedValue(SESSION);
    mockGetMessagesCursor.mockResolvedValue({ messages: [], next_cursor: null, has_more: false, count: 0 });

    await GET(req('?user_id=1&limit=9999'));
    expect(mockGetMessagesCursor).toHaveBeenLastCalledWith(FAKE_DB, 1, null, 50);

    await GET(req('?user_id=1&limit=0'));
    expect(mockGetMessagesCursor).toHaveBeenLastCalledWith(FAKE_DB, 1, null, 50);

    await GET(req('?user_id=1&limit=100'));
    expect(mockGetMessagesCursor).toHaveBeenLastCalledWith(FAKE_DB, 1, null, 100);
  });

  it('a thrown error from the query layer -> 400 with a "Failed to get messages: ..." envelope', async () => {
    mockGetSession.mockResolvedValue(SESSION);
    mockGetMessagesCursor.mockRejectedValue(new Error('boom'));

    const res = await GET(req('?user_id=1'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to get messages: boom' });
  });
});
