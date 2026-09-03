'use server';

import { sql } from 'kysely';
import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../../users/_lib/session';
import { saveShopLogoUpload } from './general-upload';

/**
 * general-actions.ts — Server Action port of settings.php's
 * `$postTab === 'general' && $tableExists` save block (lines 150-254),
 * reached via general.php's `<form method="POST" enctype="multipart/form-data"
 * id="settings-general-form">`:
 *
 *   $bankAccounts = json_encode(['banks' => array_map(fn($n,$a,$h) =>
 *       ['name'=>$n,'account'=>$a,'holder'=>$h], $_POST['bank_name'] ?? [],
 *       $_POST['bank_account'] ?? [], $_POST['bank_holder'] ?? [])]);
 *   try {
 *       $logoUrl = $_POST['shop_logo'] ?? '';
 *       if (valid uploaded logo_file) { ...move file...; $logoUrl = <public URL>; }
 *       $updateFields = [ shop_name, shop_logo, welcome_message, shop_address, shop_email,
 *           shipping_fee, free_shipping_min, bank_accounts, promptpay_number, contact_phone,
 *           is_open, cod_enabled, cod_fee, auto_confirm_payment, order_data_source,
 *           line_id, facebook_url, instagram_url ];
 *       if ($hasAccountCol && $currentBotId) {
 *           $existingId = SELECT id FROM shop_settings WHERE line_account_id = ?;
 *           if ($existingId) { UPDATE shop_settings SET <all fields> WHERE line_account_id = ?; }
 *           else { INSERT INTO shop_settings (<all fields>, line_account_id) VALUES (...); }
 *       } else { ...legacy id=1 branch... }
 *       $activityLogger->logData(...); // NOT reproduced, see below
 *       header('Location: settings.php?tab=general&saved=1'); exit;
 *   } catch (Exception $e) {
 *       $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
 *       $activeTab = 'general';
 *   }
 *
 * Per this batch's brief: `$hasAccountCol && $currentBotId` is ALWAYS true
 * in this port (see ./general-queries.ts's module doc — the committed
 * schema always has `line_account_id`, and `currentBotId` is always at
 * least 1 via `?? 1`) — the `else` legacy id=1 branch is DEAD given that,
 * and is NOT ported here.
 *
 * Real PHP has NO `$success` string for this save path at all (grepped the
 * full block) — the only success indication is the `?saved=1` query param,
 * which settings.php renders as the fixed banner text "บันทึกการตั้งค่าสำเร็จ!"
 * (settings.php lines 872-876). Ported as a redirect to
 * `/settings?tab=general&message=บันทึกการตั้งค่าสำเร็จ!` on success — same
 * Next-native `?message=`/`?error=` redirect convention already applied to
 * welcome/email (see ./welcome-actions.ts's module doc), carrying that
 * EXACT literal Thai string (not any differently-worded string, since none
 * exists on this path). `redirect()` is called OUTSIDE the try/catch for
 * the same reason documented there.
 *
 * Intentional gap (flagged, not silently dropped): PHP's
 * `$activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่าทั่วไปร้านค้า', ...)`
 * audit write is NOT reproduced — matches every other ported Phase 2 action.
 */

const SUCCESS_MESSAGE = 'บันทึกการตั้งค่าสำเร็จ!';

/** PHP `(float) $value` semantics: leading optional sign + digits + optional fractional/exponent tail, else 0. Absent field -> fallback. */
function phpFloatCast(value: FormDataEntryValue | null, fallback: number): number {
  if (value === null) return fallback;
  const str = String(value).trim();
  const match = str.match(/^[+-]?(\d+(\.\d+)?|\.\d+)([eE][+-]?\d+)?/);
  return match ? Number.parseFloat(match[0]) : 0;
}

/** Port of includes/shop-data-source.php's normalizeShopOrderDataSource(): lowercase-trim, 'odoo' iff exactly 'odoo', else 'shop'. */
function normalizeShopOrderDataSource(value: FormDataEntryValue | null): 'shop' | 'odoo' {
  const mode = String(value ?? '')
    .toLowerCase()
    .trim();
  return mode === 'odoo' ? 'odoo' : 'shop';
}

interface ZippedBankRow {
  name: string | null;
  account: string | null;
  holder: string | null;
}

/**
 * Port of `array_map(fn($n,$a,$h) => [...], $names, $accounts, $holders)` —
 * PHP's `array_map` over multiple arrays iterates up to the LONGEST array's
 * length, padding shorter ones with `null` for the missing entries.
 */
