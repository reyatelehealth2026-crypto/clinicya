import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * platform-queries.ts — read port of includes/settings/platform.php's
 * "Load existing connections" block (lines 52-65):
 *
 *   $facebookAccounts = [];
 *   $tiktokAccounts = [];
 *   try { $facebookAccounts = $db->query("SELECT * FROM facebook_accounts ORDER BY id DESC")->fetchAll(...); }
 *   catch (Exception $e) {}
 *   try { $tiktokAccounts = $db->query("SELECT * FROM tiktok_shop_accounts ORDER BY id DESC")->fetchAll(...); }
 *   catch (Exception $e) {}
 *
 *   $fbWebhookUrl = rtrim(BASE_URL, '/') . '/facebook-webhook.php';
 *   $ttWebhookUrl = rtrim(BASE_URL, '/') . '/tiktok-webhook.php';
 *
 * `facebook_accounts`/`tiktok_shop_accounts` ARE present on the committed
 * tenant schema (database/migration_2026-05-25_tenant_template.sql +
 * packages/db/src/generated/tenant-db.d.ts's `FacebookAccounts`/
 * `TiktokShopAccounts`) — platform.php's own page-load
 * `CREATE TABLE IF NOT EXISTS ...` guard (lines 12-50, mirrors
 * install/migration_add_platforms.php) is NOT reproduced, matching the
 * precedent already set by ../_components/ShopTaxTab.tsx/EmailTab.tsx for
 * tables that are already on the committed schema.
 *
 * Neither query is scoped by `line_account_id` in real PHP (confirmed by
 * reading the full source) — every connected page/shop in the tenant DB is
 * listed, unfiltered, matching database-per-tenant isolation (ADR-001):
 * "all rows in this DB" already IS "all rows for this tenant". Reproduced
 * verbatim: no `line_account_id`/`currentBotId` parameter on either query
 * function below.
 *
 * BASE_URL: config/config.php's literal `https://clinicya.re-ya.com/`,
 * used here only for the two informational "set this in Meta/TikTok
 * Partner Center" webhook-URL info boxes — display-only text, not any
 * functional redirect or served-asset path. Kept as an env-override-with-
 * literal-fallback constant (same convention as
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/notify.ts's
 * `CHECKOUT_NOTIFY_BASE_URL`), NOT the request-derived `headers()` approach
 * (tenant)/articles/_lib/seo.ts uses — that pattern exists there because a
 * wrong host would literally break a served asset URL (see uploadSlip.ts's
 * documented bug-fix comment); here a "wrong" display host is merely a
 * copy/paste inconvenience for the admin configuring Meta/TikTok's
 * dashboard, not a functional break, and platform.php itself has no
 * equivalent per-tenant-subdomain bug-fix note to port. Deliberately does
 * NOT depend on `next/headers` — this file's `getFacebookWebhookUrl()`/
 * `getTiktokWebhookUrl()` are called from ../_components/PlatformTab.tsx,
 * which is reached via ../page.tsx's shared, out-of-this-batch's-broad-edit
 * `page.test.tsx` (that test file does not mock `next/headers`).
 */

export interface FacebookAccountRow {
  id: number;
  name: string;
  page_id: string;
  app_id: string | null;
  app_secret: string | null;
  page_access_token: string;
  verify_token: string | null;
  is_active: number;
}

export interface TiktokAccountRow {
  id: number;
  name: string;
  shop_id: string;
  app_key: string | null;
  app_secret: string | null;
  access_token: string;
  refresh_token: string | null;
  shop_cipher: string | null;
  is_active: number;
}

export interface FacebookAccountView {
  id: number;
  name: string;
  pageId: string;
  appId: string;
  appSecret: string;
  pageAccessToken: string;
  verifyToken: string;
  isActive: boolean;
}

export interface TiktokAccountView {
  id: number;
  name: string;
  shopId: string;
  appKey: string;
  appSecret: string;
  accessToken: string;
  refreshToken: string;
  shopCipher: string;
  isActive: boolean;
}

export async function getFacebookAccounts(db: Kysely<TenantDB>): Promise<FacebookAccountRow[]> {
  try {
    const result = await sql<FacebookAccountRow>`SELECT * FROM facebook_accounts ORDER BY id DESC`.execute(db);
    return [...result.rows];
  } catch {
    return [];
  }
}

export async function getTiktokAccounts(db: Kysely<TenantDB>): Promise<TiktokAccountRow[]> {
  try {
    const result = await sql<TiktokAccountRow>`SELECT * FROM tiktok_shop_accounts ORDER BY id DESC`.execute(db);
    return [...result.rows];
  } catch {
    return [];
  }
}

export function mapFacebookAccountRow(row: FacebookAccountRow): FacebookAccountView {
  return {
    id: row.id,
    name: row.name,
    pageId: row.page_id,
    appId: row.app_id ?? '',
    appSecret: row.app_secret ?? '',
    pageAccessToken: row.page_access_token,
    verifyToken: row.verify_token ?? '',
    isActive: Boolean(row.is_active),
  };
}

export function mapTiktokAccountRow(row: TiktokAccountRow): TiktokAccountView {
  return {
    id: row.id,
    name: row.name,
    shopId: row.shop_id,
    appKey: row.app_key ?? '',
    appSecret: row.app_secret ?? '',
    accessToken: row.access_token,
    refreshToken: row.refresh_token ?? '',
    shopCipher: row.shop_cipher ?? '',
    isActive: Boolean(row.is_active),
  };
}

function resolvePlatformWebhookBaseUrl(): string {
  const env = process.env.PLATFORM_WEBHOOK_BASE_URL;
  return env && env.trim() !== '' ? env.replace(/\/+$/, '') : 'https://clinicya.re-ya.com';
}

export function getFacebookWebhookUrl(): string {
  return `${resolvePlatformWebhookBaseUrl()}/facebook-webhook.php`;
}

export function getTiktokWebhookUrl(): string {
  return `${resolvePlatformWebhookBaseUrl()}/tiktok-webhook.php`;
}
