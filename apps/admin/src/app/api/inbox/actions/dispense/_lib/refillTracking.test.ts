/**
 * @jest-environment node
 */
import { makeFakeTenantDb } from './testHelpers/fakeTenantDb';
import { parsePackSize, trackFromDispense, type RefillTrackingContext } from './refillTracking';
import type { DispenseItem } from './types';

describe('parsePackSize', () => {
  it('returns 1 for an empty unit string', () => {
    expect(parsePackSize('')).toBe(1);
  });

  it('returns 1 when no bracketed number is present', () => {
    expect(parsePackSize('ชิ้น')).toBe(1);
    expect(parsePackSize('กล่อง')).toBe(1);
  });

  it('extracts the bracketed number for "[N]"/"[Nเม็ด]"/"[Nซีซี]"/"[Nแคปซูล]" shapes', () => {
    expect(parsePackSize('1 กล่อง[50เม็ด]')).toBe(50);
    expect(parsePackSize('[10ซีซี]')).toBe(10);
    expect(parsePackSize('แผง[30แคปซูล]')).toBe(30);
    expect(parsePackSize('[7]')).toBe(7);
  });

  it('floors at 1 even for a bracketed "[0]"', () => {
    expect(parsePackSize('[0]')).toBe(1);
  });
});

function itemFixture(overrides: Partial<DispenseItem> = {}): DispenseItem {
  return {
    isMedicine: true,
    product_id: 10,
    qty: 2,
    unit: 'กล่อง[10เม็ด]',
    dosage: 1,
    timeOfDay: ['morning', 'evening'],
    name: 'ยา A',
    ...overrides,
  };
}

const CTX: RefillTrackingContext = {
  user_id: 42,
  line_user_id: 'Uabc123',
  line_account_id: 3,
  dispense_id: 555,
};

describe('trackFromDispense', () => {
  it('does nothing when user_id <= 0', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await trackFromDispense(db, [itemFixture()], { ...CTX, user_id: 0 });
    expect(queries).toHaveLength(0);
  });

  it('skips non-medicine items (isMedicine falsy)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await trackFromDispense(db, [itemFixture({ isMedicine: false })], CTX);
    expect(queries).toHaveLength(0);
  });

  it('skips items with product_id <= 0 or qty <= 0', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await trackFromDispense(db, [itemFixture({ product_id: 0 }), itemFixture({ qty: 0 })], CTX);
    expect(queries).toHaveLength(0);
  });

  it('inserts a new tracking row when no active tracking exists for (user, product)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.toLowerCase().includes('select')) return [];
      return { insertId: 1, affectedRows: 1 };
    });

    // qty=2, unit pack size 10 -> totalDoses=20; dosage=1, timeOfDay length 2 -> dailyDosage=2 -> daysSupply=10.
    await trackFromDispense(db, [itemFixture()], CTX);

    const insertQuery = queries.find((q) => q.sql.toLowerCase().includes('insert into medication_refill_tracking'));
    expect(insertQuery).toBeDefined();
    // (user_id, line_user_id, line_account_id, product_id, product_name, quantity_purchased, daily_dosage, [CURDATE() literal], estimated_end_date, order_id, [source literal], source_ref_id)
    expect(insertQuery!.params[0]).toBe(42);
    expect(insertQuery!.params[1]).toBe('Uabc123');
    expect(insertQuery!.params[2]).toBe(3);
    expect(insertQuery!.params[3]).toBe(10);
    expect(insertQuery!.params[4]).toBe('ยา A');
    expect(insertQuery!.params[5]).toBe(20); // quantity_purchased = totalDoses
    expect(insertQuery!.params[6]).toBe(2); // daily_dosage
    expect(insertQuery!.params[8]).toBe(555); // order_id = dispense_id
    expect(insertQuery!.params[9]).toBe(555); // source_ref_id = dispense_id
    expect(insertQuery!.sql.toLowerCase()).toContain('curdate()');
    expect(insertQuery!.sql).toContain("'dispense'");
  });

  it('extends (UPDATEs) an existing active tracking row instead of inserting a duplicate', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      const lower = sqlText.toLowerCase();
      if (lower.includes('select id')) {
        return [{ id: 77, estimated_end_date_str: '2026-08-01' }];
      }
      return { insertId: 0, affectedRows: 1 };
    });

    await trackFromDispense(db, [itemFixture()], CTX);

    const updateQuery = queries.find((q) => q.sql.toLowerCase().includes('update medication_refill_tracking'));
    expect(updateQuery).toBeDefined();
    expect(queries.some((q) => q.sql.toLowerCase().includes('insert into medication_refill_tracking'))).toBe(false);
    // (quantity_purchased increment, daily_dosage, estimated_end_date, [reminder_sent_at = NULL literal], id)
    expect(updateQuery!.params).toEqual([20, 2, '2026-08-11', 77]); // 2026-08-01 + 10 days
  });

  it('one item failing (thrown DB error) does not stop the remaining items in the batch', async () => {
    let call = 0;
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      const lower = sqlText.toLowerCase();
      if (lower.includes('select id')) {
        call += 1;
        if (call === 1) throw new Error('db exploded on first item');
        return [];
      }
      return { insertId: 1, affectedRows: 1 };
    });

    const items = [itemFixture({ product_id: 1 }), itemFixture({ product_id: 2 })];
    await expect(trackFromDispense(db, items, CTX)).resolves.toBeUndefined();

    const insertQueries = queries.filter((q) => q.sql.toLowerCase().includes('insert into medication_refill_tracking'));
    // Only the second item's insert should have gone through.
    expect(insertQueries).toHaveLength(1);
  });
});
