import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import {
  getFacebookAccounts,
  getTiktokAccounts,
  mapFacebookAccountRow,
  mapTiktokAccountRow,
  getFacebookWebhookUrl,
  getTiktokWebhookUrl,
} from './platform-queries';

describe('getFacebookAccounts', () => {
  it('runs the exact platform.php query (ORDER BY id DESC)', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 2, name: 'B' }]);
    await getFacebookAccounts(db);
    expect(queries[0]?.sql).toContain('SELECT * FROM facebook_accounts ORDER BY id DESC');
  });

  it('returns [] when the query throws, matching PHP\'s catch (Exception $e) {}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    await expect(getFacebookAccounts(db)).resolves.toEqual([]);
  });
});

describe('getTiktokAccounts', () => {
  it('runs the exact platform.php query (ORDER BY id DESC)', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 2, name: 'B' }]);
    await getTiktokAccounts(db);
    expect(queries[0]?.sql).toContain('SELECT * FROM tiktok_shop_accounts ORDER BY id DESC');
  });

  it('returns [] when the query throws', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    await expect(getTiktokAccounts(db)).resolves.toEqual([]);
  });
});

describe('mapFacebookAccountRow', () => {
  it('maps snake_case DB columns to the camelCase view, coalescing nulls to empty strings', () => {
    const view = mapFacebookAccountRow({
      id: 5,
      name: 'CNY Page',
      page_id: '12345',
      app_id: null,
      app_secret: null,
      page_access_token: 'tok',
      verify_token: null,
      is_active: 1,
    });
    expect(view).toEqual({
      id: 5,
      name: 'CNY Page',
      pageId: '12345',
      appId: '',
      appSecret: '',
      pageAccessToken: 'tok',
      verifyToken: '',
      isActive: true,
    });
  });

  it('maps is_active=0 to isActive: false', () => {
    expect(mapFacebookAccountRow({ id: 1, name: 'x', page_id: 'p', app_id: 'a', app_secret: 's', page_access_token: 't', verify_token: 'v', is_active: 0 }).isActive).toBe(
      false
    );
  });
});

describe('mapTiktokAccountRow', () => {
  it('maps snake_case DB columns to the camelCase view, coalescing nulls to empty strings', () => {
    const view = mapTiktokAccountRow({
      id: 9,
      name: 'CNY Shop',
      shop_id: '999',
      app_key: 'key',
      app_secret: 'secret',
      access_token: 'tok',
      refresh_token: null,
      shop_cipher: null,
      is_active: 1,
    });
    expect(view).toEqual({
      id: 9,
      name: 'CNY Shop',
      shopId: '999',
      appKey: 'key',
      appSecret: 'secret',
      accessToken: 'tok',
      refreshToken: '',
      shopCipher: '',
      isActive: true,
    });
  });
});

describe('webhook URL helpers', () => {
  const originalPlatformBase = process.env.PLATFORM_WEBHOOK_BASE_URL;

  afterEach(() => {
    process.env.PLATFORM_WEBHOOK_BASE_URL = originalPlatformBase;
  });

  it('falls back to the literal BASE_URL constant when no env override is set', () => {
    delete process.env.PLATFORM_WEBHOOK_BASE_URL;
    expect(getFacebookWebhookUrl()).toBe('https://clinicya.re-ya.com/facebook-webhook.php');
    expect(getTiktokWebhookUrl()).toBe('https://clinicya.re-ya.com/tiktok-webhook.php');
  });

  it('prefers PLATFORM_WEBHOOK_BASE_URL and strips trailing slashes', () => {
    process.env.PLATFORM_WEBHOOK_BASE_URL = 'https://tenant-abcd.re-ya.com/';
    expect(getFacebookWebhookUrl()).toBe('https://tenant-abcd.re-ya.com/facebook-webhook.php');
    expect(getTiktokWebhookUrl()).toBe('https://tenant-abcd.re-ya.com/tiktok-webhook.php');
  });
});
