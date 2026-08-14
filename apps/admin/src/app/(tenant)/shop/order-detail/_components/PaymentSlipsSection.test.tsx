import { render, screen, fireEvent } from '@testing-library/react';
import { PaymentSlipsSection } from './PaymentSlipsSection';
import type { SlipCardSlip } from './SlipCard';

const noop = () => {};

function slip(overrides: Partial<SlipCardSlip> = {}): SlipCardSlip {
  return {
    id: 1,
    status: 'pending',
    adminNote: null,
    createdAt: new Date('2026-08-01T00:00:00Z'),
    amount: '500.00',
    imageSrc: '/uploads/slips/a.jpg',
    verifyRef: null,
    qrPayload: null,
    verifyData: null,
    ...overrides,
  };
}

describe('PaymentSlipsSection', () => {
  it('shows the empty state when there are no slips', () => {
    render(
      <PaymentSlipsSection
        paymentStatus="pending"
        slips={[]}
        orderGrandTotal={500}
        shopAccounts={[]}
        verifySlipAction={noop}
        approvePaymentAction={noop}
        rejectPaymentAction={noop}
      />
    );
    expect(screen.getByText('ยังไม่มีหลักฐานการชำระเงิน')).toBeInTheDocument();
  });

  it('shows "รอชำระ" when payment_status is not paid, and the approve/reject buttons', () => {
    render(
      <PaymentSlipsSection
        paymentStatus="pending"
        slips={[slip()]}
        orderGrandTotal={500}
        shopAccounts={['9876543210']}
        verifySlipAction={noop}
        approvePaymentAction={noop}
        rejectPaymentAction={noop}
      />
    );
    expect(screen.getByText('⏳ รอชำระ')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /อนุมัติ$/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ปฏิเสธ/ })).toBeInTheDocument();
  });

  it('shows "ชำระแล้ว" and hides approve/reject when payment_status is paid', () => {
    render(
      <PaymentSlipsSection
        paymentStatus="paid"
        slips={[slip({ status: 'approved' })]}
        orderGrandTotal={500}
        shopAccounts={[]}
        verifySlipAction={noop}
        approvePaymentAction={noop}
        rejectPaymentAction={noop}
      />
    );
    expect(screen.getByText('✅ ชำระแล้ว')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /อนุมัติ$/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ปฏิเสธ/ })).not.toBeInTheDocument();
  });

  it('renders the verify banner when verifyReason is supplied', () => {
    render(
      <PaymentSlipsSection
        paymentStatus="pending"
        slips={[slip()]}
        orderGrandTotal={500}
        shopAccounts={[]}
        verifyReason="amount_mismatch"
        verifySlipAction={noop}
        approvePaymentAction={noop}
        rejectPaymentAction={noop}
      />
    );
    expect(screen.getByText('❌ ยอดเงินในสลิปไม่ตรงกับยอดออเดอร์')).toBeInTheDocument();
  });

  it('renders no banner when verifyReason is undefined', () => {
    render(
      <PaymentSlipsSection
        paymentStatus="pending"
        slips={[slip()]}
        orderGrandTotal={500}
        shopAccounts={[]}
        verifySlipAction={noop}
        approvePaymentAction={noop}
        rejectPaymentAction={noop}
      />
    );
    expect(screen.queryByText(/ตรวจสอบสำเร็จ/)).not.toBeInTheDocument();
  });

  it('opens the modal with the clicked slip image and closes it again', () => {
    render(
      <PaymentSlipsSection
        paymentStatus="pending"
        slips={[slip({ imageSrc: '/uploads/slips/x.jpg' })]}
        orderGrandTotal={500}
        shopAccounts={[]}
        verifySlipAction={noop}
        approvePaymentAction={noop}
        rejectPaymentAction={noop}
      />
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

    fireEvent.click(screen.getByAltText('payment slip'));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByAltText('slip')).toHaveAttribute('src', '/uploads/slips/x.jpg');

    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('renders multiple slip cards, one per slip', () => {
    render(
      <PaymentSlipsSection
        paymentStatus="pending"
        slips={[slip({ id: 1 }), slip({ id: 2 })]}
        orderGrandTotal={500}
        shopAccounts={[]}
        verifySlipAction={noop}
        approvePaymentAction={noop}
        rejectPaymentAction={noop}
      />
    );
    expect(screen.getAllByAltText('payment slip')).toHaveLength(2);
  });
});
