import { render, screen } from '@testing-library/react';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: jest.fn() }),
}));
jest.mock('../actions', () => ({
  updateOrderStatusAction: jest.fn(),
}));

import { OrderCard } from './OrderCard';
import type { OrdersListRow } from '../queries';

function baseOrder(overrides: Partial<OrdersListRow> = {}): OrdersListRow {
  return {
    id: 42,
    orderNumber: 'ORD-42',
    transactionType: 'purchase',
    status: 'pending',
    deliveryInfo: null,
    createdAt: new Date('2026-03-05T02:07:00Z'), // 09:07 Asia/Bangkok
    grandTotal: '1234.5',
    itemCount: 3,
    shippingTracking: null,
    displayName: 'สมชาย',
    pictureUrl: null,
    ...overrides,
  };
}

describe('OrderCard', () => {
  it('renders order number, formatted total, item count, and links to /shop/order-detail?id=<id>', () => {
    render(<OrderCard order={baseOrder()} hasPendingSlip={false} />);
    expect(screen.getByText('#ORD-42')).toBeInTheDocument();
    expect(screen.getByText('฿1,234.50')).toBeInTheDocument();
    expect(screen.getByText('3 รายการ')).toBeInTheDocument();
    const detailLinks = screen.getAllByRole('link', { name: /ดูรายละเอียด/ });
    expect(detailLinks[0]).toHaveAttribute('href', '/shop/order-detail?id=42');
  });

  it('shows the transaction-type badge only when type !== purchase', () => {
    const { rerender } = render(<OrderCard order={baseOrder({ transactionType: 'purchase' })} hasPendingSlip={false} />);
    expect(screen.queryByText('จองคิว')).not.toBeInTheDocument();

    rerender(<OrderCard order={baseOrder({ transactionType: 'booking' })} hasPendingSlip={false} />);
    expect(screen.getByText(/จองคิว/)).toBeInTheDocument();
  });

  it('defaults an unknown transactionType to purchase-type display, matching PHP\'s ?? fallback', () => {
    render(<OrderCard order={baseOrder({ transactionType: 'unknown-type' })} hasPendingSlip={false} />);
    // falls back to $transactionTypes['purchase'] -> not shown as a badge since effective type isn't 'purchase' string-equal...
    // PHP: $transType = $order['transaction_type'] ?? 'purchase' (raw value kept); $typeInfo falls back only for the ICON/LABEL lookup.
    expect(screen.getByText(/🛒 ซื้อสินค้า/)).toBeInTheDocument();
  });

  it('renders the status label and badge class from ORDER_STATUSES/STATUS_BADGE_CLASS', () => {
    render(<OrderCard order={baseOrder({ status: 'paid' })} hasPendingSlip={false} />);
    const badge = screen.getByText('ชำระแล้ว');
    expect(badge.className).toContain('bg-emerald-100');
  });

  it('falls back to the raw status string + default badge class for an unknown status', () => {
    render(<OrderCard order={baseOrder({ status: 'refunded' })} hasPendingSlip={false} />);
    expect(screen.getByText('refunded')).toBeInTheDocument();
  });

  it('defaults a null status to the "รอยืนยัน" LABEL, but does NOT show the confirm button (PHP\'s gate checks the RAW $order[\'status\'] === \'pending\', not the defaulted display label)', () => {
    render(<OrderCard order={baseOrder({ status: null })} hasPendingSlip={false} />);
    expect(screen.getByText('รอยืนยัน')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ยืนยัน/ })).not.toBeInTheDocument();
  });

  it('shows the ConfirmOrderButton only when status === "pending"', () => {
    const { rerender } = render(<OrderCard order={baseOrder({ status: 'pending' })} hasPendingSlip={false} />);
    expect(screen.getByRole('button', { name: /ยืนยัน/ })).toBeInTheDocument();

    rerender(<OrderCard order={baseOrder({ status: 'confirmed' })} hasPendingSlip={false} />);
    expect(screen.queryByRole('button', { name: /ยืนยัน/ })).not.toBeInTheDocument();
  });

  it('parses delivery_info JSON and renders name/phone/address when present', () => {
    render(
      <OrderCard
        order={baseOrder({ deliveryInfo: JSON.stringify({ name: 'สมหญิง', phone: '0812345678', address: '123 ถ.สุขุมวิท' }) })}
        hasPendingSlip={false}
      />
    );
    expect(screen.getByText('สมหญิง')).toBeInTheDocument();
    expect(screen.getByText('0812345678')).toBeInTheDocument();
    expect(screen.getByText('123 ถ.สุขุมวิท')).toBeInTheDocument();
  });

  it('renders no delivery block when delivery_info is null/empty/malformed', () => {
    const { rerender } = render(<OrderCard order={baseOrder({ deliveryInfo: null })} hasPendingSlip={false} />);
    expect(screen.queryByText('ผู้รับ:')).not.toBeInTheDocument();

    rerender(<OrderCard order={baseOrder({ deliveryInfo: 'not-json' })} hasPendingSlip={false} />);
    expect(screen.queryByText('ผู้รับ:')).not.toBeInTheDocument();
  });

  it('shows shipping_tracking in the footer only when present', () => {
    const { rerender } = render(<OrderCard order={baseOrder({ shippingTracking: null })} hasPendingSlip={false} />);
    expect(screen.queryByText('TH999')).not.toBeInTheDocument();

    rerender(<OrderCard order={baseOrder({ shippingTracking: 'TH999' })} hasPendingSlip={false} />);
    expect(screen.getByText('TH999')).toBeInTheDocument();
  });

  it('shows the "มีสลิปรอตรวจสอบ" banner + ตรวจสอบเลย link only when hasPendingSlip is true', () => {
    const { rerender } = render(<OrderCard order={baseOrder()} hasPendingSlip={false} />);
    expect(screen.queryByText('มีสลิปรอตรวจสอบ')).not.toBeInTheDocument();

    rerender(<OrderCard order={baseOrder()} hasPendingSlip />);
    expect(screen.getByText('มีสลิปรอตรวจสอบ')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'ตรวจสอบเลย' })).toHaveAttribute('href', '/shop/order-detail?id=42');
  });
});
