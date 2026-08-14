import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import {
  getCatalogCategories,
  getCatalogProducts,
  getCatalogSegmentsForParity,
  getCatalogUserTagsForParity,
  toCatalogBuilderCategories,
  toCatalogBuilderProducts,
  type CatalogBuilderProduct,
} from './catalog-queries';
import type { ShopCategoryItem, ShopProduct } from '@reya/contracts';

const BASE_PRODUCT_ROW = {
  id: 101,
  name: 'พาราเซตามอล 500mg',
  description: 'ยาลดไข้',
  price: '45.00',
  sale_price: '35.00',
  stock: 240,
  sku: 'MED-PARA-500',
  barcode: '8850001234567',
  manufacturer: 'GPO',
  generic_name: 'Paracetamol',
  usage_instructions: 'รับประทานครั้งละ 1-2 เม็ด',
  properties_other: null,
  unit: 'แผง',
  category_id: 3,
  image_gallery: null,
  photo_path: 'uploads/para500.jpg',
  is_flash_sale: 0,
  image_url: 'uploads/para500.jpg',
};

describe('getCatalogProducts', () => {
  it('scopes by is_active=1, stock>0, and line_account_id when lineAccountId > 0', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) return [BASE_PRODUCT_ROW];
      return [];
    });

    const result = await getCatalogProducts(db, 7);

    const productQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(productQuery?.sql).toContain('is_active = 1');
    expect(productQuery?.sql).toContain('stock > 0');
    expect(productQuery?.sql).toContain('line_account_id');
    expect(productQuery?.sql).toContain('LIMIT');
    expect(productQuery?.params).toContain(7);

    expect(result).toHaveLength(1);
    expect(result[0]).toMatchObject({
      id: 101,
      name: 'พาราเซตามอล 500mg',
      category_name: null,
      is_favorite: false,
      discount_percent: 22,
      promotion_label: 'โปรโมชัน',
      badges: [{ text: '-22%', color: 'red' }],
    });
  });

  it('omits the line_account_id clause entirely when lineAccountId <= 0', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getCatalogProducts(db, 0);
    const productQuery = queries.find((q) => q.sql.includes('FROM business_items'));
    expect(productQuery?.sql).not.toContain('line_account_id');
  });

  it('returns the same ShopProduct shape the shop-products precedent uses (structural check)', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) return [BASE_PRODUCT_ROW];
      return [];
    });
    const [product] = await getCatalogProducts(db, 1);
    const shaped: ShopProduct = product!;
    // Type-level assertion: this compiles only if getCatalogProducts()'s
    // return element is assignable to ShopProduct (the shop-products
    // precedent's own contract type).
    expect(shaped.id).toBe(101);
  });
});

describe('getCatalogCategories', () => {
  it('reads from product_categories (not business_categories), scoped by is_active + line_account_id', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM product_categories')) return [{ id: 3, name: 'ยาแก้ปวด' }];
      return [];
    });

    const result = await getCatalogCategories(db, 7);

    const categoryQuery = queries.find((q) => q.sql.includes('product_categories'));
    expect(categoryQuery?.sql).toContain('is_active = 1');
    expect(categoryQuery?.sql).toContain('line_account_id');
    expect(categoryQuery?.sql).toContain('sort_order');
    expect(categoryQuery?.sql).not.toContain('business_categories');
    expect(result).toEqual([{ id: 3, name: 'ยาแก้ปวด' }]);
  });

  it('falls back to an empty-string name when the row name is null', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM product_categories')) return [{ id: 9, name: null }];
      return [];
    });
    const result = await getCatalogCategories(db, 1);
    expect(result).toEqual([{ id: 9, name: '' }]);
  });
});

