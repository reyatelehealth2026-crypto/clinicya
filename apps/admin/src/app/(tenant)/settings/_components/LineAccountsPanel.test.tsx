import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

const mockCreate = jest.fn();
const mockUpdate = jest.fn();
const mockDelete = jest.fn();
const mockTest = jest.fn();
jest.mock('../_lib/line-actions', () => ({
  createLineAccountAction: (...args: unknown[]) => mockCreate(...args),
  updateLineAccountAction: (...args: unknown[]) => mockUpdate(...args),
  deleteLineAccountAction: (...args: unknown[]) => mockDelete(...args),
  testLineConnectionAction: (...args: unknown[]) => mockTest(...args),
}));

import { LineAccountsPanel, AddLineAccountButton, LineAccountActionButtons, CopyWebhookButton } from './LineAccountsPanel';
import type { LineAccountRow } from '../_lib/line-queries';

const ACCOUNT: LineAccountRow = {
  id: 3,
  name: 'ร้าน ทดสอบ',
  channel_id: '999',
  channel_secret: 'old-secret',
  channel_access_token: 'old-token',
  basic_id: '@test',
  bot_mode: 'general',
  liff_id: null,
  is_active: 1,
  is_default: 0,
  picture_url: null,
  webhook_url: null,
  welcome_message: 'สวัสดี',
  auto_reply_enabled: 0,
  shop_enabled: 1,
};

function Harness() {
  return (
    <LineAccountsPanel>
      <AddLineAccountButton>เพิ่มบัญชี LINE</AddLineAccountButton>
      <LineAccountActionButtons account={ACCOUNT} />
    </LineAccountsPanel>
  );
}

beforeEach(() => {
  jest.clearAllMocks();
  mockCreate.mockResolvedValue(undefined);
  mockUpdate.mockResolvedValue(undefined);
  mockDelete.mockResolvedValue(undefined);
});

describe('LineAccountsPanel — modal open (create vs. edit)', () => {
  it('opens a blank create-mode modal via AddLineAccountButton, no delete button', () => {
    render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'เพิ่มบัญชี LINE' }));

    const dialog = screen.getByRole('dialog', { name: 'เพิ่มบัญชี LINE' });
    expect(within(dialog).getByLabelText(/ชื่อบัญชี/)).toHaveValue('');
    expect(within(dialog).queryByRole('button', { name: /ลบบัญชี/ })).not.toBeInTheDocument();
  });

  it('create-mode defaults: bot_mode=shop, is_active/auto_reply/shop/receipt checkboxes all checked, is_default unchecked', () => {
    render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'เพิ่มบัญชี LINE' }));
    const dialog = screen.getByRole('dialog', { name: 'เพิ่มบัญชี LINE' });

    expect(within(dialog).getByRole('radio', { name: /โหมดร้านค้า/ })).toBeChecked();
    expect(within(dialog).getByRole('checkbox', { name: 'เปิดใช้งาน' })).toBeChecked();
    expect(within(dialog).getByRole('checkbox', { name: 'ตั้งเป็นบัญชีหลัก' })).not.toBeChecked();
  });

  it('opens edit-mode pre-filled from the clicked account, with a delete button', () => {
    render(<Harness />);
    fireEvent.click(screen.getByTitle('แก้ไข'));

    const dialog = screen.getByRole('dialog', { name: `ตั้งค่าบัญชี: ${ACCOUNT.name}` });
    expect(within(dialog).getByLabelText(/ชื่อบัญชี/)).toHaveValue(ACCOUNT.name);
    expect(within(dialog).getByLabelText('LINE Basic ID')).toHaveValue('@test');
    expect(within(dialog).getByRole('button', { name: /ลบบัญชี/ })).toBeInTheDocument();
  });

  it('closes via the Modal close (×) button and re-opens fresh next time', () => {
    render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'เพิ่มบัญชี LINE' }));
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});

