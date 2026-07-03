<?php
/**
 * Property-Based Test: ProductRecommender → LINE Flex order carousel
 *
 * **Feature: aichat-close-the-loop (Phase 1 · Workstream B)**
 *
 * Verifies the connection between `ProductRecommender` output and the existing
 * `FlexTemplates::productCarousel` conversion Flex. Two bugs previously broke
 * this link:
 *   1. the recommender didn't SELECT `stock`, so every card fell into the
 *      `$product['stock'] > 0` else-branch and rendered "❌ สินค้าหมด";
 *   2. a missing `stock` key raised a warning and also read as out-of-stock.
 * `productCard` now only shows a stock line when stock is actually known, and
 * only hides the order CTA when stock is genuinely 0.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/FlexTemplates.php';

class RecommendationFlexPropertyTest extends TestCase
{
    private const ITERATIONS = 120;
    private const ADD_TO_CART = 'เพิ่มลงตะกร้า';
    private const OUT_OF_STOCK = 'สินค้าหมด';
    private const IN_STOCK = 'เหลือ';

    /** A recommender row, mirroring ProductRecommender::lookupCandidates SELECT. */
    private function recommenderProduct(array $overrides = []): array
    {
        return array_merge([
            'id'         => mt_rand(1, 9999),
            'name'       => 'ยาแก้ปวด ' . mt_rand(0, 999),
            'description' => 'desc',
            'price'      => mt_rand(20, 500),
            'sale_price' => null,
            'image_url'  => null,
            'stock'      => 25,
        ], $overrides);
    }

    private function encode(array $bubble): string
    {
        return json_encode($bubble, JSON_UNESCAPED_UNICODE) ?: '';
    }

    /** In-stock recommender product → order CTA present, stock line shown, not out-of-stock. */
    public function testInStockProductHasOrderCta(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $stock = mt_rand(1, 999);
            $json = $this->encode(\FlexTemplates::productCard($this->recommenderProduct(['stock' => $stock])));
            $this->assertStringContainsString(self::ADD_TO_CART, $json, 'in-stock card must offer add-to-cart');
            $this->assertStringContainsString(self::IN_STOCK, $json);
            $this->assertStringNotContainsString(self::OUT_OF_STOCK, $json);
        }
    }

    /** stock = 0 → no order CTA, out-of-stock shown. */
    public function testZeroStockHidesOrderCta(): void
    {
        $json = $this->encode(\FlexTemplates::productCard($this->recommenderProduct(['stock' => 0])));
        $this->assertStringNotContainsString(self::ADD_TO_CART, $json, 'out-of-stock must not offer add-to-cart');
        $this->assertStringContainsString(self::OUT_OF_STOCK, $json);
    }

    /**
     * Missing `stock` key (the bug) → must NOT render as out-of-stock, and the
     * order CTA must still be offered so recommendations stay actionable.
     */
    public function testMissingStockIsNotOutOfStock(): void
    {
        $p = $this->recommenderProduct();
        unset($p['stock']);
        $json = $this->encode(\FlexTemplates::productCard($p));
        $this->assertStringNotContainsString(self::OUT_OF_STOCK, $json, 'missing stock must not read as out-of-stock');
        $this->assertStringContainsString(self::ADD_TO_CART, $json, 'missing stock must still allow ordering');
        $this->assertStringNotContainsString(self::IN_STOCK, $json, 'unknown stock shows no stock line');
    }

    /** A carousel of recommender products is a valid Flex carousel (≤10 bubbles). */
    public function testCarouselFromRecommenderOutput(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $n = mt_rand(1, 15);
            $products = [];
            for ($j = 0; $j < $n; $j++) {
                $products[] = $this->recommenderProduct(['stock' => mt_rand(0, 50)]);
            }
            $carousel = \FlexTemplates::productCarousel($products);
            $this->assertSame('carousel', $carousel['type']);
            $this->assertLessThanOrEqual(10, count($carousel['contents']));
            $this->assertSame(min($n, 10), count($carousel['contents']));
            foreach ($carousel['contents'] as $bubble) {
                $this->assertSame('bubble', $bubble['type']);
            }
        }
    }
}