function zipBankRows(names: FormDataEntryValue[], accounts: FormDataEntryValue[], holders: FormDataEntryValue[]): ZippedBankRow[] {
  const maxLen = Math.max(names.length, accounts.length, holders.length);
  const rows: ZippedBankRow[] = [];
  for (let i = 0; i < maxLen; i++) {
    rows.push({
      name: names[i] !== undefined ? String(names[i]) : null,
      account: accounts[i] !== undefined ? String(accounts[i]) : null,
      holder: holders[i] !== undefined ? String(holders[i]) : null,
    });
  }
  return rows;
}

export async function saveGeneralSettingsAction(formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  const bankAccountsJson = JSON.stringify({
    banks: zipBankRows(formData.getAll('bank_name[]'), formData.getAll('bank_account[]'), formData.getAll('bank_holder[]')),
  });

  let errorMessage: string | null = null;

  try {
    let logoUrl = String(formData.get('shop_logo') ?? '');
    const logoFile = formData.get('logo_file');
    if (logoFile instanceof File && logoFile.size > 0) {
      const uploaded = await saveShopLogoUpload(logoFile, currentBotId);
      if (uploaded.logoUrl) {
        logoUrl = uploaded.logoUrl;
      }
    }

    const fields = {
      shop_name: String(formData.get('shop_name') ?? ''),
      shop_logo: logoUrl,
      welcome_message: String(formData.get('welcome_message') ?? ''),
      shop_address: String(formData.get('shop_address') ?? ''),
      shop_email: String(formData.get('shop_email') ?? ''),
      shipping_fee: phpFloatCast(formData.get('shipping_fee'), 50),
      free_shipping_min: phpFloatCast(formData.get('free_shipping_min'), 500),
      bank_accounts: bankAccountsJson,
      promptpay_number: String(formData.get('promptpay_number') ?? ''),
      contact_phone: String(formData.get('contact_phone') ?? ''),
      is_open: formData.get('is_open') !== null ? 1 : 0,
      cod_enabled: formData.get('cod_enabled') !== null ? 1 : 0,
      cod_fee: phpFloatCast(formData.get('cod_fee'), 0),
      auto_confirm_payment: formData.get('auto_confirm_payment') !== null ? 1 : 0,
      order_data_source: normalizeShopOrderDataSource(formData.get('order_data_source')),
      line_id: String(formData.get('line_id') ?? ''),
      facebook_url: String(formData.get('facebook_url') ?? ''),
      instagram_url: String(formData.get('instagram_url') ?? ''),
    };

    const existing = await sql<{ id: number }>`SELECT id FROM shop_settings WHERE line_account_id = ${currentBotId}`.execute(db);
    const existingId = existing.rows[0]?.id;

    if (existingId) {
      await sql`
        UPDATE shop_settings SET
          shop_name = ${fields.shop_name}, shop_logo = ${fields.shop_logo}, welcome_message = ${fields.welcome_message},
          shop_address = ${fields.shop_address}, shop_email = ${fields.shop_email}, shipping_fee = ${fields.shipping_fee},
          free_shipping_min = ${fields.free_shipping_min}, bank_accounts = ${fields.bank_accounts},
          promptpay_number = ${fields.promptpay_number}, contact_phone = ${fields.contact_phone},
          is_open = ${fields.is_open}, cod_enabled = ${fields.cod_enabled}, cod_fee = ${fields.cod_fee},
          auto_confirm_payment = ${fields.auto_confirm_payment}, order_data_source = ${fields.order_data_source},
          line_id = ${fields.line_id}, facebook_url = ${fields.facebook_url}, instagram_url = ${fields.instagram_url}
        WHERE line_account_id = ${currentBotId}
      `.execute(db);
    } else {
      await sql`
        INSERT INTO shop_settings (
          shop_name, shop_logo, welcome_message, shop_address, shop_email, shipping_fee, free_shipping_min,
          bank_accounts, promptpay_number, contact_phone, is_open, cod_enabled, cod_fee, auto_confirm_payment,
          order_data_source, line_id, facebook_url, instagram_url, line_account_id
        ) VALUES (
          ${fields.shop_name}, ${fields.shop_logo}, ${fields.welcome_message}, ${fields.shop_address}, ${fields.shop_email},
          ${fields.shipping_fee}, ${fields.free_shipping_min}, ${fields.bank_accounts}, ${fields.promptpay_number},
          ${fields.contact_phone}, ${fields.is_open}, ${fields.cod_enabled}, ${fields.cod_fee}, ${fields.auto_confirm_payment},
          ${fields.order_data_source}, ${fields.line_id}, ${fields.facebook_url}, ${fields.instagram_url}, ${currentBotId}
        )
      `.execute(db);
    }
  } catch (err) {
    errorMessage = err instanceof Error ? err.message : String(err);
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=general&error=${encodeURIComponent(`เกิดข้อผิดพลาด: ${errorMessage}`)}`);
  }

  redirect(`/settings?tab=general&message=${encodeURIComponent(SUCCESS_MESSAGE)}`);
}
