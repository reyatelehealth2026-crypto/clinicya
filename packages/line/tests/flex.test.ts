import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  buildQuickReply,
  medicineLabel,
  medicineLabelsCarousel,
  phpNumberFormat,
  toMessage,
  type FlexContainer,
  type MedicineLabelItem,
  type QuickReplyInput,
  type QuickReplyItemInput,
  type SenderInput,
  type ShopInfo,
} from '../src/flex';

const FIXTURES_DIR = join(__dirname, '../src/__fixtures__');

interface Fixture {
  description: string;
  request: Record<string, unknown>;
  response: unknown;
}

function loadFixture(name: string): Fixture {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8')) as Fixture;
}

function listFixtureFiles(): string[] {
  return readdirSync(FIXTURES_DIR)
    .filter((f) => f.endsWith('.json'))
    .sort();
}

/**
 * PARITY EXCEPTION (documented, not silently ignored — see packages/line/src/flex.ts's
 * "PARITY NOTES" #1 and packages/line/scripts/generate-fixtures.php's header comment):
 * medicineLabel()'s "วันที่จ่ายยา" field is PHP's bare `date('d/m/Y')` at call time — the fixture
 * was captured on whatever day `generate-fixtures.php` last ran, and the TS port computes
 * "today" independently when this test runs. Every OTHER field in the JSON tree must match
 * exactly; this is the one deliberately-excluded field, normalized identically on both the
 * fixture's recorded response and the TS port's freshly computed output before comparing.
 *
 * Verified safe (not just assumed) via a one-off scan of every committed fixture: the `d/m/Y`
 * date string is the ONLY place in any of these Flex trees where a bare two-digit/two-digit/
 * four-digit slash-separated string appears — see the build report for the scan. Nothing else
 * in a medicineLabel() bubble (prices are "฿"-prefixed, names/notes are Thai prose, etc.) can
 * collide with this pattern.
 */
const DMY_DATE_PATTERN = /^\d{2}\/\d{2}\/\d{4}$/;
const NORMALIZED_DATE_PLACEHOLDER = '__NORMALIZED_DATE__';

function normalizeDates<T>(node: T): T {
  if (Array.isArray(node)) {
    return node.map((child) => normalizeDates(child)) as unknown as T;
  }
  if (node !== null && typeof node === 'object') {
    const out: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
      out[key] = normalizeDates(value);
    }
    return out as T;
  }
  if (typeof node === 'string' && DMY_DATE_PATTERN.test(node)) {
    return NORMALIZED_DATE_PLACEHOLDER as unknown as T;
  }
  return node;
}

/** Dispatches a fixture's `request.fn` to the matching ported function, exactly reproducing
 * each function's real PHP call signature (see flex.ts's module doc comment). */
function callPortedFunction(request: Record<string, unknown>): unknown {
  switch (request.fn) {
    case 'medicineLabel':
      return medicineLabel(
        request.item as MedicineLabelItem,
        request.shopInfo as ShopInfo,
        request.patientName as string,
        request.checkoutUrl as string | null
      );
    case 'medicineLabelsCarousel':
      return medicineLabelsCarousel(
        request.items as MedicineLabelItem[],
        request.shopInfo as ShopInfo,
        request.patientName as string,
        request.checkoutUrl as string | null
      );
    case 'toMessage':
      return toMessage(
        request.contents as FlexContainer,
        request.altText as string,
        request.sender as SenderInput,
        request.quickReply as QuickReplyInput
      );
    default:
      throw new Error(`fixture request.fn is not a recognized ported function: ${String(request.fn)}`);
  }
}

describe('flex.ts — golden fixture round-trip against real PHP FlexTemplates output', () => {
  const files = listFixtureFiles();

  // Fails loudly (rather than silently passing on an empty directory) if fixtures go missing —
  // this number must stay >= 12 per the porting brief's acceptance criteria.
  it('has at least 12 committed fixtures', () => {
    expect(files.length).toBeGreaterThanOrEqual(12);
  });

  for (const file of files) {
    it(`${file} — matches PHP output (date field normalized)`, () => {
      const fixture = loadFixture(file);
      const actual = callPortedFunction(fixture.request);

      const normalizedActual = normalizeDates(actual);
      const normalizedExpected = normalizeDates(fixture.response);

      expect(normalizedActual).toEqual(normalizedExpected);
    });
  }
});

