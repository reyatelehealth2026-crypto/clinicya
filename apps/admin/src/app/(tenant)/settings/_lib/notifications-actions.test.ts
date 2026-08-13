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

import { saveNotificationsAction, testOdooLiffNotificationAction } from './notifications-actions';

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
});

describe('saveNotificationsAction', () => {
  it('INSERTs the 16 columns in order (line_account_id first) with isset()?1:0 booleans for a fully-checked form', async () => {
    const { queries } = wireFakeDb(() => []);

    await expect(
      saveNotificationsAction(
        formData({
          line_notify_enabled: 'on',
          line_notify_new_order: 'on',
          line_notify_payment: 'on',
          line_notify_urgent: 'on',
          line_notify_appointment: 'on',
          line_notify_low_stock: 'on',
          email_enabled: 'on',
          email_addresses: '  a@b.com  ',
          email_notify_urgent: 'on',
          email_notify_daily_report: 'on',
          email_notify_low_stock: 'on',
          telegram_enabled: 'on',
          odoo_liff_notify_enabled: 'on',
          'odoo_liff_notify_events[]': [' order.paid ', 'order.delivered'],
          'notify_admin_users[]': ['3', '5'],
        })
      )
    ).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO notification_settings'));
    expect(insertQuery?.params).toEqual([7, 1, 1, 1, 1, 1, 1, 1, 'a@b.com', 1, 1, 1, 1, 1, 'order.paid,order.delivered', '3,5']);
    expect(insertQuery?.sql).toContain('ON DUPLICATE KEY UPDATE');
  });

  it('treats every absent checkbox as 0 (unchecked), matching isset($_POST[...])', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveNotificationsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO notification_settings'));
    // [accountId, line_notify_enabled..line_notify_low_stock (6), email_enabled, emailAddresses,
    //  email_notify_urgent..email_notify_low_stock (3), telegram_enabled, odoo_liff_notify_enabled, odooEvents, notifyAdminUsers]
    expect(insertQuery?.params).toEqual([7, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, '', '']);
  });

  it('does NOT intval() notify_admin_users on the save path (asymmetric with the read side)', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveNotificationsAction(formData({ 'notify_admin_users[]': ['3', 'not-a-number', '5'] }))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO notification_settings'));
    expect(insertQuery?.params?.[15]).toBe('3,not-a-number,5');
  });

  it('resolves accountId via currentBotId ?: 0, using 0 when currentBotId is null', async () => {
    const { queries } = wireFakeDb(() => [], { currentBotId: null });
    await expect(saveNotificationsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO notification_settings'));
    expect(insertQuery?.params?.[0]).toBe(0);
  });

  it('redirects with the exact success message (no trailing "!") on success', async () => {
    wireFakeDb(() => []);
    await expect(saveNotificationsAction(formData({}))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=notifications&message=${encodeURIComponent('บันทึกการตั้งค่าการแจ้งเตือนสำเร็จ')}`);
  });

  it('redirects with a ?error= message when the INSERT throws', async () => {
    wireFakeDb(() => {
      throw new Error('DB write failed');
    });
    await expect(saveNotificationsAction(formData({}))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=notifications&error=${encodeURIComponent('เกิดข้อผิดพลาด: DB write failed')}`);
  });
});

