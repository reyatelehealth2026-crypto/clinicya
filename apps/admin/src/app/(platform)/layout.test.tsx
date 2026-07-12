import { render, screen } from '@testing-library/react';
import type { PlatformSession } from '@reya/auth';

jest.mock('next/headers', () => ({
  cookies: jest.fn(),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
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
import { getSession } from '@reya/auth';
import PlatformLayout from './layout';

const mockCookies = cookies as jest.MockedFunction<typeof cookies>;
const mockRedirect = redirect as unknown as jest.Mock;
const mockGetSession = getSession as jest.MockedFunction<typeof getSession>;

function fakeCookieStore(sid: string | undefined) {
  return {
    get: jest.fn(() => (sid ? { name: 'reya_platform_sid', value: sid } : undefined)),
  } as unknown as Awaited<ReturnType<typeof cookies>>;
}

const BASE_SESSION: PlatformSession = {
  realm: 'platform',
  sid: 'sid-abc',
  platformUserId: 9,
  platformRole: 'support',
  email: 'support@re-ya.com',
  name: 'Platform Support',
  impersonatedTenantId: null,
  createdAt: '2026-07-01T00:00:00.000Z',
  lastSeenAt: '2026-07-12T00:00:00.000Z',
  expiresAt: '2026-07-13T00:00:00.000Z',
};

describe('(platform)/layout', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockCookies.mockResolvedValue(fakeCookieStore('sid-abc'));
  });

  it('renders the platform user name for an authenticated session', async () => {
    mockGetSession.mockResolvedValue(BASE_SESSION);

    const element = await PlatformLayout({ children: <div data-testid="child">hi</div> });
    render(element);

    expect(screen.getByTestId('platform-user-name')).toHaveTextContent('Platform Support');
    expect(screen.getByTestId('child')).toBeInTheDocument();
    expect(screen.queryByTestId('impersonation-banner')).not.toBeInTheDocument();
    expect(mockRedirect).not.toHaveBeenCalled();
  });

  it('shows the impersonation banner when impersonatedTenantId is set', async () => {
    mockGetSession.mockResolvedValue({ ...BASE_SESSION, impersonatedTenantId: 42 });

    const element = await PlatformLayout({ children: <div>x</div> });
    render(element);

    expect(screen.getByTestId('impersonation-banner')).toHaveTextContent('42');
  });

  it('redirects to /auth/login?realm=platform when there is no session', async () => {
    mockGetSession.mockResolvedValue(null);

    await PlatformLayout({ children: <div>x</div> });

    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=platform');
  });

  it('redirects when getSession resolves a TenantSession under the platform cookie (defensive narrowing)', async () => {
    mockGetSession.mockResolvedValue({
      realm: 'tenant',
      sid: 'x',
      adminUserId: 1,
      tenantId: 1,
      currentBotId: null,
      role: 'staff',
      username: 'u',
      displayName: 'U',
      createdAt: '',
      lastSeenAt: '',
      expiresAt: '',
    });

    await PlatformLayout({ children: <div>x</div> });

    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=platform');
  });
});
