import { makeFakeTenantDb } from '../testHelpers/fakeTenantDb';
import { awardOrderLoyaltyPoints } from './loyaltyAward';

/**
 * loyaltyAward.test.ts — asserts the acceptance-criteria-mandated behaviors:
 *  - default pointsPerBaht falls back to 1 (NOT 0.001) when no points_settings row exists
 *  - floor()-rounds earnedPoints
 *  - inserts into BOTH points_history and points_transactions
 *  - does NOT touch users.total_points/available_points (only users.points),
 *    and never reaches into the user-detail loyalty module (this file has no
 *    such import at all — see the module's own doc comment).
 */

const ORDER_ROW = { user_id: 55, grand_total: '199.90', order_number: 'ORD-777', current_points: 10 };

describe('awardOrderLoyaltyPoints', () => {
  it('falls back to pointsPerBaht=1 (not 0.001) when no points_settings row exists, floors the result, and writes both points tables + users.points', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM transactions o JOIN users u')) return [ORDER_ROW];
      if (sqlText.includes('FROM points_settings')) return []; // no row -> default applies
      if (/insert into `points_history`/i.test(sqlText)) return { insertId: 1, affectedRows: 1 };
      if (/insert into `points_transactions`/i.test(sqlText)) return { insertId: 2, affectedRows: 1 };
      if (/update `users`/i.test(sqlText)) return { affectedRows: 1 };
      return [];
    });

    await awardOrderLoyaltyPoints(db, 42, 7);

    // pointsPerBaht=1, grand_total=199.90 -> floor(199.90) = 199 earned points.
    const historyInsert = queries.find((q) => /insert into `points_history`/i.test(q.sql));
    expect(historyInsert).toBeDefined();
    expect(historyInsert!.params).toContain(199);

    const txInsert = queries.find((q) => /insert into `points_transactions`/i.test(q.sql));
    expect(txInsert).toBeDefined();
    expect(txInsert!.params).toContain(199);

    // balance_after = current_points(10) + earned(199) = 209, present in both inserts.
    expect(historyInsert!.params).toContain(209);
    expect(txInsert!.params).toContain(209);
  });

  it('updates users.points only — never total_points/available_points (the user-detail addPoints() columns)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM transactions o JOIN users u')) return [ORDER_ROW];
      if (sqlText.includes('FROM points_settings')) return [];
      return [];
    });

    await awardOrderLoyaltyPoints(db, 42, 7);

    const userUpdate = queries.find((q) => /update `users`/i.test(q.sql));
    expect(userUpdate).toBeDefined();
    expect(userUpdate!.sql).toMatch(/set `points`/i);
    expect(userUpdate!.sql).not.toMatch(/total_points/i);
    expect(userUpdate!.sql).not.toMatch(/available_points/i);
  });

  it('uses points_per_baht from points_settings when a row exists (not the 1 default)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM transactions o JOIN users u')) return [{ ...ORDER_ROW, grand_total: '100.00' }];
      if (sqlText.includes('FROM points_settings')) return [{ points_per_baht: '2.5' }];
      return [];
    });

    await awardOrderLoyaltyPoints(db, 42, 7);

    // floor(100 * 2.5) = 250.
    const historyInsert = queries.find((q) => /insert into `points_history`/i.test(q.sql));
    expect(historyInsert!.params).toContain(250);
  });

  it('does nothing when the computed earnedPoints is not > 0', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM transactions o JOIN users u')) return [{ ...ORDER_ROW, grand_total: '0.00' }];
      if (sqlText.includes('FROM points_settings')) return [];
      return [];
    });

    await awardOrderLoyaltyPoints(db, 42, 7);

    expect(queries.some((q) => /insert into `points_history`/i.test(q.sql))).toBe(false);
    expect(queries.some((q) => /insert into `points_transactions`/i.test(q.sql))).toBe(false);
    expect(queries.some((q) => /update `users`/i.test(q.sql))).toBe(false);
  });

  it('does nothing when the order has no user_id', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM transactions o JOIN users u')) return [{ ...ORDER_ROW, user_id: null }];
      return [];
    });

    await awardOrderLoyaltyPoints(db, 42, 7);
    expect(queries.filter((q) => /insert into|update /i.test(q.sql))).toHaveLength(0);
  });

  it('swallows a points_history insert failure (logs) but still attempts points_transactions', async () => {
    const errSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM transactions o JOIN users u')) return [ORDER_ROW];
      if (sqlText.includes('FROM points_settings')) return [];
      if (/insert into `points_history`/i.test(sqlText)) {
        throw new Error('boom');
      }
      return [];
    });

    await expect(awardOrderLoyaltyPoints(db, 42, 7)).resolves.toBeUndefined();
    expect(errSpy).toHaveBeenCalledWith('points_history insert error:', expect.any(Error));
    expect(queries.some((q) => /insert into `points_transactions`/i.test(q.sql))).toBe(true);

    errSpy.mockRestore();
  });

  it('swallows a points_transactions insert failure silently (no console.error)', async () => {
    const errSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM transactions o JOIN users u')) return [ORDER_ROW];
      if (sqlText.includes('FROM points_settings')) return [];
      if (/insert into `points_transactions`/i.test(sqlText)) {
        throw new Error('boom');
      }
      return [];
    });

    await expect(awardOrderLoyaltyPoints(db, 42, 7)).resolves.toBeUndefined();
    expect(errSpy).not.toHaveBeenCalled();

    errSpy.mockRestore();
  });
});
