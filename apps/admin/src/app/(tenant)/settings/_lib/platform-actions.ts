'use server';

import { createHmac } from 'node:crypto';
import { sql } from 'kysely';
import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../../users/_lib/session';
import type { FacebookAccountRow, TiktokAccountRow } from './platform-queries';

/**
 * platform-actions.ts — Server Action port of settings.php's 6
 * `$_POST['action']` handlers for the platform tab (lines 311-481):
 * `save_facebook` / `delete_facebook` / `test_facebook` / `save_tiktok` /
 * `delete_tiktok` / `test_tiktok`. Every one of them, in real PHP, sets
 * `$activeTab = 'platform'` and falls through to a same-request re-render
 * (NO `header('Location: ...')` anywhere in this block — grepped) — ported
 * as a redirect back to `/settings?tab=platform&message=...`/`&error=...`,
 * same Next-native adaptation already applied to welcome/email/general (see
 * ./welcome-actions.ts's module doc for the full rationale).
 *
 * Every PHP handler here has exactly ONE coarse `try { ... } catch
 * (Exception $e) { $error = '<prefix>: ' . $e->getMessage(); }` — including
 * its own upfront validation `throw new Exception(...)` for missing
 * required fields, which therefore surfaces under the SAME generic prefix
 * as a genuine DB failure would. Mirrored exactly: each action below has a
 * single try/catch producing one error prefix per action (not a separate
 * "validation error" vs "DB error" message).
 *
 * `redirect()` calls are OUTSIDE every try/catch (it works by throwing a
 * Next-internal control-flow error — see ./welcome-actions.ts's module doc).
 *
 * Intentional gap (flagged, not silently dropped): PHP's
 * `$activityLogger->logData(...)` audit writes on save_facebook/save_tiktok
 * are NOT reproduced — matches every other ported Phase 2 action (see
 * (tenant)/users/actions.ts's own note).
 */

/** PHP `(int) $value` semantics: leading optional sign + digits, else 0. */
function phpIntCast(value: FormDataEntryValue | null): number {
  if (value === null) return 0;
  const match = String(value).trim().match(/^[+-]?\d+/);
  return match ? Number.parseInt(match[0], 10) : 0;
}

/** PHP `trim($_POST['x'] ?? '')`. */
function trimmedField(value: FormDataEntryValue | null): string {
  return String(value ?? '').trim();
}

async function fetchWithTimeout(url: string, timeoutMs: number): Promise<Response> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Facebook Messenger
// ─────────────────────────────────────────────────────────────────────────

export async function saveFacebookAccountAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();

  const fbId = phpIntCast(formData.get('fb_id'));
  const fields = {
    name: trimmedField(formData.get('name')),
    page_id: trimmedField(formData.get('page_id')),
    app_id: trimmedField(formData.get('app_id')),
    app_secret: trimmedField(formData.get('app_secret')),
    page_access_token: trimmedField(formData.get('page_access_token')),
    verify_token: trimmedField(formData.get('verify_token')),
    is_active: formData.get('is_active') !== null ? 1 : 0,
  };

  let successMessage: string | null = null;
  let errorMessage: string | null = null;

  try {
    if (fields.name === '' || fields.page_id === '' || fields.page_access_token === '') {
      throw new Error('กรุณากรอกชื่อเพจ, Page ID และ Page Access Token');
    }

    if (fbId > 0) {
      await sql`
        UPDATE facebook_accounts
        SET name = ${fields.name}, page_id = ${fields.page_id}, app_id = ${fields.app_id},
            app_secret = ${fields.app_secret}, page_access_token = ${fields.page_access_token},
            verify_token = ${fields.verify_token}, is_active = ${fields.is_active}
        WHERE id = ${fbId}
      `.execute(db);
      successMessage = 'อัปเดตการเชื่อมต่อ Facebook Messenger สำเร็จ';
    } else {
      await sql`
        INSERT INTO facebook_accounts (name, page_id, app_id, app_secret, page_access_token, verify_token, is_active)
        VALUES (${fields.name}, ${fields.page_id}, ${fields.app_id}, ${fields.app_secret}, ${fields.page_access_token}, ${fields.verify_token}, ${fields.is_active})
      `.execute(db);
      successMessage = 'เพิ่มการเชื่อมต่อ Facebook Messenger สำเร็จ';
    }
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    errorMessage = `เกิดข้อผิดพลาด: ${message}`;
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=platform&error=${encodeURIComponent(errorMessage)}`);
  }
  redirect(`/settings?tab=platform&message=${encodeURIComponent(successMessage ?? '')}`);
}

export async function deleteFacebookAccountAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const fbId = phpIntCast(formData.get('fb_id'));

  let errorMessage: string | null = null;
  try {
    await sql`DELETE FROM facebook_accounts WHERE id = ${fbId}`.execute(db);
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    errorMessage = `ลบไม่สำเร็จ: ${message}`;
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=platform&error=${encodeURIComponent(errorMessage)}`);
  }
  redirect(`/settings?tab=platform&message=${encodeURIComponent('ลบการเชื่อมต่อ Facebook Messenger แล้ว')}`);
}

