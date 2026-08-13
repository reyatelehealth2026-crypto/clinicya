import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { getGeneralSettings } from './general-queries';

describe('getGeneralSettings', () => {
  it('queries by line_account_id first when currentBotId is set', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('WHERE line_account_id = ?')) return [{ shop_name: 'ร้านของฉัน' }];
      return [];
    });
    const settings = await getGeneralSettings(db, 7);
    expect(settings.shopName).toBe('ร้านของฉัน');
    expect(queries[0]?.sql).toContain('SELECT * FROM shop_settings WHERE line_account_id = ?');
    expect(queries[0]?.params).toEqual([7]);
  });

  it('falls back to the id=1/line_account_id-IS-NULL query when no row matches currentBotId', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('id = 1 OR line_account_id IS NULL')) return [{ shop_name: 'ร้านสำรอง' }];
      return [];
    });
    const settings = await getGeneralSettings(db, 7);
    expect(settings.shopName).toBe('ร้านสำรอง');
    expect(queries.some((q) => q.sql.includes('SELECT * FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1'))).toBe(true);
  });

  it('skips the line_account_id query entirely when currentBotId is null/0, going straight to the fallback query', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ shop_name: 'x' }]);
    await getGeneralSettings(db, null);
    expect(queries).toHaveLength(1);
    expect(queries[0]?.sql).toContain('id = 1 OR line_account_id IS NULL');
  });

  it('returns the hardcoded PHP defaults object (verbatim) when no row exists at all', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const settings = await getGeneralSettings(db, 7);
    expect(settings).toEqual({
      shopName: 'LINE Shop',
      shopLogo: '',
      welcomeMessage: 'ยินดีต้อนรับ!',
      shopAddress: '',
      shopEmail: '',
      shippingFee: 50,
      freeShippingMin: 500,
      bankAccounts: [],
      promptpayNumber: '',
      contactPhone: '',
      isOpen: true,
      codEnabled: false,
      codFee: 0,
      autoConfirmPayment: false,
      orderDataSource: 'shop',
      lineId: '',
      facebookUrl: '',
      instagramUrl: '',
    });
  });

  it('returns the same defaults object when the query throws (missing-table degrade)', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    const settings = await getGeneralSettings(db, 7);
    expect(settings.shopName).toBe('LINE Shop');
    expect(settings.welcomeMessage).toBe('ยินดีต้อนรับ!');
  });

  it('renders a genuinely-NULL shop_name/welcome_message column as "" — DIFFERENT from the "no row at all" case', async () => {
    const { db } = makeFakeTenantDb(() => [{ shop_name: null, welcome_message: null }]);
    const settings = await getGeneralSettings(db, 7);
    expect(settings.shopName).toBe('');
    expect(settings.welcomeMessage).toBe('');
  });

  it('parses bank_accounts JSON into the bankAccounts array', async () => {
    const { db } = makeFakeTenantDb(() => [
      { bank_accounts: JSON.stringify({ banks: [{ name: 'KBank', account: '111-1', holder: 'CNY' }] }) },
    ]);
    const settings = await getGeneralSettings(db, 7);
    expect(settings.bankAccounts).toEqual([{ name: 'KBank', account: '111-1', holder: 'CNY' }]);
  });

  it('falls back to [] for malformed bank_accounts JSON', async () => {
    const { db } = makeFakeTenantDb(() => [{ bank_accounts: '{not valid json' }]);
    const settings = await getGeneralSettings(db, 7);
    expect(settings.bankAccounts).toEqual([]);
  });

  it('defaults numeric NULL columns to their PHP form fallbacks (shipping_fee=50, free_shipping_min=500, cod_fee=0)', async () => {
    const { db } = makeFakeTenantDb(() => [{ shipping_fee: null, free_shipping_min: null, cod_fee: null }]);
    const settings = await getGeneralSettings(db, 7);
    expect(settings.shippingFee).toBe(50);
    expect(settings.freeShippingMin).toBe(500);
    expect(settings.codFee).toBe(0);
  });

  it('coerces decimal-string columns (as mysql2 returns them) to numbers', async () => {
    const { db } = makeFakeTenantDb(() => [{ shipping_fee: '75.50', cod_fee: '10.00' }]);
    const settings = await getGeneralSettings(db, 7);
    expect(settings.shippingFee).toBe(75.5);
    expect(settings.codFee).toBe(10);
  });

  it('defaults is_open to true (1) when the column is NULL', async () => {
    const { db } = makeFakeTenantDb(() => [{ is_open: null }]);
    const settings = await getGeneralSettings(db, 7);
    expect(settings.isOpen).toBe(true);
  });

  it('renders is_open: 0 as false', async () => {
    const { db } = makeFakeTenantDb(() => [{ is_open: 0 }]);
    const settings = await getGeneralSettings(db, 7);
    expect(settings.isOpen).toBe(false);
  });

  it('defaults order_data_source to "shop" when NULL', async () => {
    const { db } = makeFakeTenantDb(() => [{ order_data_source: null }]);
    const settings = await getGeneralSettings(db, 7);
    expect(settings.orderDataSource).toBe('shop');
  });
});
