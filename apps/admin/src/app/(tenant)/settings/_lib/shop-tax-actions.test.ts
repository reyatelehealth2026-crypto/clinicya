import { makeFakeTenantDb, type RecordedQuery } from '../../users/testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

import { saveShopTaxInfoAction } from './shop-tax-actions';
import { NO_LINE_ACCOUNT_MESSAGE, SAVE_SUCCESS_MESSAGE } from './shop-tax-queries';

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown, session: { currentBotId: number | null; adminUserId: number } = { currentBotId: 7, adminUserId: 3 }): { queries: RecordedQuery[] } {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session });
  return { queries };
}

const MINIMAL_INPUT = { business_name: 'ร้านยา A' };

beforeEach(() => {
  jest.clearAllMocks();
});

describe('saveShopTaxInfoAction — tenant resolution', () => {
  it('returns the no_line_account error, WITHOUT touching the DB, when every tier resolves to 0', async () => {
    const { queries } = wireFakeDb(() => [], { currentBotId: null, adminUserId: 0 });

    const result = await saveShopTaxInfoAction(MINIMAL_INPUT);

    expect(result).toEqual({ success: false, error: 'no_line_account', message: NO_LINE_ACCOUNT_MESSAGE });
    expect(queries.some((q) => q.sql.includes('INSERT INTO shop_tax_info'))).toBe(false);
  });

  it('resolves the tenant via session.currentBotId (tier 1) and writes using it', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('SELECT * FROM shop_tax_info')) return [{ business_name: 'ร้านยา A', branch_code: '00000', is_vat_registered: 0, default_vat_rate: '7.00' }];
      return [];
    });

    await saveShopTaxInfoAction(MINIMAL_INPUT);

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_tax_info'));
    expect(insertQuery?.params?.[0]).toBe(7);
  });

  it('falls through to tier 4 (line_accounts) when session tiers are exhausted', async () => {
    const { queries } = wireFakeDb(
      (sqlText) => {
        if (sqlText.includes('FROM line_accounts')) return [{ id: 21 }];
        if (sqlText.includes('SELECT * FROM shop_tax_info')) return [{}];
        return [];
      },
      { currentBotId: null, adminUserId: 0 }
    );

    await saveShopTaxInfoAction(MINIMAL_INPUT);

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_tax_info'));
    expect(insertQuery?.params?.[0]).toBe(21);
  });
});

describe('saveShopTaxInfoAction — truncation', () => {
  async function captureInsertParams(input: Record<string, unknown>) {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('SELECT * FROM shop_tax_info')) return [{}];
      return [];
    });
    await saveShopTaxInfoAction({ business_name: 'x', ...input });
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_tax_info'));
    return insertQuery!.params as unknown[];
  }

  it('truncates business_name to 255 bytes', async () => {
    const long = 'a'.repeat(300);
    const params = await captureInsertParams({ business_name: long });
    expect((params[1] as string).length).toBe(255);
  });

  it('truncates business_name_en to 255 bytes', async () => {
    const long = 'b'.repeat(300);
    const params = await captureInsertParams({ business_name_en: long });
    expect((params[2] as string).length).toBe(255);
  });

  it('truncates tax_id to 20 bytes', async () => {
    const long = '1'.repeat(40);
    const params = await captureInsertParams({ tax_id: long });
    expect((params[3] as string).length).toBe(20);
  });

  it('truncates branch_code to 20 bytes when non-empty after truncation', async () => {
    const long = '9'.repeat(40);
    const params = await captureInsertParams({ branch_code: long });
    expect((params[4] as string).length).toBe(20);
  });

  it('defaults branch_code to "00000" when the truncated value is empty (explicit empty string)', async () => {
    const params = await captureInsertParams({ branch_code: '' });
    expect(params[4]).toBe('00000');
  });

  it('defaults branch_code to "00000" when the field is absent entirely', async () => {
    const params = await captureInsertParams({});
    expect(params[4]).toBe('00000');
  });

  it('does NOT truncate address (text column, no substr() in PHP)', async () => {
    const long = 'ที่อยู่'.repeat(200); // well over 255 bytes worth of Thai text
    const params = await captureInsertParams({ address: long });
    expect(params[5]).toBe(long);
  });

  it('truncates phone to 50 bytes', async () => {
    const long = '0'.repeat(80);
    const params = await captureInsertParams({ phone: long });
    expect((params[6] as string).length).toBe(50);
  });

  it('truncates email to 100 bytes', async () => {
    const long = `${'a'.repeat(120)}@example.com`;
    const params = await captureInsertParams({ email: long });
    expect((params[7] as string).length).toBe(100);
  });

  it('truncates logo_url to 500 bytes', async () => {
    const long = `https://example.com/${'a'.repeat(600)}.png`;
    const params = await captureInsertParams({ logo_url: long });
    expect((params[8] as string).length).toBe(500);
  });

  it('truncates authorized_signer to 255 bytes', async () => {
    const long = 'c'.repeat(300);
    const params = await captureInsertParams({ authorized_signer: long });
    expect((params[9] as string).length).toBe(255);
  });

  it('truncates signer_position to 100 bytes', async () => {
    const long = 'd'.repeat(150);
    const params = await captureInsertParams({ signer_position: long });
    expect((params[10] as string).length).toBe(100);
  });

  it('truncates Thai (multi-byte UTF-8) text on a BYTE boundary, not a character boundary', async () => {
    // Each Thai character below is 3 bytes in UTF-8, so 100 characters = 300 bytes > 255.
    const thai = 'ก'.repeat(100);
    const params = await captureInsertParams({ business_name: thai });
    const truncated = params[1] as string;
    expect(Buffer.byteLength(truncated, 'utf8')).toBeLessThanOrEqual(255);
    // A naive JS `.slice(0, 255)` (character-count truncation) would have kept all 100 characters — assert we truncated more aggressively (byte-oriented).
    expect(truncated.length).toBeLessThan(100);
  });
});

