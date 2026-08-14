import { render, screen } from '@testing-library/react';
import { StatusChips } from './StatusChips';

describe('StatusChips', () => {
  it('renders "ทั้งหมด" with the sum of statusCounts and href preserving only type', () => {
    render(
      <StatusChips
        statusFilter=""
        typeFilter="booking"
        pendingSlip={false}
        statusCounts={{ pending: 2, paid: 3 }}
        pendingSlipsCount={0}
      />
    );
    const all = screen.getByRole('link', { name: /ทั้งหมด/ });
    expect(all).toHaveAttribute('href', '/shop/orders?type=booking');
    expect(all).toHaveTextContent('(5)');
  });

  it('"ทั้งหมด" href has no query string at all when there is no type filter', () => {
    render(<StatusChips statusFilter="" typeFilter="" pendingSlip={false} statusCounts={{}} pendingSlipsCount={0} />);
    expect(screen.getByRole('link', { name: /ทั้งหมด/ })).toHaveAttribute('href', '/shop/orders');
  });

  it('marks "ทั้งหมด" active (emerald) only when no status filter AND not viewing pending slips', () => {
    render(<StatusChips statusFilter="" typeFilter="" pendingSlip={false} statusCounts={{}} pendingSlipsCount={0} />);
    expect(screen.getByRole('link', { name: /ทั้งหมด/ }).className).toContain('bg-emerald-500');
  });

  it('does not mark "ทั้งหมด" active when pendingSlip is true, even with no status filter', () => {
    render(<StatusChips statusFilter="" typeFilter="" pendingSlip statusCounts={{}} pendingSlipsCount={1} />);
    expect(screen.getByRole('link', { name: /ทั้งหมด/ }).className).not.toContain('bg-emerald-500');
  });

  it('renders every status chip in declared order with its label + count, href preserving only type', () => {
    render(
      <StatusChips
        statusFilter="paid"
        typeFilter="purchase"
        pendingSlip={false}
        statusCounts={{ pending: 1, paid: 4 }}
        pendingSlipsCount={0}
      />
    );
    const links = screen.getAllByRole('link').map((el) => el.textContent);
    expect(links.some((t) => t?.includes('รอยืนยัน'))).toBe(true);
    expect(links.some((t) => t?.includes('ยกเลิก'))).toBe(true);
    const paidChip = screen.getByRole('link', { name: /ชำระแล้ว/ });
    expect(paidChip).toHaveAttribute('href', '/shop/orders?status=paid&type=purchase');
    expect(paidChip).toHaveTextContent('(4)');
    expect(paidChip.className).toContain('bg-green-500'); // paid's STATUS_FILTER_ACTIVE_CLASS
  });

  it('defaults a missing status count to 0', () => {
    render(<StatusChips statusFilter="" typeFilter="" pendingSlip={false} statusCounts={{}} pendingSlipsCount={0} />);
    expect(screen.getByRole('link', { name: /รอยืนยัน/ })).toHaveTextContent('(0)');
  });

  it('only renders the pending-slip chip when pendingSlipsCount > 0', () => {
    const { rerender } = render(
      <StatusChips statusFilter="" typeFilter="" pendingSlip={false} statusCounts={{}} pendingSlipsCount={0} />
    );
    expect(screen.queryByText('รอตรวจสลิป')).not.toBeInTheDocument();

    rerender(<StatusChips statusFilter="" typeFilter="" pendingSlip={false} statusCounts={{}} pendingSlipsCount={2} />);
    const chip = screen.getByRole('link', { name: /รอตรวจสลิป/ });
    expect(chip).toHaveAttribute('href', '/shop/orders?pending_slip=1');
    expect(chip).toHaveTextContent('2');
  });

  it('marks the pending-slip chip active (amber) when pendingSlip is true', () => {
    render(<StatusChips statusFilter="" typeFilter="" pendingSlip statusCounts={{}} pendingSlipsCount={3} />);
    expect(screen.getByRole('link', { name: /รอตรวจสลิป/ }).className).toContain('bg-amber-500');
  });
});
