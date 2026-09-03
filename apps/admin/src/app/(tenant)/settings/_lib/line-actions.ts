'use server';

import { sql } from 'kysely';
import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../../users/_lib/session';
import {
  getLineAccountById,
  buildLineWebhookUrl,
  LINE_SUCCESS_MESSAGES,
  LINE_TEST_ACCOUNT_NOT_FOUND_MESSAGE,
  LINE_TEST_CONNECTION_FAILED_MESSAGE,
  type LineBotMode,
} from './line-queries';

/**
 * line-actions.ts — Server Actions for settings.php's LINE-account POST
 * branches (lines 258-309, all inside the `if ($_SERVER['REQUEST_METHOD'] ===
 * 'POST')` block, none of them the AJAX-only branch) plus the AJAX-only
 * `test_line_connection` handler (lines 126-143). Every DB write here goes
 * through `classes/LineAccountManager.php` in real PHP — read that class in
 * full (330 LOC) before touching this file; it has several confirmed,
 * non-obvious, must-replicate-verbatim quirks documented per-function below.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * Redirect convention — page.tsx's shared `?message=`/`?error=`, NOT line.php's own `?success=`
 * ═══════════════════════════════════════════════════════════════════════
 * Real PHP redirects to `settings.php?tab=line&success=created|updated|deleted|default`
 * and line.php's own inline `<?php if (isset($_GET['success'])): ?>` banner
 * (lines 55-60) maps that key back to Thai text. This Next port's shared
 * (tenant)/settings/page.tsx (owned by a different builder, not touched
 * here) reads `?message=`/`?error=` instead — same convention
 * ../_lib/welcome-actions.ts and ../_lib/email-actions.ts already
 * established for this settings hub — so every redirect below carries the
 * pre-resolved Thai text (`LINE_SUCCESS_MESSAGES.*`, ../_lib/line-queries.ts)
 * directly in `?message=`, not a `?success=` key page.tsx does not read.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * NO try/catch on the 4 CRUD actions — confirmed, intentional (do not "fix")
 * ═══════════════════════════════════════════════════════════════════════
 * None of settings.php's `create_line`/`update_line`/`delete_line`/
 * `set_default_line` branches (lines 258-309) wrap their
 * `LineAccountManager` call in a try/catch, unlike ../_lib/welcome-actions.ts's
 * / ../_lib/email-actions.ts's own save handlers. A thrown PDOException there
 * is an unhandled PHP fatal error. Mirrored below by NOT catching anything —
 * a thrown DB error from any of `createLineAccountAction`/
 * `updateLineAccountAction`/`deleteLineAccountAction`/`setDefaultLineAccountAction`
 * propagates (the returned Promise rejects) rather than producing a friendly
 * `?error=` redirect. `testLineConnectionAction` is the one exception — see
 * its own doc below, matching the AJAX-only handler's own try/catch
 * (lines 130-142).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * Server Action call shape — mixed, one convention per action (brief allows either)
 * ═══════════════════════════════════════════════════════════════════════
 * `createLineAccountAction`/`updateLineAccountAction` take a plain
 * `LineAccountFormInput` object (not `FormData`) — same
 * `startTransition(() => action(input))` imperative-call convention as
 * (tenant)/pharmacists/_components/PharmacistFormModal.tsx's
 * `savePharmacistAction`/../ShopTaxTab.tsx's `saveShopTaxInfoAction`. Chosen
 * over native `<form action={fn}>` binding because the create/edit modal is
 * ONE physical form whose bound action must switch between create vs update
 * depending on which account (if any) is being edited — a plain function
 * reference computed in JS (`isEdit ? updateLineAccountAction : ...`) is
 * simpler than juggling a single FormData-shaped action with a hidden
 * `action`/`id` field the way PHP's own single `<form id="lineAccountForm">`
 * does (line.php lines 179-181).
 *
 * `deleteLineAccountAction`/`setDefaultLineAccountAction` DO take `FormData`
 * — `setDefaultLineAccountAction` is bound natively via
 * `<form action={setDefaultLineAccountAction}>` (Next requires a
 * FormData-shaped action for that binding), matching line.php's own
 * `<form method="POST">…<button type="submit">` set-default button (lines
 * 144-150) almost verbatim — no client JS needed for that one action at all.
 * `deleteLineAccountAction` is invoked imperatively (after a `window.confirm()`
 * gate, matching `deleteLineAccount()`'s own `confirm(...)` — lines 459-466)
 * with a manually-built `FormData`, for the same reason ../../line-groups/actions.ts's
 * `leaveGroupAction` takes `FormData` even though it's never bound to a
 * literal `<form>` — plain `Number.parseInt(String(formData.get('id') ?? ''), 10) || 0` id parsing.
 *
 * `testLineConnectionAction` takes a plain `id: number` and returns a JSON
 * result object directly (no redirect) — matches `testLineConnection()`'s
 * own `fetch()`-and-toggle-a-result-panel UX (line.php lines 475-505): this
 * one stays same-page/in-place, unlike every other action in this file.
 */

