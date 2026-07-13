import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import { getLineGroupsList, getLineGroupsStats, getLineGroupsPageData, getLineGroupForLeave, markLineGroupLeft } from './queries';

describe('getLineGroupsList', () => {
  it('binds currentBotId with a WHERE clause when set', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getLineGroupsList(db, 7);
    expect(queries[0]?.sql).toContain('WHERE g.line_account_id');
    expect(queries[0]?.sql).toContain('ORDER BY g.is_active DESC, g.joined_at DESC');
    expect(queries[0]?.params).toEqual([7]);
  });

  it('runs the unscoped branch (no WHERE at all) when currentBotId is null, matching the PHP `else` `$db->query()`', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getLineGroupsList(db, null);
    expect(queries[0]?.sql).not.toContain('WHERE');
    expect(queries[0]?.params).toEqual([]);
  });

  it('returns [] on a query failure, matching the PHP catch block ("Table doesn\'t exist")', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('no such table');
    });
    const result = await getLineGroupsList(db, 7);
    expect(result).toEqual([]);
  });
});

describe('getLineGroupsStats', () => {
  it('computes all four stats scoped to currentBotId', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('COUNT(*)') && sqlText.includes('AND is_active = 1')) return [{ count: 3 }];
      if (sqlText.includes('COUNT(*)')) return [{ count: 5 }];
      if (sqlText.includes('SUM(member_count)')) return [{ total: 42 }];
      if (sqlText.includes('SUM(total_messages)')) return [{ total: 99 }];
      return [];
    });
    const stats = await getLineGroupsStats(db, 7);
    expect(stats).toEqual({ total: 5, active: 3, totalMembers: 42, totalMessages: 99 });
  });

  it('treats a NULL SUM() (no rows) as 0', async () => {
    const { db } = makeFakeTenantDb(() => [{ count: 0, total: null }]);
    const stats = await getLineGroupsStats(db, 7);
    expect(stats).toEqual({ total: 0, active: 0, totalMembers: 0, totalMessages: 0 });
  });

  it('returns zeroed stats on any query failure, matching the PHP catch block', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    const stats = await getLineGroupsStats(db, 7);
    expect(stats).toEqual({ total: 0, active: 0, totalMembers: 0, totalMessages: 0 });
  });

  it('runs unscoped (no WHERE) when currentBotId is null', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ count: 1, total: 1 }]);
    await getLineGroupsStats(db, null);
    const totalQuery = queries.find((q) => q.sql.includes('COUNT(*)') && !q.sql.includes('is_active'));
    expect(totalQuery?.sql).not.toContain('WHERE');
  });
});

describe('getLineGroupsPageData', () => {
  it('assembles groups + stats together', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getLineGroupsPageData(db, 7);
    expect(result).toEqual({ groups: [], stats: { total: 0, active: 0, totalMembers: 0, totalMessages: 0 } });
  });
});

describe('getLineGroupForLeave / markLineGroupLeft', () => {
  it('fetches the group row by id', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 3, lineAccountId: 7, groupId: 'C1', groupType: 'group', groupName: 'X' }]);
    const group = await getLineGroupForLeave(db, 3);
    expect(group?.id).toBe(3);
    expect(queries[0]?.params).toEqual([3]);
  });

  it('UPDATEs is_active=0, left_at=NOW() by id', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await markLineGroupLeft(db, 3);
    expect(queries[0]?.sql).toContain('is_active = 0');
    expect(queries[0]?.sql).toContain('left_at = NOW()');
    expect(queries[0]?.params).toEqual([3]);
  });
});
