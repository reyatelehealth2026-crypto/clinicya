import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { toNumber } from './numeric';

/**
 * crmData.ts — DB-touching port of includes/dashboard/crm.php. Every query
 * is a literal transcription of the PHP source's raw SQL, run via Kysely's
 * `sql` tagged template (see executiveData.ts's file doc for why: matches
 * the @reya/auth house style and keeps the emitted SQL text diffable
 * against the PHP source 1:1).
 *
 * Scoping note (flagged in the build report): every query here is scoped by
 * `currentBotId` via crm.php's `(line_account_id = ? OR ? IS NULL)` pattern
 * — this is a FAITHFUL port of crm.php, which really does scope every query
 * this way. includes/dashboard/executive.php (executiveData.ts), by
 * contrast, has NO line_account_id/currentBotId filtering anywhere in its
 * queries — that asymmetry exists in the PHP source itself, not introduced
 * by this port. "Preserve behavior, not markup" cuts against fixing it here;
 * flagged to mig-orchestrator as worth a product decision, not silently
 * normalised in either direction.
 *
 * Deliberately NOT ported (per the brief): crm.php's page-load
 * auto-create-if-missing of `auto_tag_rules` via
 * `file_get_contents(database/migration_auto_tags.sql) + $db->exec(...)` —
 * that migration file doesn't even exist in this repo anymore (its DDL was
 * folded into database/install_complete_latest.sql), and
 * packages/db/src/generated/tenant-db.d.ts's generated schema already has
 * an `AutoTagRules` interface, confirming the table is part of the
 * committed/generated schema this snapshot was introspected from — so
 * there is nothing to escalate here (unlike the brief's hypothetical
 * "missing migration" concern). The two `auto_tag_rules` reads below still
 * keep PHP's defensive try/catch (empty-array / zero fallback) for
 * resilience, matching the PHP source's own belt-and-suspenders style.
 */

export interface CrmStats {
  totalCustomers: number;
  newToday: number;
  new7Days: number;
  totalTags: number;
  autoRules: number;
}

export interface TagRow {
  id: number;
  name: string;
  color: string | null;
  tagType: string | null;
  customerCount: number;
}

export interface AutoRuleRow {
  id: number;
  ruleName: string;
  isActive: boolean;
  triggerType: string;
  tagColor: string | null;
  tagName: string;
}

export interface RecentCustomerRow {
  id: number;
  displayName: string | null;
  pictureUrl: string | null;
  /** Comma-separated tag names, GROUP_CONCAT'd exactly like crm.php's subquery — null/empty means "no tags". */
  tags: string | null;
}

export interface CrmData {
  stats: CrmStats;
  tags: TagRow[];
  autoRules: AutoRuleRow[];
  recentCustomers: RecentCustomerRow[];
}

function logQueryFailure(queryName: string, error: unknown): void {
  // eslint-disable-next-line no-console
  console.error(`[dashboard/crm] query '${queryName}' failed`, error);
}

async function fetchCrmStats(db: Kysely<TenantDB>, currentBotId: number | null): Promise<CrmStats> {
  const [totalCustomers, newToday, new7Days, totalTags, autoRules] = await Promise.all([
    sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM users WHERE (line_account_id = ${currentBotId} OR ${currentBotId} IS NULL) AND is_blocked = 0
    `
      .execute(db)
      .then((r) => toNumber(r.rows[0]?.count)),
    sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM users WHERE (line_account_id = ${currentBotId} OR ${currentBotId} IS NULL) AND DATE(created_at) = CURDATE()
    `
      .execute(db)
      .then((r) => toNumber(r.rows[0]?.count)),
    sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM users WHERE (line_account_id = ${currentBotId} OR ${currentBotId} IS NULL) AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    `
      .execute(db)
      .then((r) => toNumber(r.rows[0]?.count)),
    sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM user_tags WHERE line_account_id = ${currentBotId} OR line_account_id IS NULL
    `
      .execute(db)
      .then((r) => toNumber(r.rows[0]?.count)),
    sql<{ count: number | string | null }>`
      SELECT COUNT(*) as count FROM auto_tag_rules WHERE line_account_id = ${currentBotId} OR line_account_id IS NULL
    `
      .execute(db)
      .then((r) => toNumber(r.rows[0]?.count))
      .catch((error: unknown) => {
        logQueryFailure('crmStats.autoRules', error);
        return 0;
      }),
  ]);

  return { totalCustomers, newToday, new7Days, totalTags, autoRules };
}

