import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('./_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

// UsersTable ('use client') calls useRouter() — there's no real Next App Router
// context under next/jest's plain jsdom render, so it must be mocked directly.
jest.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: jest.fn() }),
}));

// actions.ts (imported transitively via UsersTable) imports next/cache's
// revalidatePath at module scope — the real module pulls in Next server
// internals that need a global TextEncoder not present under jsdom (see
// actions.test.ts, which mocks this for the same reason).
jest.mock('next/cache', () => ({
  revalidatePath: jest.fn(),
}));

import { makeFakeTenantDb } from './testHelpers/fakeTenantDb';
import UsersPage from './page';

const originalEnv = process.env.ODOO_INTEGRATION_ENABLED;

afterEach(() => {
  if (originalEnv === undefined) {
    delete process.env.ODOO_INTEGRATION_ENABLED;
  } else {
    process.env.ODOO_INTEGRATION_ENABLED = originalEnv;
  }
});

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 1, tenantId: 1 } });
}

describe('UsersPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    delete process.env.ODOO_INTEGRATION_ENABLED;
  });

  it('renders the LINE-tab title, subtitle with the total count, and every user row', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('COUNT(*) AS count')) {
        return [{ count: 2 }];
      }
      if (sqlText.includes('FROM users u')) {
        return [
          { id: 1, lineUserId: 'U1', displayName: 'Somsri', pictureUrl: null, statusMessage: null, isBlocked: 0, createdAt: new Date(), updatedAt: new Date(), lineAccountId: 1, realName: null, phone: null, email: null, birthday: null, tags: null, messageCount: 3, lastMessageAt: null },
          { id: 2, lineUserId: 'U2', displayName: 'Anan', pictureUrl: null, statusMessage: null, isBlocked: 1, createdAt: new Date(), updatedAt: new Date(), lineAccountId: 1, realName: null, phone: null, email: null, birthday: null, tags: 'VIP', messageCount: 0, lastMessageAt: null },
        ];
      }
      return [];
    });

    const element = await UsersPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByRole('heading', { name: 'Customers' })).toBeInTheDocument();
    expect(screen.getByText('ทั้งหมด 2 คน')).toBeInTheDocument();
    expect(screen.getByText('Somsri')).toBeInTheDocument();
    expect(screen.getByText('Anan')).toBeInTheDocument();
    expect(screen.getByText('Blocked')).toBeInTheDocument();
    expect(screen.getByText('Active')).toBeInTheDocument();
  });

  it('shows the empty state when there are no matching users', async () => {
    wireDb(() => []);
    const element = await UsersPage({ searchParams: Promise.resolve({ search: 'nobody' }) });
    render(element);
    expect(screen.getByText('ไม่พบผู้ใช้')).toBeInTheDocument();
  });

  it('only offers the LINE tab when the Odoo kill-switch is off, even if ?tab=odoo is requested', async () => {
    delete process.env.ODOO_INTEGRATION_ENABLED;
    wireDb(() => []);
    const element = await UsersPage({ searchParams: Promise.resolve({ tab: 'odoo' }) });
    render(element);
    // Falls back to the LINE tab body (subtitle format only the LINE branch renders).
    expect(screen.getByText('ทั้งหมด 0 คน')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Odoo Customers' })).not.toBeInTheDocument();
  });

  it('renders a deferred stub panel (not the ported tab) for ?tab=odoo when the kill-switch is on', async () => {
    process.env.ODOO_INTEGRATION_ENABLED = '1';
    wireDb(() => []);
    const element = await UsersPage({ searchParams: Promise.resolve({ tab: 'odoo' }) });
    render(element);

    expect(screen.getByRole('link', { name: 'Odoo Customers' })).toHaveAttribute('aria-current', 'page');
    const phpLink = screen.getByRole('link', { name: /เปิดหน้า Odoo Customers/ });
    expect(phpLink).toHaveAttribute('href', '/users.php?tab=odoo');
  });

  it('renders pagination controls when there is more than one page of results', async () => {
    wireDb((sqlText) => (sqlText.includes('COUNT(*) AS count') ? [{ count: 45 }] : []));
    const element = await UsersPage({ searchParams: Promise.resolve({}) });
    render(element);
    const href = screen.getByRole('link', { name: '3' }).getAttribute('href') ?? '';
    expect(href).toContain('page=3');
  });
});
