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

import {
  createLineAccountAction,
  updateLineAccountAction,
  deleteLineAccountAction,
  setDefaultLineAccountAction,
  testLineConnectionAction,
  type LineAccountFormInput,
} from './line-actions';

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown): { queries: RecordedQuery[] } {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 1, adminUserId: 1 } });
  return { queries };
}

function formDataOf(id: number): FormData {
  const fd = new FormData();
  fd.set('id', String(id));
  return fd;
}

const BASE_INPUT: LineAccountFormInput = {
  name: 'ร้าน A',
  channel_id: '1234567890',
  channel_secret: 'secret-abc',
  channel_access_token: 'token-xyz',
  basic_id: '@shopA',
  liff_id: 'liff-1',
  bot_mode: 'shop',
  // Collected by the real form but must NEVER be written — see line-actions.ts's own doc.
  welcome_message: 'สวัสดี',
  auto_reply_enabled: true,
  shop_enabled: true,
  receipt_points_enabled: true,
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('createLineAccountAction', () => {
  it('INSERTs exactly [name, channel_id, channel_secret, channel_access_token, basic_id, is_default, bot_mode, liff_id] — dropping welcome_message/auto_reply_enabled/shop_enabled/receipt_points_enabled', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('INSERT INTO line_accounts')) return { insertId: 501, affectedRows: 1 };
      return [];
    });

    await expect(createLineAccountAction(BASE_INPUT)).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO line_accounts'));
    expect(insertQuery?.sql).toContain('(name, channel_id, channel_secret, channel_access_token, basic_id, is_default, bot_mode, liff_id)');
    expect(insertQuery?.sql).not.toMatch(/welcome_message|auto_reply_enabled|shop_enabled|receipt_points_enabled|is_active/);
    expect(insertQuery?.params).toEqual(['ร้าน A', '1234567890', 'secret-abc', 'token-xyz', '@shopA', 0, 'shop', 'liff-1']);
  });

  it('always writes webhook_url from the new insertId, using the default BASE_URL', async () => {
    delete process.env.LINE_ACCOUNTS_BASE_URL;
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('INSERT INTO line_accounts')) return { insertId: 77, affectedRows: 1 };
      return [];
    });

    await expect(createLineAccountAction(BASE_INPUT)).rejects.toThrow('REDIRECT:');

    const webhookQuery = queries.find((q) => q.sql.includes('SET webhook_url'));
    expect(webhookQuery?.sql).toContain('UPDATE line_accounts SET webhook_url');
    expect(webhookQuery?.params).toEqual(['https://clinicya.re-ya.com/webhook.php?account=77', 77]);
  });

  it('when is_default is truthy, unsets every other row then promotes the new row (two-step, not a direct SET in the INSERT-adjacent UPDATE)', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('INSERT INTO line_accounts')) return { insertId: 9, affectedRows: 1 };
      return [];
    });

    await expect(createLineAccountAction({ ...BASE_INPUT, is_default: true })).rejects.toThrow('REDIRECT:');

    const defaultQueries = queries.filter((q) => q.sql.includes('is_default'));
    expect(defaultQueries.map((q) => q.sql)).toEqual([
      expect.stringContaining('INSERT INTO line_accounts'),
      'UPDATE line_accounts SET is_default = 0',
      'UPDATE line_accounts SET is_default = 1 WHERE id = ?',
    ]);
    expect(defaultQueries[2]?.params).toEqual([9]);
  });

  it('does NOT touch is_default beyond the initial insert value when is_default is falsy', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('INSERT INTO line_accounts')) return { insertId: 9, affectedRows: 1 };
      return [];
    });

    await expect(createLineAccountAction(BASE_INPUT)).rejects.toThrow('REDIRECT:');

    const promoteQueries = queries.filter((q) => q.sql.startsWith('UPDATE line_accounts SET is_default'));
    expect(promoteQueries).toHaveLength(0);
  });

  it('redirects to /settings?tab=line&message=<เพิ่มบัญชีสำเร็จ>', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('INSERT') ? { insertId: 1, affectedRows: 1 } : []));
    await expect(createLineAccountAction(BASE_INPUT)).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=line&message=${encodeURIComponent('เพิ่มบัญชีสำเร็จ')}`);
  });

  it('propagates (rejects) a thrown DB error — no try/catch, unlike welcome/email actions', async () => {
    wireFakeDb(() => {
      throw new Error('DB write failed');
    });
    await expect(createLineAccountAction(BASE_INPUT)).rejects.toThrow('DB write failed');
    expect(mockRedirect).not.toHaveBeenCalled();
  });
});

describe('updateLineAccountAction', () => {
  it('UPDATEs exactly [name, channel_id, channel_secret, channel_access_token, basic_id, is_active, bot_mode, liff_id] — is_default and the 4 dropped fields are absent from the SET clause', async () => {
    const { queries } = wireFakeDb(() => []);

    await expect(updateLineAccountAction(12, { ...BASE_INPUT, is_active: true })).rejects.toThrow('REDIRECT:');

    const updateQuery = queries.find((q) => q.sql.includes('UPDATE line_accounts') && q.sql.includes('SET name'));
    const normalizedSql = (updateQuery?.sql ?? '').replace(/\s+/g, ' ').trim();
    expect(normalizedSql).toContain(
      'SET name = ?, channel_id = ?, channel_secret = ?, channel_access_token = ?, basic_id = ?, is_active = ?, bot_mode = ?, liff_id = ?'
    );
    expect(updateQuery?.sql).not.toMatch(/welcome_message|auto_reply_enabled|shop_enabled|receipt_points_enabled|is_default\s*=/);
    expect(updateQuery?.params).toEqual(['ร้าน A', '1234567890', 'secret-abc', 'token-xyz', '@shopA', 1, 'shop', 'liff-1', 12]);
  });

  it('when is_default is truthy, additionally runs the two-step promote for the target id', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(updateLineAccountAction(12, { ...BASE_INPUT, is_default: true })).rejects.toThrow('REDIRECT:');

    const promoteQueries = queries.filter((q) => q.sql.startsWith('UPDATE line_accounts SET is_default'));
    expect(promoteQueries.map((q) => q.sql)).toEqual(['UPDATE line_accounts SET is_default = 0', 'UPDATE line_accounts SET is_default = 1 WHERE id = ?']);
    expect(promoteQueries[1]?.params).toEqual([12]);
  });

  it('unchecking is_default on an already-default account is a no-op for is_default (never demotes)', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(updateLineAccountAction(12, { ...BASE_INPUT, is_default: false })).rejects.toThrow('REDIRECT:');
    const promoteQueries = queries.filter((q) => q.sql.includes('is_default'));
    expect(promoteQueries).toHaveLength(0);
  });

  it('redirects to /settings?tab=line&message=<อัพเดทสำเร็จ>', async () => {
    wireFakeDb(() => []);
    await expect(updateLineAccountAction(12, BASE_INPUT)).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=line&message=${encodeURIComponent('อัพเดทสำเร็จ')}`);
  });

  it('propagates (rejects) a thrown DB error', async () => {
    wireFakeDb(() => {
      throw new Error('DB write failed');
    });
    await expect(updateLineAccountAction(12, BASE_INPUT)).rejects.toThrow('DB write failed');
    expect(mockRedirect).not.toHaveBeenCalled();
  });
});