async function fetchTags(db: Kysely<TenantDB>, currentBotId: number | null): Promise<TagRow[]> {
  const result = await sql<{ id: number; name: string; color: string | null; tag_type: string | null; customer_count: number | string | null }>`
    SELECT t.id, t.name, t.color, t.tag_type,
    COUNT(a.user_id) as customer_count
    FROM user_tags t
    LEFT JOIN user_tag_assignments a ON t.id = a.tag_id
    WHERE t.line_account_id = ${currentBotId} OR t.line_account_id IS NULL
    GROUP BY t.id
    ORDER BY customer_count DESC
  `.execute(db);

  return result.rows.map((row) => ({
    id: row.id,
    name: row.name,
    color: row.color,
    tagType: row.tag_type,
    customerCount: toNumber(row.customer_count),
  }));
}

async function fetchRecentCustomers(db: Kysely<TenantDB>, currentBotId: number | null): Promise<RecentCustomerRow[]> {
  const result = await sql<{ id: number; display_name: string | null; picture_url: string | null; tags: string | null }>`
    SELECT u.id, u.display_name, u.picture_url,
    (SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM user_tags t JOIN user_tag_assignments a ON t.id = a.tag_id WHERE a.user_id = u.id) as tags
    FROM users u
    WHERE (u.line_account_id = ${currentBotId} OR ${currentBotId} IS NULL) AND u.is_blocked = 0
    ORDER BY u.created_at DESC
    LIMIT 10
  `.execute(db);

  return result.rows.map((row) => ({
    id: row.id,
    displayName: row.display_name,
    pictureUrl: row.picture_url,
    tags: row.tags,
  }));
}

/** Port of classes/AutoTagManager.php::getRules() — same JOIN, same `line_account_id = ? OR ? IS NULL` scope, same ORDER BY, same catch-returns-empty-array fallback. */
async function fetchAutoRules(db: Kysely<TenantDB>, currentBotId: number | null): Promise<AutoRuleRow[]> {
  try {
    const result = await sql<{
      id: number;
      rule_name: string;
      is_active: number | null;
      trigger_type: string;
      tag_color: string | null;
      tag_name: string;
    }>`
      SELECT r.id, r.rule_name, r.is_active, r.trigger_type, t.name as tag_name, t.color as tag_color
      FROM auto_tag_rules r
      JOIN user_tags t ON r.tag_id = t.id
      WHERE r.line_account_id = ${currentBotId} OR r.line_account_id IS NULL
      ORDER BY r.priority DESC, r.created_at DESC
    `.execute(db);

    return result.rows.map((row) => ({
      id: row.id,
      ruleName: row.rule_name,
      isActive: !!row.is_active,
      triggerType: row.trigger_type,
      tagColor: row.tag_color,
      tagName: row.tag_name,
    }));
  } catch (error) {
    logQueryFailure('autoRules', error);
    return [];
  }
}

export async function fetchCrmData(db: Kysely<TenantDB>, currentBotId: number | null): Promise<CrmData> {
  const [stats, tags, autoRules, recentCustomers] = await Promise.all([
    fetchCrmStats(db, currentBotId),
    fetchTags(db, currentBotId),
    fetchAutoRules(db, currentBotId),
    fetchRecentCustomers(db, currentBotId),
  ]);

  return { stats, tags, autoRules, recentCustomers };
}
