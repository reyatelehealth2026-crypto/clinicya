import { render, screen } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { WelcomeTab } from './WelcomeTab';
import { DEFAULT_WELCOME_SETTINGS } from '../_lib/welcome-queries';

describe('WelcomeTab', () => {
  it('renders the header + default greeting when welcome_settings is missing (degrade path)', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'tenant.welcome_settings' doesn't exist");
    });
    const element = await WelcomeTab({ db, currentBotId: 7 });
    render(element);

    expect(screen.getByRole('heading', { name: 'ข้อความต้อนรับ' })).toBeInTheDocument();
    expect(screen.getByPlaceholderText('สวัสดีค่ะ ยินดีต้อนรับสู่ร้านของเรา...')).toHaveValue(DEFAULT_WELCOME_SETTINGS.textContent);
  });

  it('pre-checks the enable toggle when is_enabled=1 in the stored row', async () => {
    const { db } = makeFakeTenantDb(() => [
      { id: 1, line_account_id: 7, is_enabled: 1, message_type: 'text', text_content: 'สวัสดี', flex_content: '' },
    ]);
    const element = await WelcomeTab({ db, currentBotId: 7 });
    render(element);

    const toggle = screen.getByLabelText('เปิดใช้งานข้อความต้อนรับ') as HTMLInputElement;
    expect(toggle.checked).toBe(true);
    expect(toggle.getAttribute('form')).toBe('welcomeForm');
  });

  it('leaves the enable toggle unchecked when is_enabled=0', async () => {
    const { db } = makeFakeTenantDb(() => [
      { id: 1, line_account_id: 7, is_enabled: 0, message_type: 'text', text_content: '', flex_content: '' },
    ]);
    const element = await WelcomeTab({ db, currentBotId: 7 });
    render(element);

    const toggle = screen.getByLabelText('เปิดใช้งานข้อความต้อนรับ') as HTMLInputElement;
    expect(toggle.checked).toBe(false);
  });

  it('renders the flex JSON pre-filled and the message-type radio set to "flex" when stored as flex', async () => {
    const { db } = makeFakeTenantDb(() => [
      { id: 1, line_account_id: 7, is_enabled: 1, message_type: 'flex', text_content: '', flex_content: '{"type":"bubble"}' },
    ]);
    const element = await WelcomeTab({ db, currentBotId: 7 });
    render(element);

    expect(screen.getByRole('radio', { name: 'Flex Message' })).toBeChecked();
    expect(screen.getByPlaceholderText('{"type": "bubble", "body": {...}}')).toHaveValue('{"type":"bubble"}');
  });
});
