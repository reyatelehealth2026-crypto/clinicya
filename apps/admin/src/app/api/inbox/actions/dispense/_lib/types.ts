import type { MedicineLabelItem } from '@reya/line';

/**
 * types.ts — the dispense-item shape passed around `case 'dispense':`'s port. Each item arrives
 * as an arbitrary JSON object from the client (mirrors PHP's `json_decode($_POST['items'],
 * true)` producing a loosely-typed associative array) and is progressively hydrated from
 * `business_items` before being handed to `@reya/line`'s `medicineLabel()` /
 * `medicineLabelsCarousel()` (whose `MedicineLabelItem` type covers only the fields those
 * two functions read — it has no `product_id`/`qty` because the pure Flex-template layer never
 * reads them by that name, see packages/line/src/flex.ts's own module doc).
 *
 * `DispenseItem` extends `MedicineLabelItem` with the additional fields
 * `case 'dispense':` itself reads: `product_id` (business_items FK, hydration/stock/cart/
 * refill-tracking key), and a catch-all index signature so unknown extra fields the client sent
 * pass through untouched (PHP's associative array never drops keys it doesn't recognize).
 */
export interface DispenseItem extends MedicineLabelItem {
  product_id?: number | string | null;
  [key: string]: unknown;
}
