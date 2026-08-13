import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * line-queries.ts — read-side port of `classes/LineAccountManager.php`'s
 * `getAllAccounts()` (lines 85-89) and `getAccountById()` (lines 61-66), the
 * two reads `includes/settings/line.php` (line 30-31, `new
 * LineAccountManager($db); $manager->getAllAccounts()`) and `testConnection()`
 * (line 314, `getAccountById($accountId)`) rely on.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `getAllAccounts()` — byte-exact SQL, no WHERE/tenant filter
 * ═══════════════════════════════════════════════════════════════════════
 *   SELECT * FROM line_accounts ORDER BY is_default DESC, name ASC
 *
 * NOT scoped by `current_bot_id`/session and NOT filtered by `is_active`
 * (contrast the constructor's own `loadAccounts()` cache, which DOES filter
 * `WHERE is_active = 1` — that private cache is never what `getAllAccounts()`
 * reads from; it always re-queries). LINE accounts are not
 * `current_bot_id`-scoped data — they ARE the list of bots a tenant can
 * switch between — so there is no tenant-internal WHERE clause to add here;
 * the already-tenant-scoped `db` connection (one physical DB per tenant) is
 * the only scoping that applies.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `getAccountById()` — used only by testLineConnectionAction (../_lib/line-actions.ts)
 * ═══════════════════════════════════════════════════════════════════════
 *   SELECT * FROM line_accounts WHERE id = ?
 *
 * ═══════════════════════════════════════════════════════════════════════
 * BASE_URL — same established env-override convention as
 * api/miniapp/checkout/order/_lib/notify.ts's `notifyBaseUrl()` /
 * api/miniapp/checkout/cart/_lib/cartProductSource.ts's
 * `MANAGER_PRODUCT_PHOTO_BASE_URL`
 * ═══════════════════════════════════════════════════════════════════════
 * `config/config.php` defines a literal `BASE_URL` constant
 * (`'https://clinicya.re-ya.com/'`, trailing slash included) used in TWO
 * places this tab touches, with two textually different (but, on the real
 * production constant, IDENTICAL-output) concatenation styles:
 *
 *   - line.php's card template (line 123): `<?= BASE_URL ?>webhook.php?account=<?= $id ?>`
 *     — direct concat, relies on BASE_URL already ending in `/`.
 *   - LineAccountManager::createAccount() (line 167-168):
 *     `rtrim(BASE_URL, '/') . '/webhook.php?account=' . $accountId` —
 *     explicitly normalizes the slash.
 *
 * Both are ported through the ONE `lineAccountsBaseUrl()`/`buildLineWebhookUrl()`
 * pair below (no trailing slash from the helper, `/webhook.php?account=`
 * always added explicitly by the caller) — on the real `BASE_URL` value
 * both PHP call sites already produce the exact same string, so one shared
 * helper is not a behavioral fork, just avoiding two near-duplicate
 * concatenation helpers for a single underlying constant.
 */

export type LineBotMode = 'shop' | 'general' | 'auto_reply_only';

/**
 * Row shape for `SELECT * FROM line_accounts`. Only the columns this tab's
 * UI (card grid + create/edit form prefill) actually reads are typed here —
 * matches this codebase's established row-interface convention (see e.g.
 * ../_lib/shop-tax-queries.ts's `ShopTaxInfoRow`) of typing "what's used",
 * not literally every column in the generated `LineAccounts` Kysely
 * interface. `channel_secret`/`channel_access_token` ARE included and DO
 * flow into the edit form's prefilled values — replicating line.php's own
 * `editLineAccount(<?= json_encode($account) ?>)` (line 137), which embeds
 * the full row (secrets included) into the page for every account card's
 * edit button. This is real, if arguably unwise, production behavior — not
 * something this port "fixes" by redacting secrets from the fetched row.
 *
 * `receipt_points_enabled` is OPTIONAL (not `| null`, genuinely absent from
 * the object) because the committed tenant schema
 * (database/migration_2026-05-25_tenant_template.sql) has no such column —
 * `line.php`'s own auto-migrate block (lines 25-27) would add it at
 * page-load time in real PHP, but `database/**` is out of scope for this
 * batch (no migration added here), so `SELECT *` on this port's target
 * schema never returns the key at all.
 */
export interface LineAccountRow {
  id: number;
  name: string;
  channel_id: string | null;
  channel_secret: string;
  channel_access_token: string;
  basic_id: string | null;
  bot_mode: LineBotMode | null;
  liff_id: string | null;
  is_active: number | null;
  is_default: number | null;
  picture_url: string | null;
  webhook_url: string | null;
  welcome_message: string | null;
  auto_reply_enabled: number | null;
  shop_enabled: number | null;
  receipt_points_enabled?: number | null;
}

/** Verbatim port of `LineAccountManager::getAllAccounts()` (lines 85-89). */
export async function getLineAccounts(db: Kysely<TenantDB>): Promise<LineAccountRow[]> {
  const result = await sql<LineAccountRow>`SELECT * FROM line_accounts ORDER BY is_default DESC, name ASC`.execute(db);
  return result.rows;
}

/** Verbatim port of `LineAccountManager::getAccountById()` (lines 61-66). */
export async function getLineAccountById(db: Kysely<TenantDB>, id: number): Promise<LineAccountRow | null> {
  const result = await sql<LineAccountRow>`SELECT * FROM line_accounts WHERE id = ${id}`.execute(db);
  return result.rows[0] ?? null;
}

/** See module doc's BASE_URL section. No trailing slash. */
export function lineAccountsBaseUrl(): string {
  const env = process.env.LINE_ACCOUNTS_BASE_URL;
  return env && env.trim() !== '' ? env.replace(/\/+$/, '') : 'https://clinicya.re-ya.com';
}

/** `{base}/webhook.php?account={id}` — shared by the card grid's display and createLineAccountAction's persisted `webhook_url`. */
export function buildLineWebhookUrl(accountId: number): string {
  return `${lineAccountsBaseUrl()}/webhook.php?account=${accountId}`;
}

/**
 * Verbatim port of line.php's own `$_GET['success']` map (lines 55-59, used
 * by that file's now-superseded per-tab banner — see ../_lib/line-actions.ts's
 * module doc for why this Next port instead feeds these strings through
 * page.tsx's shared `?message=` convention).
 */
export const LINE_SUCCESS_MESSAGES = {
  created: 'เพิ่มบัญชีสำเร็จ',
  updated: 'อัพเดทสำเร็จ',
  deleted: 'ลบสำเร็จ',
  default: 'ตั้งเป็นบัญชีหลักสำเร็จ',
} as const;

/**
 * Verbatim (untranslated-in-PHP-too) English strings from
 * `LineAccountManager::testConnection()` (lines 315, 328) — not Thai in the
 * original, kept as-is rather than "fixed" into Thai.
 */
export const LINE_TEST_ACCOUNT_NOT_FOUND_MESSAGE = 'Account not found';
export const LINE_TEST_CONNECTION_FAILED_MESSAGE = 'Connection failed';
