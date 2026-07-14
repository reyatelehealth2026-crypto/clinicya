'use server';

import { sql } from 'kysely';
import { requireTenantPageContext } from '../../users/_lib/session';
import {
  resolveLineAccountId,
  getShopTaxInfo,
  NO_LINE_ACCOUNT_MESSAGE,
  SAVE_SUCCESS_MESSAGE,
  type ShopTaxInfoView,
} from './shop-tax-queries';

/**
 * shop-tax-actions.ts — Server Action port of api/shop-tax.php's
 * `case 'save'` (lines 95-145), reached in real PHP by
 * `includes/settings/shop-tax.php`'s own inline `<script>` doing
 * `fetch('api/shop-tax.php?action=save', {method:'POST', body:
 * JSON.stringify(payload)})` — a WHOLLY SEPARATE REST endpoint from
 * settings.php's own `$_POST['action']` switch (settings.php's switch has
 * no `shop_tax`/`save_shop_tax` case at all; grepped). Ported as a Server
 * Action per this batch's brief (small-mutation-as-Server-Action-from-day-1
 * convention, same as (tenant)/templates and (tenant)/loyalty-members), NOT
 * as a mirrored `/api/shop-tax` Route Handler.
 *
 *   $i = shop_tax_input(); // JSON body if Content-Type: application/json, else $_POST
 *   INSERT INTO shop_tax_info (line_account_id, business_name, business_name_en,
 *       tax_id, branch_code, address, phone, email, logo_url, authorized_signer,
 *       signer_position, is_vat_registered, default_vat_rate)
 *     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
 *     ON DUPLICATE KEY UPDATE <every column except line_account_id> = VALUES(...);
 *   // uq_shop_tax_line_account UNIQUE KEY (line_account_id) is what makes this an upsert.
 *   $logger->logData('update', 'ปรับข้อมูลภาษีของร้าน', [...]); // NOT reproduced, see below
 *   SELECT * FROM shop_tax_info WHERE line_account_id = ? // re-read, returned as `data`
 *
 * Tenant resolution: delegates to ../_lib/shop-tax-queries.ts's
 * `resolveLineAccountId()` — see that file's module doc for the full
 * 4-tier chain + the two CONFIRMED findings (dead `$_SESSION['line_account_id']`
 * tier, always-no-op `admin_users` tier). Before touching the DB at all,
 * replicates api/shop-tax.php's own early exit (lines 43-47):
 *
 *   if ($lineAccountId <= 0) { http_response_code(401);
 *     echo json_encode(['success'=>false,'error'=>'no_line_account',
 *       'message'=>'ไม่พบบัญชี LINE — กรุณาเลือกบัญชีก่อน']); exit; }
 *
 * Truncation — byte-for-byte, not character-for-character: every `substr()`
 * call in the real PHP is PHP's native (BYTE-oriented) `substr()`, not
 * `mb_substr()`. For pure-ASCII input this is indistinguishable from a
 * character-count truncation, but for Thai business names (multi-byte UTF-8,
 * 3 bytes/character) a byte-oriented cutoff CAN land mid-character — a real,
 * if surely unintended, PHP behavior. Replicated via `phpSubstrBytes()`
 * below (`Buffer`-based), not a naive `.slice(0, n)` JS character-count
 * truncation. One Node-vs-PHP wrinkle, flagged: Node's `Buffer#toString('utf8')`
 * replaces a dangling partial multi-byte sequence at the truncation boundary
 * with U+FFFD, whereas PHP would silently store the raw (possibly invalid)
 * truncated bytes as-is. Reproducing PHP's raw-byte storage exactly would
 * require sending a `Buffer` instead of a decoded `string` to MySQL — not
 * done here; this only differs from real PHP in the single-character sliver
 * at the exact byte-255/20/50/100/500 boundary of a multi-byte string, not
 * anywhere else.
 *
 * `branch_code` — `substr(..., 0, 20) ?: '00000'`: PHP's `?:` (short
 * ternary) re-applies the `'00000'` default when the POST value, AFTER
 * truncation, is empty/falsy (e.g. an explicit `branch_code: ''` in the
 * payload) — NOT only when the field is entirely absent. Mirrored via
 * `phpSubstrBytes(input.branch_code ?? '00000', 20) || '00000'`.
 *
 * `address` is the ONE field PHP does NOT `substr()` — just `(string)(...
 * ?? '')`, matching the column's `text` type (no VARCHAR length cap).
 * Mirrored as a plain `String(...)` cast, no truncation.
 *
 * `is_vat_registered`: `!empty($i['is_vat_registered']) ? 1 : 0` — the tab's
 * own inline `<script>` always sends this as a JSON integer 1/0 (`payload.is_vat_registered
 * = checkbox.checked ? 1 : 0`), so PHP's `empty()` check only ever sees 1 or
 * 0 in practice; `isPhpTruthy()` below implements the general `!empty()`
 * semantics anyway (0/'0'/''/null/undefined/false -> falsy) for fidelity
 * with what a hand-crafted payload could send.
 *
 * `default_vat_rate`: `(float)($i['default_vat_rate'] ?? 7.00)` — PHP's
 * leading-numeric-prefix float cast, mirrored by `phpFloatCast()` (same
 * style as ../users/actions.ts's/./email-actions.ts's own `phpIntCast()`
 * helpers, just parsing an optional decimal/exponent tail too).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * Intentional, flagged deviation: the real PHP `case 'save':` block has NO
 * try/catch of its own around `$stmt->execute([...])` — an uncaught
 * PDOException there is a raw, unhandled PHP fatal error (whatever that
 * renders as depends on `display_errors`), NOT a clean JSON error body. The
 * calling JS's own `try/catch` around `fetch()` only catches NETWORK-level
 * failures, so a real DB-write failure in production PHP would most likely
 * surface to the user as `showAlert('err', 'เครือข่ายมีปัญหา: ' + err.message)`
 * (a `res.json()` parse failure on a non-JSON 500 body), not a clean
 * "บันทึกไม่สำเร็จ" message. A Next.js Server Action that throws does not
 * have a comparable "raw PHP fatal page" to reproduce (it becomes a
 * framework-level error digest with no parseable body for the client to
 * read) — reproducing that exactly would just break this tab's Client
 * Component worse than PHP's real failure mode does, for no behavioral
 * parity gained. This port therefore DOES wrap the INSERT in a try/catch
 * (returning `{success:false, error: message}` instead), preserving the
 * "inline banner, same `showAlert()` UX" contract this batch's brief
 * explicitly asks for. Flagged in the build report, not silently ported
 * as "PHP has no try/catch here either."
 *
 * Intentional gap (flagged, not silently dropped): PHP's
 * `$logger->logData('update', 'ปรับข้อมูลภาษีของร้าน', [...])` audit write is
 * NOT reproduced — ActivityLogger/TenantActivity best-effort audit writes
 * are out of scope for this batch, matching every other ported Phase 2
 * action (see (tenant)/users/actions.ts's own note, ../_lib/welcome-actions.ts's
 * own note).
 *
 * `NO_LINE_ACCOUNT_MESSAGE`/`SAVE_SUCCESS_MESSAGE` live in ./shop-tax-queries.ts,
 * not here, and are re-exported nowhere from this module — a real Next.js
 * build-time constraint (confirmed via `next build`, Turbopack): "Only async
 * functions are allowed to be exported in a 'use server' file." A plain
 * `export const` string literal in a `'use server'` module fails the
 * production build outright, even though it type-checks fine and passes
 * under Jest (Jest never runs the `'use server'` bundler transform). This
 * file exports exactly one thing: `saveShopTaxInfoAction`.
 *
 * No `next/cache` `revalidatePath()` call on success (unlike e.g.
 * (tenant)/crm-dashboard-advanced/actions.ts's own mutations): (1)
 * ../_components/ShopTaxTab.tsx is an uncontrolled, client-local form that
 * deliberately does NOT re-sync from this action's returned `data` anyway
 * (see that file's module doc — matches real PHP's own
 * `showAlert()`-only-never-updates-fields behavior), so there is no
 * currently-mounted UI that would benefit from a cache bust; and (2)
 * `next/cache`'s real module needs Next server internals (`TextEncoder`
 * et al.) not present under plain jsdom, and this settings route's
 * `page.test.tsx` (owned by settingsHubAndCore, outside this batch's
 * allowed paths) does not mock `next/cache` the way
 * crm-dashboard-advanced/page.test.tsx does — importing it here would break
 * that unrelated, frozen test file's module graph the moment `page.tsx`
 * statically imports `./_components/ShopTaxTab.tsx`. Flagged in the build
 * report rather than silently added.
 */