export interface LineAccountFormInput {
  name: string;
  channel_id?: string | null;
  channel_secret: string;
  channel_access_token: string;
  basic_id?: string | null;
  liff_id?: string | null;
  is_default?: boolean;
  bot_mode?: LineBotMode | string;
  /**
   * Collected by line.php's own modal (welcome_message textarea + 3
   * checkboxes, "Settings" sub-tab, lines 302-330) but NEVER written by
   * either `LineAccountManager::createAccount()` (columns hardcoded to
   * `['name','channel_id','channel_secret','channel_access_token','basic_id','is_default']`
   * + conditional `bot_mode`/`liff_id` only) or `::updateAccount()`
   * (`$allowedFields` is the identical set). CONFIRMED, live production
   * behavior: these 4 fields are silently discarded on every save today.
   * Accepted here (so the form UI can still collect them, matching real
   * PHP's own UI) but deliberately NEVER read by createLineAccountAction/
   * updateLineAccountAction below — do not "fix" this by wiring them into
   * the INSERT/UPDATE column list.
   */
  welcome_message?: string;
  auto_reply_enabled?: boolean;
  shop_enabled?: boolean;
  receipt_points_enabled?: boolean;
}

/**
 * Verbatim port of `LineAccountManager::createAccount()` (lines 108-174) as
 * reached by settings.php's `create_line` branch (lines 258-276).
 *
 * Column list — `bot_mode`/`liff_id` are included UNCONDITIONALLY here
 * (unlike the PHP source's own runtime `columnExists()` check, lines 111-112,
 * 125-133): both columns ARE present on the generated tenant schema this
 * port targets (packages/db/src/generated/tenant-db.d.ts's `LineAccounts`
 * interface), so `hasBotMode`/`hasLiffId` are always `true` in practice for
 * every tenant DB this code actually runs against — a static equivalent of
 * the PHP runtime check, not a behavioral fork. The per-page LIFF id columns
 * (`liff_main_id`/`liff_consent_id`/…) are correctly OMITTED — they exist
 * neither on the generated schema nor as form fields in line.php's modal.
 *
 * `is_active` is NOT part of `createAccount()`'s column list at all (only
 * `updateAccount()`'s `$allowedFields` includes it) — a brand-new account's
 * `is_active` column is left at the schema's own DEFAULT regardless of the
 * "เปิดใช้งาน" checkbox's state in the create form. CONFIRMED, replicated:
 * this action never reads/writes `is_active`.
 *
 * `is_default` — included directly in the INSERT (`data.is_default ?? 0`,
 * PHP line 122), AND (redundantly, but replicated verbatim) re-applied via
 * the same two-step `setDefault()` UPDATE (lines 162-164, 282-288) when
 * truthy — unsets every other row's `is_default` first, then sets this new
 * row's. Not merely cosmetic: this is what makes "create as default" also
 * demote a previously-default account.
 *
 * `webhook_url` — always computed + written in a THIRD statement after
 * insert (lines 166-170), regardless of `is_default`. See ../_lib/line-queries.ts's
 * `buildLineWebhookUrl()` doc for the BASE_URL normalization note.
 *
 * NOT reproduced (flagged, not silently dropped): `TenantActivity::log(...)`
 * (lines 152-159, a best-effort platform-owner activity feed + Telegram
 * write) — out of scope, matching every other ported Phase 2 action's own
 * "ActivityLogger/TenantActivity best-effort audit writes are out of scope"
 * note (see ../_lib/welcome-actions.ts's module doc).
 */
