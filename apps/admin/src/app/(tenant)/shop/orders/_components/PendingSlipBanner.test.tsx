import { render, screen } from '@testing-library/react';
import { PendingSlipBanner } from './PendingSlipBanner';

describe('PendingSlipBanner', () => {
  it('shows the pending slip count in both the heading and the href, preserving only the type filter', () => {
    render(<PendingSlipBanner pendingSlipsCount={5} typeFilter="booking" />);
    expect(screen.getByText('มีสลิปรอตรวจสอบ 5 รายการ')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /ดูรายการ/ })).toHaveAttribute('href', '/shop/orders?pending_slip=1&type=booking');
  });

  it('omits &type=... when there is no type filter', () => {
    render(<PendingSlipBanner pendingSlipsCount={1} typeFilter="" />);
    expect(screen.getByRole('link', { name: /ดูรายการ/ })).toHaveAttribute('href', '/shop/orders?pending_slip=1');
  });
});
