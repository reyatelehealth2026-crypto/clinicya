import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('./_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockIsOdooIntegrationEnabled = jest.fn();
jest.mock('./_lib/odoo', () => ({
  isOdooIntegrationEnabled: () => mockIsOdooIntegrationEnabled(),
}));

jest.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: jest.fn() }),
}));
jest.mock('./actions', () => ({
  updateOrderStatusAction: jest.fn(),
}));

import { makeFakeTenantDb, type QueryImpl } from './testHelpers/fakeTenantDb';
import ShopOrdersPage from './page';

const SESSION = { currentBotId: 5 };

function wireDb(queryImpl: QueryImpl) {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });
  return queries;
}

/** A queryImpl that answers every query shape shop/orders.php's own page needs, all "empty/shop-mode" by default. */
function baseQueryImpl(overrides: Partial<{ orderDataSource: string; ordersCount: number; orderRows: unknown[]; statusCounts: unknown[]; pendingSlipRows: unknown[]; dispenseCount: number; dispenseRows: unknown[] }> = {}): QueryImpl {
  return (sqlText) => {
    if (sqlText.includes('order_data_source')) return [{ order_data_source: overrides.orderDataSource ?? 'shop' }];
    if (sqlText.includes('COUNT(*) AS c FROM dispensing_records')) return [{ c: overrides.dispenseCount ?? 0 }];
    if (sqlText.includes('FROM dispensing_records d')) return overrides.dispenseRows ?? [];
    if (sqlText.includes('COUNT(*) AS count')) return [{ count: overrides.ordersCount ?? 0 }];
    if (sqlText.includes('SELECT status, COUNT(*) as c FROM transactions')) return overrides.statusCounts ?? [];
    if (sqlText.includes('payment_slips')) return overrides.pendingSlipRows ?? [];
    if (sqlText.includes('FROM transactions o') && sqlText.includes('JOIN users u')) return overrides.orderRows ?? [];
    return [];
  };
}

beforeEach(() => {
  jest.clearAllMocks();
  mockIsOdooIntegrationEnabled.mockReturnValue(false);
});

describe('ShopOrdersPage — Odoo mode gate', () => {
  it('renders the Odoo-mode stub (not the orders list) when order_data_source=odoo AND the global kill-switch is on', async () => {
    mockIsOdooIntegrationEnabled.mockReturnValue(true);
    wireDb(baseQueryImpl({ orderDataSource: 'odoo' }));

    const element = await ShopOrdersPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByText('โหมด Odoo (Read-only)')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /เปิดหน้าคำสั่งซื้อโหมด Odoo/ })).toHaveAttribute('href', '/shop/orders.php');
  });

  it('does NOT enter Odoo mode when order_data_source=odoo but the global kill-switch is off', async () => {
    mockIsOdooIntegrationEnabled.mockReturnValue(false);
    wireDb(baseQueryImpl({ orderDataSource: 'odoo' }));

    const element = await ShopOrdersPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.queryByText('โหมด Odoo (Read-only)')).not.toBeInTheDocument();
    expect(screen.getByText('ยังไม่มีคำสั่งซื้อ')).toBeInTheDocument();
  });

  it('does NOT enter Odoo mode when the kill-switch is on but order_data_source=shop', async () => {
    mockIsOdooIntegrationEnabled.mockReturnValue(true);
    wireDb(baseQueryImpl({ orderDataSource: 'shop' }));

    const element = await ShopOrdersPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.queryByText('โหมด Odoo (Read-only)')).not.toBeInTheDocument();
  });
});

