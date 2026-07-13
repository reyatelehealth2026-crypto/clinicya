/**
 * @jest-environment node
 */
jest.mock('next/headers', () => ({
  cookies: jest.fn(),
}));
jest.mock('@reya/db', () => ({
  getTenantDb: jest.fn(),
}));
jest.mock('@reya/auth', () => {
  const actual = jest.requireActual('@reya/auth');
  return {
    ...actual,
    login: jest.fn(),
    // Real runWithTenantDb is a pure AsyncLocalStorage wrapper (no I/O) — safe to use as-is via `actual`,
    // spelled out explicitly here only so the mock factory's shape is self-documenting.
    runWithTenantDb: actual.runWithTenantDb,
  };
});

import { cookies } from 'next/headers';
import { getTenantDb } from '@reya/db';
import { getTenantDbContext, login, type Session, type SessionCookieDescriptor } from '@reya/auth';
import { POST } from './route';

const mockCookies = cookies as jest.MockedFunction<typeof cookies>;
const mockLogin = login as jest.MockedFunction<typeof login>;
const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;

function fakeCookieStore() {
  return { set: jest.fn() } as unknown as Awaited<ReturnType<typeof cookies>>;
}

function formRequest(fields: Record<string, string>, headers: Record<string, string> = {}): Request {
  const body = new URLSearchParams(fields);
  return new Request('https://tenant-0002.re-ya.com/api/auth/login', {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded', ...headers },
    body: body.toString(),
  });
}

const COOKIE: SessionCookieDescriptor = {
  name: 'reya_sid',
  value: 'sid-xyz',
  httpOnly: true,
  sameSite: 'lax',
  secure: true,
  path: '/',
  maxAge: 3600,
};

