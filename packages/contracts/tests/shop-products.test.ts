import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  SHOP_PRODUCTS_STATUS,
  ShopCategoriesQuerySchema,
  ShopCategoriesResponseSchema,
  ShopProductDetailQuerySchema,
  ShopProductDetailResponseSchema,
  ShopProductsQuerySchema,
  ShopProductsResponseSchema,
} from '../src/shop-products';

const FIXTURES_DIR = join(__dirname, '../fixtures/shop-products');

function loadFixture(name: string): { request: unknown; response: unknown; status: number } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('shop-products contracts — action=products (checkout.php handleGetProducts())', () => {
  it('products-ok: page with a discounted product + embedded categories/brands', () => {
    const fx = loadFixture('products-ok.json');
    expect(ShopProductsQuerySchema.parse(fx.request)).toBeTruthy();
    const parsed = ShopProductsResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true });
    if (parsed.success) {
      expect(parsed.products).toHaveLength(2);
      expect(parsed.products[0]?.badges).toEqual([{ text: '-22%', color: 'red' }]);
      expect(parsed.categories.length).toBeGreaterThan(0);
    }
    expect(fx.status).toBe(SHOP_PRODUCTS_STATUS);
  });

  it('products-empty-with-brand-filter-dead-column: no matches, categories/brands still populated', () => {
    const fx = loadFixture('products-empty-with-brand-filter-dead-column.json');
    expect(ShopProductsQuerySchema.parse(fx.request)).toBeTruthy();
    expect(ShopProductsResponseSchema.parse(fx.response)).toMatchObject({ success: true, products: [], total: 0 });
  });

  it('dead client-side params (include_zero_price/include_inactive/catalog_mode/catalog_bucket) still validate on the request', () => {
    expect(
      ShopProductsQuerySchema.parse({
        action: 'products',
        include_zero_price: '1',
        include_inactive: '0',
        catalog_mode: 'all',
        catalog_bucket: 'featured',
      })
    ).toBeTruthy();
  });
});

describe('shop-products contracts — action=product_detail (checkout.php handleGetProductDetail())', () => {
  it('product-detail-ok: is_favorite true via user_wishlist join', () => {
    const fx = loadFixture('product-detail-ok.json');
    expect(ShopProductDetailQuerySchema.parse(fx.request)).toBeTruthy();
    const parsed = ShopProductDetailResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true });
    if (parsed.success) {
      expect(parsed.product.is_favorite).toBe(true);
    }
  });

  it('product-detail-not-found: success:false, message set, still HTTP 200', () => {
    const fx = loadFixture('product-detail-not-found.json');
    expect(ShopProductDetailResponseSchema.parse(fx.response)).toEqual({
      success: false,
      message: 'Product not found',
    });
    expect(fx.status).toBe(200);
  });

  it('product-detail-missing-product-id: success:false, message set', () => {
    const fx = loadFixture('product-detail-missing-product-id.json');
    expect(ShopProductDetailResponseSchema.parse(fx.response)).toEqual({
      success: false,
      message: 'Missing product_id',
    });
  });
});

describe('shop-products contracts — action=categories (shop-products.php\'s own standalone branch)', () => {
  it('categories-plain: non-Odoo tenant -> numeric ids, no `code` key, category_id_is_string:false', () => {
    const fx = loadFixture('categories-plain.json');
    expect(ShopCategoriesQuerySchema.parse(fx.request)).toBeTruthy();
    const parsed = ShopCategoriesResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true, category_id_is_string: false });
    if (parsed.success) {
      expect(parsed.categories.every((c) => typeof c.id === 'number')).toBe(true);
      expect(parsed.categories.every((c) => c.code === undefined)).toBe(true);
    }
  });

  it('categories-odoo: shop_products storefront active -> string ids WITH a `code` key, category_id_is_string:true', () => {
    const fx = loadFixture('categories-odoo.json');
    const parsed = ShopCategoriesResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true, category_id_is_string: true });
    if (parsed.success) {
      expect(parsed.categories.every((c) => typeof c.id === 'string' && c.code === c.id)).toBe(true);
    }
  });
});