describe('ShopOrdersPage — orders list (transactions mode)', () => {
  it('shows the empty state when there are no orders', async () => {
    wireDb(baseQueryImpl());
    const element = await ShopOrdersPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByText('ยังไม่มีคำสั่งซื้อ')).toBeInTheDocument();
  });

  it('renders every order card and the "แสดง X จาก Y รายการ" summary line', async () => {
    const orderRow = {
      id: 1,
      orderNumber: 'ORD-1',
      transactionType: 'purchase',
      status: 'pending',
      deliveryInfo: null,
      createdAt: new Date('2026-01-01'),
      grandTotal: '100.00',
      shippingTracking: null,
      displayName: 'สมชาย',
      pictureUrl: null,
      itemCount: 1,
    };
    wireDb(baseQueryImpl({ ordersCount: 1, orderRows: [orderRow] }));

    const element = await ShopOrdersPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByText('#ORD-1')).toBeInTheDocument();
    expect(screen.getByText(/แสดง 1 จาก 1 รายการ/)).toBeInTheDocument();
  });

  it('shows the PendingSlipBanner + status-chip pill only when there are pending-slip orders', async () => {
    wireDb(baseQueryImpl({ pendingSlipRows: [{ id: 1, order_number: 'ORD-1' }] }));
    const element = await ShopOrdersPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByText(/มีสลิปรอตรวจสอบ 1 รายการ/)).toBeInTheDocument();
  });

  it('marks an order card as having a pending slip when its id is in the pending-slip set', async () => {
    const orderRow = {
      id: 7,
      orderNumber: 'ORD-7',
      transactionType: 'purchase',
      status: 'pending',
      deliveryInfo: null,
      createdAt: new Date('2026-01-01'),
      grandTotal: '10.00',
      shippingTracking: null,
      displayName: 'A',
      pictureUrl: null,
      itemCount: 1,
    };
    wireDb(baseQueryImpl({ ordersCount: 1, orderRows: [orderRow], pendingSlipRows: [{ id: 7, order_number: 'ORD-7' }] }));
    const element = await ShopOrdersPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByText('มีสลิปรอตรวจสอบ')).toBeInTheDocument();
  });

  it('renders the pager only when totalPages > 1', async () => {
    wireDb(baseQueryImpl({ ordersCount: 1 }));
    let element = await ShopOrdersPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.queryByLabelText('Next')).not.toBeInTheDocument();

    wireDb(baseQueryImpl({ ordersCount: 200 }));
    element = await ShopOrdersPage({ searchParams: Promise.resolve({ page: '1' }) });
    render(element);
    expect(screen.getAllByLabelText('Next').length).toBeGreaterThan(0);
  });
});

describe('ShopOrdersPage — ?view=dispense tab', () => {
  it('renders the dispense record list instead of the orders list', async () => {
    const dispenseRow = {
      id: 1,
      orderNumber: 'DSP-1',
      userId: 9,
      items: JSON.stringify([]),
      totalAmount: '0.00',
      paymentMethod: 'cash',
      paymentStatus: 'pending',
      createdAt: new Date('2026-01-01'),
      displayName: 'สมหญิง',
      pictureUrl: null,
    };
    wireDb(baseQueryImpl({ dispenseRows: [dispenseRow] }));

    const element = await ShopOrdersPage({ searchParams: Promise.resolve({ view: 'dispense' }) });
    render(element);

    expect(screen.getByText('#DSP-1')).toBeInTheDocument();
    expect(screen.queryByText('ยังไม่มีคำสั่งซื้อ')).not.toBeInTheDocument();
  });

  it('shows the empty state when there are no dispensing records', async () => {
    wireDb(baseQueryImpl());
    const element = await ShopOrdersPage({ searchParams: Promise.resolve({ view: 'dispense' }) });
    render(element);
    expect(screen.getByText('ยังไม่มีรายการจ่ายยา')).toBeInTheDocument();
  });

  it('does not run the orders-list/status-counts/pending-slip queries while viewing the dispense tab', async () => {
    const queries = wireDb(baseQueryImpl());
    const element = await ShopOrdersPage({ searchParams: Promise.resolve({ view: 'dispense' }) });
    render(element);
    expect(queries.some((q) => q.sql.includes('COUNT(*) AS count'))).toBe(false);
    expect(queries.some((q) => q.sql.includes('SELECT status, COUNT(*) as c FROM transactions'))).toBe(false);
  });
});