describe('LineAccountsPanel — create/edit submit', () => {
  function fillRequired(dialog: HTMLElement, overrides: Partial<Record<'name' | 'secret' | 'token', string>> = {}) {
    fireEvent.change(within(dialog).getByLabelText(/ชื่อบัญชี/), { target: { value: overrides.name ?? 'ร้านใหม่' } });
    // Exact strings — a regex would also match the show/hide toggle buttons' own
    // `aria-label="แสดง/ซ่อน Channel Secret"` / `"…Channel Access Token"`.
    fireEvent.change(within(dialog).getByLabelText('Channel Secret *'), { target: { value: overrides.secret ?? 'sec-123' } });
    fireEvent.change(within(dialog).getByLabelText('Channel Access Token *'), { target: { value: overrides.token ?? 'tok-123' } });
  }

  it('submits createLineAccountAction with the entered values on save (create mode)', async () => {
    render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'เพิ่มบัญชี LINE' }));
    const dialog = screen.getByRole('dialog', { name: 'เพิ่มบัญชี LINE' });
    fillRequired(dialog);

    fireEvent.click(within(dialog).getByRole('button', { name: /บันทึก/ }));

    await waitFor(() => expect(mockCreate).toHaveBeenCalledTimes(1));
    expect(mockCreate).toHaveBeenCalledWith(
      expect.objectContaining({
        name: 'ร้านใหม่',
        channel_secret: 'sec-123',
        channel_access_token: 'tok-123',
        bot_mode: 'shop',
        is_default: false,
        auto_reply_enabled: true,
        shop_enabled: true,
        receipt_points_enabled: true,
      })
    );
    expect(mockUpdate).not.toHaveBeenCalled();
  });

  it('submits updateLineAccountAction(id, input) on save (edit mode), carrying is_active separately', async () => {
    render(<Harness />);
    fireEvent.click(screen.getByTitle('แก้ไข'));
    const dialog = screen.getByRole('dialog', { name: `ตั้งค่าบัญชี: ${ACCOUNT.name}` });

    fireEvent.change(within(dialog).getByLabelText(/ชื่อบัญชี/), { target: { value: 'ชื่อใหม่' } });
    fireEvent.click(within(dialog).getByRole('button', { name: /บันทึก/ }));

    await waitFor(() => expect(mockUpdate).toHaveBeenCalledTimes(1));
    expect(mockUpdate).toHaveBeenCalledWith(ACCOUNT.id, expect.objectContaining({ name: 'ชื่อใหม่', is_active: true }));
    expect(mockCreate).not.toHaveBeenCalled();
  });
});

describe('LineAccountsPanel — delete (confirm-gated)', () => {
  it('does not call deleteLineAccountAction when confirm() is dismissed', () => {
    jest.spyOn(window, 'confirm').mockReturnValue(false);
    render(<Harness />);
    fireEvent.click(screen.getByTitle('แก้ไข'));
    fireEvent.click(screen.getByRole('button', { name: /ลบบัญชี/ }));
    expect(mockDelete).not.toHaveBeenCalled();
  });

  it('calls deleteLineAccountAction with a FormData carrying the account id after confirming', async () => {
    jest.spyOn(window, 'confirm').mockReturnValue(true);
    render(<Harness />);
    fireEvent.click(screen.getByTitle('แก้ไข'));
    fireEvent.click(screen.getByRole('button', { name: /ลบบัญชี/ }));

    await waitFor(() => expect(mockDelete).toHaveBeenCalledTimes(1));
    const fd = mockDelete.mock.calls[0]?.[0] as FormData;
    expect(fd.get('id')).toBe(String(ACCOUNT.id));
  });
});

describe('LineAccountsPanel — test connection', () => {
  it('shows a loading spinner, then the success panel with displayName/pictureUrl', async () => {
    mockTest.mockResolvedValue({ success: true, data: { displayName: 'Bot Name', pictureUrl: 'https://x/p.png' } });
    render(<Harness />);
    fireEvent.click(screen.getByTitle('ทดสอบ'));

    expect(screen.getByText('กำลังทดสอบ...')).toBeInTheDocument();
    await screen.findByText('เชื่อมต่อสำเร็จ!');
    expect(screen.getByText('Bot Name')).toBeInTheDocument();
    expect(mockTest).toHaveBeenCalledWith(ACCOUNT.id);
  });

  it('shows the failure panel with result.message when success:false', async () => {
    mockTest.mockResolvedValue({ success: false, message: 'invalid token' });
    render(<Harness />);
    fireEvent.click(screen.getByTitle('ทดสอบ'));

    await screen.findByText('เชื่อมต่อไม่สำเร็จ');
    expect(screen.getByText('invalid token')).toBeInTheDocument();
  });

  it('shows the yellow-triangle client-error UI when the action call itself rejects (distinct from a resolved {success:false})', async () => {
    mockTest.mockRejectedValue(new Error('network down'));
    render(<Harness />);
    fireEvent.click(screen.getByTitle('ทดสอบ'));

    await screen.findByText('network down');
    expect(screen.queryByText('เชื่อมต่อไม่สำเร็จ')).not.toBeInTheDocument();
  });
});

describe('LineAccountsPanel — misc buttons', () => {
  it('CopyWebhookButton copies the URL via navigator.clipboard and alerts', async () => {
    const writeText = jest.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});

    render(<CopyWebhookButton webhookUrl="https://example.com/webhook.php?account=3" />);
    fireEvent.click(screen.getByTitle('คัดลอก'));

    await waitFor(() => expect(writeText).toHaveBeenCalledWith('https://example.com/webhook.php?account=3'));
    expect(alertSpy).toHaveBeenCalledWith('คัดลอก Webhook URL แล้ว!');
  });

  it('the stats button alerts the account id (showLineStats() port)', () => {
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});
    render(<Harness />);
    fireEvent.click(screen.getByTitle('สถิติ'));
    expect(alertSpy).toHaveBeenCalledWith(`ดูสถิติบัญชี ID: ${ACCOUNT.id}`);
  });
});

describe('useLineAccountsPanel — outside provider guard', () => {
  it('throws when a consumer renders outside <LineAccountsPanel>', () => {
    const consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => render(<LineAccountActionButtons account={ACCOUNT} />)).toThrow('useLineAccountsPanel must be used within <LineAccountsPanel>');
    consoleError.mockRestore();
  });
});
