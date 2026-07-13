import { makeFakeTenantDb } from './testHelpers/fakeTenantDb';
import { getAllTags, getUsersListPage, parseUsersListFilters, USERS_PER_PAGE, type UsersListFilters } from './queries';

function baseFilters(overrides: Partial<UsersListFilters> = {}): UsersListFilters {
  return {
    search: '',
    tag: 0,
    tier: '',
    points: '',
    activity: '',
    purchase: '',
    status: '',
    page: 1,
    ...overrides,
  };
}

describe('parseUsersListFilters', () => {
  it('defaults every filter to empty/zero and page to 1 when no params are given', () => {
    expect(parseUsersListFilters({})).toEqual(baseFilters());
  });

  it('trims search and reads every filter param', () => {
    const filters = parseUsersListFilters({
      search: '  somsri  ',
      tier: 'gold',
      points: '100-500',
      activity: '7days',
      purchase: 'purchased',
      status: 'blocked',
      tag: '3',
      page: '2',
    });
    expect(filters).toEqual(
      baseFilters({ search: 'somsri', tier: 'gold', points: '100-500', activity: '7days', purchase: 'purchased', status: 'blocked', tag: 3, page: 2 })
    );
  });

  it('treats a non-numeric tag as 0 (no filter), mirroring PHP (int) cast + falsy check', () => {
    expect(parseUsersListFilters({ tag: 'abc' }).tag).toBe(0);
  });

  it('clamps page to a minimum of 1', () => {
    expect(parseUsersListFilters({ page: '0' }).page).toBe(1);
    expect(parseUsersListFilters({ page: '-5' }).page).toBe(1);
    expect(parseUsersListFilters({ page: 'nonsense' }).page).toBe(1);
  });

  it('takes the first value when a param is an array (repeated query key)', () => {
    expect(parseUsersListFilters({ search: ['first', 'second'] }).search).toBe('first');
  });
});

