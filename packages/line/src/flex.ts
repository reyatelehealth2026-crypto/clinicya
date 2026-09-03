/**
 * Flex Message Templates — TypeScript port of classes/FlexTemplates.php
 * (docs/plans/2026-07-12-nextjs-full-migration-plan.md Phase 6 / Phase 12).
 *
 * SCOPE: only the three functions actually needed by the dispense flow are ported here:
 *   - medicineLabel()          (PHP: classes/FlexTemplates.php:1499)
 *   - medicineLabelsCarousel() (PHP: classes/FlexTemplates.php:1871)
 *   - toMessage()              (PHP: classes/FlexTemplates.php:117)
 * plus the private helpers toMessage()/buildQuickReply() depend on: getSender(),
 * buildQuickReply(), and the $senders / $quickReplySets lookup tables (PHP lines 9-42).
 *
 * This file does NOT decide when a carousel vs. a single bubble is sent — per CLAUDE.md's
 * dispense-system docs, that decision ("carousel auto-used when count(items) > 1") belongs
 * to the CALLER (inbox-v2.php / messages.php today, a future Phase 5 port tomorrow). The
 * three functions below keep their exact PHP call signatures and do nothing more.
 *
 * PARITY NOTES (read before touching this file):
 *
 * 1. Non-deterministic field ("landmine" called out in the porting brief): medicineLabel()'s
 *    "วันที่จ่ายยา" (date dispensed) value comes from PHP's `date('d/m/Y')` with no injectable
 *    override — it is the server's local date at call time, in a `d/m/Y` (Buddhist-era? NO —
 *    plain Gregorian `date()`, see PHP source comment "AD year") format. There is deliberately
 *    no way to inject a fixed date into this port, to stay byte-for-byte faithful to the PHP
 *    call signature (`medicineLabel($item, $shopInfo, $patientName, $checkoutUrl)` — no 5th
 *    "now" parameter exists in the original). Tests MUST normalize this one field out (see
 *    tests/flex.test.ts) rather than trying to freeze time on both sides.
 *
 * 2. Dead PHP code, ported faithfully (i.e. NOT rendered here either, on purpose):
 *    - `$timeMap` / `$timeIconsRow` (PHP lines 1521-1569) build a "checked" morning/noon/
 *      evening/bedtime checkbox row from `item.timeOfDay`, but `$timeIconsRow` is never
 *      appended to `$bodyContents` anywhere in the PHP function. `item.timeOfDay` therefore
 *      has ZERO effect on the real PHP output today. Fixtures
 *      `medicine-label-time-of-day-all.json` / `medicine-label-time-of-day-none.json` exist
 *      specifically to prove (and pin) this: the two bodies are byte-identical.
 *    - `$mealTiming` / `$beforeMeal` / `$afterMeal` (PHP lines 1531-1533) are computed and
 *      never read again.
 *    - `$isExternal` (PHP line 1516, from `item.usageType`) is computed and never read again.
 *    - `$shopAddress` / `$shopLogo` (PHP lines 1509, 1511, inside medicineLabel() specifically
 *      — a *different*, unrelated `$shopLogo` local at PHP line 1353 belongs to a different
 *      template function and IS used there) are read from `$shopInfo` and never used again
 *      inside medicineLabel().
 *    None of the above is "fixed" here — the port must reproduce what the PHP actually does,
 *    not what its doc-comment claims it does.
 *
 * 3. PHP `??` (null-coalescing) only substitutes on `null`/unset, never on `0`/`''`/`false` —
 *    this matters for `item.qty ?? 1`, `item.price ?? 0`, etc. TypeScript's `??` operator has
 *    the exact same semantics, so it is used directly wherever the PHP source uses `??`.
 *    PHP `!empty($x)` / bare `if ($x)` truthiness is different (it also treats `0`, `''`,
 *    `'0'`, `false`, and `[]` as falsy) — see `phpTruthy()` below, used wherever the PHP
 *    source uses `!empty(...)` or a bare truthiness check.
 */

