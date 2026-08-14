import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('./_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRedirect = jest.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
jest.mock('next/navigation', () => ({
  redirect: (url: string) => mockRedirect(url),
}));

import { makeFakeTenantDb } from './testHelpers/fakeTenantDb';
import OrderDetailPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 7, tenantId: 1 } });
}

const BASE_ORDER = {
  id: 42,
  orderNumber: 'ORD-42',
  createdAt: new Date('2026-08-01T10:00:00Z'),
  status: 'pending',
  paymentStatus: 'pending',
  paymentMethod: 'transfer',
  totalAmount: '500.00',
  shippingFee: '0.00',
  discountAmount: '0.00',
  grandTotal: '500.00',
  deliveryInfo: null,
  shippingName: null,
  shippingPhone: null,
  shippingAddress: null,
  shippingTracking: null,
  note: null,
  transactionType: 'purchase',
  userId: 9,
  displayName: 'ลูกค้า A',
  pictureUrl: null,
  lineUserId: 'U1',
};

function fullOrderQueryImpl(orderOverrides: Record<string, unknown> = {}) {
  return (sqlTextRaw: string) => {
    const s = sqlTextRaw.toLowerCase();
    if (s.includes('from transactions o')) {
      return [{ ...BASE_ORDER, ...orderOverrides }];
    }
    if (s.includes('from transaction_items')) {
      return [{ id: 1, productName: 'พาราเซตามอล', productPrice: '20.00', quantity: 2, subtotal: '40.00' }];
    }
    if (s.includes('from payment_slips')) {
      return [];
    }
    if (s.includes('from shop_settings')) {
      return [{ promptpayNumber: '0812345678', bankAccounts: null }];
    }
    return [];
  };
}

describe('OrderDetailPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('redirects to /shop/orders when id is missing/zero', async () => {
    await expect(OrderDetailPage({ searchParams: Promise.resolve({}) })).rejects.toThrow('REDIRECT:/shop/orders');
    expect(mockRedirect).toHaveBeenCalledWith('/shop/orders');
  });

  it('redirects to /shop/orders when the order id does not resolve to a row', async () => {
    wireDb(() => []);
    await expect(OrderDetailPage({ searchParams: Promise.resolve({ id: '999' }) })).rejects.toThrow('REDIRECT:/shop/orders');
  });

  it('renders the order header, items, totals, and customer link for an existing order', async () => {
    wireDb(fullOrderQueryImpl());
    const element = await OrderDetailPage({ searchParams: Promise.resolve({ id: '42' }) });
    render(element);

    expect(screen.getAllByText('#ORD-42').length).toBeGreaterThan(0);
    expect(screen.getByText('พาราเซตามอล')).toBeInTheDocument();
    expect(screen.getByText('ลูกค้า A')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /ลูกค้า A/ })).toHaveAttribute('href', '/user-detail?id=9');
  });

  it('shows the "อัพเดทสำเร็จ!" banner only when ?updated=1 is present', async () => {
    wireDb(fullOrderQueryImpl());
    const withUpdate = await OrderDetailPage({ searchParams: Promise.resolve({ id: '42', updated: '1' }) });
    render(withUpdate);
    expect(screen.getByText('อัพเดทสำเร็จ!')).toBeInTheDocument();
  });

  it('omits the "อัพเดทสำเร็จ!" banner when ?updated is absent', async () => {
    wireDb(fullOrderQueryImpl());
    const element = await OrderDetailPage({ searchParams: Promise.resolve({ id: '42' }) });
    render(element);
    expect(screen.queryByText('อัพเดทสำเร็จ!')).not.toBeInTheDocument();
  });

  it('shows the COD-specific "รอจัดส่ง (COD)" badge for a confirmed COD order', async () => {
    wireDb(fullOrderQueryImpl({ status: 'confirmed', paymentMethod: 'cod' }));
    const element = await OrderDetailPage({ searchParams: Promise.resolve({ id: '42' }) });
    render(element);
    expect(screen.getByText('รอจัดส่ง (COD)')).toBeInTheDocument();
  });

  it('shows the transaction-type badge for a non-purchase order', async () => {
    wireDb(fullOrderQueryImpl({ transactionType: 'booking' }));
    const element = await OrderDetailPage({ searchParams: Promise.resolve({ id: '42' }) });
    render(element);
    expect(screen.getByText(/จองคิว/)).toBeInTheDocument();
  });

  it('renders the note card only when order.note is set', async () => {
    wireDb(fullOrderQueryImpl({ note: 'ลูกค้าขอให้โทรก่อนส่ง' }));
    const element = await OrderDetailPage({ searchParams: Promise.resolve({ id: '42' }) });
    render(element);
    expect(screen.getByText('ลูกค้าขอให้โทรก่อนส่ง')).toBeInTheDocument();
  });

  it('passes the verify=<reason> query param through to the payment-slips banner', async () => {
    wireDb(fullOrderQueryImpl());
    const element = await OrderDetailPage({ searchParams: Promise.resolve({ id: '42', verify: 'amount_mismatch' }) });
    render(element);
    expect(screen.getByText('❌ ยอดเงินในสลิปไม่ตรงกับยอดออเดอร์')).toBeInTheDocument();
  });
});
