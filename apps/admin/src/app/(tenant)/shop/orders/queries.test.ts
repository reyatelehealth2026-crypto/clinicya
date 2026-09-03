import { makeFakeTenantDb } from './testHelpers/fakeTenantDb';
import {
  parseOrdersListFilters,
  getOrdersListPage,
  getStatusCounts,
  getPendingSlipOrderIds,
  getDispenseCount,
  getDispenseRecords,
  getShopOrderDataSource,
  ORDERS_PER_PAGE,
  type OrdersListFilters,
} from './queries';

function baseFilters(overrides: Partial<OrdersListFilters> = {}): OrdersListFilters {
  return {
    status: '',
    type: '',
    pendingSlip: false,
    page: 1,
    viewDispense: false,
    ...overrides,
  };
}

describe('parseOrdersListFilters', () => {
  it('defaults every filter to empty/false and page to 1 when no params are given', () => {
    expect(parseOrdersListFilters({})).toEqual(baseFilters());
  });

  it('reads status/type verbatim', () => {
    expect(parseOrdersListFilters({ status: 'pending', type: 'booking' })).toEqual(
      baseFilters({ status: 'pending', type: 'booking' })
    );
  });

  it('pending_slip is true only for the exact string "1"', () => {
    expect(parseOrdersListFilters({ pending_slip: '1' }).pendingSlip).toBe(true);
    expect(parseOrdersListFilters({ pending_slip: '0' }).pendingSlip).toBe(false);
    expect(parseOrdersListFilters({ pending_slip: 'true' }).pendingSlip).toBe(false);
    expect(parseOrdersListFilters({}).pendingSlip).toBe(false);
  });

  it('view=dispense (strict match) sets viewDispense; any other value does not', () => {
    expect(parseOrdersListFilters({ view: 'dispense' }).viewDispense).toBe(true);
    expect(parseOrdersListFilters({ view: 'Dispense' }).viewDispense).toBe(false);
    expect(parseOrdersListFilters({ view: 'odoo' }).viewDispense).toBe(false);
  });

  it('clamps page to a minimum of 1', () => {
    expect(parseOrdersListFilters({ page: '0' }).page).toBe(1);
    expect(parseOrdersListFilters({ page: '-3' }).page).toBe(1);
    expect(parseOrdersListFilters({ page: 'nonsense' }).page).toBe(1);
    expect(parseOrdersListFilters({ page: '5' }).page).toBe(5);
  });

  it('takes the first value when a param is an array (repeated query key)', () => {
    expect(parseOrdersListFilters({ status: ['a', 'b'] }).status).toBe('a');
  });
});

describe('getOrdersListPage', () => {
  async function rowSqlAndParams(filters: Partial<OrdersListFilters>, botIdForQuery: number | null) {
    const { db, queries } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*) AS count') ? [{ count: 0 }] : []));
    await getOrdersListPage(db, baseFilters(filters), botIdForQuery);
    const rowQuery = queries.find((q) => !q.sql.includes('COUNT(*) AS count'))!;
    return rowQuery;
  }

  it('runs a count query and a row query, returning max(1, ceil(total/perPage)) pages', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*) AS count') ? [{ count: 120 }] : []));
    const result = await getOrdersListPage(db, baseFilters(), 1);
    expect(result.totalOrders).toBe(120);
    expect(result.totalPages).toBe(3); // ceil(120/50)
    expect(result.perPage).toBe(ORDERS_PER_PAGE);
    expect(queries).toHaveLength(2);
  });

  it('clamps totalPages to a minimum of 1 when there are no matching orders (UNLIKE users.php, which does not clamp)', async () => {
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*) AS count') ? [{ count: 0 }] : []));
    const result = await getOrdersListPage(db, baseFilters(), 1);
    expect(result.totalOrders).toBe(0);
    expect(result.totalPages).toBe(1);
  });

  it('computes OFFSET from page and applies LIMIT 50 OFFSET x to the row query', async () => {
    const q = await rowSqlAndParams({ page: 3 }, 1);
    expect(q.sql).toContain('LIMIT ? OFFSET ?');
    expect(q.params.slice(-2)).toEqual([ORDERS_PER_PAGE, (3 - 1) * ORDERS_PER_PAGE]);
  });

  it('scopes by (o.line_account_id = ? OR o.line_account_id IS NULL) when botIdForQuery is given', async () => {
    const q = await rowSqlAndParams({}, 7);
    expect(q.sql).toContain('(o.line_account_id = ? OR o.line_account_id IS NULL)');
    expect(q.params[0]).toBe(7);
  });

  it('falls back to an unscoped 1=1 WHERE when botIdForQuery is null', async () => {
    const q = await rowSqlAndParams({}, null);
    expect(q.sql).toContain('1=1');
    expect(q.sql).not.toContain('line_account_id');
  });

  it('status filter adds "o.status = ?"', async () => {
    const q = await rowSqlAndParams({ status: 'paid' }, 1);
    expect(q.sql).toContain('o.status = ?');
    expect(q.params).toContain('paid');
  });

  it('type filter adds "o.transaction_type = ?"', async () => {
    const q = await rowSqlAndParams({ type: 'booking' }, 1);
    expect(q.sql).toContain('o.transaction_type = ?');
    expect(q.params).toContain('booking');
  });

  it('pending_slip filter adds the payment_slips subquery, no bound param of its own', async () => {
    const q = await rowSqlAndParams({ pendingSlip: true }, 1);
    expect(q.sql).toContain("o.id IN (SELECT DISTINCT transaction_id FROM payment_slips WHERE status = 'pending')");
  });

  it('combines status + type + pending_slip + tenant guard together with AND', async () => {
    const q = await rowSqlAndParams({ status: 'pending', type: 'purchase', pendingSlip: true }, 9);
    expect(q.sql).toContain('(o.line_account_id = ? OR o.line_account_id IS NULL)');
    expect(q.sql).toContain('o.status = ?');
    expect(q.sql).toContain('o.transaction_type = ?');
    expect(q.sql).toContain('payment_slips');
    // WHERE-clause params, followed by LIMIT/OFFSET (page 1 -> offset 0).
    expect(q.params).toEqual([9, 'pending', 'purchase', ORDERS_PER_PAGE, 0]);
  });

  it('selects the exact camelCase-aliased column set the render path reads, including the item_count subquery', async () => {
    const row = {
      id: 1,
      orderNumber: 'ORD-1',
      transactionType: 'purchase',
      status: 'pending',
      deliveryInfo: null,
      createdAt: new Date('2026-01-01'),
      grandTotal: '199.00',
      shippingTracking: null,
      displayName: 'สมชาย',
      pictureUrl: null,
      itemCount: 2,
    };
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*) AS count') ? [{ count: 1 }] : [row]));
    const result = await getOrdersListPage(db, baseFilters(), 1);
    expect(result.orders).toEqual([row]);
  });
});

