import { render, screen } from '@testing-library/react';

const mockRequireAnalyticsPageContext = jest.fn();
jest.mock('./_lib/session', () => ({
  requireAnalyticsPageContext: () => mockRequireAnalyticsPageContext(),
}));

// AdvancedControls ('use client') calls useRouter(); RealtimeBar/FunnelChart/AccountSelect
// don't strictly need it but live in the same 'use client' tree tested here.
jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: jest.fn(), refresh: jest.fn() }),
}));

// actions.ts (imported transitively via AdvancedTab's client islands) is a Server Action
// module — safe to import directly under jsdom (no next/cache import here, unlike
// users/loyalty-members actions.ts), so no extra mock is required for it.

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import AnalyticsPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown, role: 'admin' | 'super_admin' = 'admin') {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireAnalyticsPageContext.mockResolvedValue({ db, session: { currentBotId: 1, tenantId: 1, role } });
}

describe('AnalyticsPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders the overview tab by default with stat cards and the period selector', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE (line_account_id')) return [{ total: 120 }];
      if (sqlText.includes('FROM broadcasts')) return [{ total: 3, recipients: 90 }];
      return [];
    });

    const element = await AnalyticsPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByText('📊 สถิติรวม')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'ภาพรวม' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByText('120')).toBeInTheDocument(); // followers stat
    // Period chips render as links preserving tab=overview.
    expect(screen.getByRole('link', { name: '30 วัน' })).toHaveAttribute('href', '/analytics?tab=overview&period=30');
  });

  it('renders the advanced tab, including the realtime bar and stat cards', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('SELECT COUNT(*) AS total FROM users WHERE 1=1')) return [{ total: 500 }];
      return [];
    });
    const element = await AnalyticsPage({ searchParams: Promise.resolve({ tab: 'advanced' }) });
    render(element);
    expect(screen.getByText('Advanced Analytics')).toBeInTheDocument();
    expect(screen.getByText('Real-time')).toBeInTheDocument();
    expect(screen.getByText('500')).toBeInTheDocument();
  });

  it('renders the crm tab with the days selector', async () => {
    wireDb(() => []);
    const element = await AnalyticsPage({ searchParams: Promise.resolve({ tab: 'crm', days: '7' }) });
    render(element);
    expect(screen.getByText(/CRM Analytics/)).toBeInTheDocument();
    expect(screen.getByText(/Active Users/)).toBeInTheDocument();
  });

  it('renders the account tab prompting bot selection when no account_id is given', async () => {
    wireDb((sqlText) => (sqlText.includes('FROM line_accounts') ? [{ id: 1, name: 'บอทหลัก', is_default: 1 }] : []));
    const element = await AnalyticsPage({ searchParams: Promise.resolve({ tab: 'account' }) });
    render(element);
    expect(screen.getByText('กรุณาเลือกบอทเพื่อดูสถิติ')).toBeInTheDocument();
    expect(screen.getByText('บอทหลัก (หลัก)')).toBeInTheDocument();
  });

  it('falls back to the overview tab for an unrecognised ?tab= value, matching getActiveTab()', async () => {
    wireDb(() => []);
    const element = await AnalyticsPage({ searchParams: Promise.resolve({ tab: 'nonsense' }) });
    render(element);
    expect(screen.getByRole('link', { name: 'ภาพรวม' })).toHaveAttribute('aria-current', 'page');
  });
});
