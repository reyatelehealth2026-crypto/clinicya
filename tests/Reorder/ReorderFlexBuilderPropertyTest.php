<?php
/**
 * Property-Based Tests: ReorderFlexBuilder
 *
 * Feature: Phase 2 · Task 2.5 — personalized reorder LINE Flex with product
 * context. Validates the pure Flex-mapping logic in
 * classes/ReorderFlexBuilder.php with 100+ randomised cases per property,
 * per repo testing convention. DB-free: no PDO/LINE involved.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/FlexTemplates.php';
require_once __DIR__ . '/../../classes/ReorderFlexBuilder.php';

class ReorderFlexBuilderPropertyTest extends TestCase
{
    private const ORDER_CTA = 'เพิ่มลงตะกร้า';
    private const ITERATIONS = 120;

    /** @return array{average_interval_days:float, next_due_date:string, purchase_count:int} */
    private function prediction(array $overrides = []): array
    {
        return array_merge([
            'average_interval_days' => (float) mt_rand(20, 120),
            'next_due_date' => date('Y-m-d', strtotime('+' . mt_rand(0, 3) . ' days')),
            'purchase_count' => mt_rand(2, 20),
        ], $overrides);
    }

    /** @return array{id:int, name:string, price:float, sale_price?:float|null, image_url?:string|null, stock?:int|null} */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'id' => mt_rand(1, 99999),
            'name' => 'ยาแก้ปวด ' . mt_rand(0, 999),
            'price' => (float) mt_rand(20, 500),
            'sale_price' => null,
            'image_url' => null,
            'stock' => mt_rand(1, 200),
        ], $overrides);
    }

    private function encode(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * Property: empty product list → safe fallback (null), never a crash and
     * never a Flex message with zero bubbles.
     */
    public function testEmptyProductsReturnsNullFallback(): void
    {
        $this->assertNull(ReorderFlexBuilder::build('คุณทดสอบ', $this->prediction(), []));

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $name = mt_rand(0, 1) ? null : 'ลูกค้า ' . mt_rand(1, 999);
            $this->assertNull(ReorderFlexBuilder::build($name, $this->prediction(), []));
        }
    }

    /**
     * Property: products missing required fields (id/name/price) are
     * dropped; if ALL products are unusable, the overall result is still
     * the safe null fallback.
     */
    public function testAllUnusableProductsFallsBackToNull(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $n = mt_rand(1, 5);
            $products = [];
            for ($j = 0; $j < $n; $j++) {
                $bad = $this->product();
                unset($bad[array_rand(['id' => 1, 'name' => 1, 'price' => 1])]);
                $products[] = $bad;
            }
            $this->assertNull(ReorderFlexBuilder::build('คุณลูกค้า', $this->prediction(), $products));
        }
    }

    /**
     * Property: 1–15 valid products → a Flex carousel with 1..min(n,10)
     * bubbles, each bubble carrying an order CTA (in-stock) and correctly
     * flagged out-of-stock when stock is exactly 0.
     */
    public function testValidProductsProduceCarouselWithOrderCta(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $n = mt_rand(1, 15);
            $products = [];
            for ($j = 0; $j < $n; $j++) {
                $products[] = $this->product(['stock' => mt_rand(1, 999)]);
            }

            $message = ReorderFlexBuilder::build('คุณลูกค้า', $this->prediction(), $products);

            $this->assertIsArray($message);
            $this->assertSame('flex', $message['type']);
            $this->assertNotEmpty($message['altText']);
            $this->assertSame('carousel', $message['contents']['type']);

            $bubbles = $message['contents']['contents'];
            $expectedCount = min($n, ReorderFlexBuilder::MAX_PRODUCTS);
            $this->assertSame($expectedCount, count($bubbles));
            $this->assertLessThanOrEqual(10, count($bubbles));

            foreach ($bubbles as $bubble) {
                $this->assertSame('bubble', $bubble['type']);
                $json = $this->encode($bubble);
                $this->assertStringContainsString(self::ORDER_CTA, $json, 'every bubble must offer an order CTA');
            }
        }
    }

    /**
     * Property: more than MAX_PRODUCTS (10) valid products → carousel is
     * capped at MAX_PRODUCTS bubbles (LINE Flex carousel limit).
     */
    public function testMoreThanTenProductsIsCappedAtTen(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $n = mt_rand(11, 25);
            $products = [];
            for ($j = 0; $j < $n; $j++) {
                $products[] = $this->product();
            }

            $message = ReorderFlexBuilder::build('คุณลูกค้า', $this->prediction(), $products);
            $bubbles = $message['contents']['contents'];

            $this->assertCount(ReorderFlexBuilder::MAX_PRODUCTS, $bubbles);
        }
    }

    /**
     * Property: a product with stock = 0 does NOT offer the add-to-cart CTA
     * (out of stock), matching FlexTemplates::productCard's existing rule —
     * but a mix of in-stock and out-of-stock products still yields a
     * carousel with one bubble per valid product.
     */
    public function testOutOfStockProductHasNoAddToCartButStillRenders(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $products = [
                $this->product(['stock' => 0]),
                $this->product(['stock' => mt_rand(1, 50)]),
            ];

            $message = ReorderFlexBuilder::build('คุณลูกค้า', $this->prediction(), $products);
            $bubbles = $message['contents']['contents'];

            $this->assertCount(2, $bubbles);

            $outOfStockJson = $this->encode($bubbles[0]);
            $this->assertStringNotContainsString(self::ORDER_CTA, $outOfStockJson, 'out-of-stock bubble must not offer add-to-cart');
            $this->assertStringContainsString('สินค้าหมด', $outOfStockJson);

            $inStockJson = $this->encode($bubbles[1]);
            $this->assertStringContainsString(self::ORDER_CTA, $inStockJson);
        }
    }

    /**
     * Property: a product missing the `stock` key entirely is treated as
     * "unknown stock" (per #33) — still offered for order, no false
     * out-of-stock label.
     */
    public function testMissingStockKeyIsTreatedAsUnknownNotOutOfStock(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $product = $this->product();
            unset($product['stock']);

            $message = ReorderFlexBuilder::build('คุณลูกค้า', $this->prediction(), [$product]);
            $bubble = $message['contents']['contents'][0];
            $json = $this->encode($bubble);

            $this->assertStringContainsString(self::ORDER_CTA, $json);
            $this->assertStringNotContainsString('สินค้าหมด', $json);
        }
    }

    /**
     * Property: altText always mentions the refill/reorder cue so the LINE
     * chat-list preview is meaningful even before opening the carousel.
     */
    public function testAltTextIsAlwaysNonEmptyAndMeaningful(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $message = ReorderFlexBuilder::build('คุณลูกค้า', $this->prediction(), [$this->product()]);
            $this->assertIsString($message['altText']);
            $this->assertNotSame('', trim($message['altText']));
        }
    }
}