interface FacebookDebugTokenResponse {
  data?: {
    is_valid?: boolean;
    profile_id?: string | number;
    scopes?: string[];
    error?: { message?: string };
  };
  name?: string;
  error?: { message?: string };
}

export async function testFacebookConnectionAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const fbId = phpIntCast(formData.get('fb_id'));

  let successMessage: string | null = null;
  let errorMessage: string | null = null;

  try {
    const result = await sql<FacebookAccountRow>`SELECT * FROM facebook_accounts WHERE id = ${fbId}`.execute(db);
    const row = result.rows[0];
    if (!row) {
      throw new Error('ไม่พบเพจที่ต้องการทดสอบ (บันทึกก่อนทดสอบ)');
    }

    const pageToken = row.page_access_token;
    const appId = (row.app_id ?? '').trim();
    const appSecret = (row.app_secret ?? '').trim();

    // Validate the page token via debug_token (uses the app token, so it works even when the page
    // token lacks pages_read_engagement — a messaging-only token is still valid). Falls back to
    // /me?fields=name if app creds are unset. Mirrors settings.php lines 366-374 exactly.
    const verifyUrl =
      appId !== '' && appSecret !== ''
        ? `https://graph.facebook.com/v19.0/debug_token?input_token=${encodeURIComponent(pageToken)}&access_token=${encodeURIComponent(`${appId}|${appSecret}`)}`
        : `https://graph.facebook.com/v19.0/me?fields=name&access_token=${encodeURIComponent(pageToken)}`;

    let res: FacebookDebugTokenResponse = {};
    try {
      const resp = await fetchWithTimeout(verifyUrl, 15000);
      res = (await resp.json().catch(() => ({}))) as FacebookDebugTokenResponse;
    } catch (fetchErr) {
      const message = fetchErr instanceof Error ? fetchErr.message : String(fetchErr);
      errorMessage = `เชื่อมต่อไม่สำเร็จ: ${message}`;
    }

    if (errorMessage === null) {
      const d = res.data;
      if (d && d.is_valid !== undefined) {
        if (d.is_valid) {
          const pid = String(d.profile_id ?? '');
          if (pid !== '' && pid !== String(row.page_id)) {
            errorMessage = `Token ใช้ได้ แต่เป็นของเพจอื่น (Page ID ${pid}) — ต้องตรงกับ ${row.page_id}`;
          } else {
            const scopes = Array.isArray(d.scopes) ? d.scopes.slice(0, 8).join(', ') : '';
            const hasMsg = scopes.includes('pages_messaging');
            successMessage =
              `เชื่อมต่อ Facebook สำเร็จ: token ใช้งานได้${hasMsg ? ' (มีสิทธิ์ pages_messaging ✓)' : ''}` +
              (scopes !== '' ? ` — scopes: ${scopes}` : '');
          }
        } else {
          const msg = d.error?.message ?? 'token หมดอายุหรือถูกเพิกถอน';
          errorMessage = `เชื่อมต่อไม่สำเร็จ: ${msg}`;
        }
      } else if (res.name) {
        successMessage = `เชื่อมต่อ Facebook สำเร็จ: ${res.name}`;
      } else {
        const msg = res.error?.message ?? '';
        errorMessage = `เชื่อมต่อไม่สำเร็จ: ${msg !== '' ? msg : 'ตรวจสอบ Page Access Token / App Secret'}`;
      }
    }
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    errorMessage = `ทดสอบไม่สำเร็จ: ${message}`;
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=platform&error=${encodeURIComponent(errorMessage)}`);
  }
  redirect(`/settings?tab=platform&message=${encodeURIComponent(successMessage ?? '')}`);
}

// ─────────────────────────────────────────────────────────────────────────
// TikTok Shop
// ─────────────────────────────────────────────────────────────────────────

export async function saveTiktokAccountAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();

  const ttId = phpIntCast(formData.get('tt_id'));
  const fields = {
    name: trimmedField(formData.get('name')),
    shop_id: trimmedField(formData.get('shop_id')),
    app_key: trimmedField(formData.get('app_key')),
    app_secret: trimmedField(formData.get('app_secret')),
    access_token: trimmedField(formData.get('access_token')),
    refresh_token: trimmedField(formData.get('refresh_token')),
    shop_cipher: trimmedField(formData.get('shop_cipher')),
    is_active: formData.get('is_active') !== null ? 1 : 0,
  };

  let successMessage: string | null = null;
  let errorMessage: string | null = null;

  try {
    if (fields.name === '' || fields.shop_id === '' || fields.access_token === '') {
      throw new Error('กรุณากรอกชื่อร้าน, Shop ID และ Access Token');
    }

    if (ttId > 0) {
      await sql`
        UPDATE tiktok_shop_accounts
        SET name = ${fields.name}, shop_id = ${fields.shop_id}, app_key = ${fields.app_key},
            app_secret = ${fields.app_secret}, access_token = ${fields.access_token},
            refresh_token = ${fields.refresh_token}, shop_cipher = ${fields.shop_cipher}, is_active = ${fields.is_active}
        WHERE id = ${ttId}
      `.execute(db);
      successMessage = 'อัปเดตการเชื่อมต่อ TikTok Shop สำเร็จ';
    } else {
      await sql`
        INSERT INTO tiktok_shop_accounts (name, shop_id, app_key, app_secret, access_token, refresh_token, shop_cipher, is_active)
        VALUES (${fields.name}, ${fields.shop_id}, ${fields.app_key}, ${fields.app_secret}, ${fields.access_token}, ${fields.refresh_token}, ${fields.shop_cipher}, ${fields.is_active})
      `.execute(db);
      successMessage = 'เพิ่มการเชื่อมต่อ TikTok Shop สำเร็จ';
    }
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    errorMessage = `เกิดข้อผิดพลาด: ${message}`;
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=platform&error=${encodeURIComponent(errorMessage)}`);
  }
  redirect(`/settings?tab=platform&message=${encodeURIComponent(successMessage ?? '')}`);
}