// ---------------------------------------------------------------------------
// Flex JSON types (deliberately local to this file — see build report / task brief: flex.ts
// and api.ts must stay independently buildable, so nothing is imported from api.ts here).
// These model exactly the shapes the three ported functions can produce, not the full LINE
// Flex Message spec.
// ---------------------------------------------------------------------------

export type FlexSize =
  | 'xxs' | 'xs' | 'sm' | 'md' | 'lg' | 'xl' | 'xxl' | '3xl' | '4xl' | 'full' | 'kilo' | 'mega';

export type FlexAction =
  | { type: 'uri'; label: string; uri: string }
  | { type: 'message'; label: string | null; text: string | null }
  | { type: 'postback'; label: string | null; data: string; displayText: string | null };

export interface FlexBoxComponent {
  type: 'box';
  layout: 'horizontal' | 'vertical' | 'baseline';
  contents: FlexComponent[];
  flex?: number;
  spacing?: string;
  margin?: string;
  paddingAll?: string;
  paddingTop?: string;
  backgroundColor?: string;
  cornerRadius?: string;
  borderWidth?: string;
  borderColor?: string;
  width?: string;
  height?: string;
  justifyContent?: string;
  alignItems?: string;
  action?: FlexAction;
}

export interface FlexTextComponent {
  type: 'text';
  text: string | null;
  size?: string;
  weight?: 'bold' | 'regular';
  color?: string;
  align?: 'start' | 'center' | 'end';
  gravity?: string;
  wrap?: boolean;
  margin?: string;
  flex?: number;
  decoration?: string;
}

export interface FlexImageComponent {
  type: 'image';
  url: string;
  size?: string;
  aspectMode?: 'cover' | 'fit';
  aspectRatio?: string;
  action?: FlexAction;
}

export interface FlexButtonComponent {
  type: 'button';
  action: FlexAction;
  style?: 'primary' | 'secondary' | 'link';
  color?: string;
  height?: 'sm' | 'md';
  margin?: string;
  flex?: number;
}

export interface FlexSeparatorComponent {
  type: 'separator';
  margin?: string;
  color?: string;
}

export interface FlexFillerComponent {
  type: 'filler';
}

export type FlexComponent =
  | FlexBoxComponent
  | FlexTextComponent
  | FlexImageComponent
  | FlexButtonComponent
  | FlexSeparatorComponent
  | FlexFillerComponent;

export interface FlexBubble {
  type: 'bubble';
  size?: string;
  header?: FlexBoxComponent;
  body?: FlexBoxComponent;
  footer?: FlexBoxComponent;
}

export interface FlexCarousel {
  type: 'carousel';
  contents: FlexBubble[];
}

export type FlexContainer = FlexBubble | FlexCarousel;

export interface LineSender {
  name: string;
  iconUrl: string;
}

export interface LineQuickReplyAction {
  type: 'camera' | 'cameraRoll' | 'location' | 'uri' | 'postback' | 'message';
  label: string | null;
  uri?: string;
  data?: string;
  displayText?: string | null;
  text?: string | null;
}

export interface LineQuickReplyItem {
  type: 'action';
  action: LineQuickReplyAction;
}

export interface LineQuickReply {
  items: LineQuickReplyItem[];
}

export interface LineFlexMessage {
  type: 'flex';
  altText: string;
  contents: FlexContainer;
  sender?: LineSender;
  quickReply?: LineQuickReply | null;
}

// ---------------------------------------------------------------------------
// PHP-semantics helpers (deliberately explicit — see parity note #3 above).
// ---------------------------------------------------------------------------

/** Mirrors PHP's falsy set for `empty($x)` / bare `if ($x)`: null/undefined, false, 0, '', '0', []. */
function phpFalsy(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return true;
  if (value === 0 || value === '') return true;
  if (value === '0') return true;
  if (Array.isArray(value) && value.length === 0) return true;
  return false;
}

/** Mirrors PHP's `!empty($x)` / bare `if ($x)` truthiness check. */
function phpTruthy(value: unknown): boolean {
  return !phpFalsy(value);
}