export interface ShopTaxSaveInput {
  business_name?: unknown;
  business_name_en?: unknown;
  tax_id?: unknown;
  branch_code?: unknown;
  address?: unknown;
  phone?: unknown;
  email?: unknown;
  logo_url?: unknown;
  authorized_signer?: unknown;
  signer_position?: unknown;
  is_vat_registered?: unknown;
  default_vat_rate?: unknown;
}

export interface ShopTaxSaveResult {
  success: boolean;
  message?: string;
  error?: string;
  data?: ShopTaxInfoView;
}

/** PHP native (byte-oriented) `substr($value, 0, $maxBytes)` — see module doc's truncation note. */
function phpSubstrBytes(value: unknown, maxBytes: number): string {
  const str = String(value ?? '');
  const buf = Buffer.from(str, 'utf8');
  if (buf.length <= maxBytes) return str;
  return buf.subarray(0, maxBytes).toString('utf8');
}

/** PHP `!empty($value)` semantics: false/0/'0'/''/null/undefined/[] are falsy, everything else truthy. */
function isPhpTruthy(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return false;
  if (value === 0 || value === '0' || value === '') return false;
  if (Array.isArray(value) && value.length === 0) return false;
  return true;
}

/** PHP `(float)$value` semantics: leading optional sign + digits + optional fractional/exponent part, else 0. */
function phpFloatCast(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  const str = String(value ?? '').trim();
  const match = str.match(/^[+-]?(\d+(\.\d+)?|\.\d+)([eE][+-]?\d+)?/);
  return match ? Number.parseFloat(match[0]) : 0;
}