describe('getCatalogSegmentsForParity (dead-but-fetched, catalog.php lines 25-30)', () => {
  it('maps rows with no line_account_id filter (verbatim PHP query)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM customer_segments')) {
        return [{ id: 1, name: 'VIP', description: 'ลูกค้าประจำ', user_count: 42 }];
      }
      return [];
    });

    const result = await getCatalogSegmentsForParity(db);

    const segQuery = queries.find((q) => q.sql.includes('customer_segments'));
    expect(segQuery?.sql).not.toContain('line_account_id');
    expect(result).toEqual([{ id: 1, name: 'VIP', description: 'ลูกค้าประจำ', userCount: 42 }]);
  });

  it('swallows a thrown error and returns [] (matches PHP try/catch(Exception){})', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'customer_segments' doesn't exist");
    });
    await expect(getCatalogSegmentsForParity(db)).resolves.toEqual([]);
  });
});

describe('getCatalogUserTagsForParity (dead-but-fetched, catalog.php lines 33-38)', () => {
  it('scopes by line_account_id OR NULL', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM user_tags')) return [{ id: 5, name: 'ลูกค้าใหม่', color: '#06C755' }];
      return [];
    });

    const result = await getCatalogUserTagsForParity(db, 3);

    const tagQuery = queries.find((q) => q.sql.includes('user_tags'));
    expect(tagQuery?.sql).toContain('line_account_id');
    expect(tagQuery?.params).toContain(3);
    expect(result).toEqual([{ id: 5, name: 'ลูกค้าใหม่', color: '#06C755' }]);
  });

  it('swallows a thrown error and returns []', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    await expect(getCatalogUserTagsForParity(db, 1)).resolves.toEqual([]);
  });
});

describe('toCatalogBuilderProducts (catalog.php lines 17-23 $productsJson trim)', () => {
  function shopProduct(overrides: Partial<ShopProduct> = {}): ShopProduct {
    return {
      id: 1,
      name: 'สินค้า A',
      description: null,
      price: '100.00',
      sale_price: null,
      stock: 5,
      sku: null,
      barcode: null,
      manufacturer: null,
      generic_name: null,
      usage_instructions: null,
      properties_other: null,
      unit: null,
      category_id: 3,
      category_name: null,
      image_gallery: [],
      photo_path: null,
      image_url: null,
      is_favorite: false,
      is_flash_sale: 0,
      promotion_label: null,
      discount_percent: null,
      badges: [],
      brand: null,
      ...overrides,
    };
  }

  it('prefers sale_price over price via PHP `?:` truthiness (not `??`)', () => {
    const [mapped] = toCatalogBuilderProducts([shopProduct({ price: '100.00', sale_price: '80.00' })]);
    expect(mapped).toMatchObject<Partial<CatalogBuilderProduct>>({ price: 80 });
  });

  it('falls back to price when sale_price is the falsy string "0" (PHP `?:` semantics)', () => {
    const [mapped] = toCatalogBuilderProducts([shopProduct({ price: '100.00', sale_price: '0' })]);
    expect(mapped?.price).toBe(100);
  });

  it('treats sale_price "0.00" as truthy (PHP `?:` only rejects the exact string "0")', () => {
    const [mapped] = toCatalogBuilderProducts([shopProduct({ price: '100.00', sale_price: '0.00' })]);
    expect(mapped?.price).toBe(0);
  });

  it('falls back to the placeholder image when image_url is null/empty', () => {
    const [mapped] = toCatalogBuilderProducts([shopProduct({ image_url: null })]);
    expect(mapped?.image).toBe('https://via.placeholder.com/100');
  });

  it('uses the real image_url when present', () => {
    const [mapped] = toCatalogBuilderProducts([shopProduct({ image_url: 'https://cdn.example.com/x.jpg' })]);
    expect(mapped?.image).toBe('https://cdn.example.com/x.jpg');
  });

  it('carries category_id through as categoryId', () => {
    const [mapped] = toCatalogBuilderProducts([shopProduct({ category_id: 42 })]);
    expect(mapped?.categoryId).toBe(42);
  });
});

describe('toCatalogBuilderCategories', () => {
  it('stringifies id and keeps name', () => {
    const categories: ShopCategoryItem[] = [{ id: 3, name: 'ยาแก้ปวด' }];
    expect(toCatalogBuilderCategories(categories)).toEqual([{ id: '3', name: 'ยาแก้ปวด' }]);
  });
});
