import { render, screen } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { PlatformTab } from './PlatformTab';

describe('PlatformTab', () => {
  afterEach(() => {
    delete process.env.PLATFORM_WEBHOOK_BASE_URL;
  });

  it('renders the empty state + add-new forms when no accounts are connected', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const element = await PlatformTab({ db });
    render(element);

    expect(screen.getByText('ยังไม่มีเพจที่เชื่อมต่อ — เพิ่มเพจแรกด้านล่าง')).toBeInTheDocument();
    expect(screen.getByText('ยังไม่มีร้านที่เชื่อมต่อ — เพิ่มร้านแรกด้านล่าง')).toBeInTheDocument();
    expect(screen.getByText('เพิ่มเพจ Facebook ใหม่')).toBeInTheDocument();
    expect(screen.getByText('เพิ่มร้าน TikTok Shop ใหม่')).toBeInTheDocument();
  });

  it('renders connected Facebook + TikTok accounts with counts and status badges', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('facebook_accounts')) {
        return [
          { id: 1, name: 'CNY Page', page_id: '111', app_id: 'a', app_secret: 's', page_access_token: 't', verify_token: 'v', is_active: 1 },
        ];
      }
      if (sqlText.includes('tiktok_shop_accounts')) {
        return [
          {
            id: 2,
            name: 'CNY Shop',
            shop_id: '222',
            app_key: 'k',
            app_secret: 's',
            access_token: 't',
            refresh_token: null,
            shop_cipher: null,
            is_active: 0,
          },
        ];
      }
      return [];
    });

    const element = await PlatformTab({ db });
    render(element);

    expect(screen.getByText('1 เพจที่เชื่อมต่อ')).toBeInTheDocument();
    expect(screen.getByText('1 ร้านที่เชื่อมต่อ')).toBeInTheDocument();
    expect(screen.getByText('CNY Page')).toBeInTheDocument();
    expect(screen.getByText('CNY Shop')).toBeInTheDocument();
    expect(screen.getByText('เชื่อมต่ออยู่')).toBeInTheDocument();
    expect(screen.getByText('ปิดใช้งาน')).toBeInTheDocument();
  });

  it('renders the webhook URL info boxes using the configured base URL', async () => {
    process.env.PLATFORM_WEBHOOK_BASE_URL = 'https://tenant-abcd.re-ya.com';
    const { db } = makeFakeTenantDb(() => []);
    const element = await PlatformTab({ db });
    render(element);

    expect(screen.getByText('https://tenant-abcd.re-ya.com/facebook-webhook.php')).toBeInTheDocument();
    expect(screen.getByText('https://tenant-abcd.re-ya.com/tiktok-webhook.php')).toBeInTheDocument();
  });
});