export async function createLineAccountAction(input: LineAccountFormInput): Promise<void> {
  const { db } = await requireTenantPageContext();

  const name = input.name;
  const channelId = input.channel_id ?? null;
  const channelSecret = input.channel_secret;
  const channelAccessToken = input.channel_access_token;
  const basicId = input.basic_id ?? null;
  const isDefault = input.is_default ? 1 : 0;
  const botMode = input.bot_mode ?? 'shop';
  const liffId = input.liff_id ?? null;

  const insertResult = await sql`
    INSERT INTO line_accounts (name, channel_id, channel_secret, channel_access_token, basic_id, is_default, bot_mode, liff_id)
    VALUES (${name}, ${channelId}, ${channelSecret}, ${channelAccessToken}, ${basicId}, ${isDefault}, ${botMode}, ${liffId})
  `.execute(db);

  const accountId = Number(insertResult.insertId ?? 0);

  if (isDefault) {
    await sql`UPDATE line_accounts SET is_default = 0`.execute(db);
    await sql`UPDATE line_accounts SET is_default = 1 WHERE id = ${accountId}`.execute(db);
  }

  const webhookUrl = buildLineWebhookUrl(accountId);
  await sql`UPDATE line_accounts SET webhook_url = ${webhookUrl} WHERE id = ${accountId}`.execute(db);

  redirect(`/settings?tab=line&message=${encodeURIComponent(LINE_SUCCESS_MESSAGES.created)}`);
}

/**
 * Verbatim port of `LineAccountManager::updateAccount()` (lines 179-243) as
 * reached by settings.php's `update_line` branch (lines 277-296).
 *
 * `allowedFields` = `[name, channel_id, channel_secret, channel_access_token,
 * basic_id, is_active]` + `bot_mode`/`liff_id` (both present on the target
 * schema — see createLineAccountAction's doc for the same
 * static-vs-runtime-columnExists note). `is_default` is deliberately ABSENT
 * from this list — CONFIRMED: the main UPDATE's SET clause never touches
 * `is_default` directly, only the follow-up `setDefault()` call does (same
 * as create). Because `setDefault()` only ever PROMOTES (never demotes), a
 * quirk falls out that this port replicates exactly: unchecking "ตั้งเป็น
 * บัญชีหลัก" while editing an already-default account does NOT unset it —
 * there is no code path that clears `is_default` to 0 for a row other than
 * via a DIFFERENT row's promotion. Do not "fix" this by adding an else-branch
 * that clears `is_default` when the checkbox is unchecked.
 *
 * `welcome_message`/`auto_reply_enabled`/`shop_enabled`/`receipt_points_enabled`
 * — see `LineAccountFormInput`'s own doc; same drop as create, confirmed via
 * `updateAccount()`'s `$allowedFields`.
 */
export async function updateLineAccountAction(id: number, input: LineAccountFormInput & { is_active?: boolean }): Promise<void> {
  const { db } = await requireTenantPageContext();

  const name = input.name;
  const channelId = input.channel_id ?? null;
  const channelSecret = input.channel_secret;
  const channelAccessToken = input.channel_access_token;
  const basicId = input.basic_id ?? null;
  const isActive = input.is_active ? 1 : 0;
  const botMode = input.bot_mode ?? 'shop';
  const liffId = input.liff_id ?? null;

  await sql`
    UPDATE line_accounts
    SET name = ${name}, channel_id = ${channelId}, channel_secret = ${channelSecret},
        channel_access_token = ${channelAccessToken}, basic_id = ${basicId}, is_active = ${isActive},
        bot_mode = ${botMode}, liff_id = ${liffId}
    WHERE id = ${id}
  `.execute(db);

  if (input.is_default) {
    await sql`UPDATE line_accounts SET is_default = 0`.execute(db);
    await sql`UPDATE line_accounts SET is_default = 1 WHERE id = ${id}`.execute(db);
  }

  redirect(`/settings?tab=line&message=${encodeURIComponent(LINE_SUCCESS_MESSAGES.updated)}`);
}

/**
 * Verbatim port of `LineAccountManager::deleteAccount()` (lines 271-277) as
 * reached by settings.php's `delete_line` branch (lines 297-302).
 *
 * `DELETE FROM line_accounts WHERE id = ? AND is_default = 0` — the
 * `AND is_default = 0` guard means deleting the current default account is a
 * silent no-op (0 rows affected, no error). settings.php NEVER checks
 * `deleteAccount()`'s boolean return value before redirecting — it always
 * redirects to the success message regardless of whether a row was actually
 * removed. Both replicated verbatim: no rowCount check, no conditional
 * `?error=` path (PHP has none here).
 */
export async function deleteLineAccountAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const id = Number.parseInt(String(formData.get('id') ?? ''), 10) || 0;

  await sql`DELETE FROM line_accounts WHERE id = ${id} AND is_default = 0`.execute(db);

  redirect(`/settings?tab=line&message=${encodeURIComponent(LINE_SUCCESS_MESSAGES.deleted)}`);
}

