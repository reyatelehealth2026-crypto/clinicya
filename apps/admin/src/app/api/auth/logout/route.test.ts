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
    logout: jest.fn(),
  };
});

import { cookies } from 'next/headers';
import { logout, TENANT_SESSION_COOKIE, PLATFORM_SESSION_COOKIE } from '@reya/auth';
import { POST } from './route';

const mockCookies = cookies as jest.MockedFunction<typeof cookies>;
const mockLogout = logout as jest.MockedFunction<typeof logout>;

function fakeCookieStore(present: Record<string, string> = {}) {
  return {
    get: jest.fn((name: string) => (name in present ? { name, value: present[name] } : undefined)),
    set: jest.fn(),
    delete: jest.fn(),
  } as unknown as Awaited<ReturnType<typeof cookies>>;
}

function logoutRequest(): Request {
  return new Request('https://tenant-0002.re-ya.com/api/auth/logout', { method: 'POST' });
}

describe('POST /api/auth/logout', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockLogout.mockResolvedValue({ ok: true, value: { bridgeSynced: true } });
  });

  it('only the tenant cookie present: calls logout(sid, "tenant") exactly once, clears reya_sid + PHPSESSID, redirects 303', async () => {
    const cookieStore = fakeCookieStore({ [TENANT_SESSION_COOKIE]: 'sid-tenant' });
    mockCookies.mockResolvedValue(cookieStore);

    const response = await POST(logoutRequest());

    expect(mockLogout).toHaveBeenCalledTimes(1);
    expect(mockLogout).toHaveBeenCalledWith('sid-tenant', 'tenant');
    expect(cookieStore.delete).toHaveBeenCalledWith(TENANT_SESSION_COOKIE);
    expect(cookieStore.delete).toHaveBeenCalledWith('PHPSESSID');
    expect(response.status).toBe(303);
    expect(new URL(response.headers.get('location')!).pathname).toBe('/auth/login');
  });

  it('only the platform cookie present: calls logout(sid, "platform") exactly once, clears reya_platform_sid + PHPSESSID, redirects 303', async () => {
    const cookieStore = fakeCookieStore({ [PLATFORM_SESSION_COOKIE]: 'sid-platform' });
    mockCookies.mockResolvedValue(cookieStore);

    const response = await POST(logoutRequest());

    expect(mockLogout).toHaveBeenCalledTimes(1);
    expect(mockLogout).toHaveBeenCalledWith('sid-platform', 'platform');
    expect(cookieStore.delete).toHaveBeenCalledWith(PLATFORM_SESSION_COOKIE);
    expect(cookieStore.delete).toHaveBeenCalledWith('PHPSESSID');
    expect(response.status).toBe(303);
    expect(new URL(response.headers.get('location')!).pathname).toBe('/auth/login');
  });

  it('both cookies present (browser logged into both realms at different times): calls logout() twice, once per realm, clears all three cookies', async () => {
    const cookieStore = fakeCookieStore({
      [TENANT_SESSION_COOKIE]: 'sid-tenant',
      [PLATFORM_SESSION_COOKIE]: 'sid-platform',
    });
    mockCookies.mockResolvedValue(cookieStore);

    const response = await POST(logoutRequest());

    expect(mockLogout).toHaveBeenCalledTimes(2);
    expect(mockLogout).toHaveBeenCalledWith('sid-tenant', 'tenant');
    expect(mockLogout).toHaveBeenCalledWith('sid-platform', 'platform');
    expect(cookieStore.delete).toHaveBeenCalledWith(TENANT_SESSION_COOKIE);
    expect(cookieStore.delete).toHaveBeenCalledWith(PLATFORM_SESSION_COOKIE);
    expect(cookieStore.delete).toHaveBeenCalledWith('PHPSESSID');
    expect(response.status).toBe(303);
  });

  it('neither cookie present: logout() is never called, handler still clears cookies + redirects without throwing', async () => {
    const cookieStore = fakeCookieStore();
    mockCookies.mockResolvedValue(cookieStore);

    const response = await POST(logoutRequest());

    expect(mockLogout).not.toHaveBeenCalled();
    expect(cookieStore.delete).toHaveBeenCalledWith(TENANT_SESSION_COOKIE);
    expect(cookieStore.delete).toHaveBeenCalledWith(PLATFORM_SESSION_COOKIE);
    expect(cookieStore.delete).toHaveBeenCalledWith('PHPSESSID');
    expect(response.status).toBe(303);
    expect(new URL(response.headers.get('location')!).pathname).toBe('/auth/login');
  });
});
