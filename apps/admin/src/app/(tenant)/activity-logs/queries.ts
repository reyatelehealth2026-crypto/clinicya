import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — literal port of classes/ActivityLogger.php's getLogs()/
 * countLogs() (lines 216-304), as called from activity-logs.php (lines
 * 14-35). OFFSET pagination (not cursor) — activity-logs.php computes
 * `$page`/`$perPage`/`$offset` itself and calls `$logger->getLogs($filters,
 * $perPage, $offset)`; there is no keyset/cursor pagination anywhere in this
 * page (that contract belongs to inbox-v2's conversation list, a different
 * page — see CLAUDE.md's "Conversation List Pagination" section, not
 * applicable here).
 *
 * Every filter is a plain equality/LIKE/range condition, added only when
 * present (`!empty($filters[...])`) — same "conditionally push a `sql`
 * fragment" shape as users/queries.ts's buildUsersWhereExpr, reproduced here
 * for the 5 filters activity-logs.php actually exposes (type, action,
 * search, date_from, date_to — user_id/admin_id/entity_type/entity_id/
 * line_account_id are ActivityLogger capabilities the PHP page itself never
 * passes, so they're intentionally not exposed as filter params here either,
 * though getLogs()/countLogs() below still accept them for parity with the
 * PHP method signatures).
 */

export const ACTIVITY_LOGS_PER_PAGE = 50;

export interface ActivityLogFilters {
  type?: string;
  action?: string;
  search?: string;
  /** Already suffixed with ' 00:00:00' by the caller (mirrors activity-logs.php lines 25-26). */
  dateFrom?: string;
  /** Already suffixed with ' 23:59:59'. */
  dateTo?: string;
  userId?: number;
  adminId?: number;
  entityType?: string;
  entityId?: number;
  lineAccountId?: number;
}

export interface ActivityLogRow {
  id: number;
  log_type: string;
  action: string;
  description: string | null;
  user_id: number | null;
  user_name: string | null;
  admin_id: number | null;
  admin_name: string | null;
  entity_type: string | null;
  entity_id: number | null;
  ip_address: string | null;
  line_account_id: number | null;
  created_at: Date;
}

const SELECT_COLUMNS = sql`id, log_type, action, description, user_id, user_name, admin_id, admin_name, entity_type, entity_id, ip_address, line_account_id, created_at`;

function buildWhere(filters: ActivityLogFilters) {
  const conditions = [sql`1=1`];
  if (filters.type) conditions.push(sql`log_type = ${filters.type}`);
  if (filters.action) conditions.push(sql`action = ${filters.action}`);
  if (filters.userId) conditions.push(sql`user_id = ${filters.userId}`);
  if (filters.adminId) conditions.push(sql`admin_id = ${filters.adminId}`);
  if (filters.entityType) conditions.push(sql`entity_type = ${filters.entityType}`);
  if (filters.entityId) conditions.push(sql`entity_id = ${filters.entityId}`);
  if (filters.lineAccountId) conditions.push(sql`line_account_id = ${filters.lineAccountId}`);
  if (filters.dateFrom) conditions.push(sql`created_at >= ${filters.dateFrom}`);
  if (filters.dateTo) conditions.push(sql`created_at <= ${filters.dateTo}`);
  if (filters.search) {
    const like = `%${filters.search}%`;
    conditions.push(sql`(description LIKE ${like} OR user_name LIKE ${like} OR admin_name LIKE ${like})`);
  }
  return sql.join(conditions, sql` AND `);
}

/** Ported from ActivityLogger::getLogs($filters, $limit, $offset). */
export async function getLogs(db: Kysely<TenantDB>, filters: ActivityLogFilters, limit: number, offset: number): Promise<ActivityLogRow[]> {
  const where = buildWhere(filters);
  const result = await sql<ActivityLogRow>`
    SELECT ${SELECT_COLUMNS} FROM activity_logs WHERE ${where}
    ORDER BY created_at DESC LIMIT ${limit} OFFSET ${offset}
  `.execute(db);
  return result.rows;
}

/**
 * Ported from ActivityLogger::countLogs($filters) — note the PHP method only
 * honors type/line_account_id/date_from/date_to (NOT search/action/user_id/
 * admin_id/entity_type/entity_id, even though getLogs() honors all of them);
 * this asymmetry between count and list filters is in the PHP source itself
 * and is replicated here rather than "fixed", per this batch's "replicate
 * literally" instruction.
 */
export async function countLogs(db: Kysely<TenantDB>, filters: Pick<ActivityLogFilters, 'type' | 'lineAccountId' | 'dateFrom' | 'dateTo'>): Promise<number> {
  const conditions = [sql`1=1`];
  if (filters.type) conditions.push(sql`log_type = ${filters.type}`);
  if (filters.lineAccountId) conditions.push(sql`line_account_id = ${filters.lineAccountId}`);
  if (filters.dateFrom) conditions.push(sql`created_at >= ${filters.dateFrom}`);
  if (filters.dateTo) conditions.push(sql`created_at <= ${filters.dateTo}`);
  const where = sql.join(conditions, sql` AND `);

  const result = await sql<{ count: number }>`SELECT COUNT(*) AS count FROM activity_logs WHERE ${where}`.execute(db);
  return Number(result.rows[0]?.count ?? 0);
}

export interface ActivityLogsPageFilters {
  type: string;
  action: string;
  search: string;
  dateFrom: string;
  dateTo: string;
  page: number;
}

export type RawSearchParams = Record<string, string | string[] | undefined>;

function first(searchParams: RawSearchParams, key: string): string {
  const value = searchParams[key];
  return (Array.isArray(value) ? value[0] : value) ?? '';
}

/** Ported from activity-logs.php lines 16-31: raw $_GET parsing + page/perPage/offset + the ' 00:00:00'/' 23:59:59' date-bound suffixing. */
export function parseActivityLogsFilters(searchParams: RawSearchParams): ActivityLogsPageFilters {
  const pageRaw = first(searchParams, 'page');
  const page = Math.max(1, Number.parseInt(pageRaw, 10) || 1);
  return {
    type: first(searchParams, 'type'),
    action: first(searchParams, 'action'),
    search: first(searchParams, 'search'),
    dateFrom: first(searchParams, 'date_from'),
    dateTo: first(searchParams, 'date_to'),
    page,
  };
}

export function toLoggerFilters(filters: ActivityLogsPageFilters): ActivityLogFilters {
  const result: ActivityLogFilters = {};
  if (filters.type) result.type = filters.type;
  if (filters.action) result.action = filters.action;
  if (filters.search) result.search = filters.search;
  if (filters.dateFrom) result.dateFrom = `${filters.dateFrom} 00:00:00`;
  if (filters.dateTo) result.dateTo = `${filters.dateTo} 23:59:59`;
  return result;
}

export interface ActivityLogsPageResult {
  logs: ActivityLogRow[];
  totalLogs: number;
  totalPages: number;
  page: number;
  perPage: number;
}

export async function getActivityLogsPage(db: Kysely<TenantDB>, filters: ActivityLogsPageFilters): Promise<ActivityLogsPageResult> {
  const perPage = ACTIVITY_LOGS_PER_PAGE;
  const offset = (filters.page - 1) * perPage;
  const loggerFilters = toLoggerFilters(filters);

  const totalLogs = await countLogs(db, loggerFilters);
  const totalPages = Math.ceil(totalLogs / perPage);
  const logs = await getLogs(db, loggerFilters, perPage, offset);

  return { logs, totalLogs, totalPages, page: filters.page, perPage };
}
