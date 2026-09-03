import { makeFakeTenantDb, type RecordedQuery } from '../../users/testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRedirect = jest.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
jest.mock('next/navigation', () => ({
  redirect: (url: string) => mockRedirect(url),
}));

const mockSaveShopLogoUpload = jest.fn();
jest.mock('./general-upload', () => ({
  saveShopLogoUpload: (...args: unknown[]) => mockSaveShopLogoUpload(...args),
}));

import { saveGeneralSettingsAction } from './general-actions';

function formData(fields: Record<string, string | string[]>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) {
    if (Array.isArray(v)) {
      for (const item of v) fd.append(k, item);
    } else {
      fd.set(k, v);
    }
  }
  return fd;
}

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown,
  session: { currentBotId: number | null } = { currentBotId: 7 }
): { queries: RecordedQuery[] } {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session });
  return { queries };
}

beforeEach(() => {
  jest.clearAllMocks();
  mockSaveShopLogoUpload.mockResolvedValue({ logoUrl: null });
});

describe('saveGeneralSettingsAction — bank_accounts JSON zipping', () => {
  it('zips repeated bank_name[]/bank_account[]/bank_holder[] entries into {"banks":[{name,account,holder}]}', async () => {
    const { queries } = wireFakeDb(() => []);

    await expect(
      saveGeneralSettingsAction(
        formData({
          shop_name: 'ร้านยา',
          'bank_name[]': ['KBank', 'SCB'],
          'bank_account[]': ['111-1', '222-2'],
          'bank_holder[]': ['CNY A', 'CNY B'],
        })
      )
    ).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    const bankAccountsJson = insertQuery?.params?.[7] as string;
    expect(JSON.parse(bankAccountsJson)).toEqual({
      banks: [
        { name: 'KBank', account: '111-1', holder: 'CNY A' },
        { name: 'SCB', account: '222-2', holder: 'CNY B' },
      ],
    });
  });

  it('pads shorter arrays with null, matching PHP array_map() over uneven-length arrays', async () => {
    const { queries } = wireFakeDb(() => []);

    await expect(
      saveGeneralSettingsAction(
        formData({
          'bank_name[]': ['KBank', 'SCB', 'BBL'],
          'bank_account[]': ['111-1'],
          'bank_holder[]': [],
        })
      )
    ).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    const bankAccountsJson = insertQuery?.params?.[7] as string;
    expect(JSON.parse(bankAccountsJson)).toEqual({
      banks: [
        { name: 'KBank', account: '111-1', holder: null },
        { name: 'SCB', account: null, holder: null },
        { name: 'BBL', account: null, holder: null },
      ],
    });
  });

  it('produces an empty banks array when no bank rows are submitted', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveGeneralSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(JSON.parse(insertQuery?.params?.[7] as string)).toEqual({ banks: [] });
  });
});

describe('saveGeneralSettingsAction — upsert by line_account_id', () => {
  it('INSERTs (with line_account_id trailing) when no existing row is found', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('SELECT id FROM shop_settings')) return [];
      return [];
    });

    await expect(saveGeneralSettingsAction(formData({ shop_name: 'ร้านยา' }))).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery).toBeDefined();
    expect(insertQuery?.params?.[0]).toBe('ร้านยา');
    expect(insertQuery?.params?.[insertQuery.params.length - 1]).toBe(7); // trailing line_account_id
  });

  it('UPDATEs by line_account_id when an existing row is found', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('SELECT id FROM shop_settings')) return [{ id: 55 }];
      return [];
    });

    await expect(saveGeneralSettingsAction(formData({ shop_name: 'ร้านยา' }))).rejects.toThrow('REDIRECT:');

    const updateQuery = queries.find((q) => q.sql.includes('UPDATE shop_settings'));
    expect(updateQuery).toBeDefined();
    expect(updateQuery?.sql).toContain('WHERE line_account_id = ?');
    expect(updateQuery?.params?.[0]).toBe('ร้านยา');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery).toBeUndefined();
  });

  it('defaults currentBotId to 1 when session.currentBotId is null', async () => {
    const { queries } = wireFakeDb(() => [], { currentBotId: null });
    await expect(saveGeneralSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const selectQuery = queries.find((q) => q.sql.includes('SELECT id FROM shop_settings'));
    expect(selectQuery?.params).toEqual([1]);
  });
});

