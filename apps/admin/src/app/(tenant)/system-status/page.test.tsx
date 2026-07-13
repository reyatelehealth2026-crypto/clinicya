import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import SystemStatusPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown, currentBotId: number | null = 7) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId, tenantId: 1, adminUserId: 3 } });
}

describe('SystemStatusPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders the title and returns 200-worth of content (no throw) against an unmodified tenant template seed (admin_users/crm_deals absent)', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('admin_users')) throw new Error("Table 'tenant.admin_users' doesn't exist");
      return [{ total: 0, unread: 0 }];
    });

    const element = await SystemStatusPage();
    render(element);

    expect(screen.getByRole('heading', { name: /เช็คสถานะระบบ Inbox V2/ })).toBeInTheDocument();
    // 19 checks rendered as cards (one data-testid="check-{key}" per StatusCheck)
    expect(document.querySelectorAll('[data-testid^="check-"]')).toHaveLength(19);
  });

  it('shows the healthy banner when every portable check succeeds', async () => {
    wireDb(() => [{ total: 0, unread: 0 }]);
    const element = await SystemStatusPage();
    render(element);
    expect(screen.getByTestId('overall-heading')).toHaveTextContent('ระบบทำงานปกติ');
  });

  it('shows the critical banner when a required table is missing', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('admin_users')) throw new Error("doesn't exist");
      return [{ total: 0, unread: 0 }];
    });
    const element = await SystemStatusPage();
    render(element);
    expect(screen.getByTestId('overall-heading')).toHaveTextContent('ระบบมีปัญหา');
  });

  it('renders the 8 not_ported placeholder rows with the informational message, never a faked ok', async () => {
    wireDb(() => [{ total: 0, unread: 0 }]);
    const element = await SystemStatusPage();
    render(element);
    expect(screen.getAllByText(/ยังไม่ได้พอร์ตมายัง Next\.js/).length).toBe(8);
  });

  it('falls back to bot id 1 when there is no current bot, mirroring `$_SESSION[\'current_bot_id\'] ?? 1`', async () => {
    wireDb(() => [{ total: 0, unread: 0 }], null);
    const element = await SystemStatusPage();
    render(element);
    expect(screen.getByText('Current Bot ID:').parentElement).toHaveTextContent('1');
  });

  it('renders the quick action links with the same hrefs as system-status.php', async () => {
    wireDb(() => [{ total: 0, unread: 0 }]);
    const element = await SystemStatusPage();
    render(element);
    expect(screen.getByRole('link', { name: /เปิด Inbox V2/ })).toHaveAttribute('href', '/inbox-v2');
    expect(screen.getByRole('link', { name: /เปิด Inbox V1/ })).toHaveAttribute('href', '/inbox');
    expect(screen.getByRole('link', { name: /ตั้งค่า Vibe Selling/ })).toHaveAttribute('href', '/settings?tab=vibe-selling');
    expect(screen.getByRole('link', { name: /Dev Dashboard/ })).toHaveAttribute('href', '/dev-dashboard');
  });
});