describe('POST /api/auth/login', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetTenantDb.mockResolvedValue({ __fakeTenantDb: true } as never);
  });

  it('on success: sets the returned cookie as-is and redirects into (tenant) for a tenant session', async () => {
    const cookieStore = fakeCookieStore();
    mockCookies.mockResolvedValue(cookieStore);

    const session: Session = {
      realm: 'tenant',
      sid: 'sid-xyz',
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
    mockLogin.mockResolvedValue({ ok: true, value: { session, cookie: COOKIE, bridgeSynced: true } });

    const response = await POST(
      formRequest({ realm: 'tenant', username: 'admin1', password: 'secret' }, { 'x-tenant-id': '2' })
    );

    expect(mockGetTenantDb).toHaveBeenCalledWith(2);
    expect(mockLogin).toHaveBeenCalledWith({ realm: 'tenant', username: 'admin1', password: 'secret' });
    expect(cookieStore.set).toHaveBeenCalledWith(COOKIE.name, COOKIE.value, COOKIE);
    expect(response.status).toBe(303);
    expect(response.headers.get('location')).toBe('https://tenant-0002.re-ya.com/dashboard');
  });

  it('on tenant-realm success: also sets a PHPSESSID cookie equal to cookie.value, with attributes copied from the returned descriptor', async () => {
    const cookieStore = fakeCookieStore();
    mockCookies.mockResolvedValue(cookieStore);

    const session: Session = {
      realm: 'tenant',
      sid: 'sid-xyz',
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
    mockLogin.mockResolvedValue({ ok: true, value: { session, cookie: COOKIE, bridgeSynced: true } });

    await POST(formRequest({ realm: 'tenant', username: 'admin1', password: 'secret' }, { 'x-tenant-id': '2' }));

    expect(cookieStore.set).toHaveBeenCalledWith('PHPSESSID', COOKIE.value, {
      httpOnly: COOKIE.httpOnly,
      sameSite: COOKIE.sameSite,
      secure: COOKIE.secure,
      path: COOKIE.path,
      maxAge: COOKIE.maxAge,
    });
  });

  it('on platform-realm success: also sets a PHPSESSID cookie equal to cookie.value, with attributes copied from the returned descriptor', async () => {
    const cookieStore = fakeCookieStore();
    mockCookies.mockResolvedValue(cookieStore);

    const platformCookie: SessionCookieDescriptor = {
      name: 'reya_platform_sid',
      value: 'sid-plat',
      httpOnly: true,
      sameSite: 'lax',
      secure: false,
      path: '/',
      maxAge: 7200,
    };
    const session: Session = {
      realm: 'platform',
      sid: 'sid-plat',
      platformUserId: 1,
      platformRole: 'support',
      email: 'a@b.com',
      name: 'A',
      impersonatedTenantId: null,
      createdAt: '',
      lastSeenAt: '',
      expiresAt: '',
    };
    mockLogin.mockResolvedValue({ ok: true, value: { session, cookie: platformCookie, bridgeSynced: true } });

    await POST(formRequest({ realm: 'platform', email: 'a@b.com', password: 'secret' }));

    expect(cookieStore.set).toHaveBeenCalledWith('reya_platform_sid', platformCookie.value, platformCookie);
    expect(cookieStore.set).toHaveBeenCalledWith('PHPSESSID', platformCookie.value, {
      httpOnly: platformCookie.httpOnly,
      sameSite: platformCookie.sameSite,
      secure: platformCookie.secure,
      path: platformCookie.path,
      maxAge: platformCookie.maxAge,
    });
  });

  it('wraps tenant-realm login() in runWithTenantDb() with the resolved tenant DB (tenantDbContext.ts requirement)', async () => {
    const fakeDb = { __fakeTenantDb: true };
    mockGetTenantDb.mockResolvedValue(fakeDb as never);
    mockCookies.mockResolvedValue(fakeCookieStore());

    let capturedContext: unknown = null;
    mockLogin.mockImplementation(async () => {
      capturedContext = getTenantDbContext();
      return { ok: false, error: { code: 'invalid_credentials' } };
    });

    await POST(formRequest({ realm: 'tenant', username: 'admin1', password: 'x' }, { 'x-tenant-id': '2' }));

    expect(mockGetTenantDb).toHaveBeenCalledWith(2);
    expect(capturedContext).toEqual({ tenantId: 2, db: fakeDb });
  });

  it('missing/invalid x-tenant-id on a tenant-realm submission redirects with an error, never calls login()', async () => {
    const response = await POST(formRequest({ realm: 'tenant', username: 'admin1', password: 'x' }));

    expect(mockLogin).not.toHaveBeenCalled();
    expect(mockGetTenantDb).not.toHaveBeenCalled();
    expect(response.status).toBe(303);
    const location = new URL(response.headers.get('location')!);
    expect(location.pathname).toBe('/auth/login');
    expect(location.searchParams.get('error')).toBe('not_found');
    expect(location.searchParams.get('realm')).toBe('tenant');
  });

  it('on success with bridgeSynced:false: still redirects (soft warning via query param, not a hard failure)', async () => {
    mockCookies.mockResolvedValue(fakeCookieStore());
    const session: Session = {
      realm: 'platform',
      sid: 'sid-p',
      platformUserId: 1,
      platformRole: 'support',
      email: 'a@b.com',
      name: 'A',
      impersonatedTenantId: null,
      createdAt: '',
      lastSeenAt: '',
      expiresAt: '',
    };
    mockLogin.mockResolvedValue({ ok: true, value: { session, cookie: COOKIE, bridgeSynced: false } });

    const response = await POST(formRequest({ realm: 'platform', email: 'a@b.com', password: 'secret' }));

    expect(response.status).toBe(303);
    const location = new URL(response.headers.get('location')!);
    expect(location.pathname).toBe('/platform');
    expect(location.searchParams.get('bridgeWarning')).toBe('1');
  });

  it('on success with bridgeSynced:false: the PHPSESSID cookie is still set (the soft-warning path does not skip the PHP-visible cookie)', async () => {
    const cookieStore = fakeCookieStore();
    mockCookies.mockResolvedValue(cookieStore);
    const session: Session = {
      realm: 'platform',
      sid: 'sid-p',
      platformUserId: 1,
      platformRole: 'support',
      email: 'a@b.com',
      name: 'A',
      impersonatedTenantId: null,
      createdAt: '',
      lastSeenAt: '',
      expiresAt: '',
    };
    mockLogin.mockResolvedValue({ ok: true, value: { session, cookie: COOKIE, bridgeSynced: false } });

    await POST(formRequest({ realm: 'platform', email: 'a@b.com', password: 'secret' }));

    expect(cookieStore.set).toHaveBeenCalledWith('PHPSESSID', COOKIE.value, {
      httpOnly: COOKIE.httpOnly,
      sameSite: COOKIE.sameSite,
      secure: COOKIE.secure,
      path: COOKIE.path,
      maxAge: COOKIE.maxAge,
    });
  });

  it('on failure: redirects back to the login page with the error code, never sets a cookie (including PHPSESSID)', async () => {
    const cookieStore = fakeCookieStore();
    mockCookies.mockResolvedValue(cookieStore);
    mockLogin.mockResolvedValue({ ok: false, error: { code: 'invalid_credentials' } });

    const response = await POST(
      formRequest({ realm: 'tenant', username: 'admin1', password: 'wrong' }, { 'x-tenant-id': '2' })
    );

    expect(response.status).toBe(303);
    const location = new URL(response.headers.get('location')!);
    expect(location.pathname).toBe('/auth/login');
    expect(location.searchParams.get('error')).toBe('invalid_credentials');
    expect(location.searchParams.get('realm')).toBe('tenant');
    expect(cookieStore.set).not.toHaveBeenCalled();
    expect(cookieStore.set).not.toHaveBeenCalledWith('PHPSESSID', expect.anything(), expect.anything());
  });
});
