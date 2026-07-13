import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { getUserAnalytics, getSegments, getTotalUsers } from './crmQueries';

describe('getUserAnalytics', () => {
  it('binds days as a plain bound parameter into the INTERVAL clause', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM user_behaviors')) return [{ count: 5 }];
      if (sqlText.includes('FROM users')) return [{ count: 2 }];
      return [];
    });
    const result = await getUserAnalytics(db, 1, 30);
    expect(result.activeUsers).toBe(5);
    expect(result.newUsers).toBe(2);
    expect(queries.every((q) => q.params.includes(30) || q.sql.includes('user_tags'))).toBe(true);
  });

  it('uses the OR line_account_id IS NULL shared-bot fallback, matching AdvancedCRM::getUserAnalytics()', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getUserAnalytics(db, null, 7);
    expect(queries.every((q) => q.sql.includes('line_account_id IS NULL'))).toBe(true);
  });
});

describe('getSegments', () => {
  it('orders by user_count DESC', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, name: 'VIP', segment_type: 'manual', user_count: 10 }]);
    const result = await getSegments(db, 1);
    expect(result).toHaveLength(1);
    expect(queries[0]!.sql).toContain('ORDER BY user_count DESC');
  });
});

describe('getTotalUsers', () => {
  it('counts non-blocked users scoped to the bot (or shared)', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ count: 42 }]);
    const total = await getTotalUsers(db, 1);
    expect(total).toBe(42);
    expect(queries[0]!.sql).toContain('is_blocked = 0');
  });
});