describe('getStatusCounts', () => {
  it('scopes by tenant guard when botIdForQuery is given and maps status -> count', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { status: 'pending', c: 3 },
      { status: 'paid', c: 5 },
    ]);
    const counts = await getStatusCounts(db, 4);
    expect(counts).toEqual({ pending: 3, paid: 5 });
    expect(queries[0]?.sql).toContain('(line_account_id = ? OR line_account_id IS NULL)');
    expect(queries[0]?.params).toEqual([4]);
  });

  it('queries unscoped when botIdForQuery is null', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ status: 'pending', c: 1 }]);
    await getStatusCounts(db, null);
    expect(queries[0]?.sql).not.toContain('line_account_id');
  });

  it('coerces a null status row to the empty-string key, matching PHP array-key coercion', async () => {
    const { db } = makeFakeTenantDb(() => [{ status: null, c: 2 }]);
    const counts = await getStatusCounts(db, 1);
    expect(counts['']).toBe(2);
  });

  it('returns {} (not throw) when the query fails', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    await expect(getStatusCounts(db, 1)).resolves.toEqual({});
  });
});

describe('getPendingSlipOrderIds', () => {
  it('returns the id column of every DISTINCT pending-slip order row', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { id: 10, order_number: 'ORD-10' },
      { id: 11, order_number: 'ORD-11' },
    ]);
    const ids = await getPendingSlipOrderIds(db, 1);
    expect(ids).toEqual([10, 11]);
    expect(queries[0]?.sql).toContain("ps.status = 'pending'");
    expect(queries[0]?.sql).toContain('(t.line_account_id = ? OR t.line_account_id IS NULL)');
  });

  it('returns [] (not throw) on a query error', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    await expect(getPendingSlipOrderIds(db, 1)).resolves.toEqual([]);
  });
});

describe('getDispenseCount', () => {
  it('scopes by line_account_id when given, else unscoped; defaults to 0 on error', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ c: 7 }]);
    expect(await getDispenseCount(db, 3)).toBe(7);
    expect(queries[0]?.sql).toContain('WHERE line_account_id = ?');

    const { db: db2 } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    expect(await getDispenseCount(db2, 3)).toBe(0);
  });
});

describe('getDispenseRecords', () => {
  it('joins users and orders by created_at DESC, scoped by line_account_id when given', async () => {
    const row = {
      id: 1,
      orderNumber: 'ORD-1',
      userId: 5,
      items: '[]',
      totalAmount: '10.00',
      paymentMethod: 'cash',
      paymentStatus: 'paid',
      createdAt: new Date('2026-01-01'),
      displayName: 'สมหญิง',
      pictureUrl: null,
    };
    const { db, queries } = makeFakeTenantDb(() => [row]);
    const records = await getDispenseRecords(db, 2);
    expect(records).toEqual([row]);
    expect(queries[0]?.sql).toContain('WHERE d.line_account_id = ?');
    expect(queries[0]?.sql).toContain('ORDER BY d.created_at DESC');
  });

  it('returns [] (not throw) on a query error', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    await expect(getDispenseRecords(db, 1)).resolves.toEqual([]);
  });
});

describe('getShopOrderDataSource', () => {
  it('returns the line_account_id-scoped value, normalized, when non-empty', async () => {
    const { db } = makeFakeTenantDb(() => [{ order_data_source: 'Odoo' }]);
    expect(await getShopOrderDataSource(db, 5)).toBe('odoo');
  });

  it('falls back to the global default row when the scoped row is empty/missing', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('line_account_id = ?')) return [{ order_data_source: null }];
      return [{ order_data_source: 'shop' }];
    });
    expect(await getShopOrderDataSource(db, 5)).toBe('shop');
    expect(queries).toHaveLength(2);
    expect(queries[1]?.sql).toContain('id = 1 OR line_account_id IS NULL');
  });

  it('skips the scoped lookup entirely when lineAccountId is falsy', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ order_data_source: 'shop' }]);
    await getShopOrderDataSource(db, null);
    expect(queries).toHaveLength(1);
    expect(queries[0]?.sql).toContain('id = 1 OR line_account_id IS NULL');
  });

  it('normalizes any non-"odoo" value (including null) to "shop"', async () => {
    const { db } = makeFakeTenantDb(() => [{ order_data_source: null }]);
    expect(await getShopOrderDataSource(db, null)).toBe('shop');
  });

  it('returns "shop" (not throw) when the query fails', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    await expect(getShopOrderDataSource(db, 1)).resolves.toBe('shop');
  });
});
