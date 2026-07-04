<?php
/**
 * ReorderFlexBuilder — pure builder for the personalized reorder Flex carousel (Phase 2 · Task 2.5).
 *
 * `cron/reorder_reminder.php` already predicts *when* a customer is due for a
 * refill (via `ReorderCycle`) and sends a generic "ถึงเวลาเติมยา" text/Flex.
 * This class maps a customer's previously-bought products into a
 * `FlexTemplates::productCarousel()`-compatible Flex message, each bubble
 * carrying an order CTA ("สั่งซื้อ" / add-to-cart), so the reminder is
 * actionable instead of generic.
 *
 * No DB / LINE access here — pure functions only, so this is trivially
 * unit-testable. Callers are responsible for fetching the customer's
 * previously-bought products (see cron/reorder_reminder.php) and deciding
 * what to send when there are no biuldable products (fallback to the
 * existing generic text/Flex reminder).
 */
class ReorderFlexBuilder
{
    /** FlexTemplates::productCarousel caps at 10 bubbles; mirror that here. */
    public const MAX_PRODUCTS = 10;

    /**
     * Build a personalized reorder Flex carousel from a customer's
     * previously-bought products.
     *
     * @param string|null $displayName Customer's LINE display name.
     * @param array{average_interval_days:float, next_due_date:string, purchase_count:int} $prediction
     *        Result of ReorderCycle::predict().
     * @param array<int, array{id:int|string, name:string, price:float|int, sale_price?:float|int|null, image_url?:string|null, stock?:int|null}> $products
     *        Customer's previously-bought products, most-relevant first
     *        (e.g. most recently/frequently purchased). Only the first
     *        MAX_PRODUCTS are used.
     *
     * @return array{type:string, altText:string, contents:array}|null
     *         A LINE Flex message (via FlexTemplates::toMessage), or null
     *         when there are no usable products — callers should fall back
     *         to the generic text/Flex reminder in that case.
     */
    public static function build(?string $displayName, array $prediction, array $products): ?array
    {
        $bubbles = self::buildBubbles($products);
        if (empty($bubbles)) {
            return null;
        }

        $name = $displayName ?: 'คุณลูกค้า';
        $dueDateThai = date('d/m/Y', strtotime($prediction['next_due_date']));

        $carousel = ['type' => 'carousel', 'contents' => $bubbles];

        return FlexTemplates::toMessage(
            $carousel,
            "🔁 ถึงเวลาเติมยา {$name} ({$dueDateThai})"
        );
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>> Flex bubbles (via FlexTemplates::productCard).
     */
    private static function buildBubbles(array $products): array
    {
        $bubbles = [];
        foreach (array_slice(array_values($products), 0, self::MAX_PRODUCTS) as $product) {
            $normalized = self::normalizeProduct($product);
            if ($normalized === null) {
                continue;
            }
            $bubbles[] = FlexTemplates::productCard($normalized);
        }
        return $bubbles;
    }

    /**
     * Normalize a raw product row into the shape FlexTemplates::productCard
     * expects. Returns null for rows missing the minimum required fields
     * (id, name, price) — those can't be rendered as an order-able card.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private static function normalizeProduct(array $product): ?array
    {
        if (!isset($product['id'], $product['name'], $product['price'])) {
            return null;
        }
        if ($product['id'] === '' || $product['id'] === null) {
            return null;
        }
        if ($product['name'] === '' || $product['name'] === null) {
            return null;
        }

        return [
            'id' => $product['id'],
            'name' => (string) $product['name'],
            'price' => (float) $product['price'],
            'sale_price' => isset($product['sale_price']) && $product['sale_price'] !== ''
                ? (float) $product['sale_price']
                : null,
            // productCard reads $product['image_url'] directly (no null-coalesce),
            // so the key must always be present.
            'image_url' => !empty($product['image_url']) ? (string) $product['image_url'] : null,
            // Always carry the `stock` key (per #33): productCard treats a
            // present-but-null value the same as "unknown stock" (no stock
            // line, order CTA still shown) rather than misreading a missing
            // key as out-of-stock.
            'stock' => (isset($product['stock']) && $product['stock'] !== '')
                ? (int) $product['stock']
                : null,
        ];
    }
}
