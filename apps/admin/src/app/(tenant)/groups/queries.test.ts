import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import { getGroupsList, getAllUsersForGroups, getGroupsPageData } from './queries';

describe('getGroupsList', () => {
  it('is NOT scoped by line_account_id and orders by g.name', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, name: 'A', description: null, color: '#fff', createdAt: new Date(), lineAccountId: null, memberCount: 2 }]);
    await getGroupsList(db);
    expect(queries[0]?.sql).toContain('FROM groups g');
    expect(queries[0]?.sql).toContain('LEFT JOIN user_groups ug');
    expect(queries[0]?.sql).toContain('ORDER BY g.name');
    expect(queries[0]?.sql).not.toContain('WHERE');
    expect(queries[0]?.params).toEqual([]);
  });
});

describe('getAllUsersForGroups', () => {
  it('binds currentBotId and filters is_blocked = 0', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getAllUsersForGroups(db, 7);
    expect(queries[0]?.sql).toContain('is_blocked = 0');
    expect(queries[0]?.sql).toContain('line_account_id = ? OR line_account_id IS NULL');
    expect(queries[0]?.params).toEqual([7]);
  });

  it('binds a NULL param when currentBotId is null, leaving the IS NULL branch to match', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getAllUsersForGroups(db, null);
    expect(queries[0]?.params).toEqual([null]);
  });
});

describe('getGroupsPageData', () => {
  it('skips the view/members queries entirely when viewId is null', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await getGroupsPageData(db, 7, null);
    expect(result.viewGroup).toBeNull();
    expect(result.members).toEqual([]);
    // Only the two unconditional queries (groups list + all users) should run.
    expect(queries).toHaveLength(2);
  });

  it('fetches viewGroup + members when viewId resolves to a real group', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM groups g')) return [];
      if (sqlText.includes('SELECT id, display_name AS displayName')) return [];
      if (sqlText.includes('FROM groups WHERE id')) return [{ id: 5, name: 'VIP', description: null, color: '#fff', createdAt: new Date(), lineAccountId: null }];
      if (sqlText.includes('JOIN user_groups ug')) return [{ id: 1, displayName: 'A', pictureUrl: null }];
      return [];
    });
    const result = await getGroupsPageData(db, 7, 5);
    expect(result.viewGroup?.id).toBe(5);
    expect(result.members).toHaveLength(1);
  });

  it('leaves members empty when viewId does not resolve to a group (mirrors PHP\'s `if ($viewGroup)` guard)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => (sqlText.includes('FROM groups WHERE id') ? [] : []));
    const result = await getGroupsPageData(db, 7, 999);
    expect(result.viewGroup).toBeNull();
    expect(result.members).toEqual([]);
    // groups list + all users + the group-by-id lookup, but NOT a members query.
    expect(queries).toHaveLength(3);
    expect(queries.some((q) => q.sql.includes('JOIN user_groups ug ON u.id = ug.user_id'))).toBe(false);
  });
});
