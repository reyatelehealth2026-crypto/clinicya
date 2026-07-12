import { sql, type Expression, type Kysely, type SqlBool } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — query-building + row assembly for /users' LINE tab, ported
 * from users.php lines 29-284 (filter parsing, WHERE-clause construction,
 * row projection) and lines 274-284 (allTags dropdown source).
 *
 * The typed `TenantDB` schema (packages/db/src/generated/tenant-db.d.ts)
 * guarantees every column users.php only conditionally selected after a
 * runtime `SHOW COLUMNS`/`SHOW TABLES` probe (real_name/phone/email/
 * birthday/line_account_id, user_tags existing at all) — those probes
 * (users.php lines 182-225, 60-67, 210-217) are a legacy-schema-drift
 * compatibility shim with no equivalent need here, so this port always
 * selects the full column set users.php's "happy path" branch does. This is
 * an intentional simplification, not a behavior change on any tenant DB
 * created from the committed template.
 *
 * The WHERE clause (lines 79-168) and its per-row subqueries (message_count,
 * last_message_at, tags GROUP_CONCAT, and every filter branch) are ported as
 * literal `sql` fragments rather than the typed query-builder DSL — several
 * of them reference columns/enum literals the typed schema does not (or
 * cannot) model exactly (see two flagged quirks below), and the brief for
 * this batch is explicit: replicate literally, do not silently "fix" them.
 *
 *   - Tier filter queries `loyalty_points.tier`, a column TierService.php
 *     (via LoyaltyPoints::updateUserTier) never writes — the live tier-write
 *     path updates `users.total_points`. This filter is therefore reading
 *     effectively stale/disconnected data in production. Replicated as-is.
 *   - Purchase filter queries the `orders` table (NOT `transactions`, the
 *     table user-detail.php's order history uses) with `status = 'completed'`
 *     for the two amount-threshold branches — 'completed' is not even a
 *     legal value of `orders.status`'s enum in the generated schema
 *     ("cancelled"|"confirmed"|"delivered"|"paid"|"pending"|"shipping"),
 *     meaning those two branches can never match a row on a tenant DB
 *     created from the committed template. Replicated as-is (via the `sql`
 *     escape hatch, since Kysely's typed `.where('status','=',...)` would
 *     reject the literal outright).
 */

export const USERS_PER_PAGE = 20;

export type PointsFilter = '' | '0-100' | '100-500' | '500-1000' | '1000+';
export type ActivityFilter = '' | 'today' | '7days' | '30days' | 'inactive';
export type PurchaseFilter = '' | 'purchased' | 'never' | '1000+' | '5000+';
export type StatusFilter = '' | 'active' | 'blocked';

export interface UsersListFilters {
  search: string;
  /** 0 means "no tag filter" — mirrors PHP's `(int) $_GET['tag']` + `if ($tagFilter && ...)` falsy-0 short-circuit. */
  tag: number;
  tier: string;
  points: PointsFilter;
  activity: ActivityFilter;
  purchase: PurchaseFilter;
  status: StatusFilter;
  page: number;
}

export type RawSearchParams = Record<string, string | string[] | undefined>;

function first(searchParams: RawSearchParams, key: string): string | undefined {
  const value = searchParams[key];
  return Array.isArray(value) ? value[0] : value;
}

/** Mirrors users.php lines 30-56's `$_GET` parsing exactly, including PHP's `(int)`-cast-then-truthy-check semantics for `tag`/`page`. */
export function parseUsersListFilters(searchParams: RawSearchParams): UsersListFilters {
  const tagRaw = first(searchParams, 'tag');
  const pageRaw = first(searchParams, 'page');

  return {
    search: (first(searchParams, 'search') ?? '').trim(),
    tag: tagRaw !== undefined ? Number.parseInt(tagRaw, 10) || 0 : 0,
    tier: (first(searchParams, 'tier') ?? '').trim(),
    points: (first(searchParams, 'points') ?? '').trim() as PointsFilter,
    activity: (first(searchParams, 'activity') ?? '').trim() as ActivityFilter,
    purchase: (first(searchParams, 'purchase') ?? '').trim() as PurchaseFilter,
    status: (first(searchParams, 'status') ?? '').trim() as StatusFilter,
    page: Math.max(1, (pageRaw !== undefined ? Number.parseInt(pageRaw, 10) : 1) || 1),
  };
}

/** Ported literally from users.php lines 79-168 — see this file's module doc for the two flagged quirks. */
export function buildUsersWhereExpr(filters: UsersListFilters): Expression<SqlBool> {
  const conditions: Expression<SqlBool>[] = [sql<SqlBool>`1=1`];

  if (filters.tag) {
    conditions.push(
      sql<SqlBool>`EXISTS (SELECT 1 FROM user_tag_assignments uta WHERE uta.user_id = u.id AND uta.tag_id = ${filters.tag})`
    );
  }

  if (filters.search) {
    const like = `%${filters.search}%`;
    conditions.push(
      sql<SqlBool>`(u.display_name LIKE ${like} OR u.line_user_id LIKE ${like} OR u.real_name LIKE ${like} OR u.phone LIKE ${like})`
    );
  }

  if (filters.tier) {
    conditions.push(sql<SqlBool>`u.id IN (SELECT user_id FROM loyalty_points WHERE tier = ${filters.tier})`);
  }

  switch (filters.points) {
    case '0-100':
      conditions.push(sql<SqlBool>`COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) BETWEEN 0 AND 100`);
      break;
    case '100-500':
      conditions.push(sql<SqlBool>`COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) BETWEEN 100 AND 500`);
      break;
    case '500-1000':
      conditions.push(sql<SqlBool>`COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) BETWEEN 500 AND 1000`);
      break;
    case '1000+':
      conditions.push(sql<SqlBool>`COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) > 1000`);
      break;
    default:
      break;
  }

  switch (filters.activity) {
    case 'today':
      conditions.push(sql<SqlBool>`DATE(u.updated_at) = CURDATE()`);
      break;
    case '7days':
      conditions.push(sql<SqlBool>`u.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)`);
      break;
    case '30days':
      conditions.push(sql<SqlBool>`u.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)`);
      break;
    case 'inactive':
      conditions.push(sql<SqlBool>`u.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)`);
      break;
    default:
      break;
  }

  switch (filters.purchase) {
    case 'purchased':
      conditions.push(sql<SqlBool>`EXISTS (SELECT 1 FROM orders WHERE user_id = u.id AND status != 'cancelled')`);
      break;
    case 'never':
      conditions.push(sql<SqlBool>`NOT EXISTS (SELECT 1 FROM orders WHERE user_id = u.id)`);
      break;
    case '1000+':
      conditions.push(sql<SqlBool>`(SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') >= 1000`);
      break;
    case '5000+':
      conditions.push(sql<SqlBool>`(SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') >= 5000`);
      break;
    default:
      break;
  }

  switch (filters.status) {
    case 'active':
      conditions.push(sql<SqlBool>`u.is_blocked = 0`);
      break;
    case 'blocked':
      conditions.push(sql<SqlBool>`u.is_blocked = 1`);
      break;
    default:
      break;
  }

  return sql<SqlBool>`(${sql.join(conditions, sql` AND `)})`;
}

export interface UsersListRow {
  id: number;
  lineUserId: string;
  displayName: string | null;
  pictureUrl: string | null;
  statusMessage: string | null;
  isBlocked: number | null;
  createdAt: Date;
  updatedAt: Date;
  lineAccountId: number | null;
  realName: string | null;
  phone: string | null;
  email: string | null;
  birthday: Date | null;
  tags: string | null;
  messageCount: number;
  lastMessageAt: Date | null;
}

export interface UsersListResult {
  users: UsersListRow[];
  totalUsers: number;
  /** `Math.ceil(totalUsers / perPage)` — 0 when totalUsers is 0, matching PHP's `ceil()` (no `max(1, …)` clamp in users.php). */
  totalPages: number;
  page: number;
  perPage: number;
  offset: number;
}

/**
 * Ported from users.php lines 170-272 (count query + row projection query),
 * "happy path" branch — see module doc.
 *
 * Both queries are written as a single raw `sql` fragment rather than
 * Kysely's typed `.selectFrom()/.select()/.orderBy()` builder, for the same
 * reason every real-table query in @reya/auth (session.ts, rbac.ts,
 * sessionStore.ts, impersonation.ts) does the same: the shared
 * `Kysely<TenantDB>` instance @reya/db's `getTenantDb()` returns is built
 * WITHOUT a `CamelCasePlugin` (see packages/db/src/tenantPoolRegistry.ts —
 * no `plugins:` option passed to `new Kysely(...)`), even though the
 * generated `TenantDB` types were produced with kysely-codegen's
 * `--camel-case` flag (packages/db/src/codegen.ts). Per kysely-codegen's own
 * docs, `--camel-case` REQUIRES the consuming `Kysely` instance to register
 * `CamelCasePlugin` for the camelCase property names to map back onto the
 * real snake_case columns — without it, `.select('u.lineUserId')` would
 * compile to the literal (nonexistent) SQL identifier `lineUserId`, not
 * `line_user_id`. Rather than fork a one-off `db.withPlugin(new
 * CamelCasePlugin())` convention not used anywhere else in this codebase,
 * this follows the established house style: literal snake_case SQL via the
 * `sql` escape hatch, with `AS camelAlias` on every column so the returned
 * rows still come back shaped as `UsersListRow` (camelCase) for callers.
 */
export async function getUsersListPage(db: Kysely<TenantDB>, filters: UsersListFilters): Promise<UsersListResult> {
  const perPage = USERS_PER_PAGE;
  const offset = (filters.page - 1) * perPage;
  const whereExpr = buildUsersWhereExpr(filters);

  const countResult = await sql<{ count: number }>`
    SELECT COUNT(*) AS count FROM users u WHERE ${whereExpr}
  `.execute(db);
  const totalUsers = Number(countResult.rows[0]?.count ?? 0);
  const totalPages = Math.ceil(totalUsers / perPage);

  const rowsResult = await sql<UsersListRow>`
    SELECT
      u.id AS id,
      u.line_user_id AS lineUserId,
      u.display_name AS displayName,
      u.picture_url AS pictureUrl,
      u.status_message AS statusMessage,
      u.is_blocked AS isBlocked,
      u.created_at AS createdAt,
      u.updated_at AS updatedAt,
      u.line_account_id AS lineAccountId,
      u.real_name AS realName,
      u.phone AS phone,
      u.email AS email,
      u.birthday AS birthday,
      (SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM user_tags t JOIN user_tag_assignments uta ON t.id = uta.tag_id WHERE uta.user_id = u.id) AS tags,
      (SELECT COUNT(*) FROM messages m WHERE m.user_id = u.id) AS messageCount,
      (SELECT MAX(created_at) FROM messages m WHERE m.user_id = u.id) AS lastMessageAt
    FROM users u
    WHERE ${whereExpr}
    ORDER BY u.created_at DESC
    LIMIT ${perPage} OFFSET ${offset}
  `.execute(db);

  return {
    users: rowsResult.rows,
    totalUsers,
    totalPages,
    page: filters.page,
    perPage,
    offset,
  };
}

export interface UserTagOption {
  id: number;
  name: string;
  color: string | null;
}

/**
 * Ported from users.php lines 274-284: `SELECT * FROM user_tags WHERE
 * line_account_id = ? OR line_account_id IS NULL ORDER BY name`, bound with
 * `$currentBotId` (== session.currentBotId here, mirrors $_SESSION
 * ['current_bot_id']). Deliberately kept as a raw `sql` fragment: when
 * currentBotId is null, PHP binds NULL to the `=` comparison (always false
 * under normal SQL semantics), leaving `OR line_account_id IS NULL` to do
 * all the work — the typed builder's `eb('lineAccountId', '=', null)` would
 * either reject `null` at the type level or require an `is`/`is not`
 * rewrite that changes this fallback's semantics; the raw fragment
 * reproduces the exact bound-NULL-parameter behavior instead.
 */
export async function getAllTags(db: Kysely<TenantDB>, currentBotId: number | null): Promise<UserTagOption[]> {
  const result = await sql<UserTagOption>`
    SELECT id, name, color
    FROM user_tags
    WHERE line_account_id = ${currentBotId} OR line_account_id IS NULL
    ORDER BY name
  `.execute(db);
  return result.rows;
}
