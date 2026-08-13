import { render, screen } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { NotificationsTab } from './NotificationsTab';

const originalOdooEnv = process.env.ODOO_INTEGRATION_ENABLED;

afterEach(() => {
  if (originalOdooEnv === undefined) {
    delete process.env.ODOO_INTEGRATION_ENABLED;
  } else {
    process.env.ODOO_INTEGRATION_ENABLED = originalOdooEnv;
  }
});

function wireDb(opts: { settingsRow?: Record<string, unknown> | undefined; adminUsers?: Record<string, unknown>[] } = {}) {
  const { settingsRow, adminUsers = [] } = opts;
  return makeFakeTenantDb((sqlText) => {
    if (sqlText.includes('FROM notification_settings')) return settingsRow ? [settingsRow] : [];
    if (sqlText.includes('FROM admin_users')) return adminUsers;
    return [];
  });
}

/** LINE/Email both render a "🚨 เคสฉุกเฉิน (Red Flag)" / "📦 สินค้าใกล้หมด" checkbox with the SAME visible title text
 *  (matches PHP's own duplicated copy) — so tests locate checkboxes by their unique `name` attribute, not by title text. */
function inputByName(container: HTMLElement, name: string): HTMLInputElement | null {
  return container.querySelector(`input[name="${name}"]`);
}