/**
 * Mirrors PHP's `number_format($value, $decimals)`: thousands-comma-grouped, fixed decimal
 * places, half-away-from-zero rounding. `decimals` defaults to 0, matching PHP's default.
 * (e.g. `phpNumberFormat(1234.5, 2)` -> `"1,234.50"`, `phpNumberFormat(1234.5)` -> `"1,235"` —
 * PHP's `number_format(1234.5)` rounds to `"1,235"` since decimals=0 rounds half away from zero.)
 */
export function phpNumberFormat(value: number, decimals = 0): string {
  const factor = 10 ** decimals;
  const shifted = value * factor;
  // Tiny epsilon (scaled by sign) counters binary floating-point representation error near
  // exact .5 boundaries — the standard fix for JS's Math.round() not matching PHP's decimal
  // "round half away from zero" on values like 1.005 that can't be represented exactly.
  const epsilon = (shifted >= 0 ? 1 : -1) * 1e-9;
  const roundedInt = Math.round(shifted + epsilon);
  const rounded = roundedInt / factor;

  const fixed = Math.abs(rounded).toFixed(decimals);
  const dotIndex = fixed.indexOf('.');
  const intPart = dotIndex === -1 ? fixed : fixed.slice(0, dotIndex);
  const decPart = dotIndex === -1 ? '' : fixed.slice(dotIndex + 1);
  const withCommas = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const sign = roundedInt < 0 ? '-' : '';

  return decimals > 0 ? `${sign}${withCommas}.${decPart}` : `${sign}${withCommas}`;
}

/** Mirrors PHP's `date('d/m/Y')` — zero-padded day/month, 4-digit Gregorian year, server-local. */
function phpDateDMY(): string {
  const now = new Date();
  const dd = String(now.getDate()).padStart(2, '0');
  const mm = String(now.getMonth() + 1).padStart(2, '0');
  const yyyy = String(now.getFullYear());
  return `${dd}/${mm}/${yyyy}`;
}

// ---------------------------------------------------------------------------
// Sender / quick-reply lookup tables — verbatim port of PHP's private static
// $senders / $quickReplySets (classes/FlexTemplates.php:9-42).
// ---------------------------------------------------------------------------

export const SENDERS: Record<string, LineSender> = {
  default: { name: 'Shop Bot', iconUrl: 'https://i.imgur.com/BOBkgJA.png' },
  shop: { name: '🛒 Shop', iconUrl: 'https://i.imgur.com/BOBkgJA.png' },
  support: { name: '💬 Support', iconUrl: 'https://i.imgur.com/YkPqZKx.png' },
  notify: { name: '🔔 Notify', iconUrl: 'https://i.imgur.com/8LQHV0Z.png' },
  order: { name: '📦 Order', iconUrl: 'https://i.imgur.com/wPVlSoK.png' },
  payment: { name: '💳 Payment', iconUrl: 'https://i.imgur.com/3P1Z3hB.png' },
};

interface QuickReplyPresetItem {
  label: string;
  text: string;
}

export const QUICK_REPLY_SETS: Record<string, QuickReplyPresetItem[]> = {
  main: [
    { label: '🛒 ดูสินค้า', text: 'shop' },
    { label: '📋 เมนู', text: 'menu' },
    { label: '🛍️ ตะกร้า', text: 'cart' },
    { label: '📦 ออเดอร์', text: 'orders' },
  ],
  shop: [
    { label: '🛒 ดูสินค้า', text: 'shop' },
    { label: '🛍️ ตะกร้า', text: 'cart' },
    { label: '💳 ชำระเงิน', text: 'checkout' },
  ],
  order: [
    { label: '📦 เช็คสถานะ', text: 'orders' },
    { label: '💳 ส่งสลิป', text: 'สลิป' },
    { label: '🛒 ช้อปต่อ', text: 'shop' },
  ],
  support: [
    { label: '📋 เมนู', text: 'menu' },
    { label: '❓ FAQ', text: 'faq' },
    { label: '📞 โทรหาเรา', text: 'contact' },
  ],
};

/** Port of `FlexTemplates::getSender()` (PHP line 55). */
export function getSender(key = 'default'): LineSender {
  return SENDERS[key] ?? SENDERS.default!;
}