describe('deleteLineAccountAction', () => {
  it('executes DELETE FROM line_accounts WHERE id = ? AND is_default = 0 (the exact guard)', async () => {
    const { queries } = wireFakeDb(() => ({ affectedRows: 1 }));
    await expect(deleteLineAccountAction(formDataOf(5))).rejects.toThrow('REDIRECT:');
    expect(queries[0]?.sql).toBe('DELETE FROM line_accounts WHERE id = ? AND is_default = 0');
    expect(queries[0]?.params).toEqual([5]);
  });

  it('redirects to the success message even when the guard silently deletes zero rows (deleting the default account)', async () => {
    wireFakeDb(() => ({ affectedRows: 0 }));
    await expect(deleteLineAccountAction(formDataOf(5))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=line&message=${encodeURIComponent('ลบสำเร็จ')}`);
  });

  it('propagates (rejects) a thrown DB error', async () => {
    wireFakeDb(() => {
      throw new Error('DB write failed');
    });
    await expect(deleteLineAccountAction(formDataOf(5))).rejects.toThrow('DB write failed');
    expect(mockRedirect).not.toHaveBeenCalled();
  });
});

describe('setDefaultLineAccountAction', () => {
  it('runs the two-step unset-then-promote UPDATE', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(setDefaultLineAccountAction(formDataOf(9))).rejects.toThrow('REDIRECT:');
    expect(queries.map((q) => q.sql)).toEqual(['UPDATE line_accounts SET is_default = 0', 'UPDATE line_accounts SET is_default = 1 WHERE id = ?']);
    expect(queries[1]?.params).toEqual([9]);
  });

  it('redirects to /settings?tab=line&message=<ตั้งเป็นบัญชีหลักสำเร็จ>', async () => {
    wireFakeDb(() => []);
    await expect(setDefaultLineAccountAction(formDataOf(9))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=line&message=${encodeURIComponent('ตั้งเป็นบัญชีหลักสำเร็จ')}`);
  });

  it('propagates (rejects) a thrown DB error', async () => {
    wireFakeDb(() => {
      throw new Error('DB write failed');
    });
    await expect(setDefaultLineAccountAction(formDataOf(9))).rejects.toThrow('DB write failed');
    expect(mockRedirect).not.toHaveBeenCalled();
  });
});

