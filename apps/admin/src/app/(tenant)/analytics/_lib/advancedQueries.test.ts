import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { getDateRange, getPreviousDateRange, getUserStats, getCustomerFunnel, getRealTimeStats } from './advancedQueries';

const FIXED_NOW = new Date('2026-07-15T10:00:00Z');

describe('getDateRange', () => {
  it('maps every known period key, defaulting unknown values to 7d', () => {
    expect(getDateRange('7d', FIXED_NOW).start).toBe('2026-07-08 00:00:00');
    expect(getDateRange('30d', FIXED_NOW).start).toBe('2026-06-15 00:00:00');
    expect(getDateRange('90d', FIXED_NOW).start).toBe('2026-04-16 00:00:00');
    expect(getDateRange('year', FIXED_NOW).start).toBe('2026-01-01 00:00:00');
    expect(getDateRange('bogus', FIXED_NOW).start).toBe(getDateRange('7d', FIXED_NOW).start);
  });

  it('end is always 23:59:59 same-day (Bangkok)', () => {
    expect(getDateRange('7d', FIXED_NOW).end).toBe('2026-07-15 23:59:59');
  });
});

describe('getPreviousDateRange', () => {
  it('shifts the window back by the current range\'s own width, ending 1 second before the current start', () => {
    const current = { start: '2026-07-08 00:00:00', end: '2026-07-15 23:59:59' };
    const prev = getPreviousDateRange(current);
    expect(prev.end).toBe('2026-07-07 23:59:59');
    // Ported from AnalyticsModel::getPreviousDateRange() literally: `$diff = end - start;
    // ['start' => start - diff, 'end' => start - 1]` — the resulting previous window is
    // exactly 1 second SHORTER than the current one (end2 = start1 - 1s, start2 = start1 -
    // diff), an off-by-one-second quirk in the PHP source itself, replicated as-is rather
    // than "fixed" to a clean equal-width window.
    const widthMs = Date.parse(current.end.replace(' ', 'T') + 'Z') - Date.parse(current.start.replace(' ', 'T') + 'Z');
    const prevWidthMs = Date.parse(prev.end.replace(' ', 'T') + 'Z') - Date.parse(prev.start.replace(' ', 'T') + 'Z');
    expect(prevWidthMs).toBe(widthMs - 1000);
  });
});

describe('getUserStats — account filter semantics (no OR-IS-NULL fallback, unlike overviewQueries)', () => {
  const range = { start: '2026-01-01 00:00:00', end: '2026-01-31 23:59:59' };

  it('omits the account filter entirely when lineAccountId is null', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getUserStats(db, null, range);
    for (const q of queries) {
      expect(q.sql).not.toContain('line_account_id');
    }
  });

  it('adds a plain `AND line_account_id = ?` filter (no OR IS NULL) when lineAccountId is set', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getUserStats(db, 9, range);
    expect(queries.some((q) => q.sql.includes('AND line_account_id = ?') && q.params.includes(9))).toBe(true);
    expect(queries.every((q) => !q.sql.includes('OR line_account_id IS NULL'))).toBe(true);
  });

  it('computes growthRate = round(new/total*1000)/10, 0 when total is 0', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('SELECT COUNT(*) AS total FROM users WHERE 1=1')) return [{ total: 200 }];
      if (sqlText.includes("created_at BETWEEN") && sqlText.includes('FROM users')) return [{ total: 50 }];
      return [];
    });
    const stats = await getUserStats(db, null, range);
    expect(stats.total).toBe(200);
    expect(stats.new).toBe(50);
    expect(stats.growthRate).toBe(25);
  });
});

describe('getCustomerFunnel', () => {
  const range = { start: '2026-01-01 00:00:00', end: '2026-01-31 23:59:59' };

  it('the cart stage query has NO account filter at all, even when lineAccountId is set (matches AnalyticsModel::getCustomerFunnel())', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getCustomerFunnel(db, 9, range);
    const cartQuery = queries.find((q) => q.sql.includes('FROM cart_items'));
    expect(cartQuery?.sql).not.toContain('line_account_id');
    expect(cartQuery?.params).toEqual([range.start, range.end]);
  });

  it('the first stage is always 100% and stage counts/rates are computed off visitors', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE created_at')) return [{ count: 100 }];
      if (sqlText.includes('INNER JOIN messages')) return [{ count: 40 }];
      if (sqlText.includes('FROM cart_items')) return [{ count: 10 }];
      if (sqlText.includes('FROM orders')) return [{ count: 5 }];
      return [];
    });
    const stages = await getCustomerFunnel(db, null, range);
    expect(stages[0]).toEqual({ stage: 'ผู้ติดตามใหม่', count: 100, rate: 100 });
    expect(stages[1]).toEqual({ stage: 'มีการสนทนา', count: 40, rate: 40 });
    expect(stages[2]).toEqual({ stage: 'เพิ่มตะกร้า', count: 10, rate: 10 });
    expect(stages[3]).toEqual({ stage: 'สั่งซื้อ', count: 5, rate: 5 });
  });
});

describe('getRealTimeStats', () => {
  it('reads last-1-hour active/messages and today orders/revenue', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('DISTINCT user_id')) return [{ count: 3 }];
      if (sqlText.includes('COUNT(*) AS count FROM messages')) return [{ count: 12 }];
      if (sqlText.includes('FROM orders')) return [{ count: 2, total: 555.5 }];
      return [];
    });
    const stats = await getRealTimeStats(db, null);
    expect(stats.activeUsers).toBe(3);
    expect(stats.messagesPerHour).toBe(12);
    expect(stats.ordersToday).toBe(2);
    expect(stats.revenueToday).toBe(555.5);
  });
});