// ---------------------------------------------------------------------------
// buildQuickReply() — port of PHP lines 63-107. The 6-way action-shape branch order below
// matches the PHP if/elseif chain exactly (a plain object literal could structurally match
// more than one branch, so the CHECK ORDER, not just the shape, is what PHP parity requires).
// ---------------------------------------------------------------------------

export type QuickReplyItemInput =
  | { type: 'camera'; label?: string | null }
  | { type: 'cameraRoll'; label?: string | null }
  | { type: 'location'; label?: string | null }
  | { uri: string; label?: string | null }
  | { data: string; label?: string | null; displayText?: string | null }
  | { label?: string | null; text?: string | null };

/** Loosened runtime shape used only inside buildQuickReply's branch dispatch below — mirrors
 * how PHP treats `$item` as a plain dynamic array (`isset($item['uri'])` etc. don't care what
 * other keys exist). Keeping ONE cast here avoids fighting TS's union-narrowing across a
 * 6-way if/elseif chain while still exposing every field buildQuickReply() might read. */
interface QuickReplyItemShape {
  type?: string;
  label?: string | null;
  text?: string | null;
  uri?: string;
  data?: string;
  displayText?: string | null;
}

/** Port of `FlexTemplates::buildQuickReply()` (PHP line 63). */
export function buildQuickReply(
  items: QuickReplyItemInput[] = [],
  preset: string | null = null
): LineQuickReply | null {
  let effectiveItems: QuickReplyItemInput[] = items;
  if (preset) {
    const presetSet = QUICK_REPLY_SETS[preset];
    if (presetSet) {
      effectiveItems = presetSet;
    }
  }

  if (phpFalsy(effectiveItems)) return null;

  const quickReplyItems: LineQuickReplyItem[] = [];
  for (const rawItem of effectiveItems) {
    const item = rawItem as QuickReplyItemShape;

    if (item.type === 'camera') {
      quickReplyItems.push({ type: 'action', action: { type: 'camera', label: item.label ?? '📷 ถ่ายรูป' } });
    } else if (item.type === 'cameraRoll') {
      quickReplyItems.push({ type: 'action', action: { type: 'cameraRoll', label: item.label ?? '🖼️ เลือกรูป' } });
    } else if (item.type === 'location') {
      quickReplyItems.push({ type: 'action', action: { type: 'location', label: item.label ?? '📍 ส่งตำแหน่ง' } });
    } else if (item.uri !== undefined) {
      quickReplyItems.push({ type: 'action', action: { type: 'uri', label: item.label ?? null, uri: item.uri } });
    } else if (item.data !== undefined) {
      quickReplyItems.push({
        type: 'action',
        action: {
          type: 'postback',
          label: item.label ?? null,
          data: item.data,
          displayText: item.displayText ?? item.label ?? null,
        },
      });
    } else {
      quickReplyItems.push({
        type: 'action',
        action: { type: 'message', label: item.label ?? null, text: item.text ?? item.label ?? null },
      });
    }
  }

  return { items: quickReplyItems };
}

// ---------------------------------------------------------------------------
// toMessage() — port of PHP lines 117-144.
// ---------------------------------------------------------------------------

export type SenderInput = string | LineSender | null;
export type QuickReplyInput = string | QuickReplyItemInput[] | null;

/** Port of `FlexTemplates::toMessage()` (PHP line 117). */
export function toMessage(
  contents: FlexContainer,
  altText = 'ข้อความ',
  sender: SenderInput = null,
  quickReply: QuickReplyInput = null
): LineFlexMessage {
  const message: LineFlexMessage = {
    type: 'flex',
    altText,
    contents,
  };

  if (phpTruthy(sender)) {
    if (typeof sender === 'string') {
      message.sender = getSender(sender);
    } else if (sender !== null && typeof sender === 'object') {
      message.sender = sender;
    }
  }

  if (phpTruthy(quickReply)) {
    if (typeof quickReply === 'string') {
      // PHP (line 136) assigns unconditionally, even when buildQuickReply() returns null
      // for an unrecognized preset name — the key is present with a null value.
      message.quickReply = buildQuickReply([], quickReply);
    } else if (Array.isArray(quickReply)) {
      // buildQuickReply() can never return null here: the outer phpTruthy(quickReply) check
      // already screened out an empty array, so effectiveItems is always non-empty.
      message.quickReply = buildQuickReply(quickReply);
    }
  }

  return message;
}