/**
 * Verbatim port of `LineAccountManager::setDefault()` (lines 282-288) as
 * reached by settings.php's `set_default_line` branch (lines 303-309): a
 * two-step "unset every row's is_default, then set this one" — never a
 * single conditional UPDATE.
 */
export async function setDefaultLineAccountAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const id = Number.parseInt(String(formData.get('id') ?? ''), 10) || 0;

  await sql`UPDATE line_accounts SET is_default = 0`.execute(db);
  await sql`UPDATE line_accounts SET is_default = 1 WHERE id = ${id}`.execute(db);

  redirect(`/settings?tab=line&message=${encodeURIComponent(LINE_SUCCESS_MESSAGES.default)}`);
}

export interface LineTestConnectionResult {
  success: boolean;
  data?: Record<string, unknown>;
  message?: string;
  /** Only set on the outer try/catch's genuine-exception path — see doc below; the UI never reads this key (matches real PHP's client JS). */
  error?: string;
}

/**
 * Verbatim port of `LineAccountManager::testConnection()` (lines 312-329) as
 * reached by settings.php's AJAX-only `test_line_connection` branch (lines
 * 126-143, itself wrapped in its own try/catch — the ONE action in this file
 * that has one, matching real PHP exactly).
 *
 *   $account = getAccountById($id);              // SELECT * FROM line_accounts WHERE id = ?
 *   if (!$account) return ['success'=>false,'message'=>'Account not found'];
 *   $line = new LineAPI($account['channel_access_token'], $account['channel_secret']);
 *   $result = $line->getBotInfo();                // GET {apiEndpoint}/info, Authorization: Bearer <token>
 *                                                  // classes/LineAPI.php line 10: apiEndpoint = 'https://api.line.me/v2/bot'
 *   if (isset($result['userId'])) {
 *       UPDATE line_accounts SET picture_url = ? WHERE id = ?;   // $result['pictureUrl'] ?? null
 *       return ['success'=>true,'data'=>$result];
 *   }
 *   return ['success'=>false,'message'=>$result['message'] ?? 'Connection failed'];
 *
 * `requireTenantPageContext()` is called OUTSIDE the try/catch (same
 * "redirect() must never be swallowed" reasoning as every other ported
 * action's module doc — this call can itself `redirect()` to the login page
 * for an unauthenticated caller, and that must propagate, not become a
 * `{success:false, error:...}` JSON body).
 *
 * `LineAPI::getBotInfo()` uses `curl_exec()` + `json_decode(..., true)` —
 * neither throws on a network failure or invalid JSON; a broken connection
 * yields `$response = false` -> `json_decode(false, true)` -> `null`, which
 * then falls straight into the `!isset($result['userId'])` branch as
 * `'Connection failed'`, NOT into the outer try/catch's exception path. This
 * port's `fetch()` DOES reject on a genuine network failure (no curl-style
 * silent-false equivalent in the Fetch API) — that rejection is caught by
 * THIS function's own try/catch below and surfaces as `{success:false,
 * error: message}` instead. A flagged, minor divergence from the exact PHP
 * failure shape for the "network unreachable" edge only; the two behaviorally
 * load-bearing paths this batch's acceptance criteria test — `userId`
 * present (success + DB write) vs. absent (failure, no DB write) — are
 * unaffected.
 */
export async function testLineConnectionAction(id: number): Promise<LineTestConnectionResult> {
  const { db } = await requireTenantPageContext();

  try {
    const account = await getLineAccountById(db, id);
    if (!account) {
      return { success: false, message: LINE_TEST_ACCOUNT_NOT_FOUND_MESSAGE };
    }

    const response = await fetch('https://api.line.me/v2/bot/info', {
      headers: { Authorization: `Bearer ${account.channel_access_token}` },
    });
    const result = (await response.json().catch(() => null)) as Record<string, unknown> | null;

    if (result && typeof result === 'object' && 'userId' in result) {
      const pictureUrl = typeof result.pictureUrl === 'string' ? result.pictureUrl : null;
      await sql`UPDATE line_accounts SET picture_url = ${pictureUrl} WHERE id = ${id}`.execute(db);
      return { success: true, data: result };
    }

    const message = typeof result?.message === 'string' ? result.message : LINE_TEST_CONNECTION_FAILED_MESSAGE;
    return { success: false, message };
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    return { success: false, error: message };
  }
}
