import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — Server Component data layer for system-status.php's 19 named
 * checks (lines 22-173). This app only PORTS the 11 checks that are pure
 * DB probes; the other 8 test whether a specific PHP class/service
 * (VibeSellingHelper, InboxService, the 4 V2 *Service classes,
 * LineAccountManager/LineAPI, AIChat's GeminiChatAdapter) instantiates
 * without throwing — none of those classes/services exist on this side of
 * the migration yet (they land Phase 4/6/7 per
 * docs/plans/2026-07-12-nextjs-full-migration-plan.md), so faking an 'ok'
 * for them would be a lie. They render as a fixed NOT_PORTED row instead
 * (see CheckStatus below) — informational, never a faked 'ok'.
 *
 * `$currentBotId = $_SESSION['current_bot_id'] ?? 1` (system-status.php line
 * 16) — note the literal fallback of `1`, NOT null/0 like most other Phase 2
 * pages (e.g. loyalty-members.php's `$lineAccountId > 0` gate). Mirrored
 * exactly by the caller (page.tsx): `session.currentBotId ?? 1`.
 *
 * overallStatus is computed from ONLY the 11 portable checks (documented,
 * intentionally narrower health signal than PHP's — PHP folds all 19 into
 * one cascade, but this port has no signal at all for the 8 not-yet-ported
 * checks, so folding them in would either under- or over-report). Mirrors
 * PHP's exact per-check severity mapping:
 *   - `database` + the 5 `table_*` checks: any error -> 'critical'
 *     (unconditional set in PHP, not gated behind `healthy`)
 *   - the 3 `v2_table_*` checks: any warning -> 'degraded' (PHP:
 *     `if ($overallStatus === 'healthy') $overallStatus = 'degraded'`)
 *   - `message_stats`/`user_stats`: PHP's catch blocks for these two do NOT
 *     touch `$overallStatus` at all (lines 156-158, 171-173) — a failure
 *     here never degrades the cascade. Excluded from the fold entirely to
 *     mirror that exactly (their own status can still show 'warning' in the
 *     grid; it just never feeds the banner).
 */

export type CheckStatus = 'ok' | 'warning' | 'error' | 'not_ported';

export interface StatusCheck {
  key: string;
  status: CheckStatus;
  message: string;
}

export type OverallStatus = 'healthy' | 'degraded' | 'critical';

export interface SystemStatusResult {
  checks: StatusCheck[];
  overallStatus: OverallStatus;
  currentBotId: number;
}

const NOT_PORTED = 'ยังไม่ได้พอร์ตมายัง Next.js (จะพอร์ตใน Phase 4/6/7 ตามแผนมิเกรท)';

/** Ported from system-status.php lines 84-92 (`$requiredTables`) — 5 checks. */
const REQUIRED_TABLES: { table: string; name: string }[] = [
  { table: 'users', name: 'ตารางผู้ใช้' },
  { table: 'messages', name: 'ตารางข้อความ' },
  { table: 'line_accounts', name: 'ตารางบัญชี LINE' },
  { table: 'user_tags', name: 'ตารางแท็กผู้ใช้' },
  { table: 'admin_users', name: 'ตารางผู้ดูแลระบบ' },
];

/** Ported from system-status.php lines 95-99 (`$v2Tables`) — 3 checks. */
const V2_TABLES: { table: string; name: string }[] = [
  { table: 'customer_health_profiles', name: 'Health Profiles' },
  { table: 'drug_pricing_rules', name: 'Drug Pricing Rules' },
  { table: 'ghost_draft_learning', name: 'Ghost Draft Learning' },
];

/** Ported from system-status.php lines 57-62 (`$v2Services`) — 4 not-yet-ported placeholder checks. */
const V2_SERVICE_PLACEHOLDERS: { key: string; name: string }[] = [
  { key: 'v2_DrugPricingEngineService', name: 'Drug Pricing Engine' },
  { key: 'v2_CustomerHealthEngineService', name: 'Customer Health Engine' },
  { key: 'v2_PharmacyImageAnalyzerService', name: 'Pharmacy Image Analyzer' },
  { key: 'v2_PharmacyGhostDraftService', name: 'Ghost Draft Service' },
];

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

async function checkTable(db: Kysely<TenantDB>, table: string, name: string, keyPrefix: string, onMissing: CheckStatus): Promise<StatusCheck> {
  try {
    // Table name is from the fixed literal lists above, never user input — safe to splice into raw SQL.
    await sql.raw(`SELECT 1 FROM \`${table}\` LIMIT 1`).execute(db);
    return { key: `${keyPrefix}${table}`, status: 'ok', message: `${name} (${table}) พร้อมใช้งาน` };
  } catch {
    const message = onMissing === 'error' ? `${name} (${table}) ไม่พบ` : `${name} (${table}) ยังไม่ได้ migrate`;
    return { key: `${keyPrefix}${table}`, status: onMissing, message };
  }
}

/** Ported from system-status.php lines 23-29 — DB connectivity ping. */
async function checkDatabase(db: Kysely<TenantDB>): Promise<StatusCheck> {
  try {
    await sql`SELECT 1`.execute(db);
    return { key: 'database', status: 'ok', message: 'เชื่อมต่อฐานข้อมูลสำเร็จ' };
  } catch (error) {
    return { key: 'database', status: 'error', message: `ไม่สามารถเชื่อมต่อฐานข้อมูล: ${errorMessage(error)}` };
  }
}

/** Ported from system-status.php lines 141-158. */
async function checkMessageStats(db: Kysely<TenantDB>, currentBotId: number): Promise<StatusCheck> {
  try {
    const totalResult = await sql<{ total: number | string | null }>`
      SELECT COUNT(*) as total FROM messages WHERE line_account_id = ${currentBotId}
    `.execute(db);
    const total = Number(totalResult.rows[0]?.total ?? 0);

    const unreadResult = await sql<{ unread: number | string | null }>`
      SELECT COUNT(*) as unread FROM messages WHERE line_account_id = ${currentBotId} AND direction = 'incoming' AND is_read = 0
    `.execute(db);
    const unread = Number(unreadResult.rows[0]?.unread ?? 0);

    return { key: 'message_stats', status: 'ok', message: `ข้อความทั้งหมด: ${total}, ยังไม่อ่าน: ${unread}` };
  } catch {
    return { key: 'message_stats', status: 'warning', message: 'ไม่สามารถดึงสถิติข้อความ' };
  }
}

/** Ported from system-status.php lines 161-173. */
async function checkUserStats(db: Kysely<TenantDB>, currentBotId: number): Promise<StatusCheck> {
  try {
    const result = await sql<{ total: number | string | null }>`
      SELECT COUNT(*) as total FROM users WHERE line_account_id = ${currentBotId}
    `.execute(db);
    const total = Number(result.rows[0]?.total ?? 0);
    return { key: 'user_stats', status: 'ok', message: `ผู้ใช้ทั้งหมด: ${total}` };
  } catch {
    return { key: 'user_stats', status: 'warning', message: 'ไม่สามารถดึงสถิติผู้ใช้' };
  }
}

/** Computes the overall cascade from ONLY the 11 portable checks — see this file's module doc. */
export function computeOverallStatus(checks: StatusCheck[]): OverallStatus {
  const byKey = new Map(checks.map((c) => [c.key, c]));

  const criticalKeys = ['database', 'table_users', 'table_messages', 'table_line_accounts', 'table_user_tags', 'table_admin_users'];
  if (criticalKeys.some((k) => byKey.get(k)?.status === 'error')) {
    return 'critical';
  }

  const degradedKeys = ['v2_table_customer_health_profiles', 'v2_table_drug_pricing_rules', 'v2_table_ghost_draft_learning'];
  if (degradedKeys.some((k) => byKey.get(k)?.status === 'warning')) {
    return 'degraded';
  }

  return 'healthy';
}

/**
 * Runs all 19 checks in system-status.php's exact insertion order (the PHP
 * grid iterates `foreach ($checks as $key => $check)`, so order here is the
 * render order) and folds overallStatus per this file's module doc.
 */
export async function getSystemStatus(db: Kysely<TenantDB>, currentBotId: number): Promise<SystemStatusResult> {
  const checks: StatusCheck[] = [];

  checks.push(await checkDatabase(db));

  checks.push({ key: 'vibe_selling', status: 'not_ported', message: `Vibe Selling Helper: ${NOT_PORTED}` });
  checks.push({ key: 'inbox_service', status: 'not_ported', message: `Inbox Service: ${NOT_PORTED}` });
  for (const { key, name } of V2_SERVICE_PLACEHOLDERS) {
    checks.push({ key, status: 'not_ported', message: `${name}: ${NOT_PORTED}` });
  }

  for (const { table, name } of REQUIRED_TABLES) {
    checks.push(await checkTable(db, table, name, 'table_', 'error'));
  }

  for (const { table, name } of V2_TABLES) {
    checks.push(await checkTable(db, table, name, 'v2_table_', 'warning'));
  }

  checks.push({ key: 'line_api', status: 'not_ported', message: `LINE API: ${NOT_PORTED}` });
  checks.push({ key: 'ai_module', status: 'not_ported', message: `AI Module: ${NOT_PORTED}` });

  checks.push(await checkMessageStats(db, currentBotId));
  checks.push(await checkUserStats(db, currentBotId));

  return { checks, overallStatus: computeOverallStatus(checks), currentBotId };
}