// ---------------------------------------------------------------------------
// medicineLabel() — port of PHP lines 1499-1866.
// ---------------------------------------------------------------------------

export interface MedicineLabelItem {
  isMedicine?: boolean;
  usageType?: 'internal' | 'external' | string;
  image?: string | null;
  /** Dead in the PHP source (see parity note #2) — accepted for call-signature/type parity only. */
  timeOfDay?: string[];
  /** Dead in the PHP source (see parity note #2) — accepted for call-signature/type parity only. */
  mealTiming?: 'before' | 'after' | string;
  specialInstructions?: string[];
  notes?: string | null;
  generic_name?: string | null;
  strength?: string | null;
  name?: string | null;
  manufacturer?: string | null;
  indication?: string | number | null;
  usage_text?: string | null;
  dosage?: number | string;
  dosageUnit?: string;
  qty?: number;
  unit?: string;
  price?: number;
}

export interface ShopInfo {
  name?: string | null;
  /** Read by the PHP source but never used again inside medicineLabel() — see parity note #2. */
  address?: string | null;
  phone?: string | null;
  /** Read by the PHP source but never used again inside medicineLabel() — see parity note #2. */
  logo?: string | null;
  open_hours?: string | null;
  pharmacist?: string | null;
}

/** Port of `FlexTemplates::medicineLabel()` (PHP line 1499). */
export function medicineLabel(
  item: MedicineLabelItem,
  shopInfo: ShopInfo = {},
  patientName = '',
  checkoutUrl: string | null = null
): FlexBubble {
  const darkGreen = '#006400';
  const lightGreen = '#E8F5E9';
  const white = '#FFFFFF';
  const black = '#000000';
  const gray = '#666666';

  const shopName = phpTruthy(shopInfo.name) ? (shopInfo.name as string) : 'ร้านยา';
  const shopPhone = phpTruthy(shopInfo.phone) ? (shopInfo.phone as string) : '';
  const openHours = phpTruthy(shopInfo.open_hours) ? (shopInfo.open_hours as string) : '08:00-24:00 น.';
  const pharmacistName = phpTruthy(shopInfo.pharmacist) ? (shopInfo.pharmacist as string) : '';

  const isMedicine = phpTruthy(item.isMedicine) && (item.isMedicine as unknown) !== false;

  const productImage = phpTruthy(item.image)
    ? (item.image as string)
    : 'https://via.placeholder.com/100x100?text=No+Image';

  // Warnings-only block (2026-05-27 revision, see PHP comment at line 1571): only items the
  // pharmacist actually ticked, plus a free-text note line.
  const specialInst = item.specialInstructions ?? [];
  const warningMap: Record<string, string> = {
    drowsiness: 'ยานี้อาจทำให้ง่วงซึม',
    no_alcohol: 'ห้ามดื่มแอลกอฮอล์',
  };
  const specialContents: FlexComponent[] = [];
  for (const key of Object.keys(warningMap)) {
    if (!specialInst.includes(key)) continue;
    specialContents.push({
      type: 'box',
      layout: 'horizontal',
      contents: [
        { type: 'text', text: '⚠️', size: 'sm', flex: 0 },
        { type: 'text', text: warningMap[key]!, size: 'sm', color: '#B91C1C', weight: 'bold', margin: 'sm', wrap: true, flex: 1 },
      ],
      margin: 'sm',
    });
  }
  if (phpTruthy(item.notes)) {
    specialContents.push({
      type: 'box',
      layout: 'horizontal',
      contents: [
        { type: 'text', text: '📝', size: 'sm', flex: 0 },
        { type: 'text', text: item.notes as string, size: 'xs', color: gray, margin: 'sm', wrap: true, flex: 1 },
      ],
      margin: 'sm',
    });
  }

  const bodyContents: FlexComponent[] = [];

  // (1) Pregnancy / allergy warning bar.
  bodyContents.push({
    type: 'box',
    layout: 'vertical',
    contents: [
      { type: 'text', text: '⚠️ ตั้งครรภ์ แพ้ยา มีโรคประจำตัว กรุณาแจ้งเภสัชกร', size: 'xxs', color: white, wrap: true, align: 'center', weight: 'bold' },
    ],
    backgroundColor: '#B91C1C',
    paddingAll: 'sm',
    cornerRadius: 'md',
  });

  // (2) Rx badge row.
  bodyContents.push({
    type: 'box',
    layout: 'horizontal',
    contents: [
      { type: 'text', text: '℞ ฉลากยา', size: 'md', weight: 'bold', color: darkGreen, flex: 1 },
      { type: 'text', text: 'MEDICINE LABEL', size: 'xs', color: gray, align: 'end', gravity: 'center', flex: 1 },
    ],
    margin: 'md',
  });
  bodyContents.push({ type: 'separator', color: '#E5E7EB', margin: 'sm' });

  // (3) Framed patient + date block. adDate is the documented non-deterministic exception —
  // see parity note #1 at the top of this file and tests/flex.test.ts's normalization.
  const adDate = phpDateDMY();
  bodyContents.push({
    type: 'box',
    layout: 'horizontal',
    contents: [
      {
        type: 'box',
        layout: 'vertical',
        contents: [
          { type: 'text', text: 'ผู้ป่วย', size: 'xxs', color: gray },
          { type: 'text', text: phpTruthy(patientName) ? patientName : 'ลูกค้าทั่วไป', size: 'md', weight: 'bold', color: black, wrap: true, margin: 'xs' },
        ],
        flex: 2,
      },
      {
        type: 'box',
        layout: 'vertical',
        contents: [
          { type: 'text', text: 'วันที่จ่ายยา', size: 'xxs', color: gray, align: 'end' },
          { type: 'text', text: adDate, size: 'sm', color: black, align: 'end', margin: 'xs' },
        ],
        flex: 1,
      },
    ],
    margin: 'md',
    paddingAll: 'md',
    backgroundColor: '#F9FAFB',
    cornerRadius: 'md',
    borderWidth: '1px',
    borderColor: '#E5E7EB',
  });

  // Medicine card — image left, name + brand right.
  const brandLine: string[] = [];
  if (phpTruthy(item.generic_name)) brandLine.push(item.generic_name as string);
  if (phpTruthy(item.strength)) brandLine.push(item.strength as string);
  const brandText = brandLine.join(' · ');

  const medContents: FlexComponent[] = [
    { type: 'text', text: 'ชื่อสินค้า', size: 'xxs', color: gray },
    { type: 'text', text: item.name ?? '-', size: 'md', weight: 'bold', wrap: true, color: black },
  ];
  if (phpTruthy(brandText)) {
    medContents.push({ type: 'text', text: brandText, size: 'xxs', color: gray, wrap: true, margin: 'xs' });
  }
  if (phpTruthy(item.manufacturer)) {
    medContents.push({ type: 'text', text: 'ผลิตโดย ' + (item.manufacturer as string), size: 'xxs', color: gray, wrap: true });
  }

  bodyContents.push({
    type: 'box',
    layout: 'horizontal',
    contents: [
      {
        type: 'box',
        layout: 'vertical',
        contents: [{ type: 'image', url: productImage, size: 'full', aspectMode: 'cover', aspectRatio: '1:1' }],
        width: '64px',
        height: '64px',
        cornerRadius: 'md',
        flex: 0,
      },
      {
        type: 'box',
        layout: 'vertical',
        contents: medContents,
        flex: 1,
        margin: 'md',
      },
    ],
    margin: 'md',
    paddingAll: 'md',
    backgroundColor: lightGreen,
    cornerRadius: 'md',
    borderWidth: '1px',
    borderColor: darkGreen,
  });

  // ข้อบ่งใช้ (indication) — green box, only if present.
  if (phpTruthy(item.indication)) {
    bodyContents.push({
      type: 'box',
      layout: 'vertical',
      contents: [
        { type: 'text', text: '💊 สรรพคุณ / ข้อบ่งใช้', size: 'xs', weight: 'bold', color: darkGreen },
        { type: 'text', text: String(item.indication), size: 'sm', wrap: true, color: black, margin: 'sm' },
      ],
      margin: 'md',
      paddingAll: 'md',
      backgroundColor: '#F0F9F4',
      cornerRadius: 'md',
    });
  }

  // วิธีใช้ (usage) — yellow box; defaults from item.usage_text, else a generated dosage
  // sentence but ONLY when isMedicine is true.
  let usageDisplay = '';
  if (phpTruthy(item.usage_text)) {
    usageDisplay = String(item.usage_text);
  } else if (isMedicine) {
    usageDisplay = 'รับประทานครั้งละ ' + (item.dosage ?? 1) + ' ' + (item.dosageUnit ?? 'เม็ด');
  }
  if (usageDisplay !== '') {
    bodyContents.push({
      type: 'box',
      layout: 'vertical',
      contents: [
        { type: 'text', text: '📖 วิธีใช้', size: 'xs', weight: 'bold', color: '#B45309' },
        { type: 'text', text: usageDisplay, size: 'sm', wrap: true, color: black, margin: 'sm' },
      ],
      margin: 'md',
      paddingAll: 'md',
      backgroundColor: '#FFFBEB',
      cornerRadius: 'md',
      borderWidth: '1px',
      borderColor: '#FCD34D',
    });
  }

  // Quantity row — always rendered.
  bodyContents.push({
    type: 'box',
    layout: 'horizontal',
    contents: [
      { type: 'text', text: 'จำนวน:', size: 'sm', color: gray },
      { type: 'text', text: `${item.qty ?? 1} ${item.unit ?? 'ชิ้น'}`, size: 'sm', weight: 'bold', align: 'end', color: black },
    ],
    margin: 'lg',
  });

  const price = (item.price ?? 0) * (item.qty ?? 1);
  if (price > 0) {
    bodyContents.push({
      type: 'box',
      layout: 'horizontal',
      contents: [
        { type: 'text', text: 'ราคา:', size: 'sm', color: gray },
        { type: 'text', text: '฿' + phpNumberFormat(price, 2), size: 'lg', weight: 'bold', color: darkGreen, align: 'end' },
      ],
      margin: 'sm',
    });
  }

  // หมายเหตุ — unconditional on item.notes (independent of isMedicine; NOT the same row as
  // the 📝 line inside the warnings box below, which only renders when isMedicine is true).
  if (phpTruthy(item.notes)) {
    bodyContents.push({
      type: 'box',
      layout: 'horizontal',
      contents: [
        { type: 'text', text: 'หมายเหตุ:', size: 'sm', color: gray, flex: 0 },
        { type: 'text', text: String(item.notes), size: 'sm', color: black, flex: 1, margin: 'sm', wrap: true },
      ],
      margin: 'md',
    });
  }

  // Warnings (red box) — only if isMedicine AND the pharmacist ticked something (or left a note).
  if (isMedicine && specialContents.length > 0) {
    bodyContents.push({
      type: 'box',
      layout: 'vertical',
      contents: [{ type: 'text', text: '⚠️ คำเตือน', size: 'xs', weight: 'bold', color: '#B91C1C' }, ...specialContents],
      margin: 'md',
      paddingAll: 'md',
      backgroundColor: '#FEF2F2',
      cornerRadius: 'md',
      borderWidth: '1px',
      borderColor: '#FCA5A5',
    });
  }

  // Header — shop name, optional "Pharmacist: X", optional phone.
  const headerContents: FlexComponent[] = [
    { type: 'text', text: shopName, weight: 'bold', size: 'xl', color: white, align: 'center', wrap: true },
  ];
  if (phpTruthy(pharmacistName)) {
    headerContents.push({ type: 'text', text: 'Pharmacist: ' + pharmacistName, size: 'xs', color: white, align: 'center', margin: 'xs' });
  }
  if (phpTruthy(shopPhone)) {
    headerContents.push({ type: 'text', text: '☎ ' + shopPhone, size: 'sm', color: white, align: 'center', margin: 'sm' });
  }

  const headerBox: FlexBoxComponent = {
    type: 'box',
    layout: 'vertical',
    contents: headerContents,
    backgroundColor: darkGreen,
    paddingAll: 'lg',
  };

  const bubble: FlexBubble = {
    type: 'bubble',
    size: 'mega',
    header: headerBox,
    body: {
      type: 'box',
      layout: 'vertical',
      contents: bodyContents,
      paddingAll: 'lg',
      backgroundColor: white,
    },
  };

  // Footer — opening hours, with an optional checkout button.
  if (phpTruthy(checkoutUrl)) {
    bubble.footer = {
      type: 'box',
      layout: 'vertical',
      contents: [
        { type: 'button', action: { type: 'uri', label: '💳 ชำระเงิน', uri: checkoutUrl as string }, style: 'primary', color: darkGreen },
        { type: 'text', text: 'เปิดทำการทุกวัน เวลา ' + openHours, size: 'xxs', color: gray, align: 'center', margin: 'md' },
      ],
      paddingAll: 'lg',
      backgroundColor: lightGreen,
    };
  } else {
    bubble.footer = {
      type: 'box',
      layout: 'vertical',
      contents: [{ type: 'text', text: 'เปิดทำการทุกวัน เวลา ' + openHours, size: 'xs', color: darkGreen, align: 'center', weight: 'bold' }],
      paddingAll: 'md',
      backgroundColor: lightGreen,
    };
  }

  return bubble;
}

