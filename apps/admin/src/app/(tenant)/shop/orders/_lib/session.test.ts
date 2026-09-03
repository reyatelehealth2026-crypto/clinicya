jest.mock('next/headers', () => ({
  cookies: jest.fn(),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));
jest.mock('@reya/db', () => ({
  getTenantDb: jest.fn(),
}));
jest.mock('@reya/auth', () => {
  const actual = jest.requireActual('@reya/auth');
  return {
    ...actual,
    getSession: jest.fn(),
  };
});

import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { getTenantDb } from '@reya/db';
import { getSession, type TenantSession } from '@reya/auth';
import { requireTenantPageContext } from './session';

const mockCookies = cookies as jest.MockedFunction<typeof cookies>;
const mockRedirect = redirect as unknown as jest.Mock;
const mockGetSession = getSession as jest.MockedFunction<typeof getSession>;
const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;

function fakeCookieStore(sid: string | undefined) {
  return {
    get: jest.fn(() => (sid ? { name: 'reya_sid', value: sid } : undefined)),
  } as unknown as Awaited<ReturnType<typeof cookies>>;
}

const BASE_SESSION: TenantSession = {
  realm: 'tenant',
  sid: 'sid-123',
  adminUserId: 1,
  tenantId: 2,
  currentBotId: 1,
  role: 'admin',
  username: 'admin1',
  displayName: 'Admin One',
  createdAt: '2026-07-01T00:00:00.000Z',
  lastSeenAt: '2026-07-12T00:00:00.000Z',
  expiresAt: '2026-07-13T00:00:00.000Z',
};

describe('requireTenantPageContext', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockCookies.mockResolvedValue(fakeCookieStore('sid-123'));
  });

  it('resolves session + a tenant db for a valid tenant-realm session', async () => {
    mockGetSession.mockResolvedValue(BASE_SESSION);
    const fakeDb = { fake: true } as unknown as ReturnType<typeof getTenantDb> extends Promise<infer T> ? T : never;
    mockGetTenantDb.mockResolvedValue(fakeDb);

    const ctx = await requireTenantPageContext();

    expect(ctx.session.adminUserId).toBe(1);
    expect(ctx.session.tenantId).toBe(2);
    expect(ctx.db).toBe(fakeDb);
    expect(mockGetTenantDb).toHaveBeenCalledWith(2);
    expect(mockRedirect).not.toHaveBeenCalled();
  });

  it('redirects to /auth/login?realm=tenant when there is no session', async () => {
    mockGetSession.mockResolvedValue(null);
    await expect(requireTenantPageContext()).rejects.toThrow();
    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=tenant');
  });

  it('redirects when the session role is not an allowed tenant role', async () => {
    // requireRole()'s allow-list is every TenantRole, so simulate the "wrong realm" defensive case instead.
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
    await expect(requireTenantPageContext()).rejects.toThrow();
    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=tenant');
  });

  it('redirects a super_admin session with no active tenant (tenantId === null)', async () => {
    mockGetSession.mockResolvedValue({ ...BASE_SESSION, role: 'super_admin', tenantId: null });
    await expect(requireTenantPageContext()).rejects.toThrow();
    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=tenant');
    expect(mockGetTenantDb).not.toHaveBeenCalled();
  });
});
