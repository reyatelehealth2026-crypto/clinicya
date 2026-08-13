import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * notifications-queries.ts — port of includes/settings/notifications.php's
 * READ block (lines 7-90). Read the full 442-line source before editing
 * this file (or ./notifications-actions.ts / ../_components/NotificationsTab.tsx).
 *
 *   $currentBotId = $_SESSION['current_bot_id'] ?? null;   // this partial's OWN
 *                                                            local var — see below
 *   $accountId = (int) ($currentBotId ?: 0);
 *   try {
 *       $stmt = $db->prepare("SELECT * FROM notification_settings WHERE line_account_id = ?");
 *       $stmt->execute([$accountId]);
 *       $notifySettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
 *   } catch (Exception $e) { $notifySettings = []; // implicit }
 *
 *   $adminUsers = [];
 *   try {
 *       $stmt = $db->query("SELECT id, username, email, line_user_id, role FROM admin_users WHERE is_active = 1 ORDER BY role, username");
 *       $adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *   } catch (Exception $e) {}
 *
 *   $lineNotifyEnabled = $notifySettings['line_notify_enabled'] ?? 1;
 *   ... (15 total per-field `?? default` fallbacks across lines 60-78; see
 *   below for the exact list — the hand-off brief that spawned this batch
 *   said "16", the real source has 15 explicit `?? ` fallback expressions
 *   producing 15 output fields; noted here rather than silently
 *   "fixed"/miscounted away) ...
 *   $odooLiffNotifyEventsRaw = $notifySettings['odoo_liff_notify_events'] ?? '';
 *   $odooLiffNotifyEvents = array_filter(array_map('trim', explode(',', $odooLiffNotifyEventsRaw)));
 *   if (empty($odooLiffNotifyEvents)) {
 *       $odooLiffNotifyEvents = ['order.validated', 'order.awaiting_payment', 'order.paid', 'order.in_delivery', 'order.delivered'];
 *   }
 *   $notifyAdminUsersRaw = $notifySettings['notify_admin_users'] ?? '';
 *   $notifyAdminUsers = array_filter(array_map('intval', explode(',', $notifyAdminUsersRaw)));
 *
 * IMPORTANT — same shadowing note as ./welcome-queries.ts's module doc:
 * this partial's `$currentBotId = $_SESSION['current_bot_id'] ?? null;`
 * (notifications.php line 7) is a LOCAL reassignment of settings.php's own
 * outer `$currentBotId = $_SESSION['current_bot_id'] ?? 1` (settings.php
 * line 27) — PHP `include` shares the caller's scope, so for the duration of
 * rendering this tab the effective default is `null`, not `1`. Mirrored here
 * by taking `currentBotId: number | null` straight through (the Next
 * equivalent of the raw, un-defaulted `$_SESSION['current_bot_id']`) — NOT
 * defaulted to 1 by this function.
 *
 * `odoo_liff_notify_events`' 5-code default list DELIBERATELY OMITS
 * `order.to_delivery` (and both `invoice.*` codes) even though all 8 are
 * valid, selectable options in `$odooEventOptions` (notifications.php lines
 * 81-90) — verified by reading the literal default-array PHP source above,
 * not assumed. Reproduced exactly as `DEFAULT_ODOO_LIFF_EVENTS` below; do
 * NOT "complete" the list to all 8 codes.
 *
 * `array_filter` with no callback drops PHP-falsy elements (`''`, `0`,
 * `null`, `false`, `'0'`) — for the trimmed-event-code array that means
 * empty strings; for the intval'd admin-user-id array that means `0`
 * (`intval('')` on a stray/trailing comma or non-numeric fragment). Both
 * mirrored below via `.filter((x) => x !== '')` / `.filter((n) => n !== 0)`
 * respectively — NOT a truthiness filter (which would incorrectly drop a
 * real admin user id of `0`, though no such id can exist in practice since
 * `admin_users.id` is an auto-increment PK starting at 1).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED FINDING — `notification_settings` IS on the committed tenant
 * schema (unlike welcome_settings) with every DATA column the page-load
 * CREATE TABLE/ALTER TABLE DDL (notifications.php lines 9-41) would add —
 * BUT it is MISSING the `UNIQUE KEY unique_account (line_account_id)` that
 * same DDL defines, a real pre-existing schema drift, not fixed here
 * ═══════════════════════════════════════════════════════════════════════
 * database/migration_2026-05-25_tenant_template.sql lines 756-777 define
 * `notification_settings` with every data column
 * (line_notify_enabled/new_order/payment/urgent/appointment/low_stock,
 * email_enabled/addresses/notify_urgent/notify_daily_report/notify_low_stock,
 * telegram_enabled, odoo_liff_notify_enabled, odoo_liff_notify_events,
 * notify_admin_users) but its ONLY key is `PRIMARY KEY (id)` — the
 * `UNIQUE KEY unique_account (line_account_id)` that PHP's own runtime
 * `CREATE TABLE IF NOT EXISTS` (notifications.php line 31) defines is
 * ABSENT from the committed template (confirmed by reading the migration
 * file directly, not assumed). PHP's own `ALTER TABLE` guard (lines 34-39)
 * only probes `SHOW COLUMNS` for two specific column names — it never
 * checks for or adds a missing unique key — so a tenant DB provisioned from
 * the committed template has this exact gap **in real PHP too**, not only
 * in this port: `save_notifications`'s `ON DUPLICATE KEY UPDATE
 * line_account_id = ...` (settings.php lines 736-751, mirrored in
 * ./notifications-actions.ts) can only collide on `PRIMARY KEY (id)`, which
 * a fresh `INSERT` never supplies — so every save on such a tenant DB
 * inserts ANOTHER row instead of updating the existing one, and
 * `getNotificationSettings()`'s `WHERE line_account_id = ?` (unqualified by
 * `id`) would then read back whichever row MySQL happens to return, not
 * necessarily the most recently saved one. This is flagged, not fixed:
 * `database/**` is out of scope for this batch, and per CLAUDE.md ("new
 * code must never auto-create schema") no page-load `CREATE TABLE`/`ALTER
 * TABLE` DDL is ported here as executable code regardless.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED FINDING — `admin_users` has no Kysely interface and does not
 * exist in a tenant DB built from the committed template (4th sighting)
 * ═══════════════════════════════════════════════════════════════════════
 * Same root cause already independently confirmed and documented at
 * apps/admin/src/app/api/inbox/actions/get-admins/_lib/getAdmins.ts's module
 * doc (1st sighting), ./shop-tax-queries.ts's `resolveLineAccountId()` doc
 * (2nd), and ./consent-queries.ts's module doc (3rd) — `admin_users` is a
 * PLATFORM-level table (database/migration_2026-05-25_tenant_template.sql's
 * own header: "Platform-level tables (admin_users, dev_logs, etc.) live in
 * `reya_platform`"), absent from every tenant DB and from
 * packages/db/src/generated/tenant-db.d.ts. This is the 4th confirmed
 * sighting of the identical schema-drift gap, cited rather than
 * re-discovered — out of scope to fix here (database/** is off-limits to
 * this batch). Read via the raw `sql` tagged-template escape hatch (no typed
 * Kysely path onto this table exists).
 *
 * UNLIKE getAdmins.ts (which lets the missing-table throw propagate to its
 * caller's own try/catch), notifications.php's OWN `admin_users` read has
 * its own local `try { } catch (Exception $e) {}` that silently degrades to
 * `$adminUsers = []` — mirrored here with the welcome-queries.ts-style
 * catch-to-default shape (NOT getAdmins.ts's throw-and-let-caller-handle
 * shape), per this batch's brief. On the committed schema this ALWAYS
 * throws in practice (missing table) and ALWAYS degrades to `[]` — same
 * "permanent, not rare" situation ./consent-queries.ts's module doc
 * documents for its own `admin_users` join.
 */

export interface NotificationSettings {
  lineNotifyEnabled: boolean;
  lineNotifyNewOrder: boolean;
  lineNotifyPayment: boolean;
  lineNotifyUrgent: boolean;
  lineNotifyAppointment: boolean;
  lineNotifyLowStock: boolean;
  emailEnabled: boolean;
  emailAddresses: string;
  emailNotifyUrgent: boolean;
  emailNotifyDailyReport: boolean;
  emailNotifyLowStock: boolean;
  telegramEnabled: boolean;
  odooLiffNotifyEnabled: boolean;
  /** Parsed, non-empty event-code list — either the row's own comma list or DEFAULT_ODOO_LIFF_EVENTS. */
  odooLiffNotifyEvents: string[];
  /** Parsed admin_users.id list (0 dropped, matching PHP's array_filter). */
  notifyAdminUsers: number[];
}

export interface NotificationAdminUser {
  id: number;
  username: string;
  email: string | null;
  line_user_id: string | null;
  role: string;
}

export interface OdooEventOption {
  code: string;
  label: string;
}

/** Verbatim port of notifications.php's $odooEventOptions (lines 81-90) — the 8 known Odoo event codes, in order. */
export const ODOO_EVENT_OPTIONS: OdooEventOption[] = [
  { code: 'order.validated', label: 'ยืนยันออเดอร์' },
  { code: 'order.awaiting_payment', label: 'รอชำระเงิน' },
  { code: 'order.paid', label: 'ชำระเงินแล้ว' },
  { code: 'order.to_delivery', label: 'เตรียมส่ง' },
  { code: 'order.in_delivery', label: 'กำลังจัดส่ง' },
  { code: 'order.delivered', label: 'จัดส่งสำเร็จ' },
  { code: 'invoice.created', label: 'ออกใบแจ้งหนี้' },
  { code: 'invoice.overdue', label: 'ใบแจ้งหนี้เกินกำหนด' },
];

/**
 * Verbatim port of notifications.php's hardcoded default-events list (line
 * 76) — DELIBERATELY 5 of the 8 `ODOO_EVENT_OPTIONS` codes; `order.to_delivery`,
 * `invoice.created`, and `invoice.overdue` are NOT included. See module doc.
 */
export const DEFAULT_ODOO_LIFF_EVENTS: string[] = ['order.validated', 'order.awaiting_payment', 'order.paid', 'order.in_delivery', 'order.delivered'];

interface NotificationSettingsRow {
  id: number;
  line_account_id: number;
  line_notify_enabled: number | null;
  line_notify_new_order: number | null;
  line_notify_payment: number | null;
  line_notify_urgent: number | null;
  line_notify_appointment: number | null;
  line_notify_low_stock: number | null;
  email_enabled: number | null;
  email_addresses: string | null;
  email_notify_urgent: number | null;
  email_notify_daily_report: number | null;
  email_notify_low_stock: number | null;
  telegram_enabled: number | null;
  odoo_liff_notify_enabled: number | null;
  odoo_liff_notify_events: string | null;
  notify_admin_users: string | null;
}

/** PHP `intval($str)` semantics: leading whitespace + optional sign + digits, else 0. */
function phpIntval(value: string): number {
  const match = value.match(/^\s*[+-]?\d+/);
  return match ? Number.parseInt(match[0], 10) : 0;
}

function mapRow(row: NotificationSettingsRow | undefined): NotificationSettings {
  const odooEventsRaw = row?.odoo_liff_notify_events ?? '';
  let odooLiffNotifyEvents = odooEventsRaw
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s !== '');
  if (odooLiffNotifyEvents.length === 0) {
    odooLiffNotifyEvents = [...DEFAULT_ODOO_LIFF_EVENTS];
  }

  const notifyAdminUsersRaw = row?.notify_admin_users ?? '';
  const notifyAdminUsers = notifyAdminUsersRaw
    .split(',')
    .map((s) => phpIntval(s))
    .filter((n) => n !== 0);

  return {
    lineNotifyEnabled: Boolean(row?.line_notify_enabled ?? 1),
    lineNotifyNewOrder: Boolean(row?.line_notify_new_order ?? 1),
    lineNotifyPayment: Boolean(row?.line_notify_payment ?? 1),
    lineNotifyUrgent: Boolean(row?.line_notify_urgent ?? 1),
    lineNotifyAppointment: Boolean(row?.line_notify_appointment ?? 1),
    lineNotifyLowStock: Boolean(row?.line_notify_low_stock ?? 0),
    emailEnabled: Boolean(row?.email_enabled ?? 0),
    emailAddresses: row?.email_addresses ?? '',
    emailNotifyUrgent: Boolean(row?.email_notify_urgent ?? 1),
    emailNotifyDailyReport: Boolean(row?.email_notify_daily_report ?? 0),
    emailNotifyLowStock: Boolean(row?.email_notify_low_stock ?? 0),
    telegramEnabled: Boolean(row?.telegram_enabled ?? 0),
    odooLiffNotifyEnabled: Boolean(row?.odoo_liff_notify_enabled ?? 1),
    odooLiffNotifyEvents,
    notifyAdminUsers,
  };
}

