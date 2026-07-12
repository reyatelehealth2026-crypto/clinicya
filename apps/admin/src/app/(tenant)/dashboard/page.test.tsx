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
jest.mock('@reya/db', () => ({
  getTenantDb: jest.fn(),
}));
jest.mock('./executive', () => ({
  ExecutiveTab: jest.fn(async (props: { dateParam?: string }) => (
    <div data-testid="stub-executive-tab" data-date-param={props.dateParam ?? ''} />
  )),
}));
jest.mock('./crm', () => ({
  CrmTab: jest.fn(async (props: { currentBotId: number | null }) => (
    <div data-testid="stub-crm-tab" data-current-bot-id={String(props.currentBotId)} />
  )),
}));

import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { getSession } from '@reya/auth';
import { getTenantDb } from '@reya/db';
import { ExecutiveTab } from './executive';
import { CrmTab } from './crm';
import DashboardPage from './page';

const mockCookies = cookies as jest.MockedFunction<typeof cookies>;
const mockRedirect = redirect as unknown as jest.Mock;
const mockGetSession = getSession as jest.MockedFunction<typeof getSession>;
const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;
const mockExecutiveTab = ExecutiveTab as unknown as jest.Mock;
const mockCrmTab = CrmTab as unknown as jest.Mock;

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
  currentBotId: 9,
  role: 'admin',
  username: 'admin1',
  displayName: 'ผู้ดูแลระบบ',
  createdAt: '2026-07-01T00:00:00.000Z',
  lastSeenAt: '2026-07-12T00:00:00.000Z',
  expiresAt: '2026-07-13T00:00:00.000Z',
};

describe('(tenant)/dashboard/page', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockCookies.mockResolvedValue(fakeCookieStore('sid-123'));
    mockGetSession.mockResolvedValue(BASE_SESSION);
    mockGetTenantDb.mockResolvedValue({} as never);
  });

  it('renders the executive tab when ?tab= is absent (dashboard.php default)', async () => {
    const element = await DashboardPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByTestId('stub-executive-tab')).toBeInTheDocument();
    expect(screen.queryByTestId('stub-crm-tab')).not.toBeInTheDocument();
  });

  it("falls back to the executive tab for an invalid ?tab= value, exactly like dashboard.php's \$validTabs guard", async () => {
    const element = await DashboardPage({ searchParams: Promise.resolve({ tab: 'xyz' }) });
    render(element);

    expect(screen.getByTestId('stub-executive-tab')).toBeInTheDocument();
    expect(screen.queryByTestId('stub-crm-tab')).not.toBeInTheDocument();
  });

  it('renders the CRM tab for ?tab=crm', async () => {
    const element = await DashboardPage({ searchParams: Promise.resolve({ tab: 'crm' }) });
    render(element);

    expect(screen.getByTestId('stub-crm-tab')).toBeInTheDocument();
    expect(screen.queryByTestId('stub-executive-tab')).not.toBeInTheDocument();
  });

  it('passes the date searchParam through to the executive tab', async () => {
    await DashboardPage({ searchParams: Promise.resolve({ tab: 'executive', date: '2026-03-05' }) });
    expect(mockExecutiveTab).toHaveBeenCalledWith(expect.objectContaining({ dateParam: '2026-03-05' }));
  });

  it("passes the session's currentBotId through to the CRM tab", async () => {
    await DashboardPage({ searchParams: Promise.resolve({ tab: 'crm' }) });
    expect(mockCrmTab).toHaveBeenCalledWith(expect.objectContaining({ currentBotId: 9 }));
  });

  it('resolves the tenant db via the session tenantId', async () => {
    await DashboardPage({ searchParams: Promise.resolve({}) });
    expect(mockGetTenantDb).toHaveBeenCalledWith(2);
  });

  it('redirects to login when there is no session', async () => {
    mockGetSession.mockResolvedValue(null);
    await DashboardPage({ searchParams: Promise.resolve({}) });
    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=tenant');
  });

  it('redirects to login when a PlatformSession resolves under the tenant cookie (defensive narrowing)', async () => {
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
    await DashboardPage({ searchParams: Promise.resolve({}) });
    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=tenant');
  });

  it('redirects to login when the session has no tenantId', async () => {
    mockGetSession.mockResolvedValue({ ...BASE_SESSION, tenantId: null });
    await DashboardPage({ searchParams: Promise.resolve({}) });
    expect(mockRedirect).toHaveBeenCalledWith('/auth/login?realm=tenant');
  });
});
