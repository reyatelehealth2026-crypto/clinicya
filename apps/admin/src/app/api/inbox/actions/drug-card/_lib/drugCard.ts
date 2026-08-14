import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * drugCard.ts — port of `classes/DrugRecommendEngineService.php`'s
 * `generateDrugCard()` (lines 569-773) and `buildInfoRow()` (775-786), as
 * driven by api/inbox-v2.php's `case 'drug_card': case 'drug-card': case
 * 'generate_drug_card':` (lines ~1447-1475). Pure LINE Flex-JSON assembly —
 * no DB writes; every icon/emoji/Thai label/hex color/button action string
 * below must match the PHP source byte-for-byte, since this bubble is
 * rendered verbatim to LINE and inserted into the pharmacist's message
 * composer.
 *
 * ```php
 * public function generateDrugCard(int $drugId): array
 * {
 *     $drug = $this->getDrugDetails($drugId);
 *     if (!$drug) {
 *         return ['type' => 'bubble', 'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [
 *             ['type' => 'text', 'text' => '❌ ไม่พบข้อมูลยา', 'weight' => 'bold', 'color' => '#EF4444']
 *         ]]];
 *     }
 *
 *     $price = (float)($drug['sale_price'] ?? $drug['price'] ?? 0);
 *     $originalPrice = $drug['sale_price'] ? (float)$drug['price'] : null;
 *     $hasDiscount = $originalPrice && $originalPrice > $price;
 *     $inStock = ($drug['stock'] ?? 0) > 0;
 *     $isPrescription = (bool)($drug['is_prescription'] ?? false);
 *
 *     $priceContents = [
 *         ['type' => 'text', 'text' => '฿' . number_format($price, 2), 'size' => 'xl', 'weight' => 'bold', 'color' => '#06C755']
 *     ];
 *     if ($hasDiscount) {
 *         $priceContents[] = ['type' => 'text', 'text' => '฿' . number_format($originalPrice, 2), 'size' => 'sm', 'color' => '#AAAAAA', 'decoration' => 'line-through', 'margin' => 'sm'];
 *     }
 *
 *     $infoContents = [];
 *     if (!empty($drug['dosage'])) { $infoContents[] = $this->buildInfoRow('💊 ขนาดยา', $drug['dosage']); }
 *     if (!empty($drug['usage_instructions'])) { $infoContents[] = $this->buildInfoRow('📋 วิธีใช้', $drug['usage_instructions']); }
 *     if (!empty($drug['side_effects'])) { $infoContents[] = $this->buildInfoRow('⚠️ ผลข้างเคียง', $drug['side_effects']); }
 *     if (!empty($drug['contraindications'])) { $infoContents[] = $this->buildInfoRow('🚫 ข้อห้ามใช้', $drug['contraindications']); }
 *
 *     $stockText = $inStock ? "📦 เหลือ {$drug['stock']} ชิ้น" : '❌ สินค้าหมด';
 *     $stockColor = $inStock ? '#888888' : '#EF4444';
 *
 *     $bodyContents = [['type' => 'text', 'text' => $drug['name'], 'weight' => 'bold', 'size' => 'lg', 'wrap' => true]];
 *     if (!empty($drug['generic_name'])) {
 *         $bodyContents[] = ['type' => 'text', 'text' => "({$drug['generic_name']})", 'size' => 'sm', 'color' => '#888888', 'margin' => 'sm'];
 *     }
 *     if ($isPrescription) {
 *         $bodyContents[] = ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
 *             ['type' => 'text', 'text' => '💊 ยาควบคุมพิเศษ', 'size' => 'xs', 'color' => '#FFFFFF', 'align' => 'center']
 *         ], 'backgroundColor' => '#EF4444', 'cornerRadius' => 'md', 'paddingAll' => 'xs', 'margin' => 'md', 'width' => '120px'];
 *     }
 *     $bodyContents[] = ['type' => 'box', 'layout' => 'horizontal', 'contents' => $priceContents, 'margin' => 'lg'];
 *     $bodyContents[] = ['type' => 'text', 'text' => $stockText, 'size' => 'xs', 'color' => $stockColor, 'margin' => 'md'];
 *     if (!empty($infoContents)) {
 *         $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
 *         $bodyContents = array_merge($bodyContents, $infoContents);
 *     }
 *
 *     $buttons = [];
 *     if ($inStock && !$isPrescription) {
 *         $buttons[] = ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 เพิ่มลงตะกร้า', 'text' => "add {$drugId}"], 'style' => 'primary', 'color' => '#06C755'];
 *     } elseif ($isPrescription) {
 *         $buttons[] = ['type' => 'button', 'action' => ['type' => 'message', 'label' => '💬 ปรึกษาเภสัชกร', 'text' => "consult {$drugId}"], 'style' => 'primary', 'color' => '#3B82F6'];
 *     }
 *     $buttons[] = ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🔍 ตรวจสอบยาตีกัน', 'text' => "check interaction {$drugId}"], 'style' => 'secondary', 'margin' => 'sm'];
 *
 *     $bubble = ['type' => 'bubble', 'size' => 'mega'];
 *     if (!empty($drug['image_url'])) {
 *         $bubble['hero'] = ['type' => 'image', 'url' => $drug['image_url'], 'size' => 'full', 'aspectRatio' => '4:3', 'aspectMode' => 'cover'];
 *     }
 *     $bubble['body'] = ['type' => 'box', 'layout' => 'vertical', 'contents' => $bodyContents, 'paddingAll' => 'lg'];
 *     $bubble['footer'] = ['type' => 'box', 'layout' => 'vertical', 'contents' => $buttons, 'paddingAll' => 'lg'];
 *     return $bubble;
 * }
 *
 * private function buildInfoRow(string $label, string $value): array
 * {
 *     return ['type' => 'box', 'layout' => 'vertical', 'contents' => [
 *         ['type' => 'text', 'text' => $label, 'size' => 'xs', 'color' => '#888888'],
 *         ['type' => 'text', 'text' => $value, 'size' => 'sm', 'wrap' => true, 'margin' => 'xs']
 *     ], 'margin' => 'lg'];
 * }
 *
 * private function getDrugDetails(int $drugId): ?array
 * {
 *     $stmt = $this->db->prepare("SELECT bi.*, ic.name as category_name FROM business_items bi
 *         LEFT JOIN item_categories ic ON bi.category_id = ic.id WHERE bi.id = ?");
 *     ...
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `bi.*` -> explicit column list; `category_name` dropped (never read here)
 * ═══════════════════════════════════════════════════════════════════════
 * Same "explicit column list instead of `SELECT *`" convention already
 * established by `../../drug-info/_lib/drugInfo.ts` (Phase 4 batch 4a) —
 * this file's own `getDrugDetails()` is a SEPARATE, self-contained copy
 * from `../../safe-alternatives/_lib/safeAlternatives.ts`'s (each selects
 * only the columns its own consumer reads; this batch's ownership boundary
 * keeps each action's `_lib/` independently editable, per this batch's
 * brief). Only `id`/`name`/`generic_name`/`price`/`sale_price`/`stock`/
 * `image_url`/`is_prescription`/`dosage`/`usage_instructions`/
 * `side_effects`/`contraindications` are selected — every field
 * `generateDrugCard()` reads.
 *
 * CONFIRMED SCHEMA-DRIFT FIX — `is_prescription` -> `requires_prescription`
 * (ALIASED): `bi.*` in the literal PHP never actually populates a key
 * literally named `is_prescription` (that column doesn't exist — same
 * confirmed finding as `../../drug-inventory/_lib/drugInventory.ts`), so
 * `(bool)($drug['is_prescription'] ?? false)` is silently, PERMANENTLY
 * `false` in production today. This port selects the real
 * `bi.requires_prescription`, ALIASED to `is_prescription` in the result
 * set, so the prescription badge/button-set logic actually reflects real
 * data — same fix-forward precedent as `../../drug-inventory/_lib/drugInventory.ts`.
 *
 * `number_format($price, 2)` — PHP's thousands-grouped, 2-decimal format
 * (e.g. `1234.5` -> `'1,234.50'`) — ported via `toLocaleString('en-US', {
 * minimumFractionDigits: 2, maximumFractionDigits: 2 })`, which produces
 * the identical grouped/rounded output for the non-negative baht amounts
 * this function ever handles.
 *
 * `$drug['sale_price'] ? (float)$drug['price'] : null` / `$originalPrice &&
 * $originalPrice > $price` — PHP truthiness on the raw `sale_price`
 * column value (a falsy `0`/`'0'`/`null`/`''` sale_price disables the
 * discount badge entirely, regardless of `price`).
 */

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `(int) $v` on a DB column value — non-numeric/null/undefined -> 0. */
function toIntOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

/** PHP truthiness on a raw DB column value (falsy: `null`/`undefined`/`0`/`'0'`/`''`). */
function isPhpTruthy(value: unknown): boolean {
  if (value === null || value === undefined) return false;
  if (typeof value === 'number') return value !== 0;
  if (typeof value === 'string') return value !== '' && value !== '0';
  return Boolean(value);
}

/** PHP `empty($v)` for a possibly-null string DB column value. */
function isPhpEmptyString(value: string | null | undefined): boolean {
  return value === null || value === undefined || value === '' || value === '0';
}

/** PHP `number_format($x, 2)`. */
function numberFormat2(value: number): string {
  return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

interface DrugCardRow {
  id: number;
  name: string;
  generic_name: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  image_url: string | null;
  is_prescription: unknown;
  dosage: string | null;
  usage_instructions: string | null;
  side_effects: string | null;
  contraindications: string | null;
}

export async function getDrugDetails(db: Kysely<TenantDB>, drugId: number): Promise<DrugCardRow | null> {
  try {
    const result = await sql<DrugCardRow>`
      SELECT bi.id, bi.name, bi.generic_name, bi.price, bi.sale_price, bi.stock, bi.image_url,
             bi.requires_prescription AS is_prescription, bi.dosage, bi.usage_instructions,
             bi.side_effects, bi.contraindications
      FROM business_items bi
      WHERE bi.id = ${drugId}
    `.execute(db);
    return result.rows[0] ?? null;
  } catch {
    return null;
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Flex JSON types (structurally typed as `unknown`-friendly plain objects —
// this module only needs to build/return them, not validate against the
// full LINE Flex Message spec).
// ─────────────────────────────────────────────────────────────────────────

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type FlexComponent = Record<string, any>;

export function buildInfoRow(label: string, value: string): FlexComponent {
  return {
    type: 'box',
    layout: 'vertical',
    contents: [
      { type: 'text', text: label, size: 'xs', color: '#888888' },
      { type: 'text', text: value, size: 'sm', wrap: true, margin: 'xs' },
    ],
    margin: 'lg',
  };
}

function notFoundBubble(): FlexComponent {
  return {
    type: 'bubble',
    body: {
      type: 'box',
      layout: 'vertical',
      contents: [{ type: 'text', text: '❌ ไม่พบข้อมูลยา', weight: 'bold', color: '#EF4444' }],
    },
  };
}

export async function generateDrugCard(db: Kysely<TenantDB>, drugId: number): Promise<FlexComponent> {
  const drug = await getDrugDetails(db, drugId);
  if (!drug) {
    return notFoundBubble();
  }

  const price = toFloatOrZero(drug.sale_price ?? drug.price ?? 0);
  const originalPrice = isPhpTruthy(drug.sale_price) ? toFloatOrZero(drug.price) : null;
  const hasDiscount = Boolean(originalPrice) && (originalPrice as number) > price;
  const stock = toIntOrZero(drug.stock ?? 0);
  const inStock = stock > 0;
  const isPrescription = isPhpTruthy(drug.is_prescription ?? false);

  const priceContents: FlexComponent[] = [{ type: 'text', text: `฿${numberFormat2(price)}`, size: 'xl', weight: 'bold', color: '#06C755' }];
  if (hasDiscount) {
    priceContents.push({
      type: 'text',
      text: `฿${numberFormat2(originalPrice as number)}`,
      size: 'sm',
      color: '#AAAAAA',
      decoration: 'line-through',
      margin: 'sm',
    });
  }

  const infoContents: FlexComponent[] = [];
  if (!isPhpEmptyString(drug.dosage)) infoContents.push(buildInfoRow('💊 ขนาดยา', drug.dosage as string));
  if (!isPhpEmptyString(drug.usage_instructions)) infoContents.push(buildInfoRow('📋 วิธีใช้', drug.usage_instructions as string));
  if (!isPhpEmptyString(drug.side_effects)) infoContents.push(buildInfoRow('⚠️ ผลข้างเคียง', drug.side_effects as string));
  if (!isPhpEmptyString(drug.contraindications)) infoContents.push(buildInfoRow('🚫 ข้อห้ามใช้', drug.contraindications as string));

  const stockText = inStock ? `📦 เหลือ ${stock} ชิ้น` : '❌ สินค้าหมด';
  const stockColor = inStock ? '#888888' : '#EF4444';

  const bodyContents: FlexComponent[] = [{ type: 'text', text: drug.name, weight: 'bold', size: 'lg', wrap: true }];

  if (!isPhpEmptyString(drug.generic_name)) {
    bodyContents.push({ type: 'text', text: `(${drug.generic_name})`, size: 'sm', color: '#888888', margin: 'sm' });
  }

  if (isPrescription) {
    bodyContents.push({
      type: 'box',
      layout: 'horizontal',
      contents: [{ type: 'text', text: '💊 ยาควบคุมพิเศษ', size: 'xs', color: '#FFFFFF', align: 'center' }],
      backgroundColor: '#EF4444',
      cornerRadius: 'md',
      paddingAll: 'xs',
      margin: 'md',
      width: '120px',
    });
  }

  bodyContents.push({ type: 'box', layout: 'horizontal', contents: priceContents, margin: 'lg' });
  bodyContents.push({ type: 'text', text: stockText, size: 'xs', color: stockColor, margin: 'md' });

  if (infoContents.length > 0) {
    bodyContents.push({ type: 'separator', margin: 'lg' });
    bodyContents.push(...infoContents);
  }

  const buttons: FlexComponent[] = [];
  if (inStock && !isPrescription) {
    buttons.push({
      type: 'button',
      action: { type: 'message', label: '🛒 เพิ่มลงตะกร้า', text: `add ${drugId}` },
      style: 'primary',
      color: '#06C755',
    });
  } else if (isPrescription) {
    buttons.push({
      type: 'button',
      action: { type: 'message', label: '💬 ปรึกษาเภสัชกร', text: `consult ${drugId}` },
      style: 'primary',
      color: '#3B82F6',
    });
  }
  buttons.push({
    type: 'button',
    action: { type: 'message', label: '🔍 ตรวจสอบยาตีกัน', text: `check interaction ${drugId}` },
    style: 'secondary',
    margin: 'sm',
  });

  const bubble: FlexComponent = { type: 'bubble', size: 'mega' };

  if (!isPhpEmptyString(drug.image_url)) {
    bubble.hero = { type: 'image', url: drug.image_url, size: 'full', aspectRatio: '4:3', aspectMode: 'cover' };
  }

  bubble.body = { type: 'box', layout: 'vertical', contents: bodyContents, paddingAll: 'lg' };
  bubble.footer = { type: 'box', layout: 'vertical', contents: buttons, paddingAll: 'lg' };

  return bubble;
}