/**
 * `(int) ($currentBotId ?: 0)` (notifications.php line 45, and again inline
 * in the `save_notifications`/`test_odoo_liff_notification` handlers at
 * settings.php lines 704/770) — PHP's `?:` short ternary treats `null`/`0`
 * as falsy, falling back to `0`. `currentBotId` here is already
 * `number | null` (never a numeric string), so no separate `(int)` cast step
 * is needed. Exported so ./notifications-actions.ts computes the identical
 * `accountId` for both the save and test-send handlers without
 * re-deriving the ternary differently in three places.
 */
export function resolveNotificationAccountId(currentBotId: number | null): number {
  return currentBotId ? currentBotId : 0;
}

export async function getNotificationSettings(db: Kysely<TenantDB>, currentBotId: number | null): Promise<NotificationSettings> {
  const accountId = resolveNotificationAccountId(currentBotId);
  try {
    const result = await sql<NotificationSettingsRow>`SELECT * FROM notification_settings WHERE line_account_id = ${accountId}`.execute(db);
    return mapRow(result.rows[0]);
  } catch {
    // Mirrors `catch (Exception $e) {}` leaving `$notifySettings = []` -> every field falls to its `?? default`.
    return mapRow(undefined);
  }
}

export async function getNotificationAdminUsers(db: Kysely<TenantDB>): Promise<NotificationAdminUser[]> {
  try {
    const result = await sql<NotificationAdminUser>`
      SELECT id, username, email, line_user_id, role
      FROM admin_users
      WHERE is_active = 1
      ORDER BY role, username
    `.execute(db);
    return result.rows;
  } catch {
    // Mirrors `catch (Exception $e) {}` leaving `$adminUsers = []`.
    return [];
  }
}
