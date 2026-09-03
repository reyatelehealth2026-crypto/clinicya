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

import { saveWelcomeSettingsAction } from './welcome-actions';

function formData(fields: Record<string, string>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) fd.set(k, v);
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
});

describe('saveWelcomeSettingsAction', () => {
  it('redirects with a ?error= message when welcome_settings is missing (the documented degrade path)', async () => {
    wireFakeDb(() => {
      throw new Error("Table 'tenant.welcome_settings' doesn't exist");
    });

    await expect(saveWelcomeSettingsAction(formData({ message_type: 'text', text_content: 'hi' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=welcome&error=${encodeURIComponent("เกิดข้อผิดพลาด: Table 'tenant.welcome_settings' doesn't exist")}`
    );
  });

  it('UPDATEs the existing row and redirects with a success ?message= when a row already exists', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('SELECT id FROM welcome_settings')) return [{ id: 42 }];
      return [];
    });

    await expect(
      saveWelcomeSettingsAction(formData({ is_enabled: 'on', message_type: 'flex', flex_content: '{"type":"bubble"}' }))
    ).rejects.toThrow('REDIRECT:');

    const updateQuery = queries.find((q) => q.sql.includes('UPDATE welcome_settings'));
    expect(updateQuery?.sql).toContain('SET is_enabled = ?');
    expect(updateQuery?.params).toEqual([1, 'flex', '', '{"type":"bubble"}', 42]);

    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=welcome&message=${encodeURIComponent('บันทึกการตั้งค่าข้อความต้อนรับสำเร็จ!')}`
    );
  });

  it('INSERTs a new row when none exists yet, binding currentBotId as line_account_id', async () => {
    const { queries } = wireFakeDb(() => []);

    await expect(saveWelcomeSettingsAction(formData({ message_type: 'text', text_content: 'สวัสดี' }))).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO welcome_settings'));
    expect(insertQuery?.params).toEqual([7, 0, 'text', 'สวัสดี', '']);
  });

  it('treats a missing is_enabled field as 0 (unchecked checkbox), matching isset($_POST[\'is_enabled\'])', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveWelcomeSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO welcome_settings'));
    expect(insertQuery?.params?.[1]).toBe(0);
  });

  it('defaults message_type to "text" for any value other than "flex"', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveWelcomeSettingsAction(formData({ message_type: 'garbage' }))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO welcome_settings'));
    expect(insertQuery?.params?.[2]).toBe('text');
  });

  it('redirects with a ?error= message when the UPDATE/INSERT write itself throws (row lookup succeeded)', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('SELECT id FROM welcome_settings')) return [];
      if (sqlText.includes('INSERT INTO welcome_settings')) throw new Error('DB write failed');
      return [];
    });
    mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 7 } });

    await expect(saveWelcomeSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=welcome&error=${encodeURIComponent('เกิดข้อผิดพลาด: DB write failed')}`);
  });

  it('binds a null currentBotId through to both the lookup and the insert, matching $_SESSION[\'current_bot_id\'] ?? null', async () => {
    const { queries } = wireFakeDb(() => [], { currentBotId: null });
    await expect(saveWelcomeSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const selectQuery = queries.find((q) => q.sql.includes('SELECT id FROM welcome_settings'));
    expect(selectQuery?.params).toEqual([null, null]);
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO welcome_settings'));
    expect(insertQuery?.params?.[0]).toBeNull();
  });
});
