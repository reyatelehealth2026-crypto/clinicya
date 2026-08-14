import type { LineMessage } from '@reya/line';

/**
 * flexTemplates.ts — the two hand-inlined Flex JSON literals from
 * `api/inbox-v2.php`'s `case 'send_batch_messages':` (lines 3169-3487).
 * These are NOT built via `classes/FlexTemplates.php` (that class backs the
 * dispense flow only, ported separately to `packages/line/src/flex.ts` by
 * the dispenseChain batch) — `send_batch_messages` inlines its own two Flex
 * bubbles directly in the PHP case body, so this port inlines the
 * equivalent JS object literals locally too, per the brief. Read the full
 * case body (lines 3169-3487) before editing either builder below.
 */

/** `pathinfo($name, PATHINFO_EXTENSION)` — empty string when the name has no dot, NO 'jpg'/other fallback (unlike the sibling mediaSend batch's upload helpers). */
function phpFileExtension(name: string): string {
  const base = name.split(/[\\/]/).pop() ?? '';
  const dot = base.lastIndexOf('.');
  return dot > -1 ? base.slice(dot + 1) : '';
}

/**
 * Port of the file-attachment Flex bubble (inbox-v2.php lines 3255-3319),
 * built when `type === 'file'`.
 *
 * ```php
 * $fileName = $msg['fileName'] ?? 'File';
 * $fileUrl = $msg['originalContentUrl'];
 * $fileSize = "Unknown Size"; // dead code — never referenced again below
 * $fileType = strtoupper(pathinfo($fileName, PATHINFO_EXTENSION)) . " File"; // dead code — immediately overwritten
 * $expiryDate = date('d M Y H:i', strtotime('+7 days')); // dead code — never referenced again below
 * $iconUrl = "https://cny.re-ya.com/uploads/chat_images/chat_1769145030_697302c699ee0.png";
 * $fileType = strtoupper(pathinfo($fileName, PATHINFO_EXTENSION)) . " Document";
 *
 * [
 *     'type' => 'flex',
 *     'altText' => "Sent a file: {$fileName}",
 *     'contents' => [
 *         'type' => 'bubble',
 *         'hero' => [
 *             'type' => 'image', 'url' => $iconUrl, 'size' => 'full',
 *             'aspectRatio' => '20:13', 'aspectMode' => 'fit',
 *             'action' => ['type' => 'uri', 'uri' => $fileUrl]
 *         ],
 *         'body' => [
 *             'type' => 'box', 'layout' => 'vertical',
 *             'contents' => [
 *                 ['type' => 'text', 'text' => $fileName, 'weight' => 'bold', 'size' => 'md', 'wrap' => true],
 *                 ['type' => 'text', 'text' => $fileType, 'size' => 'xs', 'color' => '#aaaaaa', 'margin' => 'sm'],
 *                 ['type' => 'separator', 'margin' => 'lg']
 *             ]
 *         ],
 *         'footer' => [
 *             'type' => 'box', 'layout' => 'vertical',
 *             'contents' => [
 *                 ['type' => 'button', 'style' => 'primary', 'color' => '#1DB446', 'height' => 'sm',
 *                     'action' => ['type' => 'uri', 'label' => 'Download File', 'uri' => $fileUrl]]
 *             ]
 *         ]
 *     ]
 * ]
 * ```
 *
 * `$fileSize` and `$expiryDate` are assigned but grep-verified NEVER
 * referenced anywhere else in the case body — dead code, not ported.
 * `$fileType`'s FIRST assignment (`... . " File"`) is likewise dead —
 * immediately overwritten by the second assignment (`... . " Document"`)
 * before ever being read — so only the final `" Document"`-suffixed value
 * is reproduced here.
 *
 * The hero image URL is a HARDCODED CNY Healthcare branding icon —
 * `chat_1769145030_697302c699ee0.png` — reproduced byte-for-byte, not
 * parameterized. This is intentional: every file-attachment bubble this
 * action sends carries the same CNY icon regardless of the actual
 * uploaded file's type.
 */
