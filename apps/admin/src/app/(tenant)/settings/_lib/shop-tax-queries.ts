import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * shop-tax-queries.ts — port of the tenant-resolution + read logic shared by
 * `includes/settings/shop-tax.php` (its own top-of-file inline read, lines
 * 14-52 — prefills the SSR'd form) and `api/shop-tax.php` (its GET
 * `case 'get'` action, lines 68-93 — the same read, reachable by a direct
 * client `fetch('api/shop-tax.php?action=get')` that the tab's own inline
 * `<script>` never actually calls today, but the endpoint still exposes).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * ONE shared `resolveLineAccountId()`, not two divergent copies
 * ═══════════════════════════════════════════════════════════════════════
 * The two PHP blocks are *almost* the same 3-4 step tenant resolution, with
 * one real textual difference: `includes/settings/shop-tax.php`'s own inline
 * copy (lines 16-29) is missing the `$_GET['line_account_id']` tier that
 * `api/shop-tax.php`'s shared preamble (lines 26-42, used by BOTH its `get`
 * and `save` actions) has — even though the tab file's own comment claims
 * "same tenant resolution as api/shop-tax.php" (shop-tax.php line 14). This
 * is ported as ONE function, matching the api-file's fuller version (4
 * tiers), for two reasons: (1) the tab's own comment states intent to match
 * it, and (2) the `$_GET['line_account_id']` tier is DEAD from every actual
 * call site in this Next port regardless of which copy we match — see the
 * `queryLineAccountId` param doc below — so there is no real behavioral
 * difference in practice between "port the tab's own 3-tier copy" and "port
 * the api file's 4-tier copy with an always-unset 4th tier". Reusing one
 * function (rather than forking two near-identical copies) also avoids
 * exactly the kind of duplicated-logic drift this migration's conventions
 * warn against.
 *
 * Tier 1 (`$_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ??
 * 0`): the `?? $_SESSION['line_account_id']` half is DEAD in this Next
 * port too — `@reya/auth`'s `TenantSession` has no `lineAccountId` field at
 * all, and grepping the PHP source confirms `$_SESSION['line_account_id']`
 * is never assigned anywhere in the whole repo (see
 * (tenant)/crm-dashboard-advanced/queries.ts's own "lineAccountId scoping"
 * finding for the identical dead session key). Reproduced as a plain
 * `sessionCurrentBotId ?? 0`, not a fabricated second session field.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED FINDING — tier 3 (admin_users lookup) always no-ops on the
 * committed schema, same root cause as consent-queries.ts's own finding
 * ═══════════════════════════════════════════════════════════════════════
 * `admin_users` is a PLATFORM-level table (see
 * database/migration_2026-05-25_tenant_template.sql's own header: "Platform-
 * level tables (admin_users, dev_logs, etc.) live in `reya_platform`") — it
 * does NOT exist inside a tenant DB. On a tenant DB matching the committed
 * template, tier 3's `SELECT line_account_id FROM admin_users WHERE id = ?`
 * ALWAYS throws ("table doesn't exist"), caught and swallowed exactly like
 * PHP's own `catch (\Throwable $e) {}`, falling through to tier 4. This is a
 * pre-existing PHP defect, out of scope to fix (database/** is off-limits) —
 * implemented + independently tested below anyway (a fake DB CAN answer this
 * query in a unit test, exercising the tier directly), matching this batch's
 * brief ("unit test each tier independently").
 *
 * `shop_tax_info` itself and `line_accounts` (tier 4's target) DO both exist
 * on the committed tenant schema (database/migration_2026-05-25_tenant_template.sql
 * lines ~2145 and ~128 respectively) — unlike `welcome_settings`/
 * `admin_users`, reads/writes against THOSE two tables are not expected to
 * degrade on a fresh tenant DB.
 */

export interface ShopTaxInfoView {
  businessName: string;
  businessNameEn: string;
  taxId: string;
  branchCode: string;
  address: string;
  phone: string;
  email: string;
  logoUrl: string;
  authorizedSigner: string;
  signerPosition: string;
  isVatRegistered: boolean;
  /** Kept as the raw DECIMAL(4,2) string mysql2/Kysely returns (see generated `Decimal = ColumnType<string, number | string>`), matching PHP's own un-cast `$row['default_vat_rate']` (a PDO string) for the POPULATED-row path. See `DEFAULT_SHOP_TAX_INFO`'s own doc for why the NO-row default is a different literal. */
  defaultVatRate: string;
}

/**
 * Verbatim port of both PHP blocks' hardcoded default-row shape (shop-tax.php
 * lines 31-44 / api/shop-tax.php lines 75-89) — used when no `shop_tax_info`
 * row exists yet for the resolved tenant.
 *
 * `defaultVatRate: '7'`, NOT `'7.00'` — CONFIRMED via `php -r
 * 'var_dump((string)7.00);'` -> `string(1) "7"`. Both PHP sources hardcode
 * `'default_vat_rate' => 7.00` as a PHP float literal, and
 * shop-tax.php's own `$h = fn ($v) => htmlspecialchars((string)$v, ...)`
 * helper casts it through PHP's float-to-string conversion before it ever
 * reaches the `<input value="...">` attribute — which drops the trailing
 * `.00` for a whole-number float. This is DIFFERENT from the POPULATED-row
 * path (a real DECIMAL(4,2) column, returned by PDO/mysql2 as the
 * zero-padded string `"7.00"` — see `ShopTaxInfoView.defaultVatRate`'s own
 * doc) — the two code paths render two different strings for the "same"
 * conceptual 7.00 value, and this default matches the specific one PHP's
 * default (no-row) path actually emits. Found + flagged by
 * infra/e2e/lib/extract.mjs's `settings:shop-tax` parity extractor (see that
 * file's own module doc) — fixed here, not left failing.
 */
export const DEFAULT_SHOP_TAX_INFO: ShopTaxInfoView = {
  businessName: '',
  businessNameEn: '',
  taxId: '',
  branchCode: '00000',
  address: '',
  phone: '',
  email: '',
  logoUrl: '',
  authorizedSigner: '',
  signerPosition: '',
  isVatRegistered: false,
  defaultVatRate: '7',
};

/**
 * Shared banner-text constants for ../_lib/shop-tax-actions.ts's
 * `saveShopTaxInfoAction()` and ../_components/ShopTaxTab.tsx's
 * `showAlert()`-equivalent fallback text. Declared HERE (a plain module, no
 * `'use server'` directive) rather than in shop-tax-actions.ts because a
 * `'use server'` file may only export async functions — Next.js/Turbopack
 * rejects a production build otherwise ("Only async functions are allowed to
 * be exported in a 'use server' file", confirmed via `next build`) — see
 * shop-tax-actions.ts's own module doc for the full note.
 */
export const NO_LINE_ACCOUNT_MESSAGE = 'ไม่พบบัญชี LINE — กรุณาเลือกบัญชีก่อน';
export const SAVE_SUCCESS_MESSAGE = 'บันทึกข้อมูลกิจการสำเร็จ — เอกสารใหม่จะแสดงข้อมูลนี้';

export interface ResolveLineAccountIdParams {
  db: Kysely<TenantDB>;
  /** `$_SESSION['current_bot_id']` — `session.currentBotId` from `requireTenantPageContext()`. */
  sessionCurrentBotId: number | null;
  /** `$_SESSION['user_id']` — `session.adminUserId` (always a positive int for an authenticated `TenantSession`). */
  sessionAdminUserId: number;
  /**
   * `$_GET['line_account_id']` (api/shop-tax.php only). ALWAYS `undefined`
   * from every real call site in this port: the tab's own inline `<script>`
   * never sends this query param (confirmed by reading its `fetch(...)` call
   * — plain `action=save`, no `line_account_id` in the URL), and a Next.js
   * Server Action has no query-string of its own to read one from. Kept as
   * an explicit, independently-testable parameter per this batch's brief
   * ("unit test each tier independently") rather than silently dropped.
   */
  queryLineAccountId?: number | null;
}

/**
 * Ported from the tenant-resolution preamble shared by api/shop-tax.php's
 * `get`/`save` actions (and, per this file's module doc, reused here in
 * place of includes/settings/shop-tax.php's own slightly-shorter inline
 * copy too):
 *
 *   $lineAccountId = (int)($_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 0);
 *   if ($lineAccountId <= 0 && isset($_GET['line_account_id'])) { $lineAccountId = (int)$_GET['line_account_id']; }
 *   if ($lineAccountId <= 0 && !empty($_SESSION['user_id'])) { try { ...admin_users lookup... } catch {} }
 *   if ($lineAccountId <= 0) { try { ...first is_active=1 line_accounts row... } catch {} }
 */
export async function resolveLineAccountId(params: ResolveLineAccountIdParams): Promise<number> {
  const { db, sessionCurrentBotId, sessionAdminUserId, queryLineAccountId } = params;

  // Tier 1: $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 0 — see module doc, the 2nd half is dead in this port.
  let lineAccountId = sessionCurrentBotId ?? 0;

  // Tier 2: $_GET['line_account_id'] — always unset from this port's real call sites, see param doc.
  if (lineAccountId <= 0 && queryLineAccountId !== undefined && queryLineAccountId !== null) {
    lineAccountId = queryLineAccountId;
  }

  // Tier 3: admin_users.line_account_id lookup by $_SESSION['user_id'] — always no-ops on the committed schema, see module doc.
  if (lineAccountId <= 0 && sessionAdminUserId > 0) {
    try {
      const result = await sql<{ line_account_id: number | null }>`
        SELECT line_account_id FROM admin_users WHERE id = ${sessionAdminUserId} LIMIT 1
      `.execute(db);
      lineAccountId = Number(result.rows[0]?.line_account_id ?? 0);
    } catch {
      // Mirrors `catch (\Throwable $e) {}`.
    }
  }

  // Tier 4: first is_active=1 line_accounts row, order by id asc.
  if (lineAccountId <= 0) {
    try {
      const result = await sql<{ id: number }>`
        SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1
      `.execute(db);
      lineAccountId = Number(result.rows[0]?.id ?? 0);
    } catch {
      // Mirrors `catch (\Throwable $e) {}`.
    }
  }

  return lineAccountId;
}

interface ShopTaxInfoRow {
  business_name: string | null;
  business_name_en: string | null;
  tax_id: string | null;
  branch_code: string | null;
  address: string | null;
  phone: string | null;
  email: string | null;
  logo_url: string | null;
  authorized_signer: string | null;
  signer_position: string | null;
  is_vat_registered: number | null;
  default_vat_rate: string | null;
}

/**
 * Ported from `includes/settings/shop-tax.php`'s own read (line 45's `if
 * ($lineAccountId > 0) { try { SELECT * FROM shop_tax_info WHERE
 * line_account_id = ? ... } catch (\Throwable $e) {} }`) — when
 * `lineAccountId <= 0`, PHP never even attempts the query and the caller's
 * `$row` stays the plain hardcoded defaults (no error, no `line_account_id`
 * key set); mirrored below by short-circuiting before issuing any SQL.
 */
export async function getShopTaxInfo(db: Kysely<TenantDB>, lineAccountId: number): Promise<ShopTaxInfoView> {
  if (lineAccountId <= 0) {
    return DEFAULT_SHOP_TAX_INFO;
  }

  try {
    const result = await sql<ShopTaxInfoRow>`SELECT * FROM shop_tax_info WHERE line_account_id = ${lineAccountId}`.execute(db);
    const row = result.rows[0];
    if (!row) {
      return DEFAULT_SHOP_TAX_INFO;
    }
    return {
      businessName: row.business_name ?? '',
      businessNameEn: row.business_name_en ?? '',
      taxId: row.tax_id ?? '',
      branchCode: row.branch_code ?? '00000',
      address: row.address ?? '',
      phone: row.phone ?? '',
      email: row.email ?? '',
      logoUrl: row.logo_url ?? '',
      authorizedSigner: row.authorized_signer ?? '',
      signerPosition: row.signer_position ?? '',
      isVatRegistered: Boolean(row.is_vat_registered),
      // Unreachable given the schema's `NOT NULL DEFAULT 7.00` constraint (a
      // real row's `default_vat_rate` can never be SQL NULL) — kept
      // defensive-but-consistent with every sibling field's own `?? ''`
      // NULL-column fallback (not `DEFAULT_SHOP_TAX_INFO`'s `'7'`, which is
      // specifically the NO-ROW-AT-ALL default, a different PHP code path —
      // see that constant's own doc).
      defaultVatRate: row.default_vat_rate ?? '',
    };
  } catch {
    // Mirrors `catch (\Throwable $e) { /* table may not exist on stale envs */ }` -> defaults.
    return DEFAULT_SHOP_TAX_INFO;
  }
}
