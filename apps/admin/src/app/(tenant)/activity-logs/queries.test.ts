import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import {
  ACTIVITY_LOGS_PER_PAGE,
  getActivityLogsPage,
  getLogs,
  countLogs,
  parseActivityLogsFilters,
  toLoggerFilters,
  type ActivityLogsPageFilters,
} from './queries';

function baseFilters(overrides: Partial<ActivityLogsPageFilters> = {}): ActivityLogsPageFilters {
  return { type: '', action: '', search: '', dateFrom: '', dateTo: '', page: 1, ...overrides };
}

describe('parseActivityLogsFilters', () => {
  it('defaults every filter to empty and page to 1', () => {
    expect(parseActivityLogsFilters({})).toEqual(baseFilters());
  });

  it('reads every $_GET-equivalent param', () => {
    const filters = parseActivityLogsFilters({
      type: 'auth',
      action: 'login',
      search: 'somsri',
      date_from: '2026-01-01',
      date_to: '2026-01-31',
      page: '3',
    });
    expect(filters).toEqual(baseFilters({ type: 'auth', action: 'login', search: 'somsri', dateFrom: '2026-01-01', dateTo: '2026-01-31', page: 3 }));
  });

  it('clamps page to a minimum of 1, matching PHP max(1, (int)...)', () => {
    expect(parseActivityLogsFilters({ page: '0' }).page).toBe(1);
    expect(parseActivityLogsFilters({ page: '-9' }).page).toBe(1);
    expect(parseActivityLogsFilters({ page: 'nope' }).page).toBe(1);
  });

  it('takes the first value when a param is an array', () => {
    expect(parseActivityLogsFilters({ type: ['auth', 'admin'] }).type).toBe('auth');
  });
});

describe('toLoggerFilters', () => {
  it('suffixes date_from with 00:00:00 and date_to with 23:59:59, mirroring activity-logs.php lines 25-26', () => {
    const result = toLoggerFilters(baseFilters({ dateFrom: '2026-01-01', dateTo: '2026-01-31' }));
    expect(result.dateFrom).toBe('2026-01-01 00:00:00');
    expect(result.dateTo).toBe('2026-01-31 23:59:59');
  });

  it('omits empty filters entirely rather than passing empty strings through', () => {
    expect(toLoggerFilters(baseFilters())).toEqual({});
  });
});

describe('getLogs', () => {
  it('applies every filter as a conditional AND clause and binds LIMIT/OFFSET', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getLogs(db, { type: 'auth', action: 'login', search: 'foo', dateFrom: '2026-01-01 00:00:00', dateTo: '2026-01-31 23:59:59' }, 50, 100);
    expect(queries).toHaveLength(1);
    const q = queries[0]!;
    expect(q.sql).toContain('log_type = ?');
    expect(q.sql).toContain('action = ?');
    expect(q.sql).toContain('description LIKE ? OR user_name LIKE ? OR admin_name LIKE ?');
    expect(q.sql).toContain('created_at >= ?');
    expect(q.sql).toContain('created_at <= ?');
    expect(q.sql).toContain('ORDER BY created_at DESC LIMIT ? OFFSET ?');
    expect(q.params).toEqual(['auth', 'login', '2026-01-01 00:00:00', '2026-01-31 23:59:59', '%foo%', '%foo%', '%foo%', 50, 100]);
  });

  it('with no filters, only binds LIMIT/OFFSET', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getLogs(db, {}, 50, 0);
    expect(queries[0]!.params).toEqual([50, 0]);
  });
});

describe('countLogs', () => {
  it('only honors type/lineAccountId/dateFrom/dateTo, NOT search/action — matches ActivityLogger::countLogs() asymmetry with getLogs()', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ count: 7 }]);
    const total = await countLogs(db, { type: 'auth', dateFrom: '2026-01-01 00:00:00', dateTo: '2026-01-31 23:59:59' });
    expect(total).toBe(7);
    expect(queries[0]!.sql).toContain('log_type = ?');
    expect(queries[0]!.sql).not.toContain('description LIKE');
  });
});

describe('getActivityLogsPage', () => {
  it('computes totalPages = ceil(total/perPage) and offset from page', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*)') ? [{ count: 125 }] : []));
    const result = await getActivityLogsPage(db, baseFilters({ page: 2 }));
    expect(result.totalLogs).toBe(125);
    expect(result.totalPages).toBe(3); // ceil(125/50)
    expect(result.perPage).toBe(ACTIVITY_LOGS_PER_PAGE);
    const rowQuery = queries.find((q) => !q.sql.includes('COUNT(*)'));
    expect(rowQuery?.params.slice(-2)).toEqual([50, 50]); // page 2 -> offset 50
  });

  it('returns the mapped log rows', async () => {
    const row = {
      id: 1,
      log_type: 'auth',
      action: 'login',
      description: 'เข้าสู่ระบบสำเร็จ',
      user_id: null,
      user_name: null,
      admin_id: 5,
      admin_name: 'admin1',
      entity_type: null,
      entity_id: null,
      ip_address: '1.2.3.4',
      line_account_id: 1,
      created_at: new Date('2026-01-01T10:00:00Z'),
    };
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('COUNT(*)') ? [{ count: 1 }] : [row]));
    const result = await getActivityLogsPage(db, baseFilters());
    expect(result.logs).toEqual([row]);
  });
});