describe('saveShopTaxInfoAction — is_vat_registered / default_vat_rate', () => {
  it('stores is_vat_registered=1 for a truthy value', async () => {
    const { queries } = wireFakeDb((sqlText) => (sqlText.includes('SELECT * FROM shop_tax_info') ? [{}] : []));
    await saveShopTaxInfoAction({ business_name: 'x', is_vat_registered: 1 });
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_tax_info'));
    expect(insertQuery?.params?.[11]).toBe(1);
  });

  it('stores is_vat_registered=0 for 0/absent (PHP !empty() semantics)', async () => {
    const { queries } = wireFakeDb((sqlText) => (sqlText.includes('SELECT * FROM shop_tax_info') ? [{}] : []));
    await saveShopTaxInfoAction({ business_name: 'x', is_vat_registered: 0 });
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_tax_info'));
    expect(insertQuery?.params?.[11]).toBe(0);
  });

  it('casts default_vat_rate with PHP (float)-cast semantics', async () => {
    const { queries } = wireFakeDb((sqlText) => (sqlText.includes('SELECT * FROM shop_tax_info') ? [{}] : []));
    await saveShopTaxInfoAction({ business_name: 'x', default_vat_rate: '7.5abc' });
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_tax_info'));
    expect(insertQuery?.params?.[12]).toBe(7.5);
  });

  it('defaults default_vat_rate to 7.0 when absent', async () => {
    const { queries } = wireFakeDb((sqlText) => (sqlText.includes('SELECT * FROM shop_tax_info') ? [{}] : []));
    await saveShopTaxInfoAction({ business_name: 'x' });
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_tax_info'));
    expect(insertQuery?.params?.[12]).toBe(7.0);
  });

  it('casts a non-numeric default_vat_rate to 0', async () => {
    const { queries } = wireFakeDb((sqlText) => (sqlText.includes('SELECT * FROM shop_tax_info') ? [{}] : []));
    await saveShopTaxInfoAction({ business_name: 'x', default_vat_rate: 'abc' });
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_tax_info'));
    expect(insertQuery?.params?.[12]).toBe(0);
  });
});

describe('saveShopTaxInfoAction — insert-vs-update (ON DUPLICATE KEY UPDATE) branch', () => {
  it('always issues a single INSERT ... ON DUPLICATE KEY UPDATE statement (uq_shop_tax_line_account upsert), matching api/shop-tax.php\'s exact column list, regardless of whether a row already exists', async () => {
    const { queries } = wireFakeDb((sqlText) => (sqlText.includes('SELECT * FROM shop_tax_info') ? [{}] : []));
    await saveShopTaxInfoAction(MINIMAL_INPUT);

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_tax_info'));
    expect(insertQuery).toBeDefined();
    expect(insertQuery?.sql).toContain('ON DUPLICATE KEY UPDATE');
    expect(insertQuery?.sql).toContain(
      'INSERT INTO shop_tax_info'
    );
    for (const column of [
      'line_account_id',
      'business_name',
      'business_name_en',
      'tax_id',
      'branch_code',
      'address',
      'phone',
      'email',
      'logo_url',
      'authorized_signer',
      'signer_position',
      'is_vat_registered',
      'default_vat_rate',
    ]) {
      expect(insertQuery?.sql).toContain(column);
    }
    // line_account_id is excluded from the UPDATE clause (it's the unique key itself, not updated on conflict).
    const updateClause = insertQuery!.sql.split('ON DUPLICATE KEY UPDATE')[1]!;
    expect(updateClause).not.toContain('line_account_id = VALUES');
    expect(insertQuery?.params).toHaveLength(13);
  });

  it('re-reads the row and returns it as `data` on success (matching api/shop-tax.php\'s post-write re-SELECT)', async () => {
    wireFakeDb((sqlText) =>
      sqlText.includes('SELECT * FROM shop_tax_info')
        ? [{ business_name: 'ร้านยา A', branch_code: '00000', is_vat_registered: 0, default_vat_rate: '7.00' }]
        : []
    );

    const result = await saveShopTaxInfoAction(MINIMAL_INPUT);

    expect(result.success).toBe(true);
    expect(result.message).toBe(SAVE_SUCCESS_MESSAGE);
    expect(result.data?.businessName).toBe('ร้านยา A');
  });

  it('returns {success:false} with the write error message when the INSERT throws', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('INSERT INTO shop_tax_info')) {
        throw new Error('Duplicate entry for key uq_shop_tax_line_account');
      }
      return [];
    });

    const result = await saveShopTaxInfoAction(MINIMAL_INPUT);

    expect(result.success).toBe(false);
    expect(result.error).toBe('Duplicate entry for key uq_shop_tax_line_account');
  });
});
