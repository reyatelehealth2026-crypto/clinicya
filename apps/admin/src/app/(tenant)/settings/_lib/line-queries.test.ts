import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { getLineAccounts, getLineAccountById, lineAccountsBaseUrl, buildLineWebhookUrl, LINE_SUCCESS_MESSAGES } from './line-queries';

describe('getLineAccounts', () => {
  it('issues the exact SQL LineAccountManager::getAllAccounts() runs — no WHERE/tenant filter', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getLineAccounts(db);
    expect(queries).toHaveLength(1);
    expect(queries[0]?.sql).toBe('SELECT * FROM line_accounts ORDER BY is_default DESC, name ASC');
    expect(queries[0]?.params).toEqual([]);
  });

  it('returns the rows as-is (default-first, name-ascending — real ordering is the DB\'s job, not JS post-processing)', async () => {
    const rows = [
      { id: 2, name: 'ร้าน B', is_default: 1 },
      { id: 1, name: 'ร้าน A', is_default: 0 },
    ];
    const { db } = makeFakeTenantDb(() => rows);
    const result = await getLineAccounts(db);
    expect(result).toEqual(rows);
  });
});

describe('getLineAccountById', () => {
  it('issues SELECT * FROM line_accounts WHERE id = ? bound to the given id', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getLineAccountById(db, 42);
    expect(queries[0]?.sql).toContain('SELECT * FROM line_accounts WHERE id = ?');
    expect(queries[0]?.params).toEqual([42]);
  });

  it('returns null when no row matches', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getLineAccountById(db, 999);
    expect(result).toBeNull();
  });

  it('returns the row when found', async () => {
    const row = { id: 5, name: 'ร้าน C' };
    const { db } = makeFakeTenantDb(() => [row]);
    const result = await getLineAccountById(db, 5);
    expect(result).toEqual(row);
  });
});

describe('lineAccountsBaseUrl / buildLineWebhookUrl', () => {
  const OLD_ENV = process.env.LINE_ACCOUNTS_BASE_URL;

  afterEach(() => {
    if (OLD_ENV === undefined) {
      delete process.env.LINE_ACCOUNTS_BASE_URL;
    } else {
      process.env.LINE_ACCOUNTS_BASE_URL = OLD_ENV;
    }
  });

  it('defaults to the production BASE_URL constant (no trailing slash)', () => {
    delete process.env.LINE_ACCOUNTS_BASE_URL;
    expect(lineAccountsBaseUrl()).toBe('https://clinicya.re-ya.com');
  });

  it('respects a LINE_ACCOUNTS_BASE_URL env override, stripping trailing slashes', () => {
    process.env.LINE_ACCOUNTS_BASE_URL = 'https://override.example.com/';
    expect(lineAccountsBaseUrl()).toBe('https://override.example.com');
  });

  it('buildLineWebhookUrl appends /webhook.php?account={id} to the base', () => {
    delete process.env.LINE_ACCOUNTS_BASE_URL;
    expect(buildLineWebhookUrl(7)).toBe('https://clinicya.re-ya.com/webhook.php?account=7');
  });
});

describe('LINE_SUCCESS_MESSAGES', () => {
  it('matches line.php\'s own $_GET[\'success\'] map verbatim', () => {
    expect(LINE_SUCCESS_MESSAGES).toEqual({
      created: 'เพิ่มบัญชีสำเร็จ',
      updated: 'อัพเดทสำเร็จ',
      deleted: 'ลบสำเร็จ',
      default: 'ตั้งเป็นบัญชีหลักสำเร็จ',
    });
  });
});
