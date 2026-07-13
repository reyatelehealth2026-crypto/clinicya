import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import { getLoyaltyMembersData, lmName } from './queries';

describe('lmName', () => {
  it('prefers real_name', () => {
    expect(lmName({ real_name: '  สมศรี  ', first_name: 'A', last_name: 'B', display_name: 'C' })).toBe('สมศรี');
  });
  it('falls back to first_name + last_name', () => {
    expect(lmName({ real_name: '', first_name: 'สมชาย', last_name: 'ใจดี', display_name: 'C' })).toBe('สมชาย ใจดี');
  });
  it('falls back to display_name', () => {
    expect(lmName({ real_name: '', first_name: '', last_name: '', display_name: 'Nickname' })).toBe('Nickname');
  });
  it('falls back to ลูกค้า when everything is empty', () => {
    expect(lmName({})).toBe('ลูกค้า');
  });
});

describe('getLoyaltyMembersData', () => {
  it('returns zeroed defaults without querying when lineAccountId <= 0, mirroring `if ($lineAccountId > 0)`', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await getLoyaltyMembersData(db, 0, '');
    expect(result).toEqual({ stats: { total: 0, points: 0, today: 0 }, members: [] });
    expect(queries).toHaveLength(0);
  });

  it('queries stats + members scoped to line_account_id and offline: phone members', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('COUNT(*) AS total')) return [{ total: 3, points: 150, today: 1 }];
      return [{ id: 1, display_name: 'A', real_name: null, first_name: null, last_name: null, phone: '0812345678', available_points: 50, total_points: 50, created_at: new Date() }];
    });
    const result = await getLoyaltyMembersData(db, 42, '');
    expect(result.stats).toEqual({ total: 3, points: 150, today: 1 });
    expect(result.members).toHaveLength(1);

    const statsQuery = queries.find((q) => q.sql.includes('COUNT(*) AS total'));
    expect(statsQuery?.params).toEqual([42]);
    const membersQuery = queries.find((q) => q.sql.includes('SELECT id, display_name'));
    expect(membersQuery?.sql).toContain("line_user_id LIKE 'offline:%'");
    expect(membersQuery?.sql).not.toContain('phone LIKE');
    expect(membersQuery?.params).toEqual([42]);
  });

  it('adds the phone/real_name/display_name LIKE clause only when search is non-empty', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*) AS total') ? [{ total: 0, points: 0, today: 0 }] : []));
    await getLoyaltyMembersData(db, 42, '081');
    const membersQuery = queries.find((q) => q.sql.includes('SELECT id, display_name'));
    expect(membersQuery?.sql).toContain('phone LIKE ? OR real_name LIKE ? OR display_name LIKE ?');
    expect(membersQuery?.params).toEqual([42, '%081%', '%081%', '%081%']);
  });

  it('returns empty defaults on a query error, matching the PHP catch block', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    const result = await getLoyaltyMembersData(db, 42, '');
    expect(result).toEqual({ stats: { total: 0, points: 0, today: 0 }, members: [] });
  });
});
