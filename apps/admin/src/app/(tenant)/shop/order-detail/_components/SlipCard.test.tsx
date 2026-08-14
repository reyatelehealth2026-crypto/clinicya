import { render, screen, fireEvent } from '@testing-library/react';
import { SlipCard, type SlipCardSlip } from './SlipCard';

const noop = () => {};

function baseSlip(overrides: Partial<SlipCardSlip> = {}): SlipCardSlip {
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

const SHOP_ACCOUNTS = ['9876543210'];

function transferVerifyData(amount = 500, toAccountNo = '987-6-54321-0') {
  return JSON.stringify({
    slipVerification: { transfer: { transactionRef: 'REF-1', toAccountNo, amount: { amount } } },
  });
}

describe('SlipCard', () => {
  it('shows the "already verified" state with no action button when verify_ref is set', () => {
    render(
      <SlipCard
        slip={baseSlip({ verifyRef: 'REF-1', verifyData: transferVerifyData() })}
        orderGrandTotal={500}
        shopAccounts={SHOP_ACCOUNTS}
        verifySlipAction={noop}
        onOpenModal={noop}
      />
    );
    expect(screen.getByText(/ยืนยันแล้ว — อนุมัติการชำระแล้ว/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /อนุมัติ \(GhostX/ })).not.toBeInTheDocument();
  });

  it('shows the "amount matched, ready to approve" state with an approve button when pending', () => {
    render(
      <SlipCard
        slip={baseSlip({ verifyData: transferVerifyData() })}
        orderGrandTotal={500}
        shopAccounts={SHOP_ACCOUNTS}
        verifySlipAction={noop}
        onOpenModal={noop}
      />
    );
    expect(screen.getByText(/พร้อมอนุมัติ/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /อนุมัติ \(GhostX \+ ยอดตรง\)/ })).toBeInTheDocument();
  });

  it('hides the approve button once the slip itself is already approved', () => {
    render(
      <SlipCard
        slip={baseSlip({ status: 'approved', verifyData: transferVerifyData() })}
        orderGrandTotal={500}
        shopAccounts={SHOP_ACCOUNTS}
        verifySlipAction={noop}
        onOpenModal={noop}
      />
    );
    expect(screen.getByText(/พร้อมอนุมัติ/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /อนุมัติ \(GhostX/ })).not.toBeInTheDocument();
  });

  it('shows the mismatch warning + retry button when the amount does not match', () => {
    render(
      <SlipCard
        slip={baseSlip({ verifyData: transferVerifyData(499) })}
        orderGrandTotal={500}
        shopAccounts={SHOP_ACCOUNTS}
        verifySlipAction={noop}
        onOpenModal={noop}
      />
    );
    expect(screen.getByText(/ยอดในสลิปไม่ตรงกับออเดอร์/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ประเมินซ้ำ/ })).toBeInTheDocument();
  });

  it('shows the GhostX error message + retry button when a qrPayload is present', () => {
    render(
      <SlipCard
        slip={baseSlip({ qrPayload: 'RAWQR', verifyData: JSON.stringify({ error: 'ไม่มีรหัสอ้างอิงรายการ' }) })}
        orderGrandTotal={500}
        shopAccounts={SHOP_ACCOUNTS}
        verifySlipAction={noop}
        onOpenModal={noop}
      />
    );
    expect(screen.getByText('GhostX ตรวจสลิปไม่ผ่าน')).toBeInTheDocument();
    expect(screen.getByText('ไม่มีรหัสอ้างอิงรายการ')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ลองตรวจกับ GhostX อีกครั้ง/ })).toBeInTheDocument();
  });

  it('hides the retry button on a GhostX error when there is no stored qrPayload', () => {
    render(
      <SlipCard
        slip={baseSlip({ qrPayload: null, verifyData: JSON.stringify({ error: 'some error' }) })}
        orderGrandTotal={500}
        shopAccounts={SHOP_ACCOUNTS}
        verifySlipAction={noop}
        onOpenModal={noop}
      />
    );
    expect(screen.getByText('GhostX ตรวจสลิปไม่ผ่าน')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ลองตรวจกับ GhostX อีกครั้ง/ })).not.toBeInTheDocument();
  });

  it('shows the "ตรวจสอบกับ GhostX" scan button when a qrPayload exists but has never been evaluated', () => {
    render(
      <SlipCard slip={baseSlip({ qrPayload: 'RAWQR', verifyData: null })} orderGrandTotal={500} shopAccounts={SHOP_ACCOUNTS} verifySlipAction={noop} onOpenModal={noop} />
    );
    expect(screen.getByRole('button', { name: /ตรวจสอบกับ GhostX/ })).toBeInTheDocument();
  });

  it('shows the "decode QR from image" button when there is no qrPayload at all', () => {
    render(
      <SlipCard slip={baseSlip({ qrPayload: null, verifyData: null })} orderGrandTotal={500} shopAccounts={SHOP_ACCOUNTS} verifySlipAction={noop} onOpenModal={noop} />
    );
    expect(screen.getByText(/ยังไม่มีข้อมูล QR/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ถอด QR จากรูป/ })).toBeInTheDocument();
  });

  it('hides the "decode QR" button once the slip is already approved (nothing to decode toward)', () => {
    render(
      <SlipCard
        slip={baseSlip({ status: 'approved', qrPayload: null, verifyData: null })}
        orderGrandTotal={500}
        shopAccounts={SHOP_ACCOUNTS}
        verifySlipAction={noop}
        onOpenModal={noop}
      />
    );
    expect(screen.getByText(/ยังไม่มีข้อมูล QR/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ถอด QR จากรูป/ })).not.toBeInTheDocument();
  });

  it('calls onOpenModal with the slip image src when the image is clicked', () => {
    const onOpenModal = jest.fn();
    render(
      <SlipCard slip={baseSlip()} orderGrandTotal={500} shopAccounts={SHOP_ACCOUNTS} verifySlipAction={noop} onOpenModal={onOpenModal} />
    );
    fireEvent.click(screen.getByAltText('payment slip'));
    expect(onOpenModal).toHaveBeenCalledWith('/uploads/slips/a.jpg');
  });
});