export async function deleteTiktokAccountAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const ttId = phpIntCast(formData.get('tt_id'));

  let errorMessage: string | null = null;
  try {
    await sql`DELETE FROM tiktok_shop_accounts WHERE id = ${ttId}`.execute(db);
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    errorMessage = `ลบไม่สำเร็จ: ${message}`;
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=platform&error=${encodeURIComponent(errorMessage)}`);
  }
  redirect(`/settings?tab=platform&message=${encodeURIComponent('ลบการเชื่อมต่อ TikTok Shop แล้ว')}`);
}

// ─────────────────────────────────────────────────────────────────────────
// TikTok Shop request signing — MINIMAL port of classes/TikTokShopAPI.php,
// only what test_tiktok's `$api->getConversations(1)` call needs (one
// signed GET to /customer_service/conversations). Messaging send, buyer
// profile, token refresh, and webhook validation are OUT OF SCOPE for this
// tab (unused here) — see this batch's brief.
// ─────────────────────────────────────────────────────────────────────────

const TIKTOK_API_BASE_URL = 'https://open-api.tiktokglobalshop.com';
const TIKTOK_API_VERSION = '202309';

/**
 * Port of TikTokShopAPI::signRequest(): HMAC-SHA256(app_secret, path +
 * sorted_query_params_string + body_string), where the param string is
 * `key1value1key2value2...` over params SORTED BY KEY (PHP `ksort`), with
 * NO delimiter between key/value pairs.
 */
function tiktokSignRequest(path: string, queryParams: Record<string, string>, bodyString: string, appSecret: string): string {
  const sortedKeys = Object.keys(queryParams).sort();
  let paramStr = '';
  for (const key of sortedKeys) {
    paramStr += key + queryParams[key];
  }
  const toSign = path + paramStr + bodyString;
  return createHmac('sha256', appSecret).update(toSign).digest('hex');
}

interface TiktokConversationsResponse {
  code?: number | string;
  message?: string;
  error?: unknown;
  success?: boolean;
  [key: string]: unknown;
}

/**
 * Port of TikTokShopAPI::getConversations($pageSize)'s underlying signed
 * GET (private get()/parseResponse()) — ONLY the shape settings.php's
 * `test_tiktok` handler actually calls: `$api->getConversations(1)`, no
 * cursor.
 */
async function tiktokGetConversations(account: TiktokAccountRow, pageSize: number): Promise<TiktokConversationsResponse> {
  const path = '/customer_service/conversations';
  const timestamp = String(Math.floor(Date.now() / 1000));

  const allParams: Record<string, string> = {
    page_size: String(pageSize),
    app_key: account.app_key ?? '',
    access_token: account.access_token,
    timestamp,
    version: TIKTOK_API_VERSION,
  };
  if (account.shop_cipher !== null && account.shop_cipher !== undefined) {
    allParams.shop_cipher = account.shop_cipher;
  }

  // Sign — exclude access_token from the signature params, per TikTok docs (TikTokShopAPI::get()).
  const signParams = { ...allParams };
  delete signParams.access_token;
  allParams.sign = tiktokSignRequest(path, signParams, '', account.app_secret ?? '');

  const url = `${TIKTOK_API_BASE_URL}${path}?${new URLSearchParams(allParams).toString()}`;

  const resp = await fetchWithTimeout(url, 30000);
  const body = (await resp.json().catch(() => ({}))) as TiktokConversationsResponse;
  const httpOk = resp.status >= 200 && resp.status < 300;
  const codeOk = body.code === 0 || body.code === '0';
  return { ...body, success: httpOk && codeOk };
}

export async function testTiktokConnectionAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const ttId = phpIntCast(formData.get('tt_id'));

  let successMessage: string | null = null;
  let errorMessage: string | null = null;

  try {
    const result = await sql<TiktokAccountRow>`SELECT * FROM tiktok_shop_accounts WHERE id = ${ttId}`.execute(db);
    const row = result.rows[0];
    if (!row) {
      throw new Error('ไม่พบร้านที่ต้องการทดสอบ (บันทึกก่อนทดสอบ)');
    }

    const res = await tiktokGetConversations(row, 1);
    if (res.success) {
      successMessage = `เชื่อมต่อ TikTok Shop สำเร็จ: ${row.name}`;
    } else {
      const msg = typeof res.message === 'string' && res.message !== '' ? res.message : typeof res.error === 'string' ? res.error : '';
      errorMessage = `เชื่อมต่อไม่สำเร็จ: ${msg !== '' ? msg : 'ตรวจสอบ Access Token / App Key / Shop Cipher'}`;
    }
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    errorMessage = `ทดสอบไม่สำเร็จ: ${message}`;
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=platform&error=${encodeURIComponent(errorMessage)}`);
  }
  redirect(`/settings?tab=platform&message=${encodeURIComponent(successMessage ?? '')}`);
}
