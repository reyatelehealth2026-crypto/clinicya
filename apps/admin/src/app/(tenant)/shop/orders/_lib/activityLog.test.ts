import { makeFakeTenantDb } from '../testHelpers/fakeTenantDb';
import { logOrderActivity } from './activityLog';

const SESSION = { adminUserId: 42, username: 'admin1', currentBotId: 7 };

describe('logOrderActivity', () => {
  it('INSERTs one activity_logs row for the update_status branch (ACTION_UPDATE)', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ insertId: 1, affectedRows: 1 }));

    await logOrderActivity(db, SESSION, {
      action: 'update',
      description: 'อัพเดทสถานะคำสั่งซื้อ',
      entityId: 99,
      newValue: { status: 'confirmed' },
    });

    expect(queries).toHaveLength(1);
    const q = queries[0]!;
    expect(q.sql).toContain('INSERT INTO activity_logs');
    expect(q.sql).toContain('log_type, action, description, admin_id, admin_name, entity_type, entity_id, new_value, line_account_id');
    expect(q.params).toEqual(['order', 'update', 'อัพเดทสถานะคำสั่งซื้อ', 42, 'admin1', 'order', 99, JSON.stringify({ status: 'confirmed' }), 7]);
  });

  it('INSERTs one activity_logs row for the approve_payment branch (ACTION_APPROVE)', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ insertId: 1, affectedRows: 1 }));

    await logOrderActivity(db, SESSION, {
      action: 'approve',
      description: 'อนุมัติการชำระเงิน',
      entityId: 100,
      newValue: { payment_status: 'paid', status: 'paid' },
    });

    const q = queries[0]!;
    expect(q.params).toEqual([
      'order',
      'approve',
      'อนุมัติการชำระเงิน',
      42,
      'admin1',
      'order',
      100,
      JSON.stringify({ payment_status: 'paid', status: 'paid' }),
      7,
    ]);
  });

  it('binds session.currentBotId RAW (no ?? 1 default) as line_account_id, unlike the page/actions-level currentBotId used elsewhere', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ insertId: 1, affectedRows: 1 }));

    await logOrderActivity(db, { adminUserId: 1, username: 'a', currentBotId: null }, {
      action: 'update',
      description: 'x',
      entityId: 1,
      newValue: {},
    });

    expect(queries[0]?.params.at(-1)).toBeNull();
  });
});