describe('testOdooLiffNotificationAction', () => {
  beforeEach(() => {
    (global as unknown as { fetch: jest.Mock }).fetch = jest.fn().mockResolvedValue({ status: 200 });
  });

  it('errors when test_line_user_id is missing (validation, wrapped by the outer catch-all prefix)', async () => {
    wireFakeDb(() => []);
    await expect(testOdooLiffNotificationAction(formData({}))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=notifications&error=${encodeURIComponent('ทดสอบส่งแจ้งเตือนไม่สำเร็จ: กรุณาระบุ LINE User ID ที่ต้องการทดสอบส่ง')}`
    );
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('errors on an unrecognized test_odoo_event code', async () => {
    wireFakeDb(() => []);
    await expect(testOdooLiffNotificationAction(formData({ test_line_user_id: 'U123', test_odoo_event: 'not.a.real.code' }))).rejects.toThrow(
      'REDIRECT:'
    );
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=notifications&error=${encodeURIComponent('ทดสอบส่งแจ้งเตือนไม่สำเร็จ: สถานะทดสอบไม่ถูกต้อง')}`);
  });

  it('defaults test_odoo_event to order.validated when the field is entirely absent (not merely empty)', async () => {
    const { queries } = wireFakeDb((sqlText) => (sqlText.includes('line_accounts') ? [{ channel_access_token: 'token-abc' }] : []));
    await expect(testOdooLiffNotificationAction(formData({ test_line_user_id: 'U123' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=notifications&message=${encodeURIComponent('ส่งข้อความทดสอบ Odoo → LIFF สำเร็จแล้ว')}`);
    expect(queries.some((q) => q.sql.includes('WHERE id = ?') || q.sql.includes('line_accounts'))).toBe(true);
  });

  it('resolves channel_access_token from the account row when present', async () => {
    wireFakeDb((sqlText, params) => {
      if (sqlText.includes('WHERE id =')) {
        expect(params).toEqual([7]);
        return [{ channel_access_token: 'token-from-account' }];
      }
      return [];
    });
    await expect(testOdooLiffNotificationAction(formData({ test_line_user_id: 'U123' }))).rejects.toThrow('REDIRECT:');
    expect(global.fetch).toHaveBeenCalledWith(
      'https://api.line.me/v2/bot/message/push',
      expect.objectContaining({ headers: expect.objectContaining({ Authorization: 'Bearer token-from-account' }) })
    );
  });

  it('falls back to the is_default=1 line_accounts row when the account-scoped token is empty', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('WHERE id =')) return [{ channel_access_token: '' }];
      if (sqlText.includes('is_default = 1')) return [{ channel_access_token: 'default-token' }];
      return [];
    });
    await expect(testOdooLiffNotificationAction(formData({ test_line_user_id: 'U123' }))).rejects.toThrow('REDIRECT:');
    expect(global.fetch).toHaveBeenCalledWith(
      'https://api.line.me/v2/bot/message/push',
      expect.objectContaining({ headers: expect.objectContaining({ Authorization: 'Bearer default-token' }) })
    );
  });

  it('errors when neither line_accounts lookup yields a token', async () => {
    wireFakeDb(() => []);
    await expect(testOdooLiffNotificationAction(formData({ test_line_user_id: 'U123' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=notifications&error=${encodeURIComponent('ทดสอบส่งแจ้งเตือนไม่สำเร็จ: ไม่พบ Channel Access Token สำหรับส่งข้อความ')}`
    );
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('sends to the LINE push API with the submitted test_line_user_id and succeeds with the exact success message on HTTP 200', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('line_accounts') ? [{ channel_access_token: 'token-abc' }] : []));
    await expect(
      testOdooLiffNotificationAction(
        formData({ test_line_user_id: 'U999', test_odoo_event: 'order.paid', test_order_ref: 'SO-1', test_customer_name: 'ทดสอบ' })
      )
    ).rejects.toThrow('REDIRECT:');

    const [, init] = (global.fetch as jest.Mock).mock.calls[0];
    const body = JSON.parse(init.body);
    expect(body.to).toBe('U999');
    expect(body.messages[0].type).toBe('flex');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=notifications&message=${encodeURIComponent('ส่งข้อความทดสอบ Odoo → LIFF สำเร็จแล้ว')}`);
  });

  it('wraps a thrown fetch (network) error with "เกิดข้อผิดพลาดเครือข่าย: " inside the outer catch-all prefix', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('line_accounts') ? [{ channel_access_token: 'token-abc' }] : []));
    (global as unknown as { fetch: jest.Mock }).fetch = jest.fn().mockRejectedValue(new Error('network unreachable'));

    await expect(testOdooLiffNotificationAction(formData({ test_line_user_id: 'U123' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=notifications&error=${encodeURIComponent('ทดสอบส่งแจ้งเตือนไม่สำเร็จ: เกิดข้อผิดพลาดเครือข่าย: network unreachable')}`
    );
  });

  it('wraps a non-200 LINE API response with "LINE API ตอบกลับไม่สำเร็จ (<code>): <body>" inside the outer catch-all prefix', async () => {
    wireFakeDb((sqlText) => (sqlText.includes('line_accounts') ? [{ channel_access_token: 'token-abc' }] : []));
    (global as unknown as { fetch: jest.Mock }).fetch = jest.fn().mockResolvedValue({ status: 400, text: async () => '{"message":"bad"}' });

    await expect(testOdooLiffNotificationAction(formData({ test_line_user_id: 'U123' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=notifications&error=${encodeURIComponent('ทดสอบส่งแจ้งเตือนไม่สำเร็จ: LINE API ตอบกลับไม่สำเร็จ (400): {"message":"bad"}')}`
    );
  });

  it('has NO isOdooIntegrationEnabled() gate of its own — reachable regardless of the env var (form-hiding-only gate)', async () => {
    delete process.env.ODOO_INTEGRATION_ENABLED;
    wireFakeDb((sqlText) => (sqlText.includes('line_accounts') ? [{ channel_access_token: 'token-abc' }] : []));
    await expect(testOdooLiffNotificationAction(formData({ test_line_user_id: 'U123' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=notifications&message=${encodeURIComponent('ส่งข้อความทดสอบ Odoo → LIFF สำเร็จแล้ว')}`);
  });
});
