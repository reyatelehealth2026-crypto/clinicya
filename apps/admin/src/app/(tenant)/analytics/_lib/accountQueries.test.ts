import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { getAllAccounts, getAccountById, getAccountTabData } from './accountQueries';

describe('getAllAccounts', () => {
  it('orders by is_default DESC, name ASC', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, name: 'Main', is_default: 1 }]);
    const result = await getAllAccounts(db);
    expect(result).toHaveLength(1);
    expect(queries[0]!.sql).toContain('ORDER BY is_default DESC, name ASC');
  });
});

describe('getAccountById', () => {
  it('returns null when no account matches', async () => {
    const { db } = makeFakeTenantDb(() => []);
    expect(await getAccountById(db, 999)).toBeNull();
  });
  it('returns the account row', async () => {
    const { db } = makeFakeTenantDb(() => [{ id: 1, name: 'Main', basic_id: '@main', picture_url: null }]);
    expect(await getAccountById(db, 1)).toEqual({ id: 1, name: 'Main', basic_id: '@main', picture_url: null });
  });
});

describe('getAccountTabData', () => {
  it('computes unfollowed = total - active, and returns followers/events/dailyStats', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM account_followers af')) return [{ id: 1, is_following: 1 }];
      if (sqlText.includes('COUNT(*) AS count FROM account_followers WHERE line_account_id = ${1} AND is_following')) return [];
      if (sqlText.includes('is_following = 1')) return [{ count: 8 }];
      if (sqlText.includes('COUNT(*) AS count FROM account_followers')) return [{ count: 10 }];
      if (sqlText.includes('FROM account_events')) return [{ id: 1, event_type: 'follow', line_user_id: 'U1', display_name: null, created_at: new Date() }];
      if (sqlText.includes('FROM account_daily_stats')) return [{ stat_date: '2026-01-01', new_followers: 2, unfollowers: 0, incoming_messages: 5, outgoing_messages: 5, total_messages: 10 }];
      return [];
    });

    const result = await getAccountTabData(db, 1, '2026-01-01', '2026-01-31');
    expect(result.followerStats).toEqual({ total: 10, active: 8, unfollowed: 2 });
    expect(result.followers).toHaveLength(1);
    expect(result.recentEvents).toHaveLength(1);
    expect(result.dailyStats).toHaveLength(1);
    expect(queries.every((q) => q.params.includes(1))).toBe(true);
  });
});