describe('NotificationsTab', () => {
  it('defaults every LINE/Email/Telegram checkbox per notifications.php\'s own per-field fallbacks when no row exists', async () => {
    delete process.env.ODOO_INTEGRATION_ENABLED;
    const { db } = wireDb();
    const element = await NotificationsTab({ db, currentBotId: 7 });
    const { container } = render(element);

    // Defaults to 1 (checked)
    expect(inputByName(container, 'line_notify_enabled')).toBeChecked();
    expect(inputByName(container, 'line_notify_new_order')).toBeChecked();
    expect(inputByName(container, 'line_notify_payment')).toBeChecked();
    expect(inputByName(container, 'line_notify_urgent')).toBeChecked();
    expect(inputByName(container, 'line_notify_appointment')).toBeChecked();
    expect(inputByName(container, 'email_notify_urgent')).toBeChecked();
    // Defaults to 0 (unchecked)
    expect(inputByName(container, 'line_notify_low_stock')).not.toBeChecked();
    expect(inputByName(container, 'email_enabled')).not.toBeChecked();
    expect(inputByName(container, 'email_notify_daily_report')).not.toBeChecked();
    expect(inputByName(container, 'email_notify_low_stock')).not.toBeChecked();
    expect(inputByName(container, 'telegram_enabled')).not.toBeChecked();
  });

  it('renders the Notification Recipients empty state when admin_users has no rows', async () => {
    const { db } = wireDb();
    const element = await NotificationsTab({ db, currentBotId: 7 });
    render(element);
    expect(screen.getByText('ไม่พบผู้ใช้งาน')).toBeInTheDocument();
  });

  it('disables the checkbox and shows the red "ไม่มี LINE User ID" label for an admin user with no line_user_id', async () => {
    const { db } = wireDb({
      adminUsers: [
        { id: 1, username: 'pharmacist_a', email: 'a@example.com', line_user_id: 'U123', role: 'pharmacist' },
        { id: 2, username: 'no_line_user', email: null, line_user_id: null, role: 'staff' },
      ],
    });
    const element = await NotificationsTab({ db, currentBotId: 7 });
    render(element);

    const withLine = screen.getByText('pharmacist_a').closest('label')?.querySelector('input');
    expect(withLine).not.toBeDisabled();
    expect(withLine).not.toBeChecked();

    const withoutLine = screen.getByText('no_line_user').closest('label')?.querySelector('input');
    expect(withoutLine).toBeDisabled();
    expect(screen.getByText(/ไม่มี LINE User ID/)).toBeInTheDocument();
    expect(screen.getByText(/a@example\.com/)).toBeInTheDocument();
  });

  it('pre-checks notify_admin_users[] checkboxes already listed in notify_admin_users', async () => {
    const { db } = wireDb({
      settingsRow: { line_account_id: 7, notify_admin_users: '2,5' },
      adminUsers: [{ id: 2, username: 'checked_user', email: null, line_user_id: 'U1', role: 'admin' }],
    });
    const element = await NotificationsTab({ db, currentBotId: 7 });
    render(element);
    expect(screen.getByText('checked_user').closest('label')?.querySelector('input')).toBeChecked();
  });

  it('always renders the save button bound to save_notifications', async () => {
    const { db } = wireDb();
    const element = await NotificationsTab({ db, currentBotId: 7 });
    render(element);
    const saveButton = screen.getByRole('button', { name: /บันทึกการตั้งค่า/ });
    expect(saveButton).toHaveAttribute('name', 'action');
    expect(saveButton).toHaveAttribute('value', 'save_notifications');
  });

  it('hides all three Odoo-gated blocks when ODOO_INTEGRATION_ENABLED is unset', async () => {
    delete process.env.ODOO_INTEGRATION_ENABLED;
    const { db } = wireDb();
    const element = await NotificationsTab({ db, currentBotId: 7 });
    render(element);

    expect(screen.queryByText('Odoo → LIFF Notification')).not.toBeInTheDocument();
    expect(screen.queryByText('การตั้งค่าการแจ้งเตือน Odoo (ขั้นสูง)')).not.toBeInTheDocument();
    expect(screen.queryByText('ทดสอบส่งแจ้งเตือน Odoo → LIFF')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ส่งข้อความทดสอบ/ })).not.toBeInTheDocument();
  });

  it('shows all three Odoo-gated blocks when ODOO_INTEGRATION_ENABLED=true, incl. all 8 event checkboxes', async () => {
    process.env.ODOO_INTEGRATION_ENABLED = 'true';
    const { db } = wireDb();
    const element = await NotificationsTab({ db, currentBotId: 7 });
    const { container } = render(element);

    expect(screen.getByText('Odoo → LIFF Notification')).toBeInTheDocument();
    expect(screen.getByText('การตั้งค่าการแจ้งเตือน Odoo (ขั้นสูง)')).toBeInTheDocument();
    expect(screen.getByText('ทดสอบส่งแจ้งเตือน Odoo → LIFF')).toBeInTheDocument();

    for (const code of [
      'order.validated',
      'order.awaiting_payment',
      'order.paid',
      'order.to_delivery',
      'order.in_delivery',
      'order.delivered',
      'invoice.created',
      'invoice.overdue',
    ]) {
      expect(inputByName(container, 'odoo_liff_notify_events[]')).not.toBeNull();
      expect(container.querySelector(`input[name="odoo_liff_notify_events[]"][value="${code}"]`)).toBeInTheDocument();
    }

    const testButton = screen.getByRole('button', { name: /ส่งข้อความทดสอบ/ });
    expect(testButton).toHaveAttribute('name', 'action');
    expect(testButton).toHaveAttribute('value', 'test_odoo_liff_notification');
  });

  it('applies the 5-code default odoo_liff_notify_events list (omitting order.to_delivery) when the stored value is empty', async () => {
    process.env.ODOO_INTEGRATION_ENABLED = 'true';
    const { db } = wireDb({ settingsRow: { line_account_id: 7, odoo_liff_notify_events: '' } });
    const element = await NotificationsTab({ db, currentBotId: 7 });
    const { container } = render(element);

    const checkedByValue = (value: string) => container.querySelector<HTMLInputElement>(`input[name="odoo_liff_notify_events[]"][value="${value}"]`);

    for (const code of ['order.validated', 'order.awaiting_payment', 'order.paid', 'order.in_delivery', 'order.delivered']) {
      expect(checkedByValue(code)).toBeChecked();
    }
    // order.to_delivery is a selectable option but NOT part of the default substitution list.
    expect(checkedByValue('order.to_delivery')).not.toBeChecked();
    expect(checkedByValue('invoice.created')).not.toBeChecked();
    expect(checkedByValue('invoice.overdue')).not.toBeChecked();
  });

  it('respects a real, non-empty odoo_liff_notify_events value instead of substituting the default list', async () => {
    process.env.ODOO_INTEGRATION_ENABLED = 'true';
    const { db } = wireDb({ settingsRow: { line_account_id: 7, odoo_liff_notify_events: 'order.to_delivery,invoice.overdue' } });
    const element = await NotificationsTab({ db, currentBotId: 7 });
    const { container } = render(element);

    const checkedByValue = (value: string) => container.querySelector<HTMLInputElement>(`input[name="odoo_liff_notify_events[]"][value="${value}"]`);

    expect(checkedByValue('order.to_delivery')).toBeChecked();
    expect(checkedByValue('invoice.overdue')).toBeChecked();
    expect(checkedByValue('order.validated')).not.toBeChecked();
  });

  it('renders the literal Telegram cross-link note pointing at the Telegram tab', async () => {
    const { db } = wireDb();
    const element = await NotificationsTab({ db, currentBotId: 7 });
    render(element);
    expect(screen.getByText('ตั้งค่า Telegram Bot ได้ที่แท็บ "Telegram"')).toBeInTheDocument();
  });
});
