import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import {
  getNotificationSettings,
  getNotificationAdminUsers,
  resolveNotificationAccountId,
  DEFAULT_ODOO_LIFF_EVENTS,
  ODOO_EVENT_OPTIONS,
} from './notifications-queries';

describe('resolveNotificationAccountId', () => {
  it('mirrors PHP\'s (int) ($currentBotId ?: 0): a positive id passes through, null/0 fall back to 0', () => {
    expect(resolveNotificationAccountId(7)).toBe(7);
    expect(resolveNotificationAccountId(null)).toBe(0);
    expect(resolveNotificationAccountId(0)).toBe(0);
  });
});

describe('getNotificationSettings', () => {
  it('falls back to every per-field default when no row exists (query succeeds, empty result)', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getNotificationSettings(db, 7);
    expect(result).toEqual({
      lineNotifyEnabled: true,
      lineNotifyNewOrder: true,
      lineNotifyPayment: true,
      lineNotifyUrgent: true,
      lineNotifyAppointment: true,
      lineNotifyLowStock: false,
      emailEnabled: false,
      emailAddresses: '',
      emailNotifyUrgent: true,
      emailNotifyDailyReport: false,
      emailNotifyLowStock: false,
      telegramEnabled: false,
      odooLiffNotifyEnabled: true,
      odooLiffNotifyEvents: DEFAULT_ODOO_LIFF_EVENTS,
      notifyAdminUsers: [],
    });
  });

  it('falls back to the same defaults when the query itself throws (mirrors catch (Exception $e) {})', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'tenant.notification_settings' doesn't exist");
    });
    const result = await getNotificationSettings(db, 7);
    expect(result.lineNotifyEnabled).toBe(true);
    expect(result.odooLiffNotifyEvents).toEqual(DEFAULT_ODOO_LIFF_EVENTS);
  });

  it('binds line_account_id via resolveNotificationAccountId (currentBotId ?: 0)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getNotificationSettings(db, 7);
    expect(queries[0]?.sql).toContain('line_account_id = ?');
    expect(queries[0]?.params).toEqual([7]);

    const { db: db2, queries: queries2 } = makeFakeTenantDb(() => []);
    await getNotificationSettings(db2, null);
    expect(queries2[0]?.params).toEqual([0]);
  });

  it('treats a stored 0 value as falsy (unchecked), not as "unset" (?? only substitutes for null/undefined)', async () => {
    const { db } = makeFakeTenantDb(() => [
      {
        id: 1,
        line_account_id: 7,
        line_notify_enabled: 0,
        line_notify_new_order: 0,
        line_notify_payment: 0,
        line_notify_urgent: 0,
        line_notify_appointment: 0,
        line_notify_low_stock: 1,
        email_enabled: 1,
        email_addresses: 'a@b.com',
        email_notify_urgent: 0,
        email_notify_daily_report: 1,
        email_notify_low_stock: 1,
        telegram_enabled: 1,
        odoo_liff_notify_enabled: 0,
        odoo_liff_notify_events: 'order.paid',
        notify_admin_users: '3',
      },
    ]);
    const result = await getNotificationSettings(db, 7);
    expect(result.lineNotifyEnabled).toBe(false);
    expect(result.lineNotifyLowStock).toBe(true);
    expect(result.odooLiffNotifyEnabled).toBe(false);
    expect(result.emailAddresses).toBe('a@b.com');
    expect(result.odooLiffNotifyEvents).toEqual(['order.paid']);
    expect(result.notifyAdminUsers).toEqual([3]);
  });

  it('substitutes the 5-code default list when odoo_liff_notify_events is an empty string, and that default OMITS order.to_delivery', async () => {
    const { db } = makeFakeTenantDb(() => [{ id: 1, line_account_id: 7, odoo_liff_notify_events: '' }]);
    const result = await getNotificationSettings(db, 7);
    expect(result.odooLiffNotifyEvents).toEqual(DEFAULT_ODOO_LIFF_EVENTS);
    expect(result.odooLiffNotifyEvents).not.toContain('order.to_delivery');
    expect(result.odooLiffNotifyEvents).not.toContain('invoice.created');
    expect(result.odooLiffNotifyEvents).not.toContain('invoice.overdue');
    // Sanity: order.to_delivery IS one of the 8 selectable options, just not a default.
    expect(ODOO_EVENT_OPTIONS.map((o) => o.code)).toContain('order.to_delivery');
  });

  it('substitutes the default list when odoo_liff_notify_events is only whitespace/commas (all entries trim to empty)', async () => {
    const { db } = makeFakeTenantDb(() => [{ id: 1, line_account_id: 7, odoo_liff_notify_events: ' , , ' }]);
    const result = await getNotificationSettings(db, 7);
    expect(result.odooLiffNotifyEvents).toEqual(DEFAULT_ODOO_LIFF_EVENTS);
  });

  it('trims each stored odoo_liff_notify_events code and drops empty fragments, without substituting the default', async () => {
    const { db } = makeFakeTenantDb(() => [{ id: 1, line_account_id: 7, odoo_liff_notify_events: ' order.paid , order.delivered,, ' }]);
    const result = await getNotificationSettings(db, 7);
    expect(result.odooLiffNotifyEvents).toEqual(['order.paid', 'order.delivered']);
  });

  it('parses notify_admin_users via intval-and-filter-zero (stray/non-numeric fragments drop out, no default substitution)', async () => {
    const { db } = makeFakeTenantDb(() => [{ id: 1, line_account_id: 7, notify_admin_users: '3,5,0,abc,  12 ' }]);
    const result = await getNotificationSettings(db, 7);
    expect(result.notifyAdminUsers).toEqual([3, 5, 12]);
  });

  it('returns an empty notifyAdminUsers array (no default substitution) when the stored value is empty', async () => {
    const { db } = makeFakeTenantDb(() => [{ id: 1, line_account_id: 7, notify_admin_users: '' }]);
    const result = await getNotificationSettings(db, 7);
    expect(result.notifyAdminUsers).toEqual([]);
  });
});

describe('getNotificationAdminUsers', () => {
  it('returns admin_users rows ordered by role, username on success', async () => {
    const rows = [{ id: 1, username: 'a', email: 'a@b.com', line_user_id: 'U1', role: 'admin' }];
    const { db, queries } = makeFakeTenantDb(() => rows);
    const result = await getNotificationAdminUsers(db);
    expect(result).toEqual(rows);
    expect(queries[0]?.sql).toContain('FROM admin_users');
    expect(queries[0]?.sql).toContain('WHERE is_active = 1');
    expect(queries[0]?.sql).toContain('ORDER BY role, username');
  });

  it('degrades to an empty array (catch-to-default, NOT throw-to-caller) when admin_users is missing', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'tenant.admin_users' doesn't exist");
    });
    await expect(getNotificationAdminUsers(db)).resolves.toEqual([]);
  });
});