describe('medicineLabelsCarousel() — summary-bubble total math (PHP number_format semantics)', () => {
  it('three-items-with-checkout fixture: total = Σ(price×qty) = 1234.5 -> "1,234.50"', () => {
    const fixture = loadFixture('medicine-labels-carousel-three-items-with-checkout.json');
    const items = fixture.request.items as MedicineLabelItem[];

    const total = items.reduce((sum, item) => sum + (item.price ?? 0) * (item.qty ?? 1), 0);
    expect(total).toBe(1234.5);
    expect(phpNumberFormat(total, 2)).toBe('1,234.50');

    const response = fixture.response as FlexContainer;
    if (response.type !== 'carousel') throw new Error('expected a carousel');
    const summaryBubble = response.contents[response.contents.length - 1]!;
    const totalRow = summaryBubble.body!.contents[summaryBubble.body!.contents.length - 1] as {
      contents: Array<{ text: string | null }>;
    };
    expect(totalRow.contents[1]!.text).toBe('฿1,234.50');
  });
});

describe('medicineLabel() — pinned dead-code parity (PHP source, not a design choice)', () => {
  it('timeOfDay has zero effect on output: all-4-ticked vs none-ticked bubbles are identical', () => {
    const allTicked = loadFixture('medicine-label-time-of-day-all.json');
    const noneTicked = loadFixture('medicine-label-time-of-day-none.json');

    const actualAll = normalizeDates(medicineLabel(
      allTicked.request.item as MedicineLabelItem,
      allTicked.request.shopInfo as ShopInfo,
      allTicked.request.patientName as string,
      allTicked.request.checkoutUrl as string | null
    ));
    const actualNone = normalizeDates(medicineLabel(
      noneTicked.request.item as MedicineLabelItem,
      noneTicked.request.shopInfo as ShopInfo,
      noneTicked.request.patientName as string,
      noneTicked.request.checkoutUrl as string | null
    ));

    expect(actualAll).toEqual(actualNone);
  });

  it('isMedicine=false suppresses the warnings box even when specialInstructions is ticked', () => {
    const fixture = loadFixture('medicine-label-not-medicine-external.json');
    const item = fixture.request.item as MedicineLabelItem;
    expect(item.isMedicine).toBe(false);
    expect(item.specialInstructions?.length).toBeGreaterThan(0);

    const bubble = medicineLabel(item, fixture.request.shopInfo as ShopInfo, fixture.request.patientName as string, fixture.request.checkoutUrl as string | null);
    const bodyTexts = JSON.stringify(bubble.body);
    expect(bodyTexts).not.toContain('คำเตือน');
  });
});

describe('buildQuickReply() — all 6 action-shape branches', () => {
  it('covers camera / cameraRoll / location / uri / postback(data) / message(default) in one call', () => {
    const items: QuickReplyItemInput[] = [
      { type: 'camera', label: 'ถ่ายรูป' },
      { type: 'cameraRoll' },
      { type: 'location', label: 'ตำแหน่ง' },
      { label: 'เว็บไซต์', uri: 'https://example.com' },
      { label: 'ยืนยัน', data: 'action=x', displayText: 'ยืนยันแล้ว' },
      { label: 'เมนู', text: 'menu' },
    ];

    const result = buildQuickReply(items);
    expect(result).not.toBeNull();
    const actions = result!.items.map((i) => i.action.type);
    expect(actions).toEqual(['camera', 'cameraRoll', 'location', 'uri', 'postback', 'message']);
  });

  it('preset lookup ("main") returns FlexTemplates::$quickReplySets[\'main\'] verbatim', () => {
    const result = buildQuickReply([], 'main');
    expect(result).toEqual({
      items: [
        { type: 'action', action: { type: 'message', label: '🛒 ดูสินค้า', text: 'shop' } },
        { type: 'action', action: { type: 'message', label: '📋 เมนู', text: 'menu' } },
        { type: 'action', action: { type: 'message', label: '🛍️ ตะกร้า', text: 'cart' } },
        { type: 'action', action: { type: 'message', label: '📦 ออเดอร์', text: 'orders' } },
      ],
    });
  });

  it('empty items and no preset returns null (PHP: empty($items) -> return null)', () => {
    expect(buildQuickReply([])).toBeNull();
    expect(buildQuickReply()).toBeNull();
  });
});

describe('phpNumberFormat() — PHP number_format() semantics', () => {
  it('matches PHP number_format() for the documented rounding/grouping cases', () => {
    // Values cross-checked directly against `php -r 'echo number_format(...);'` — see build report.
    expect(phpNumberFormat(1234.5, 2)).toBe('1,234.50');
    expect(phpNumberFormat(1178.75)).toBe('1,179');
    expect(phpNumberFormat(24.75)).toBe('25');
    expect(phpNumberFormat(31)).toBe('31');
    expect(phpNumberFormat(0.005, 2)).toBe('0.01');
    expect(phpNumberFormat(70, 2)).toBe('70.00');
  });
});
