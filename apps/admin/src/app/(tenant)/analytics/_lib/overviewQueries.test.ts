import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { getOverviewData } from './overviewQueries';

describe('getOverviewData', () => {
  it('runs the followers/messages/broadcasts/sales/active-users queries scoped to (line_account_id = ? OR line_account_id IS NULL)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE (line_account_id')) return [{ total: 10 }];
      if (sqlText.includes('FROM broadcasts')) return [{ total: 2, recipients: 40 }];
      if (sqlText.includes('FROM transactions')) return [{ total_orders: 5, revenue: 500 }];
      return [];
    });
    const data = await getOverviewData(db, 3, '2026-01-01', '2026-01-31');

    expect(data.stats.followers).toBe(10);
    expect(data.stats.broadcasts).toBe(2);
    expect(data.stats.broadcastRecipients).toBe(40);
    expect(data.stats.orders).toBe(5);
    expect(data.stats.revenue).toBe(500);

    // Every query in this file binds (start, end, lineAccountId) or (lineAccountId) with the shared-bot OR-NULL fallback.
    expect(queries.some((q) => q.sql.includes('line_account_id IS NULL'))).toBe(true);
    expect(queries.every((q) => q.params.includes(3))).toBe(true);
  });

  it('defaults sales stats to 0/0 when the transactions query throws (table might not exist)', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM transactions')) throw new Error('no such table');
      return [];
    });
    const data = await getOverviewData(db, null, '2026-01-01', '2026-01-31');
    expect(data.stats.orders).toBe(0);
    expect(data.stats.revenue).toBe(0);
  });

  it('defaults topTags/segmentsCount/revenueByDay/topKeywords to empty on error, matching the PHP try/catch fallbacks', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM user_tags')) throw new Error('boom');
      if (sqlText.includes('FROM customer_segments')) throw new Error('boom');
      if (sqlText.includes('FROM auto_replies')) throw new Error('boom');
      return [];
    });
    const data = await getOverviewData(db, null, '2026-01-01', '2026-01-31');
    expect(data.topTags).toEqual([]);
    expect(data.segmentsCount).toBe(0);
    expect(data.topKeywords).toEqual([]);
  });

  it('returns the daily series rows verbatim', async () => {
    const messagesByDay = [{ date: '2026-01-01', incoming: 3, outgoing: 1 }];
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('SUM(direction')) return messagesByDay;
      return [];
    });
    const data = await getOverviewData(db, null, '2026-01-01', '2026-01-31');
    expect(data.messagesByDay).toEqual(messagesByDay);
  });
});