// ---------------------------------------------------------------------------
// medicineLabelsCarousel() — port of PHP lines 1871-1940.
// ---------------------------------------------------------------------------

/** Port of `FlexTemplates::medicineLabelsCarousel()` (PHP line 1871). */
export function medicineLabelsCarousel(
  items: MedicineLabelItem[],
  shopInfo: ShopInfo = {},
  patientName = '',
  checkoutUrl: string | null = null
): FlexCarousel {
  const bubbles: FlexBubble[] = items.map((item) => medicineLabel(item, shopInfo, patientName, null));

  if (phpTruthy(checkoutUrl) && items.length > 0) {
    const total = items.reduce((sum, item) => sum + (item.price ?? 0) * (item.qty ?? 1), 0);

    const itemsList: FlexComponent[] = items.map((item) => ({
      type: 'box',
      layout: 'horizontal',
      contents: [
        { type: 'text', text: item.name ?? null, size: 'xs', flex: 3, wrap: true },
        { type: 'text', text: 'x' + (item.qty ?? 1), size: 'xs', flex: 1, align: 'center' },
        { type: 'text', text: '฿' + phpNumberFormat((item.price ?? 0) * (item.qty ?? 1)), size: 'xs', flex: 1, align: 'end' },
      ],
      margin: 'sm',
    }));

    const summaryBubble: FlexBubble = {
      type: 'bubble',
      size: 'mega',
      header: {
        type: 'box',
        layout: 'vertical',
        contents: [{ type: 'text', text: '🧾 สรุปรายการ', weight: 'bold', size: 'lg', color: '#FFFFFF', align: 'center' }],
        backgroundColor: '#8B5CF6',
        paddingAll: 'lg',
      },
      body: {
        type: 'box',
        layout: 'vertical',
        contents: [
          ...itemsList,
          { type: 'separator', margin: 'lg' },
          {
            type: 'box',
            layout: 'horizontal',
            contents: [
              { type: 'text', text: 'รวมทั้งหมด', weight: 'bold', size: 'md' },
              { type: 'text', text: '฿' + phpNumberFormat(total, 2), weight: 'bold', size: 'xl', color: '#06C755', align: 'end' },
            ],
            margin: 'lg',
          },
        ],
        paddingAll: 'lg',
      },
      footer: {
        type: 'box',
        layout: 'vertical',
        contents: [
          { type: 'button', action: { type: 'uri', label: '💳 ชำระเงินทั้งหมด', uri: checkoutUrl as string }, style: 'primary', color: '#06C755' },
        ],
        paddingAll: 'lg',
      },
    };

    bubbles.push(summaryBubble);
  }

  return { type: 'carousel', contents: bubbles };
}