describe('saveGeneralSettingsAction — field coercion', () => {
  it('normalizes order_data_source to "odoo" only for an exact (trimmed, lowercased) "odoo" value', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveGeneralSettingsAction(formData({ order_data_source: ' ODOO ' }))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery?.params?.[14]).toBe('odoo');
  });

  it('normalizes any other order_data_source value (including absent) to "shop"', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveGeneralSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery?.params?.[14]).toBe('shop');
  });

  it('treats absent checkboxes (is_open/cod_enabled/auto_confirm_payment) as 0', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveGeneralSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery?.params?.[10]).toBe(0); // is_open
    expect(insertQuery?.params?.[11]).toBe(0); // cod_enabled
    expect(insertQuery?.params?.[13]).toBe(0); // auto_confirm_payment
  });

  it('treats present checkboxes as 1', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(
      saveGeneralSettingsAction(formData({ is_open: 'on', cod_enabled: 'on', auto_confirm_payment: 'on' }))
    ).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery?.params?.[10]).toBe(1);
    expect(insertQuery?.params?.[11]).toBe(1);
    expect(insertQuery?.params?.[13]).toBe(1);
  });

  it('defaults shipping_fee/free_shipping_min/cod_fee when absent (50 / 500 / 0)', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveGeneralSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery?.params?.[5]).toBe(50);
    expect(insertQuery?.params?.[6]).toBe(500);
    expect(insertQuery?.params?.[12]).toBe(0);
  });

  it('casts numeric fields with PHP (float)-cast semantics', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveGeneralSettingsAction(formData({ shipping_fee: '75.5abc', cod_fee: 'notanumber' }))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery?.params?.[5]).toBe(75.5);
    expect(insertQuery?.params?.[12]).toBe(0);
  });
});

describe('saveGeneralSettingsAction — shop logo upload', () => {
  it('keeps the shop_logo URL text field when no file is uploaded', async () => {
    mockSaveShopLogoUpload.mockResolvedValue({ logoUrl: null });
    const { queries } = wireFakeDb(() => []);
    await expect(saveGeneralSettingsAction(formData({ shop_logo: 'https://example.com/logo.png' }))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery?.params?.[1]).toBe('https://example.com/logo.png');
  });

  it('uses the uploaded file URL over the shop_logo text field when a file upload succeeds', async () => {
    mockSaveShopLogoUpload.mockResolvedValue({ logoUrl: 'https://clinicya.re-ya.com/uploads/shop/logo_7_123.png' });
    const { queries } = wireFakeDb(() => []);

    const fd = formData({ shop_logo: 'https://example.com/old-logo.png' });
    fd.set('logo_file', new File([new Uint8Array([1, 2, 3])], 'logo.png'));

    await expect(saveGeneralSettingsAction(fd)).rejects.toThrow('REDIRECT:');
    expect(mockSaveShopLogoUpload).toHaveBeenCalledWith(expect.any(File), 7);

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO shop_settings'));
    expect(insertQuery?.params?.[1]).toBe('https://clinicya.re-ya.com/uploads/shop/logo_7_123.png');
  });

  it('does not attempt an upload when logo_file is absent/empty', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveGeneralSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    expect(mockSaveShopLogoUpload).not.toHaveBeenCalled();
    void queries;
  });
});

describe('saveGeneralSettingsAction — redirects', () => {
  it('redirects to /settings?tab=general&message=บันทึกการตั้งค่าสำเร็จ! on success', async () => {
    wireFakeDb(() => []);
    await expect(saveGeneralSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=general&message=${encodeURIComponent('บันทึกการตั้งค่าสำเร็จ!')}`);
  });

  it('redirects to /settings?tab=general&error=เกิดข้อผิดพลาด: <message> when the write throws', async () => {
    wireFakeDb(() => {
      throw new Error('DB write failed');
    });
    await expect(saveGeneralSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=general&error=${encodeURIComponent('เกิดข้อผิดพลาด: DB write failed')}`);
  });
});
