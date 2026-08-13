import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * general-queries.ts — read port of includes/shop/general.php's settings
 * resolution (lines 7-50; NOTE the naming trap this file's own module doc
 * warns about: `general.php` lives under `includes/shop/`, not
 * `includes/settings/`, despite being a settings tab):
 *
 *   $settings = [];
 *   if ($tableExists) {
 *       try {
 *           if ($hasAccountCol && $currentBotId) {
 *               $settings = SELECT * FROM shop_settings WHERE line_account_id = ? [fetch()];
 *           }
 *           if (!$settings) {
 *               $settings = SELECT * FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1 [fetch()];
 *           }
 *       } catch (Exception $e) { $settings = []; }
 *   }
 *   if (!$settings) {
 *       $settings = [ shop_name=>'LINE Shop', shop_logo=>'', welcome_message=>'ยินดีต้อนรับ!',
 *           shipping_fee=>50, free_shipping_min=>500, bank_accounts=>'{"banks":[]}',
 *           promptpay_number=>'', contact_phone=>'', is_open=>1, cod_enabled=>0, cod_fee=>0,
 *           auto_confirm_payment=>0, order_data_source=>'shop', shop_address=>'', shop_email=>'',
 *           line_id=>'', facebook_url=>'', instagram_url=>'' ];
 *   }
 *   $bankAccounts = json_decode($settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];
 *
 * `$tableExists`/`$hasAccountCol` are settings.php's own runtime
 * `SHOW TABLES`/`SHOW COLUMNS` + `CREATE TABLE IF NOT EXISTS`/`ALTER TABLE`
 * probes (settings.php lines 51-118) — CONFIRMED (per this batch's brief)
 * that every column that block would add is ALREADY present on the
 * committed Kysely `ShopSettings` tenant-db type
 * (packages/db/src/generated/tenant-db.d.ts). NOT ported here, matching the
 * precedent ../_components/ShopTaxTab.tsx/EmailTab.tsx already set for
 * committed-schema tables: `shop_settings` unconditionally exists with every
 * column general.php reads, so `$tableExists`/`$hasAccountCol` are always
 * effectively `true`/`true` in this port — simplified away rather than
 * reproduced as always-true booleans.
 *
 * `$currentBotId` here is the SIMPLE `session.currentBotId ?? 1` value
 * already resolved by ../page.tsx (settings.php's own top-level
 * `$currentBotId = $_SESSION['current_bot_id'] ?? 1;`) — NOT
 * ../_lib/shop-tax-queries.ts's `resolveLineAccountId()` 4-tier resolver
 * (that helper is shop-tax-specific and more elaborate than what
 * general.php's real source actually does; do not reuse it here).
 *
 * The two-phase default resolution below is replicated EXACTLY as PHP
 * structures it (not flattened into a single per-field `row.x ?? y`
 * mapping): first resolve a "source" object (either the raw DB row, whose
 * columns CAN be SQL NULL even though the table itself always exists, or —
 * only when NO row is found at all — the hardcoded defaults object above),
 * THEN apply each `renderField()`/`renderToggle()` call's own `?? <default>`
 * fallback uniformly on top of whichever source was resolved. This matters
 * for `shop_name`/`welcome_message` specifically: a genuinely-NULL DB column
 * renders as `''` (renderField's own fallback), while "no row found at all"
 * renders as `'LINE Shop'`/`'ยินดีต้อนรับ!'` (the defaults object's
 * concrete values) — two different results a single flattened `?? 'LINE
 * Shop'` mapping would conflate.
 */

export interface BankAccount {
  name: string;
  account: string;
  holder: string;
}

export interface GeneralSettingsView {
  shopName: string;
  shopLogo: string;
  welcomeMessage: string;
  shopAddress: string;
  shopEmail: string;
  shippingFee: number;
  freeShippingMin: number;
  bankAccounts: BankAccount[];
  promptpayNumber: string;
  contactPhone: string;
  isOpen: boolean;
  codEnabled: boolean;
  codFee: number;
  autoConfirmPayment: boolean;
  orderDataSource: string;
  lineId: string;
  facebookUrl: string;
  instagramUrl: string;
}

interface ShopSettingsSource {
  shop_name?: string | null;
  shop_logo?: string | null;
  welcome_message?: string | null;
  shipping_fee?: number | string | null;
  free_shipping_min?: number | string | null;
  bank_accounts?: string | null;
  promptpay_number?: string | null;
  contact_phone?: string | null;
  is_open?: number | null;
  cod_enabled?: number | null;
  cod_fee?: number | string | null;
  auto_confirm_payment?: number | null;
  order_data_source?: string | null;
  shop_address?: string | null;
  shop_email?: string | null;
  line_id?: string | null;
  facebook_url?: string | null;
  instagram_url?: string | null;
}

/** Verbatim port of general.php lines 27-48 ("Default values" — used only when NO shop_settings row exists at all). */
const FALLBACK_SOURCE: ShopSettingsSource = {
  shop_name: 'LINE Shop',
  shop_logo: '',
  welcome_message: 'ยินดีต้อนรับ!',
  shipping_fee: 50,
  free_shipping_min: 500,
  bank_accounts: '{"banks":[]}',
  promptpay_number: '',
  contact_phone: '',
  is_open: 1,
  cod_enabled: 0,
  cod_fee: 0,
  auto_confirm_payment: 0,
  order_data_source: 'shop',
  shop_address: '',
  shop_email: '',
  line_id: '',
  facebook_url: '',
  instagram_url: '',
};

function numOr(value: number | string | null | undefined, fallback: number): number {
  if (value === null || value === undefined) return fallback;
  const n = typeof value === 'string' ? Number(value) : value;
  return Number.isFinite(n) ? n : fallback;
}

/** `json_decode($settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? []`. */
function parseBankAccounts(raw: string | null | undefined): BankAccount[] {
  try {
    const parsed = JSON.parse(raw ?? '{"banks":[]}') as { banks?: unknown };
    if (!Array.isArray(parsed.banks)) return [];
    return parsed.banks.map((b) => {
      const bank = (b ?? {}) as Partial<BankAccount>;
      return { name: bank.name ?? '', account: bank.account ?? '', holder: bank.holder ?? '' };
    });
  } catch {
    return [];
  }
}

function toView(source: ShopSettingsSource): GeneralSettingsView {
  return {
    shopName: source.shop_name ?? '',
    shopLogo: source.shop_logo ?? '',
    welcomeMessage: source.welcome_message ?? '',
    shopAddress: source.shop_address ?? '',
    shopEmail: source.shop_email ?? '',
    shippingFee: numOr(source.shipping_fee, 50),
    freeShippingMin: numOr(source.free_shipping_min, 500),
    bankAccounts: parseBankAccounts(source.bank_accounts),
    promptpayNumber: source.promptpay_number ?? '',
    contactPhone: source.contact_phone ?? '',
    isOpen: Boolean(source.is_open ?? 1),
    codEnabled: Boolean(source.cod_enabled ?? 0),
    codFee: numOr(source.cod_fee, 0),
    autoConfirmPayment: Boolean(source.auto_confirm_payment ?? 0),
    orderDataSource: source.order_data_source ?? 'shop',
    lineId: source.line_id ?? '',
    facebookUrl: source.facebook_url ?? '',
    instagramUrl: source.instagram_url ?? '',
  };
}

export async function getGeneralSettings(db: Kysely<TenantDB>, currentBotId: number | null): Promise<GeneralSettingsView> {
  let row: ShopSettingsSource | undefined;

  try {
    if (currentBotId) {
      const byAccount = await sql<ShopSettingsSource>`SELECT * FROM shop_settings WHERE line_account_id = ${currentBotId}`.execute(db);
      row = byAccount.rows[0];
    }
    if (!row) {
      const fallbackRow = await sql<ShopSettingsSource>`SELECT * FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1`.execute(db);
      row = fallbackRow.rows[0];
    }
  } catch {
    row = undefined;
  }

  return toView(row ?? FALLBACK_SOURCE);
}
