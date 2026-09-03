import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockRefresh = jest.fn();
jest.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: mockRefresh }),
}));

const mockUpdateOrderStatusAction = jest.fn();
jest.mock('../actions', () => ({
  updateOrderStatusAction: (...args: unknown[]) => mockUpdateOrderStatusAction(...args),
}));

import { ConfirmOrderButton } from './ConfirmOrderButton';

describe('ConfirmOrderButton', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUpdateOrderStatusAction.mockResolvedValue({ success: true });
  });

  it('shows the exact PHP confirm text and does nothing when the user cancels', async () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    const user = userEvent.setup();
    render(<ConfirmOrderButton orderId={42} orderNumber="ORD-42" />);

    await user.click(screen.getByRole('button', { name: /ยืนยัน/ }));

    expect(confirmSpy).toHaveBeenCalledWith('ยืนยันออเดอร์ #ORD-42? จะส่งข้อความ LINE ถึงลูกค้า');
    expect(mockUpdateOrderStatusAction).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it('calls updateOrderStatusAction({orderId, status:"confirmed"}) and refreshes the router when confirmed', async () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    render(<ConfirmOrderButton orderId={42} orderNumber="ORD-42" />);

    await user.click(screen.getByRole('button', { name: /ยืนยัน/ }));

    expect(mockUpdateOrderStatusAction).toHaveBeenCalledWith({ orderId: 42, status: 'confirmed' });
    expect(mockRefresh).toHaveBeenCalled();
    confirmSpy.mockRestore();
  });
});
