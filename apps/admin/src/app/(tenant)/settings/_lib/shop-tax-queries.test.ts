import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { resolveLineAccountId, getShopTaxInfo, DEFAULT_SHOP_TAX_INFO } from './shop-tax-queries';

describe('resolveLineAccountId', () => {
  it('tier 1: uses sessionCurrentBotId when it is a positive number, touching no DB query', async () => {
    const { db, queries } = makeFakeTenantDb(() => {
      throw new Error('should not be queried — tier 1 already resolved');
    });

    const result = await resolveLineAccountId({ db, sessionCurrentBotId: 7, sessionAdminUserId: 3 });

    expect(result).toBe(7);
    expect(queries).toHaveLength(0);
  });

  it('tier 2: falls back to queryLineAccountId ($_GET tier) when sessionCurrentBotId is null', async () => {
    const { db, queries } = makeFakeTenantDb(() => {
      throw new Error('should not be queried — tier 2 already resolved');
    });

    const result = await resolveLineAccountId({ db, sessionCurrentBotId: null, sessionAdminUserId: 3, queryLineAccountId: 9 });

    expect(result).toBe(9);
    expect(queries).toHaveLength(0);
  });

  it('tier 2: falls back to queryLineAccountId when sessionCurrentBotId is 0 (not just null)', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await resolveLineAccountId({ db, sessionCurrentBotId: 0, sessionAdminUserId: 3, queryLineAccountId: 11 });
    expect(result).toBe(11);
  });

  it('tier 3: falls back to the admin_users.line_account_id lookup when tiers 1-2 are exhausted', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('FROM admin_users')) return [{ line_account_id: 42 }];
      return [];
    });

    const result = await resolveLineAccountId({ db, sessionCurrentBotId: null, sessionAdminUserId: 5 });

    expect(result).toBe(42);
    expect(queries[0]?.sql).toContain('FROM admin_users');
    expect(queries[0]?.params).toEqual([5]);
  });

  it('tier 3 is skipped when sessionAdminUserId is 0 (PHP !empty($_SESSION[\'user_id\']) is false)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('FROM admin_users')) return [{ line_account_id: 42 }];
      if (sqlText.includes('FROM line_accounts')) return [{ id: 3 }];
      return [];
    });

    const result = await resolveLineAccountId({ db, sessionCurrentBotId: null, sessionAdminUserId: 0 });

    expect(result).toBe(3);
    expect(queries.some((q) => q.sql.includes('FROM admin_users'))).toBe(false);
  });

  it('tier 3 swallows a thrown error (admin_users missing on the committed tenant schema) and falls through to tier 4', async () => {
    const { db } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('FROM admin_users')) {
        throw new Error("Table 'reya_tenant_0001.admin_users' doesn't exist");
      }
      if (sqlText.includes('FROM line_accounts')) return [{ id: 6 }];
      return [];
    });

    const result = await resolveLineAccountId({ db, sessionCurrentBotId: null, sessionAdminUserId: 5 });
    expect(result).toBe(6);
  });

  it('tier 4: falls back to the first is_active=1 line_accounts row when every earlier tier is exhausted', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('FROM line_accounts')) return [{ id: 13 }];
      return [];
    });

    const result = await resolveLineAccountId({ db, sessionCurrentBotId: null, sessionAdminUserId: 0 });

    expect(result).toBe(13);
    expect(queries[0]?.sql).toContain('is_active = 1');
    expect(queries[0]?.sql).toContain('ORDER BY id ASC');
  });

  it('tier 4 swallows a thrown error and resolves to 0 when every tier is exhausted', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('connection refused');
    });

    const result = await resolveLineAccountId({ db, sessionCurrentBotId: null, sessionAdminUserId: 0 });
    expect(result).toBe(0);
  });

  it('resolves to 0 when every tier is exhausted and no line_accounts row exists', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await resolveLineAccountId({ db, sessionCurrentBotId: null, sessionAdminUserId: 0 });
    expect(result).toBe(0);
  });
});

describe('DEFAULT_SHOP_TAX_INFO', () => {
  it('defaultVatRate is the string "7", matching PHP\'s own (string)7.00 float-to-string cast on the no-row path (confirmed via `php -r \'var_dump((string)7.00);\'` -> `string(1) "7"`), NOT the "7.00" a populated DECIMAL(4,2) row would return', () => {
    expect(DEFAULT_SHOP_TAX_INFO.defaultVatRate).toBe('7');
  });
});

describe('getShopTaxInfo', () => {
  it('returns the GET-side default row shape when lineAccountId <= 0, without querying the DB', async () => {
    const { db, queries } = makeFakeTenantDb(() => {
      throw new Error('should not be queried');
    });

    const result = await getShopTaxInfo(db, 0);

    expect(result).toEqual(DEFAULT_SHOP_TAX_INFO);
    expect(result).toEqual({
      businessName: '',
      businessNameEn: '',
      taxId: '',
      branchCode: '00000',
      address: '',
      phone: '',
      email: '',
      logoUrl: '',
      authorizedSigner: '',
      signerPosition: '',
      isVatRegistered: false,
      // '7' not '7.00' — PHP's own `(string)7.00` float cast on this
      // hardcoded-default (no-row) path drops the trailing zeros; see
      // DEFAULT_SHOP_TAX_INFO's own doc (confirmed via `php -r
      // 'var_dump((string)7.00);'` -> `string(1) "7"`).
      defaultVatRate: '7',
    });
    expect(queries).toHaveLength(0);
  });

  it('returns the default row shape when lineAccountId > 0 but no row exists yet', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getShopTaxInfo(db, 5);
    expect(result).toEqual(DEFAULT_SHOP_TAX_INFO);
  });

  it('maps an existing shop_tax_info row', async () => {
    const { db } = makeFakeTenantDb(() => [
      {
        business_name: 'บริษัท เรยา เฮลธ์ จำกัด',
        business_name_en: 'REYA Health Co., Ltd.',
        tax_id: '0105566123456',
        branch_code: '00001',
        address: '123 ถนนสุขุมวิท',
        phone: '02-123-4567',
        email: 'contact@reya.com',
        logo_url: 'https://cdn.example.com/logo.png',
        authorized_signer: 'นาย ก. ขีดเส้น',
        signer_position: 'กรรมการผู้จัดการ',
        is_vat_registered: 1,
        default_vat_rate: '7.00',
      },
    ]);

    const result = await getShopTaxInfo(db, 5);

    expect(result).toEqual({
      businessName: 'บริษัท เรยา เฮลธ์ จำกัด',
      businessNameEn: 'REYA Health Co., Ltd.',
      taxId: '0105566123456',
      branchCode: '00001',
      address: '123 ถนนสุขุมวิท',
      phone: '02-123-4567',
      email: 'contact@reya.com',
      logoUrl: 'https://cdn.example.com/logo.png',
      authorizedSigner: 'นาย ก. ขีดเส้น',
      signerPosition: 'กรรมการผู้จัดการ',
      isVatRegistered: true,
      defaultVatRate: '7.00',
    });
  });

  it('degrades to the default row shape when the query throws (table may not exist on stale envs)', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'reya_tenant_0001.shop_tax_info' doesn't exist");
    });
    const result = await getShopTaxInfo(db, 5);
    expect(result).toEqual(DEFAULT_SHOP_TAX_INFO);
  });
});
