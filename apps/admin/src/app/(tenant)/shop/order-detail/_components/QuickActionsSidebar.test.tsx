import { render, screen } from '@testing-library/react';
import { QuickActionsSidebar } from './QuickActionsSidebar';

const noop = () => {};

describe('QuickActionsSidebar', () => {
  it('pending + unpaid: shows "ยืนยันออเดอร์" and the "รอลูกค้าชำระเงิน" banner', () => {
    render(
      <QuickActionsSidebar status="pending" paymentStatus="pending" shippingTracking={null} updateStatusAction={noop} addTrackingAction={noop} />
    );
    expect(screen.getByRole('button', { name: /ยืนยันออเดอร์/ })).toBeInTheDocument();
    expect(screen.getByText('รอลูกค้าชำระเงิน')).toBeInTheDocument();
    expect(screen.queryByPlaceholderText(/กรอกเลขพัสดุ/)).not.toBeInTheDocument();
  });

  it('paid + not yet shipping: shows the add-tracking form', () => {
    render(
      <QuickActionsSidebar status="confirmed" paymentStatus="paid" shippingTracking={null} updateStatusAction={noop} addTrackingAction={noop} />
    );
    expect(screen.getByPlaceholderText(/กรอกเลขพัสดุ/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ส่งเลขพัสดุ/ })).toBeInTheDocument();
  });

  it('shipping status: shows "ยืนยันส่งถึงแล้ว"', () => {
    render(
      <QuickActionsSidebar status="shipping" paymentStatus="paid" shippingTracking="TH1" updateStatusAction={noop} addTrackingAction={noop} />
    );
    expect(screen.getByRole('button', { name: /ยืนยันส่งถึงแล้ว/ })).toBeInTheDocument();
    expect(screen.getByText('TH1')).toBeInTheDocument();
  });

  it('delivered status: shows the completion banner and hides the cancel button', () => {
    render(
      <QuickActionsSidebar status="delivered" paymentStatus="paid" shippingTracking="TH1" updateStatusAction={noop} addTrackingAction={noop} />
    );
    expect(screen.getByText('ออเดอร์เสร็จสมบูรณ์')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ยกเลิกออเดอร์/ })).not.toBeInTheDocument();
  });

  it('cancelled status: also hides the cancel button', () => {
    render(
      <QuickActionsSidebar status="cancelled" paymentStatus="pending" shippingTracking={null} updateStatusAction={noop} addTrackingAction={noop} />
    );
    expect(screen.queryByRole('button', { name: /ยกเลิกออเดอร์/ })).not.toBeInTheDocument();
  });

  it('shows the cancel button for any other status and blocks submit when confirm() is declined', () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    render(
      <QuickActionsSidebar status="pending" paymentStatus="pending" shippingTracking={null} updateStatusAction={noop} addTrackingAction={noop} />
    );
    const cancelBtn = screen.getByRole('button', { name: /ยกเลิกออเดอร์/ });
    expect(cancelBtn).toBeInTheDocument();

    const form = cancelBtn.closest('form')!;
    const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(submitEvent);

    expect(confirmSpy).toHaveBeenCalledWith('ยกเลิกออเดอร์นี้?');
    expect(submitEvent.defaultPrevented).toBe(true);

    confirmSpy.mockRestore();
  });

  it('renders the manual status dropdown pre-selected to the current status', () => {
    render(
      <QuickActionsSidebar status="shipping" paymentStatus="paid" shippingTracking={null} updateStatusAction={noop} addTrackingAction={noop} />
    );
    const select = screen.getByRole('combobox') as HTMLSelectElement;
    expect(select.value).toBe('shipping');
  });
});
