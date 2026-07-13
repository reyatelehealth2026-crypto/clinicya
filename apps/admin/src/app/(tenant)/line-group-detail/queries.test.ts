import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import {
  getLineGroupDetail,
  getLineGroupMembersDetail,
  getLineGroupMessagesDetail,
  getLineGroupDetailPageData,
} from './queries';

describe('getLineGroupDetail', () => {
  it('is NOT scoped by line_account_id — any group id resolves regardless of the current bot', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { id: 5, groupId: 'C1', groupType: 'group', groupName: 'X', pictureUrl: null, memberCount: 1, totalMessages: 2, isActive: 1, joinedAt: new Date(), botName: 'Bot' },
    ]);
    const group = await getLineGroupDetail(db, 5);
    expect(group?.id).toBe(5);
    expect(queries[0]?.sql).not.toContain('line_account_id = ?');
    expect(queries[0]?.params).toEqual([5]);
  });

  it('returns null when the id does not resolve', async () => {
    const { db } = makeFakeTenantDb(() => []);
    expect(await getLineGroupDetail(db, 999)).toBeNull();
  });
});

describe('getLineGroupMembersDetail', () => {
  it('orders by is_active DESC, total_messages DESC', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getLineGroupMembersDetail(db, 5);
    expect(queries[0]?.sql).toContain('ORDER BY is_active DESC, total_messages DESC');
    expect(queries[0]?.params).toEqual([5]);
  });

  it('returns [] on a query failure, matching the PHP catch block', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('no such table');
    });
    expect(await getLineGroupMembersDetail(db, 5)).toEqual([]);
  });
});

describe('getLineGroupMessagesDetail', () => {
  it('joins line_group_members on (group_id, line_user_id), orders by created_at DESC, LIMIT 50', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getLineGroupMessagesDetail(db, 5);
    expect(queries[0]?.sql).toContain('LEFT JOIN line_group_members lgm ON gm.group_id = lgm.group_id AND gm.line_user_id = lgm.line_user_id');
    expect(queries[0]?.sql).toContain('ORDER BY gm.created_at DESC');
    expect(queries[0]?.sql).toContain('LIMIT 50');
  });

  it('returns [] on a query failure, matching the PHP catch block', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    expect(await getLineGroupMessagesDetail(db, 5)).toEqual([]);
  });
});

describe('getLineGroupDetailPageData', () => {
  it('returns null (and skips members/messages queries) when the group id does not resolve', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await getLineGroupDetailPageData(db, 999);
    expect(result).toBeNull();
    expect(queries).toHaveLength(1);
  });

  it('assembles group + members + messages for a resolved id', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM line_groups g')) {
        return [{ id: 5, groupId: 'C1', groupType: 'group', groupName: 'X', pictureUrl: null, memberCount: 1, totalMessages: 2, isActive: 1, joinedAt: new Date(), botName: 'Bot' }];
      }
      if (sqlText.includes('FROM line_group_members')) return [{ id: 1, displayName: 'A', pictureUrl: null, isActive: 1, totalMessages: 2, lastMessageAt: null }];
      if (sqlText.includes('FROM line_group_messages')) return [{ id: 1, displayName: 'A', createdAt: new Date(), messageType: 'text', content: 'hi' }];
      return [];
    });
    const result = await getLineGroupDetailPageData(db, 5);
    expect(result?.group.id).toBe(5);
    expect(result?.members).toHaveLength(1);
    expect(result?.messages).toHaveLength(1);
  });
});