export function buildFileFlexMessage(fileName: string, fileUrl: string): LineMessage {
  const iconUrl = 'https://cny.re-ya.com/uploads/chat_images/chat_1769145030_697302c699ee0.png';
  const fileType = `${phpFileExtension(fileName).toUpperCase()} Document`;

  return {
    type: 'flex',
    altText: `Sent a file: ${fileName}`,
    contents: {
      type: 'bubble',
      hero: {
        type: 'image',
        url: iconUrl,
        size: 'full',
        aspectRatio: '20:13',
        aspectMode: 'fit',
        action: { type: 'uri', uri: fileUrl },
      },
      body: {
        type: 'box',
        layout: 'vertical',
        contents: [
          { type: 'text', text: fileName, weight: 'bold', size: 'md', wrap: true },
          { type: 'text', text: fileType, size: 'xs', color: '#aaaaaa', margin: 'sm' },
          { type: 'separator', margin: 'lg' },
        ],
      },
      footer: {
        type: 'box',
        layout: 'vertical',
        contents: [
          {
            type: 'button',
            style: 'primary',
            color: '#1DB446',
            height: 'sm',
            action: { type: 'uri', label: 'Download File', uri: fileUrl },
          },
        ],
      },
    },
  };
}

/** Bank details hardcoded in inbox-v2.php lines 3346-3348 — reproduced byte-for-byte, never parameterized per-tenant. */
export const PAYMENT_BANK_NAME = 'KBANK (กสิกรไทย)';
export const PAYMENT_ACCOUNT_NUMBER = '068-3-84622-8';
export const PAYMENT_ACCOUNT_NAME = 'บจก.ซี เอ็น วาย เฮลท์แคร์';

/**
 * Port of the payment-request Flex bubble (inbox-v2.php lines 3341-3435),
 * built when `type === 'payment'` — reached either because the item's own
 * `type` field literally IS `'payment'`, or because a `type: 'text'` item's
 * `content` was the magic string `'{{PAYMENT_TEMPLATE_V1}}'` (see
 * `sendBatchMessages.ts`'s loop). Unlike the `image`/`file` branches, this
 * one has NO required-field guard at all — it always produces a message,
 * even with `amount` entirely absent (defaults to `0.00`).
 *
 * `$amount = number_format((float) ($msg['amount'] ?? 0), 2);` — already
 * formatted with thousands separators + exactly 2 decimals by the time it
 * reaches this builder (see `phpNumberFormat()` in `sendBatchMessages.ts`).
 */
export function buildPaymentFlexMessage(formattedAmount: string): LineMessage {
  const clipboardAccountNumber = PAYMENT_ACCOUNT_NUMBER.replace(/-/g, '');

  return {
    type: 'flex',
    altText: `แจ้งยอดชำระ: ${formattedAmount} บาท`,
    contents: {
      type: 'bubble',
      size: 'kilo',
      body: {
        type: 'box',
        layout: 'vertical',
        contents: [
          { type: 'text', text: 'PAYMENT DETAILS', weight: 'bold', color: '#1DB446', size: 'xxs' },
          { type: 'text', text: `${formattedAmount} THB`, weight: 'bold', size: 'xxl', margin: 'md' },
          { type: 'separator', margin: 'lg' },
          {
            type: 'box',
            layout: 'vertical',
            margin: 'lg',
            spacing: 'sm',
            contents: [
              { type: 'text', text: PAYMENT_BANK_NAME, size: 'sm', color: '#555555' },
              {
                type: 'text',
                text: PAYMENT_ACCOUNT_NUMBER,
                size: 'xl',
                weight: 'bold',
                color: '#111111',
                action: { type: 'clipboard', label: 'Copy', clipboardText: clipboardAccountNumber },
              },
              { type: 'text', text: PAYMENT_ACCOUNT_NAME, size: 'xs', color: '#aaaaaa' },
            ],
          },
          { type: 'separator', margin: 'lg' },
          {
            type: 'text',
            text: 'กรุณาส่งสลิปเพื่อยืนยันการโอนเงิน',
            size: 'xxs',
            color: '#aaaaaa',
            margin: 'md',
            align: 'center',
          },
        ],
      },
      footer: {
        type: 'box',
        layout: 'vertical',
        contents: [
          {
            type: 'button',
            style: 'secondary',
            height: 'sm',
            action: { type: 'clipboard', label: 'คัดลอกเลขบัญชี', clipboardText: clipboardAccountNumber },
          },
        ],
      },
    },
  };
}

/** `"💰 แจ้งยอดชำระ: {$amount} บาท\n{$bankName}\n{$accNumber}\n{$accName}"` (inbox-v2.php line 3434) — saved to `messages.content` as `message_type='text'` (NOT 'payment' — see sendBatchMessages.ts's module doc). */
export function buildPaymentDbText(formattedAmount: string): string {
  return `💰 แจ้งยอดชำระ: ${formattedAmount} บาท\n${PAYMENT_BANK_NAME}\n${PAYMENT_ACCOUNT_NUMBER}\n${PAYMENT_ACCOUNT_NAME}`;
}
