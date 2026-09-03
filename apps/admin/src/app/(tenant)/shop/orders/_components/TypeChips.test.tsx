import { render, screen } from '@testing-library/react';
import { TypeChips } from './TypeChips';

describe('TypeChips', () => {
  it('renders "ทุกประเภท" with href="/shop/orders" (drops every param, including status)', () => {
    render(<TypeChips typeFilter="" statusFilter="pending" viewDispense={false} dispenseCount={0} />);
    expect(screen.getByRole('link', { name: 'ทุกประเภท' })).toHaveAttribute('href', '/shop/orders');
  });

  it('renders every transaction type chip with its icon+label, preserving statusFilter only', () => {
    render(<TypeChips typeFilter="" statusFilter="paid" viewDispense={false} dispenseCount={0} />);
    const purchase = screen.getByRole('link', { name: '🛒 ซื้อสินค้า' });
    expect(purchase).toHaveAttribute('href', '/shop/orders?type=purchase&status=paid');
    expect(screen.getByRole('link', { name: '📅 จองคิว' })).toHaveAttribute('href', '/shop/orders?type=booking&status=paid');
    expect(screen.getByRole('link', { name: '🔄 สมัครสมาชิก' })).toHaveAttribute('href', '/shop/orders?type=subscription&status=paid');
    expect(screen.getByRole('link', { name: '🎁 แลกของรางวัล' })).toHaveAttribute('href', '/shop/orders?type=redemption&status=paid');
  });

  it('omits &status=... when no status filter is active', () => {
    render(<TypeChips typeFilter="" statusFilter="" viewDispense={false} dispenseCount={0} />);
    expect(screen.getByRole('link', { name: '🛒 ซื้อสินค้า' })).toHaveAttribute('href', '/shop/orders?type=purchase');
  });

  it('renders the dispense chip with href="/shop/orders?view=dispense" and a badge only when dispenseCount > 0', () => {
    const { rerender } = render(<TypeChips typeFilter="" statusFilter="" viewDispense={false} dispenseCount={0} />);
    const dispenseLink = screen.getByRole('link', { name: /จ่ายยา/ });
    expect(dispenseLink).toHaveAttribute('href', '/shop/orders?view=dispense');
    expect(screen.queryByText('3')).not.toBeInTheDocument();

    rerender(<TypeChips typeFilter="" statusFilter="" viewDispense={false} dispenseCount={3} />);
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('marks "ทุกประเภท" active only when no type filter AND not viewing dispense', () => {
    render(<TypeChips typeFilter="" statusFilter="" viewDispense={false} dispenseCount={0} />);
    expect(screen.getByRole('link', { name: 'ทุกประเภท' }).className).toContain('bg-primary-600');
  });

  it('marks the matching type chip active', () => {
    render(<TypeChips typeFilter="booking" statusFilter="" viewDispense={false} dispenseCount={0} />);
    expect(screen.getByRole('link', { name: '📅 จองคิว' }).className).toContain('bg-primary-600');
    expect(screen.getByRole('link', { name: '🛒 ซื้อสินค้า' }).className).not.toContain('bg-primary-600');
  });

  it('marks the dispense chip active (emerald) when viewDispense is true', () => {
    render(<TypeChips typeFilter="" statusFilter="" viewDispense dispenseCount={0} />);
    expect(screen.getByRole('link', { name: /จ่ายยา/ }).className).toContain('bg-emerald-500');
  });
});
