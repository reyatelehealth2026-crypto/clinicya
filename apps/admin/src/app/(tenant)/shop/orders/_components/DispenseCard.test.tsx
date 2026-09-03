import { render, screen } from '@testing-library/react';
import { DispenseCard } from './DispenseCard';
import type { DispenseRecordRow } from '../queries';

function baseRecord(overrides: Partial<DispenseRecordRow> = {}): DispenseRecordRow {
  return {
    id: 1,
    orderNumber: 'DSP-1',
    userId: 55,
    items: JSON.stringify([]),
    totalAmount: '0.00',
    paymentMethod: 'cash',
    paymentStatus: 'pending',
    createdAt: new Date('2026-03-05T02:07:00Z'),
    displayName: 'สมชาย',
    pictureUrl: null,
    ...overrides,
  };
}

describe('DispenseCard', () => {
  it('renders order number, total, and the "แชท" link to /chat.php?user=<userId>', () => {
    render(<DispenseCard record={baseRecord({ totalAmount: '250.5' })} />);
    expect(screen.getByText('#DSP-1')).toBeInTheDocument();
    expect(screen.getByText('฿250.50')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /แชท/ })).toHaveAttribute('href', '/chat.php?user=55');
  });

  it('shows ✅ ชำระแล้ว only when paymentStatus === "paid", else ⏳ รอชำระ', () => {
    const { rerender } = render(<DispenseCard record={baseRecord({ paymentStatus: 'paid' })} />);
    expect(screen.getByText('✅ ชำระแล้ว')).toBeInTheDocument();

    rerender(<DispenseCard record={baseRecord({ paymentStatus: 'pending' })} />);
    expect(screen.getByText('⏳ รอชำระ')).toBeInTheDocument();

    rerender(<DispenseCard record={baseRecord({ paymentStatus: null })} />);
    expect(screen.getByText('⏳ รอชำระ')).toBeInTheDocument();
  });

  it('renders every dispense item with name, qty/unit, and subtotal', () => {
    const items = [{ name: 'พาราเซตามอล', qty: 10, unit: 'เม็ด', price: 2, isMedicine: true }];
    render(<DispenseCard record={baseRecord({ items: JSON.stringify(items) })} />);
    expect(screen.getByText('พาราเซตามอล')).toBeInTheDocument();
    expect(screen.getByText('จำนวน: 10 เม็ด')).toBeInTheDocument();
    expect(screen.getByText('฿20.00')).toBeInTheDocument(); // 2 * 10
    expect(screen.getByText('1 รายการ')).toBeInTheDocument();
  });

  it('defaults unit to "ชิ้น" when absent', () => {
    render(<DispenseCard record={baseRecord({ items: JSON.stringify([{ name: 'สินค้า', qty: 2 }]) })} />);
    expect(screen.getByText('จำนวน: 2 ชิ้น')).toBeInTheDocument();
  });

  it('shows medicine-only dosage/frequency/meal-timing block only when isMedicine is true', () => {
    const items = [
      { name: 'พาราเซตามอล', qty: 1, isMedicine: true, dosage: 2, dosageUnit: 'เม็ด', frequency: '3', mealTiming: 'after' },
    ];
    render(<DispenseCard record={baseRecord({ items: JSON.stringify(items) })} />);
    expect(screen.getByText(/รับประทานครั้งละ 2 เม็ด 3 ครั้ง\/วัน/)).toBeInTheDocument();
    expect(screen.getByText(/หลังอาหาร/)).toBeInTheDocument();
  });

  it('does not show the medicine block for a non-medicine item', () => {
    render(<DispenseCard record={baseRecord({ items: JSON.stringify([{ name: 'ผ้าก๊อซ', qty: 1 }]) })} />);
    expect(screen.queryByText(/รับประทานครั้งละ/)).not.toBeInTheDocument();
  });

  it('shows indication and notes lines only when present', () => {
    const items = [{ name: 'ยา', qty: 1, isMedicine: true, indication: 'ปวดหัว', notes: 'กินหลังอาหารเช้า' }];
    render(<DispenseCard record={baseRecord({ items: JSON.stringify(items) })} />);
    expect(screen.getByText(/ข้อบ่งใช้: ปวดหัว/)).toBeInTheDocument();
    expect(screen.getByText(/กินหลังอาหารเช้า/)).toBeInTheDocument();
  });

  it('shows the empty item list ("0 รายการ") when items is null/malformed', () => {
    render(<DispenseCard record={baseRecord({ items: null })} />);
    expect(screen.getByText('0 รายการ')).toBeInTheDocument();
  });

  it('renders the mapped payment method text', () => {
    render(<DispenseCard record={baseRecord({ paymentMethod: 'transfer' })} />);
    expect(screen.getByText('📱 โอนเงิน')).toBeInTheDocument();
  });
});