export async function saveShopTaxInfoAction(input: ShopTaxSaveInput): Promise<ShopTaxSaveResult> {
  const { db, session } = await requireTenantPageContext();

  const lineAccountId = await resolveLineAccountId({
    db,
    sessionCurrentBotId: session.currentBotId,
    sessionAdminUserId: session.adminUserId,
  });

  if (lineAccountId <= 0) {
    return { success: false, error: 'no_line_account', message: NO_LINE_ACCOUNT_MESSAGE };
  }

  const businessName = phpSubstrBytes(input.business_name ?? '', 255);
  const businessNameEn = phpSubstrBytes(input.business_name_en ?? '', 255);
  const taxId = phpSubstrBytes(input.tax_id ?? '', 20);
  const branchCode = phpSubstrBytes(input.branch_code ?? '00000', 20) || '00000';
  const address = String(input.address ?? '');
  const phone = phpSubstrBytes(input.phone ?? '', 50);
  const email = phpSubstrBytes(input.email ?? '', 100);
  const logoUrl = phpSubstrBytes(input.logo_url ?? '', 500);
  const authorizedSigner = phpSubstrBytes(input.authorized_signer ?? '', 255);
  const signerPosition = phpSubstrBytes(input.signer_position ?? '', 100);
  const isVatRegistered = isPhpTruthy(input.is_vat_registered) ? 1 : 0;
  const defaultVatRate = phpFloatCast(input.default_vat_rate ?? 7.0);

  try {
    await sql`
      INSERT INTO shop_tax_info
        (line_account_id, business_name, business_name_en, tax_id, branch_code, address,
         phone, email, logo_url, authorized_signer, signer_position,
         is_vat_registered, default_vat_rate)
      VALUES (${lineAccountId}, ${businessName}, ${businessNameEn}, ${taxId}, ${branchCode}, ${address},
              ${phone}, ${email}, ${logoUrl}, ${authorizedSigner}, ${signerPosition},
              ${isVatRegistered}, ${defaultVatRate})
      ON DUPLICATE KEY UPDATE
        business_name = VALUES(business_name),
        business_name_en = VALUES(business_name_en),
        tax_id = VALUES(tax_id),
        branch_code = VALUES(branch_code),
        address = VALUES(address),
        phone = VALUES(phone),
        email = VALUES(email),
        logo_url = VALUES(logo_url),
        authorized_signer = VALUES(authorized_signer),
        signer_position = VALUES(signer_position),
        is_vat_registered = VALUES(is_vat_registered),
        default_vat_rate = VALUES(default_vat_rate)
    `.execute(db);
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    return { success: false, error: message, message };
  }

  const data = await getShopTaxInfo(db, lineAccountId);
  return { success: true, message: SAVE_SUCCESS_MESSAGE, data };
}
