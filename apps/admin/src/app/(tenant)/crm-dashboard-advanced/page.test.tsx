import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

// actions.ts (imported transitively via AddDealModal/CreateTicketModal/Customer360Modal) imports
// next/cache's revalidatePath at module scope — the real module needs Next server internals not
// present under plain jsdom (same issue loyalty-members/page.test.tsx's own doc comment flags).
jest.mock('next/cache', () => ({
  revalidatePath: jest.fn(),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import CrmDashboardAdvancedPage from './page';

const MISSING_TABLES = ['crm_deals', 'crm_tickets', 'crm_ticket_interactions'];

// getRevenueAnalytics() selects a `created_at` column off `odoo_webhooks_log`
// that doesn't exist on the committed tenant template (real columns are
// received_at/processed_at) -> real MySQL raises ER_BAD_FIELD_ERROR, not a
// missing-table error. Simulated separately from MISSING_TABLES so this
// regression path (queries.ts:getRevenueAnalytics) stays covered.
function simulateOdooWebhooksLogBadColumn(sqlText: string): boolean {
  return sqlText.includes('odoo_webhooks_log') && sqlText.includes('created_at');
}

function wireMissingCrmTablesDb() {
  const { db } = makeFakeTenantDb((sqlText) => {
    if (simulateOdooWebhooksLogBadColumn(sqlText)) {
      throw new Error("Unknown column 'created_at' in 'field list'");
    }
    if (MISSING_TABLES.some((t) => sqlText.includes(t))) {
      throw new Error(`Table 'tenant.${MISSING_TABLES.find((t) => sqlText.includes(t))}' doesn't exist`);
    }
    return [];
  });
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 7, tenantId: 1, adminUserId: 3 } });
}

describe('CrmDashboardAdvancedPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it.each(['overview', 'pipeline', 'service', 'marketing', 'analytics', 'customers', 'deals', 'tickets', 'reports'])(
    'renders tab=%s without throwing against a tenant DB lacking crm_deals/crm_tickets/crm_ticket_interactions (regression check for the defensive-fallback requirement)',
    async (tab) => {
      wireMissingCrmTablesDb();
      const element = await CrmDashboardAdvancedPage({ searchParams: Promise.resolve({ tab }) });
      expect(() => render(element)).not.toThrow();
    }
  );

  it('defaults to the overview tab when ?tab= is absent', async () => {
    wireMissingCrmTablesDb();
    const element = await CrmDashboardAdvancedPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByRole('heading', { name: 'Executive Overview' })).toBeInTheDocument();
  });

  it('defaults to the overview tab for an unknown ?tab= value', async () => {
    wireMissingCrmTablesDb();
    const element = await CrmDashboardAdvancedPage({ searchParams: Promise.resolve({ tab: 'not-a-real-tab' }) });
    render(element);
    expect(screen.getByRole('heading', { name: 'Executive Overview' })).toBeInTheDocument();
  });

  it('renders the 9-tab nav with all expected ?tab= links', async () => {
    wireMissingCrmTablesDb();
    const element = await CrmDashboardAdvancedPage({ searchParams: Promise.resolve({}) });
    render(element);

    for (const [key, label] of [
      ['overview', 'Executive Overview'],
      ['pipeline', 'Sales Pipeline'],
      ['service', 'Service Center'],
      ['marketing', 'Marketing Hub'],
      ['analytics', 'Analytics Studio'],
      ['customers', 'Customers'],
      ['deals', 'All Deals'],
      ['tickets', 'Tickets'],
      ['reports', 'Reports'],
    ]) {
      const link = screen.getByRole('link', { name: label });
      expect(link).toHaveAttribute('href', `/crm-dashboard-advanced?tab=${key}`);
    }
  });

  it('renders real users-table data on the Executive Overview tab even with crm_deals/crm_tickets missing', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (simulateOdooWebhooksLogBadColumn(sqlText)) {
        throw new Error("Unknown column 'created_at' in 'field list'");
      }
      if (MISSING_TABLES.some((t) => sqlText.includes(t))) {
        throw new Error("doesn't exist");
      }
      if (sqlText.includes('FROM users') && sqlText.includes('is_blocked = 0') && !sqlText.includes('GROUP BY')) {
        return [{ count: 123 }];
      }
      return [];
    });
    mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 7, tenantId: 1, adminUserId: 3 } });

    const element = await CrmDashboardAdvancedPage({ searchParams: Promise.resolve({ tab: 'overview' }) });
    render(element);
    expect(screen.getByText('123')).toBeInTheDocument();
  });

  it('renders the Sales Pipeline kanban board with 6 stage columns even when crm_deals is missing', async () => {
    wireMissingCrmTablesDb();
    const element = await CrmDashboardAdvancedPage({ searchParams: Promise.resolve({ tab: 'pipeline' }) });
    render(element);
    for (const label of ['New Leads', 'Qualified', 'Proposal', 'Negotiation', 'Closed Won', 'Closed Lost']) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }
  });

  it('renders the Reports tab with the 3 static report-type cards', async () => {
    wireMissingCrmTablesDb();
    const element = await CrmDashboardAdvancedPage({ searchParams: Promise.resolve({ tab: 'reports' }) });
    render(element);
    expect(screen.getByText('Sales Report')).toBeInTheDocument();
    expect(screen.getByText('Customer Report')).toBeInTheDocument();
    expect(screen.getByText('Team Performance')).toBeInTheDocument();
  });
});
