import { fireEvent, render, screen, within } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1, adminUserId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { LineAccountsTab } from './LineAccountsTab';
import type { LineAccountRow } from '../_lib/line-queries';

const ACCOUNT_DEFAULT: LineAccountRow = {
  id: 1,
  name: 'ร้าน A (หลัก)',
  channel_id: '1111111111',
  channel_secret: 'secret-1',
  channel_access_token: 'token-1',
  basic_id: '@shopA',
  bot_mode: 'shop',
  liff_id: 'liff-123',
  is_active: 1,
  is_default: 1,
  picture_url: null,
  webhook_url: null,
  welcome_message: null,
  auto_reply_enabled: 1,
  shop_enabled: 1,
};

const ACCOUNT_SECONDARY: LineAccountRow = {
  id: 2,
  name: 'ร้าน B',
  channel_id: null,
  channel_secret: 'secret-2',
  channel_access_token: 'token-2',
  basic_id: null,
  bot_mode: 'general',
  liff_id: null,
  is_active: 0,
  is_default: 0,
  picture_url: null,
  webhook_url: null,
  welcome_message: null,
  auto_reply_enabled: 0,
  shop_enabled: 0,
};

describe('LineAccountsTab — empty state', () => {
  it('renders the empty-accounts placeholder and a working "เพิ่มบัญชีแรก" trigger when there are zero accounts', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const element = await LineAccountsTab({ db });
    render(element);

    expect(screen.getByText('ยังไม่มีบัญชี LINE')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /เพิ่มบัญชีแรก/ })).toBeInTheDocument();
    expect(screen.queryByText('ร้าน A')).not.toBeInTheDocument();
  });
});

describe('LineAccountsTab — populated grid', () => {
  async function renderPopulated() {
    const { db } = makeFakeTenantDb(() => [ACCOUNT_DEFAULT, ACCOUNT_SECONDARY]);
    const element = await LineAccountsTab({ db });
    render(element);
  }

  it('renders one card per account with name + basic_id', async () => {
    await renderPopulated();
    expect(screen.getByText('ร้าน A (หลัก)')).toBeInTheDocument();
    expect(screen.getByText('@shopA')).toBeInTheDocument();
    expect(screen.getByText('ร้าน B')).toBeInTheDocument();
    expect(screen.getByText('ไม่มี Basic ID')).toBeInTheDocument();
  });

  it('shows the ⭐ หลัก badge + hides the set-default button only for the default account', async () => {
    await renderPopulated();
    expect(screen.getByText('⭐ หลัก')).toBeInTheDocument();
    // Only the non-default card (ร้าน B) gets a submittable set-default star button.
    expect(screen.getAllByTitle('ตั้งเป็นหลัก')).toHaveLength(1);
  });

  it('shows Active/Inactive + bot-mode badges per account', async () => {
    await renderPopulated();
    expect(screen.getByText('✓ Active')).toBeInTheDocument();
    expect(screen.getByText('✗ Inactive')).toBeInTheDocument();
    expect(screen.getByText(/ร้านค้า/)).toBeInTheDocument();
    expect(screen.getByText(/ทั่วไป/)).toBeInTheDocument();
  });

  it('shows the LIFF badge + LIFF ID row only for the account with a liff_id', async () => {
    await renderPopulated();
    expect(screen.getByText('📱 LIFF')).toBeInTheDocument();
    expect(screen.getByText('liff-123')).toBeInTheDocument();
  });

  it('renders the readonly webhook URL input using the default BASE_URL', async () => {
    delete process.env.LINE_ACCOUNTS_BASE_URL;
    await renderPopulated();
    const input = document.getElementById('webhook_1') as HTMLInputElement;
    expect(input).toHaveValue('https://clinicya.re-ya.com/webhook.php?account=1');
    expect(input).toHaveAttribute('readonly');
  });
});

describe('LineAccountsTab — modal open (create vs. edit)', () => {
  async function renderPopulated() {
    const { db } = makeFakeTenantDb(() => [ACCOUNT_DEFAULT, ACCOUNT_SECONDARY]);
    const element = await LineAccountsTab({ db });
    render(element);
  }

  it('opens the create modal, blank form, when "เพิ่มบัญชี LINE" is clicked', async () => {
    await renderPopulated();

    fireEvent.click(screen.getByRole('button', { name: /เพิ่มบัญชี LINE/ }));

    const dialog = await screen.findByRole('dialog', { name: 'เพิ่มบัญชี LINE' });
    expect(within(dialog).getByLabelText(/ชื่อบัญชี/)).toHaveValue('');
    // Create mode has no delete button.
    expect(within(dialog).queryByRole('button', { name: /ลบบัญชี/ })).not.toBeInTheDocument();
  });

  it('opens the edit modal pre-filled with the clicked card\'s data', async () => {
    await renderPopulated();

    const cardB = screen.getByText('ร้าน B').closest('.account-card') as HTMLElement;
    fireEvent.click(within(cardB).getByTitle('แก้ไข'));

    const dialog = await screen.findByRole('dialog', { name: 'ตั้งค่าบัญชี: ร้าน B' });
    expect(within(dialog).getByLabelText(/ชื่อบัญชี/)).toHaveValue('ร้าน B');
    expect(within(dialog).getByRole('button', { name: /ลบบัญชี/ })).toBeInTheDocument();
  });
});
