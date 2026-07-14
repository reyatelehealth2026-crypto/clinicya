import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * consent-queries.ts — port of includes/settings/consent.php's read block
 * (lines 7-50): 4 queries sharing ONE try/catch, backing a read-only PDPA
 * consent/audit-log dashboard (zero mutations anywhere on this tab — no
 * `case` in settings.php's `$_POST['action']` switch touches consent data
 * at all).
 *
 *   try {
 *     total_consented: SELECT COUNT(DISTINCT user_id) FROM user_consents WHERE is_accepted = 1
 *     by_type:         SELECT consent_type, COUNT(*) FROM user_consents WHERE is_accepted = 1 GROUP BY consent_type   (PDO::FETCH_KEY_PAIR)
 *     recentLogs:      SELECT cl.*, u.display_name, u.line_user_id FROM consent_logs cl JOIN users u ON cl.user_id = u.id ORDER BY cl.created_at DESC LIMIT 50
 *     accessLogs:      SELECT dal.*, au.username AS admin_name, u.display_name AS target_user FROM data_access_logs dal LEFT JOIN admin_users au ON dal.admin_user_id = au.id LEFT JOIN users u ON dal.user_id = u.id ORDER BY dal.created_at DESC LIMIT 50
 *   } catch (Exception $e) { $consentError = $e->getMessage(); }
 *
 * All 4 queries share ONE try block in real PHP — replicated here as ONE
 * async function running all 4 sequentially inside a single try, returning
 * an "all-or-nothing" result. consent.php's view is `if ($consentError) {
 * <red error banner only> } else { <stats + tabs + tables> }` — a query
 * failing partway through never produces a partial stats/table render, only
 * the error banner. getConsentPageData() mirrors that exactly: ANY of the 4
 * queries throwing degrades the WHOLE result to `{error, stats: empty,
 * recentLogs: [], accessLogs: []}` (the empty payload is irrelevant in
 * practice — ConsentTab.tsx only reads it when `error` is null, same as the
 * PHP `if/else`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED FINDING — this tab's red error banner is the PERMANENT state
 * on any tenant DB built from the committed template, not a rare edge case
 * ═══════════════════════════════════════════════════════════════════════
 * The 4th query's `LEFT JOIN admin_users au ON dal.admin_user_id = au.id`
 * targets a table that does NOT exist inside a tenant DB.
 * database/migration_2026-05-25_tenant_template.sql's own header says so
 * explicitly: "Platform-level tables (admin_users, dev_logs, etc.) live in
 * `reya_platform` and are defined by a separate migration" — confirmed by
 * grep: zero `CREATE TABLE ... admin_users` matches in the tenant template,
 * and no `AdminUsers` interface in the generated
 * packages/db/src/generated/tenant-db.d.ts either (same missing-table class
 * as api/shop-tax.php's admin_users lookup — see ./shop-tax-queries.ts's
 * `resolveLineAccountId()` doc for the sibling finding). Because all 4
 * consent.php queries share ONE try/catch, this 4th query throwing means
 * consent.php's red "❌ ... กรุณารัน migration ก่อน" banner is what actually
 * renders in production today on any tenant DB matching the committed
 * schema — REGARDLESS of whether consent_logs/user_consents/data_access_logs
 * themselves have any rows. This is a pre-existing PHP defect (same class as
 * the crm-dashboard-advanced and welcome_settings findings other Phase 2
 * batches documented), out of this batch's scope to fix (database/** is
 * off-limits) — replicated faithfully below by issuing the exact same LEFT
 * JOIN and letting it throw/degrade the page the same way. The SUCCESS path
 * (a DB that does answer the admin_users join — e.g. a legacy/compat tenant)
 * is still fully implemented and independently tested here, since nothing in
 * this function special-cases the missing table. Flagged in the build report
 * as worth a product decision, not silently "fixed" in either direction.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * lineAccountId scoping — a SECOND, separate, non-blocking finding
 * ═══════════════════════════════════════════════════════════════════════
 * None of the 4 queries filter by `line_account_id` at all, even though
 * `consent_logs`/`data_access_logs`/`user_consents` all carry a
 * `line_account_id` column per the generated schema ("tenant scope - FK
 * line_accounts.id (added during DB-per-tenant migration)"). This tab
 * therefore aggregates across every LINE OA in the tenant DB, not just the
 * admin's current one — replicated as-is (no WHERE added), matching the
 * "byte-for-byte, don't silently fix" convention this migration follows
 * elsewhere (e.g. (tenant)/crm-dashboard-advanced/queries.ts's own
 * lineAccountId-scoping finding).
 *
 * Uses the raw `sql` tagged-template escape hatch, not Kysely's typed
 * `.selectFrom()` builder, matching this settings folder's own established
 * convention (see ./email-queries.ts's module doc: no CamelCasePlugin is
 * registered on the shared `Kysely<TenantDB>` instance).
 */

export interface ConsentStats {
  totalConsented: number;
  /** consent_type -> accepted count, PDO::FETCH_KEY_PAIR shape (e.g. `{privacy_policy: 12, marketing: 3}`). */
  byType: Record<string, number>;
}

export interface ConsentLogRow {
  id: number;
  createdAt: Date | string | null;
  displayName: string | null;
  lineUserId: string;
  consentType: string;
  action: 'accept' | 'update' | 'withdraw';
  consentVersion: string;
  ipAddress: string | null;
}

export interface DataAccessLogRow {
  id: number;
  createdAt: Date | string | null;
  /**
   * `au.username`, LEFT JOIN — a NULL admin_user_id (or a missing
   * admin_users row) falls back to 'System' here, at read time, mirroring
   * the PHP view's `htmlspecialchars($log['admin_name'] ?? 'System')`.
   */
  adminName: string;
  action: string;
  resourceType: string;
  /** `u.display_name`, LEFT JOIN via `dal.user_id` — legitimately nullable (a data-access log with no associated end-user row). */
  targetUser: string | null;
  ipAddress: string | null;
}

export interface ConsentPageData {
  error: string | null;
  stats: ConsentStats;
  recentLogs: ConsentLogRow[];
  accessLogs: DataAccessLogRow[];
}

const EMPTY_STATS: ConsentStats = { totalConsented: 0, byType: {} };

interface RecentLogRow {
  id: number;
  created_at: Date | string | null;
  display_name: string | null;
  line_user_id: string;
  consent_type: string;
  action: 'accept' | 'update' | 'withdraw';
  consent_version: string;
  ip_address: string | null;
}

interface AccessLogRow {
  id: number;
  created_at: Date | string | null;
  admin_name: string | null;
  action: string;
  resource_type: string;
  target_user: string | null;
  ip_address: string | null;
}

export async function getConsentPageData(db: Kysely<TenantDB>): Promise<ConsentPageData> {
  try {
    const totalResult = await sql<{ total: number | string | null }>`
      SELECT COUNT(DISTINCT user_id) as total FROM user_consents WHERE is_accepted = 1
    `.execute(db);
    const totalConsented = Number(totalResult.rows[0]?.total ?? 0);

    const byTypeResult = await sql<{ consent_type: string; count: number | string }>`
      SELECT consent_type, COUNT(*) as count
      FROM user_consents
      WHERE is_accepted = 1
      GROUP BY consent_type
    `.execute(db);
    const byType: Record<string, number> = {};
    for (const row of byTypeResult.rows) {
      byType[row.consent_type] = Number(row.count);
    }

    const recentLogsResult = await sql<RecentLogRow>`
      SELECT cl.*, u.display_name, u.line_user_id
      FROM consent_logs cl
      JOIN users u ON cl.user_id = u.id
      ORDER BY cl.created_at DESC
      LIMIT 50
    `.execute(db);
    const recentLogs: ConsentLogRow[] = recentLogsResult.rows.map((row) => ({
      id: row.id,
      createdAt: row.created_at,
      displayName: row.display_name,
      lineUserId: row.line_user_id,
      consentType: row.consent_type,
      action: row.action,
      consentVersion: row.consent_version,
      ipAddress: row.ip_address,
    }));

    const accessLogsResult = await sql<AccessLogRow>`
      SELECT dal.*, au.username as admin_name, u.display_name as target_user
      FROM data_access_logs dal
      LEFT JOIN admin_users au ON dal.admin_user_id = au.id
      LEFT JOIN users u ON dal.user_id = u.id
      ORDER BY dal.created_at DESC
      LIMIT 50
    `.execute(db);
    const accessLogs: DataAccessLogRow[] = accessLogsResult.rows.map((row) => ({
      id: row.id,
      createdAt: row.created_at,
      adminName: row.admin_name ?? 'System',
      action: row.action,
      resourceType: row.resource_type,
      targetUser: row.target_user,
      ipAddress: row.ip_address,
    }));

    return { error: null, stats: { totalConsented, byType }, recentLogs, accessLogs };
  } catch (err) {
    // Mirrors PHP's single shared `catch (Exception $e) { $consentError = $e->getMessage(); }`.
    return {
      error: err instanceof Error ? err.message : String(err),
      stats: EMPTY_STATS,
      recentLogs: [],
      accessLogs: [],
    };
  }
}

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

/** Mirrors PHP's `date('d/m/Y H:i', strtotime($log['created_at']))` under this codebase's forced Asia/Bangkok server timezone (no seconds, unlike (tenant)/activity-logs/_lib/format.ts's `d/m/Y H:i:s`). */
export function formatConsentLogTimestamp(value: Date | string | null): string {
  if (value === null) return '-';
  const date = typeof value === 'string' ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) return '-';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: BANGKOK_TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  return `${get('day')}/${get('month')}/${get('year')} ${get('hour')}:${get('minute')}`;
}
