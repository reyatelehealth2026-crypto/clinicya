import { render, screen } from '@testing-library/react';
import type { TenantSession } from '@reya/auth';

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
import TenantLayout from './layout';

const mockCookies = cookies as jest.MockedFunction<typeof cookies>;
const mockRedirect = redirect as unknown as jest.Mock;
const mockGetSession = getSession as jest.MockedFunction<typeof getSession>;

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
  role: 'pharmacist',
  username: 'pharm1',
  displayName: 'เภสัชกร ทดสอบ',
  createdAt: '2026-07-01T00:00:00.000Z',
  lastSeenAt: '2026-07-12T00:00:00.000Z',
  expiresAt: '2026-07-13T00:00:00.000Z',
};

describe('(tenant)/layout', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockCookies.mockResolvedValue(fakeCookieStore('sid-123'));
  });

  it('renders role-filtered nav + display name for an authenticated session', async () => {
    mockGetSession.mockResolvedValue(BASE_SESSION);

    const element = await TenantLayout({ children: <div data-testid="child">hello</div> });
    render(element);

    expect(screen.getByTestId('tenant-display-name')).toHaveTextContent('เภสัชกร ทดสอบ');
    expect(screen.getByTestId('child')).toBeInTheDocument();
    // pharmacist should see 'pharmacy' (roles include pharmacist) but not 'overview' (owner/admin only)
    expect(screen.getByRole('link', { name: /งานเภสัช/ })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /ภาพรวม/ })).not.toBeInTheDocument();
    expect(mockRedirect).not.toHaveBeenCalled();
  });

  it('redirects to /auth/login?realm=tenant when there is no session', async () => {
    mockGetSession.mockResolvedValue(null);

    await TenantLayout({ children: <div>child</div> });

    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=tenant');
  });

  it('redirects when getSession resolves a PlatformSession under the tenant cookie (defensive narrowing)', async () => {
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

    await TenantLayout({ children: <div>child</div> });

    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=tenant');
  });
});
