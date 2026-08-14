import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import {
  getBroadcastGroups,
  getBroadcastHistory,
  getBroadcastTags,
  getBroadcastTemplates,
  getSegments,
  getTotalUsers,
  resolveCurrentBotId,
} from './send-queries';

describe('resolveCurrentBotId — broadcast.php lines 33-42', () => {
  it('returns the session value directly when set, issuing zero queries', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await resolveCurrentBotId(db, 7);
    expect(result).toBe(7);
    expect(queries).toHaveLength(0);
  });

  it('falls back to is_default DESC, id ASC LIMIT 1 among active accounts when session value is null', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 3 }]);
    const result = await resolveCurrentBotId(db, null);
    expect(result).toBe(3);
    expect(queries[0]?.sql).toContain('is_active = 1');
    expect(queries[0]?.sql).toContain('ORDER BY is_default DESC, id ASC LIMIT 1');
  });

  it('falls back the same way when session value is 0 (falsy, matches PHP `?? null` / `!$currentBotId`)', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 5 }]);
    const result = await resolveCurrentBotId(db, 0);
    expect(result).toBe(5);
    expect(queries).toHaveLength(1);
  });

  it('returns null when there are no active line_accounts rows at all', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await resolveCurrentBotId(db, null);
    expect(result).toBeNull();
  });
});

describe('getBroadcastGroups — send.php:132', () => {
  it('is NOT scoped by currentBotId (byte-for-byte port of the unscoped PHP query)', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, name: 'VIP', member_count: 12 }]);
    const result = await getBroadcastGroups(db);
    expect(result).toEqual([{ id: 1, name: 'VIP', memberCount: 12 }]);
    expect(queries[0]?.sql).toContain('FROM groups g LEFT JOIN user_groups ug');
    expect(queries[0]?.sql).not.toContain('line_account_id');
  });
});

describe('getSegments — classes/AdvancedCRM.php::getSegments()', () => {
  it('scopes by line_account_id = ? OR line_account_id IS NULL, orders by user_count DESC', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, name: 'High value', user_count: 40 }]);
    const result = await getSegments(db, 9);
    expect(result).toEqual([{ id: 1, name: 'High value', userCount: 40 }]);
    expect(queries[0]?.sql).toContain('customer_segments');
    expect(queries[0]?.sql).toContain('ORDER BY user_count DESC');
    expect(queries[0]?.params).toEqual([9]);
  });
});

describe('getBroadcastTags — send.php:138-140', () => {
  it('joins user_tag_assignments, scopes by line_account_id, orders by user_count DESC', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 5, name: 'สนใจโปร', user_count: 3 }]);
    const result = await getBroadcastTags(db, 9);
    expect(result).toEqual([{ id: 5, name: 'สนใจโปร', userCount: 3 }]);
    expect(queries[0]?.sql).toContain('user_tag_assignments');
    expect(queries[0]?.sql).toContain('ORDER BY user_count DESC');
  });
});

describe('getBroadcastTemplates — send.php:155-176', () => {
  it('merges templates (unscoped) with flex_templates (scoped, message_type forced to "flex")', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM templates')) {
        return [{ id: 1, name: 'Welcome', category: 'FAQ', message_type: 'text', content: 'hi' }];
      }
      if (sqlText.includes('FROM flex_templates')) {
        return [{ id: 2, name: 'Promo bubble', category: null, content: '{"type":"bubble"}' }];
      }
      return [];
    });
    const result = await getBroadcastTemplates(db, 9);
    expect(result).toEqual([
      { id: 1, name: 'Welcome', category: 'FAQ', messageType: 'text', content: 'hi' },
      { id: 2, name: 'Promo bubble', category: 'Flex Builder', messageType: 'flex', content: '{"type":"bubble"}' },
    ]);
  });

  it('returns an empty list when both tables are empty', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getBroadcastTemplates(db, null);
    expect(result).toEqual([]);
  });
});

describe('getTotalUsers — send.php:194-196', () => {
  it('counts non-blocked users scoped by line_account_id OR NULL', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ c: 1234 }]);
    const result = await getTotalUsers(db, 9);
    expect(result).toBe(1234);
    expect(queries[0]?.sql).toContain('is_blocked = 0');
  });

  it('returns 0 when the count row is missing', async () => {
    const { db } = makeFakeTenantDb(() => []);
    expect(await getTotalUsers(db, 9)).toBe(0);
  });
});

describe('getBroadcastHistory — send.php:178-190 (LIMIT historyLimit+1 hasMore trick)', () => {
  it('fetches LIMIT 11 and reports hasMore=true, slicing down to 10 items, when an 11th row comes back', async () => {
    const rows = Array.from({ length: 11 }, (_, i) => ({
      id: i + 1,
      title: `Broadcast ${i + 1}`,
      message_type: 'text',
      status: 'sent',
      sent_count: 5,
      sent_at: new Date('2026-08-01T00:00:00Z'),
      scheduled_at: null,
    }));
    const { db, queries } = makeFakeTenantDb(() => rows);
    const result = await getBroadcastHistory(db, 9, 1);
    expect(result.items).toHaveLength(10);
    expect(result.hasMore).toBe(true);
    expect(result.page).toBe(1);
    expect(queries[0]?.sql).toContain('LIMIT ? OFFSET ?');
    expect(queries[0]?.params).toEqual([9, 11, 0]);
  });

  it('reports hasMore=false when exactly (or fewer than) 10 rows come back', async () => {
    const rows = Array.from({ length: 3 }, (_, i) => ({
      id: i + 1,
      title: `B${i + 1}`,
      message_type: 'text',
      status: 'scheduled',
      sent_count: 0,
      sent_at: null,
      scheduled_at: new Date('2026-09-01T00:00:00Z'),
    }));
    const { db } = makeFakeTenantDb(() => rows);
    const result = await getBroadcastHistory(db, 9, 1);
    expect(result.items).toHaveLength(3);
    expect(result.hasMore).toBe(false);
  });

  it('computes OFFSET from (page - 1) * 10, and clamps a non-positive/garbage page to 1', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getBroadcastHistory(db, 9, 3);
    expect(queries[0]?.params).toEqual([9, 11, 20]);

    const second = makeFakeTenantDb(() => []);
    const result = await getBroadcastHistory(second.db, 9, 0);
    expect(result.page).toBe(1);
    expect(second.queries[0]?.params).toEqual([9, 11, 0]);
  });
});