describe('testLineConnectionAction', () => {
  beforeEach(() => {
    (global as unknown as { fetch: jest.Mock }).fetch = jest.fn();
  });

  it('returns "Account not found" without calling fetch when no row matches', async () => {
    wireFakeDb(() => []);
    const result = await testLineConnectionAction(999);
    expect(result).toEqual({ success: false, message: 'Account not found' });
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('calls GET https://api.line.me/v2/bot/info with Authorization: Bearer <channel_access_token>', async () => {
    wireFakeDb(() => [{ id: 5, channel_access_token: 'token-abc', channel_secret: 'secret' }]);
    (global.fetch as jest.Mock).mockResolvedValue({ json: async () => ({ userId: 'U123', displayName: 'ร้าน A', pictureUrl: 'https://x/pic.png' }) });

    await testLineConnectionAction(5);

    expect(global.fetch).toHaveBeenCalledWith('https://api.line.me/v2/bot/info', expect.objectContaining({ headers: { Authorization: 'Bearer token-abc' } }));
  });

  it('on a response containing userId, UPDATEs picture_url and returns {success:true, data}', async () => {
    const { queries } = wireFakeDb((sqlText) => (sqlText.includes('SELECT') ? [{ id: 5, channel_access_token: 'token-abc', channel_secret: 'secret' }] : []));
    (global.fetch as jest.Mock).mockResolvedValue({ json: async () => ({ userId: 'U123', displayName: 'ร้าน A', pictureUrl: 'https://x/pic.png' }) });

    const result = await testLineConnectionAction(5);

    expect(result.success).toBe(true);
    expect(result.data).toEqual({ userId: 'U123', displayName: 'ร้าน A', pictureUrl: 'https://x/pic.png' });
    const updateQuery = queries.find((q) => q.sql.includes('UPDATE line_accounts SET picture_url'));
    expect(updateQuery?.sql).toBe('UPDATE line_accounts SET picture_url = ? WHERE id = ?');
    expect(updateQuery?.params).toEqual(['https://x/pic.png', 5]);
  });

  it('on a response with no userId, returns {success:false, message} and does NOT write to the DB', async () => {
    const { queries } = wireFakeDb((sqlText) => (sqlText.includes('SELECT') ? [{ id: 5, channel_access_token: 'token-abc', channel_secret: 'secret' }] : []));
    (global.fetch as jest.Mock).mockResolvedValue({ json: async () => ({ message: 'invalid token' }) });

    const result = await testLineConnectionAction(5);

    expect(result).toEqual({ success: false, message: 'invalid token' });
    expect(queries.some((q) => q.sql.includes('UPDATE'))).toBe(false);
  });

  it('falls back to the literal "Connection failed" message when the failed response has none of its own', async () => {
    wireFakeDb(() => [{ id: 5, channel_access_token: 'token-abc', channel_secret: 'secret' }]);
    (global.fetch as jest.Mock).mockResolvedValue({ json: async () => null });

    const result = await testLineConnectionAction(5);
    expect(result).toEqual({ success: false, message: 'Connection failed' });
  });

  it('catches a thrown fetch error and returns {success:false, error} instead of propagating', async () => {
    wireFakeDb(() => [{ id: 5, channel_access_token: 'token-abc', channel_secret: 'secret' }]);
    (global.fetch as jest.Mock).mockRejectedValue(new Error('network unreachable'));

    const result = await testLineConnectionAction(5);
    expect(result).toEqual({ success: false, error: 'network unreachable' });
  });
});
