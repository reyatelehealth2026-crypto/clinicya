import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * crmQueries.ts — port of includes/analytics/crm.php + the two AdvancedCRM
 * methods it calls (classes/AdvancedCRM.php: getUserAnalytics($days),
 * getSegments()). `$days` is `(int)($_GET['days'] ?? 30)` — parsed by the
 * caller (page.tsx), not here.
 */

export interface CrmTagRow {
  name: string;
  color: string | null;
  count: number;
}

export interface CrmAnalytics {
  activeUsers: number;
  newUsers: number;
  topTags: CrmTagRow[];
}

/** Ported from AdvancedCRM::getUserAnalytics($days). */
export async function getUserAnalytics(db: Kysely<TenantDB>, lineAccountId: number | null, days: number): Promise<CrmAnalytics> {
  const activeResult = await sql<{ count: number }>`
    SELECT COUNT(DISTINCT user_id) AS count FROM user_behaviors
    WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
    AND created_at > DATE_SUB(NOW(), INTERVAL ${days} DAY)
  `.execute(db);
  const activeUsers = Number(activeResult.rows[0]?.count ?? 0);

  const newResult = await sql<{ count: number }>`
    SELECT COUNT(*) AS count FROM users
    WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
    AND created_at > DATE_SUB(NOW(), INTERVAL ${days} DAY)
  `.execute(db);
  const newUsers = Number(newResult.rows[0]?.count ?? 0);

  const topTagsResult = await sql<CrmTagRow>`
    SELECT t.name AS name, t.color AS color, COUNT(a.user_id) AS count
    FROM user_tags t
    LEFT JOIN user_tag_assignments a ON t.id = a.tag_id
    WHERE (t.line_account_id = ${lineAccountId} OR t.line_account_id IS NULL)
    GROUP BY t.id ORDER BY count DESC LIMIT 10
  `.execute(db);

  return { activeUsers, newUsers, topTags: topTagsResult.rows };
}

export interface CrmSegmentRow {
  id: number;
  name: string;
  segment_type: string | null;
  user_count: number;
}

/** Ported from AdvancedCRM::getSegments(). */
export async function getSegments(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<CrmSegmentRow[]> {
  const result = await sql<CrmSegmentRow>`
    SELECT id, name, segment_type, user_count FROM customer_segments
    WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
    ORDER BY user_count DESC
  `.execute(db);
  return result.rows;
}

/** Ported from includes/analytics/crm.php's own totalUsers query (lines 19-22). */
export async function getTotalUsers(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<number> {
  const result = await sql<{ count: number }>`
    SELECT COUNT(*) AS count FROM users WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL) AND is_blocked = 0
  `.execute(db);
  return Number(result.rows[0]?.count ?? 0);
}