describe('getUsersListPage', () => {
  it('runs a count query and a row query, returning ceil(total/perPage) pages', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('COUNT(*) AS count')) {
        return [{ count: 45 }];
      }
      return [];
    });

    const result = await getUsersListPage(db, baseFilters());

    expect(result.totalUsers).toBe(45);
    expect(result.totalPages).toBe(3); // ceil(45/20)
    expect(result.perPage).toBe(USERS_PER_PAGE);
    expect(result.offset).toBe(0);
    expect(queries).toHaveLength(2);
  });

  it('returns 0 total pages (not clamped to 1) when there are no matching users, matching PHP ceil() with no max(1,…)', async () => {
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*) AS count') ? [{ count: 0 }] : []));
    const result = await getUsersListPage(db, baseFilters());
    expect(result.totalUsers).toBe(0);
    expect(result.totalPages).toBe(0);
  });

  it('computes OFFSET from page and applies it to the row query bound params', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*) AS count') ? [{ count: 100 }] : []));
    await getUsersListPage(db, baseFilters({ page: 3 }));
    const rowQuery = queries.find((q) => !q.sql.includes('COUNT(*) AS count'));
    expect(rowQuery?.sql).toContain('LIMIT ? OFFSET ?');
    expect(rowQuery?.params.slice(-2)).toEqual([USERS_PER_PAGE, (3 - 1) * USERS_PER_PAGE]);
  });

  it('maps every selected column onto UsersListRow via SQL aliases', async () => {
    const row = {
      id: 1,
      lineUserId: 'U123',
      displayName: 'Somsri',
      pictureUrl: null,
      statusMessage: null,
      isBlocked: 0,
      createdAt: new Date('2026-01-01'),
      updatedAt: new Date('2026-01-01'),
      lineAccountId: 1,
      realName: null,
      phone: null,
      email: null,
      birthday: null,
      tags: 'VIP, ทดสอบ',
      messageCount: 5,
      lastMessageAt: new Date('2026-01-02'),
    };
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*) AS count') ? [{ count: 1 }] : [row]));
    const result = await getUsersListPage(db, baseFilters());
    expect(result.users).toEqual([row]);
  });

  describe('WHERE clause per filter branch', () => {
    async function whereSqlFor(filters: Partial<UsersListFilters>) {
      const { db, queries } = makeFakeTenantDb(() => []);
      await getUsersListPage(db, baseFilters(filters));
      return queries.map((q) => q.sql).join('\n');
    }
    async function paramsFor(filters: Partial<UsersListFilters>) {
      // getUsersListPage() runs two queries (COUNT then row projection) sharing the same
      // WHERE clause — inspect just the row query so param counts aren't doubled.
      const { db, queries } = makeFakeTenantDb(() => []);
      await getUsersListPage(db, baseFilters(filters));
      const rowQuery = queries.find((q) => !q.sql.includes('COUNT(*) AS count'));
      return rowQuery?.params ?? [];
    }

    it('search: LIKEs display_name/line_user_id/real_name/phone with %term%', async () => {
      const sqlText = await whereSqlFor({ search: 'somsri' });
      expect(sqlText).toContain('u.display_name LIKE ?');
      expect(sqlText).toContain('u.line_user_id LIKE ?');
      expect(sqlText).toContain('u.real_name LIKE ?');
      expect(sqlText).toContain('u.phone LIKE ?');
      const params = await paramsFor({ search: 'somsri' });
      expect(params.filter((p) => p === '%somsri%')).toHaveLength(4);
    });

    it('tag: EXISTS against user_tag_assignments', async () => {
      const sqlText = await whereSqlFor({ tag: 7 });
      expect(sqlText).toContain('EXISTS (SELECT 1 FROM user_tag_assignments uta WHERE uta.user_id = u.id AND uta.tag_id = ?)');
      const params = await paramsFor({ tag: 7 });
      expect(params).toContain(7);
    });

    it('tier: subquery against loyalty_points.tier (known quirk — not the live tier-write column)', async () => {
      const sqlText = await whereSqlFor({ tier: 'gold' });
      expect(sqlText).toContain('u.id IN (SELECT user_id FROM loyalty_points WHERE tier = ?)');
      const params = await paramsFor({ tier: 'gold' });
      expect(params).toContain('gold');
    });

    it.each([
      ['0-100', 'BETWEEN 0 AND 100'],
      ['100-500', 'BETWEEN 100 AND 500'],
      ['500-1000', 'BETWEEN 500 AND 1000'],
      ['1000+', '> 1000'],
    ] as const)('points=%s renders %s against the loyalty_points subquery', async (points, expected) => {
      const sqlText = await whereSqlFor({ points });
      expect(sqlText).toContain('COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0)');
      expect(sqlText).toContain(expected);
    });

    it.each([
      ['today', 'DATE(u.updated_at) = CURDATE()'],
      ['7days', 'u.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'],
      ['30days', 'u.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'],
      ['inactive', 'u.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'],
    ] as const)('activity=%s', async (activity, expected) => {
      const sqlText = await whereSqlFor({ activity });
      expect(sqlText).toContain(expected);
    });

    it.each([
      ['purchased', "EXISTS (SELECT 1 FROM orders WHERE user_id = u.id AND status != 'cancelled')"],
      ['never', 'NOT EXISTS (SELECT 1 FROM orders WHERE user_id = u.id)'],
      // PHP hardcodes the 1000/5000 threshold directly into the SQL string (no bound param) —
      // replicated literally here too, not parameterized.
      ['1000+', "(SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') >= 1000"],
      ['5000+', "(SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') >= 5000"],
    ] as const)('purchase=%s queries `orders` (not `transactions`), status literal \'completed\'', async (purchase, expected) => {
      const sqlText = await whereSqlFor({ purchase });
      expect(sqlText).toContain(expected);
    });

    it('status=active -> is_blocked = 0, status=blocked -> is_blocked = 1', async () => {
      expect(await whereSqlFor({ status: 'active' })).toContain('u.is_blocked = 0');
      expect(await whereSqlFor({ status: 'blocked' })).toContain('u.is_blocked = 1');
    });

    it('combines multiple active filters with AND', async () => {
      const sqlText = await whereSqlFor({ search: 'a', status: 'active', activity: 'today' });
      expect(sqlText).toContain('LIKE ?');
      expect(sqlText).toContain('u.is_blocked = 0');
      expect(sqlText).toContain('DATE(u.updated_at) = CURDATE()');
    });

    it('applies no extra condition beyond the 1=1 base when every filter is empty', async () => {
      const sqlText = await whereSqlFor({});
      expect(sqlText).toContain('1=1');
    });
  });
});

describe('getAllTags', () => {
  it('queries user_tags scoped to the current bot id OR NULL, ordered by name', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, name: 'VIP', color: '#ff0000' }]);
    const rows = await getAllTags(db, 5);
    expect(rows).toEqual([{ id: 1, name: 'VIP', color: '#ff0000' }]);
    expect(queries[0]?.sql).toContain('line_account_id = ? OR line_account_id IS NULL');
    expect(queries[0]?.sql).toContain('ORDER BY name');
    expect(queries[0]?.params).toEqual([5]);
  });

  it('binds NULL as the current bot id when there is no current bot (falls through to IS NULL)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getAllTags(db, null);
    expect(queries[0]?.params).toEqual([null]);
  });
});
