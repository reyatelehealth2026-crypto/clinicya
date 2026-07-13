/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { getCategoriesAction, getProductDetailAction, getProductsAction } from './query';

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
  category_name: 'ยาสามัญประจำบ้าน',
  image_gallery: null,
  photo_path: 'uploads/para500.jpg',
  is_flash_sale: 0,
  // The fake DB below returns canned rows verbatim (it does not execute real SQL), so this must
  // already reflect what the real `COALESCE(NULLIF(image_url,''), NULLIF(photo_path,''))` expression
  // in query.ts's SELECT would produce against a real row with a null image_url column.
  image_url: 'uploads/para500.jpg',
  is_favorite: 0,
};

describe('getProductsAction', () => {
  it('maps rows through normalization: discount badge/label computed from sale_price < price', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM business_items p') && sqlText.includes('LIMIT')) return [BASE_PRODUCT_ROW];
      if (sqlText.includes('COUNT(*) AS total')) return [{ total: 1 }];
      if (sqlText.includes('FROM business_categories')) return [{ id: 3, name: 'ยาสามัญประจำบ้าน', icon_url: null }];
      if (sqlText.includes('DISTINCT manufacturer')) return [{ manufacturer: 'GPO' }];
      return [];
    });

    const result = await getProductsAction(db, {
      lineAccountId: '1',
      categoryId: null,
      search: '',
      sort: undefined,
      brand: '',
      lineUserId: null,
      limit: 12,
      offset: 0,
    });

    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.products[0]).toMatchObject({
        id: 101,
        discount_percent: 22,
        promotion_label: 'โปรโมชัน',
        badges: [{ text: '-22%', color: 'red' }],
        image_url: 'uploads/para500.jpg', // falls back to photo_path via buildImageGallery's coalesce input
        brand: 'GPO',
      });
      expect(result.categories).toEqual([{ id: 3, name: 'ยาสามัญประจำบ้าน', icon_url: null }]);
      expect(result.brands).toEqual(['GPO']);
      expect(result.total).toBe(1);
      expect(result.has_more).toBe(false);
    }
  });

  it('no line_user_id -> is_favorite always false, wishlist JOIN not attempted', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('LIMIT') && sqlText.includes('business_items p')) {
        return [{ ...BASE_PRODUCT_ROW, sale_price: null, is_favorite: 0 }];
      }
      if (sqlText.includes('COUNT(*)')) return [{ total: 1 }];
      return [];
    });

    const result = await getProductsAction(db, {
      lineAccountId: null,
      categoryId: null,
      search: '',
      sort: undefined,
      brand: '',
      lineUserId: null,
      limit: 12,
      offset: 0,
    });

    expect(result.success && result.products[0]?.is_favorite).toBe(false);
    expect(queries.some((q) => q.sql.includes('FROM users WHERE line_user_id'))).toBe(false);
  });

  it('has_more is true when offset+limit < total', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('LIMIT') && sqlText.includes('business_items p')) return [BASE_PRODUCT_ROW];
      if (sqlText.includes('COUNT(*)')) return [{ total: 50 }];
      return [];
    });

    const result = await getProductsAction(db, {
      lineAccountId: null,
      categoryId: null,
      search: '',
      sort: undefined,
      brand: '',
      lineUserId: null,
      limit: 12,
      offset: 0,
    });

    expect(result.success && result.has_more).toBe(true);
  });

  it('empty result set still returns populated categories/brands (independent queries)', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('LIMIT') && sqlText.includes('business_items p')) return [];
      if (sqlText.includes('COUNT(*)')) return [{ total: 0 }];
      if (sqlText.includes('FROM business_categories')) return [{ id: 3, name: 'X', icon_url: null }];
      return [];
    });

    const result = await getProductsAction(db, {
      lineAccountId: null,
      categoryId: null,
      search: 'zzz',
      sort: undefined,
      brand: '',
      lineUserId: null,
      limit: 12,
      offset: 0,
    });

    expect(result).toMatchObject({ success: true, products: [], total: 0, categories: [{ id: 3, name: 'X' }] });
  });
});

describe('getProductDetailAction', () => {
  it('productId <= 0 -> Missing product_id, no query issued', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>();
    const result = await getProductDetailAction(db, 0, null, null);
    expect(result).toEqual({ success: false, message: 'Missing product_id' });
    expect(queries).toHaveLength(0);
  });

  it('no matching row -> Product not found', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => []);
    const result = await getProductDetailAction(db, 999, '1', null);
    expect(result).toEqual({ success: false, message: 'Product not found' });
  });

  it('matching row -> success with normalized product', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => [BASE_PRODUCT_ROW]);
    const result = await getProductDetailAction(db, 101, '1', null);
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.product.id).toBe(101);
      expect(result.message).toBe('');
    }
  });

  it('a thrown query error is caught -> Error loading product: <message>', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => {
      throw new Error('connection lost');
    });
    const result = await getProductDetailAction(db, 101, null, null);
    expect(result).toEqual({ success: false, message: 'Error loading product: connection lost' });
  });
});

describe('getCategoriesAction', () => {
  it('useOdoo:false -> item_categories rows, numeric ids, category_id_is_string:false', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM item_categories')) {
        return [
          { id: 3, name: 'ยาสามัญประจำบ้าน' },
          { id: 4, name: 'วิตามิน' },
        ];
      }
      return [];
    });

    const result = await getCategoriesAction(db, { lineAccountId: 1, useOdoo: false });

    expect(result).toEqual({
      success: true,
      categories: [
        { id: 3, name: 'ยาสามัญประจำบ้าน' },
        { id: 4, name: 'วิตามิน' },
      ],
      category_id_is_string: false,
    });
  });

  it('item_categories throws -> falls back to product_categories', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM item_categories')) throw new Error('table missing');
      if (sqlText.includes('FROM product_categories')) return [{ id: 9, name: 'Fallback' }];
      return [];
    });

    const result = await getCategoriesAction(db, { lineAccountId: 1, useOdoo: false });
    expect(result).toEqual({ success: true, categories: [{ id: 9, name: 'Fallback' }], category_id_is_string: false });
  });

  it('both category tables throw -> empty categories array, still success:true', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => {
      throw new Error('nope');
    });
    const result = await getCategoriesAction(db, { lineAccountId: 1, useOdoo: false });
    expect(result).toEqual({ success: true, categories: [], category_id_is_string: false });
  });

  it('useOdoo:true -> DISTINCT category strings double as id/name/code, category_id_is_string:true', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM shop_products')) return [{ category: 'ยาแก้ปวด' }, { category: 'อาหารเสริม' }];
      return [];
    });

    const result = await getCategoriesAction(db, { lineAccountId: 7, useOdoo: true });

    expect(result).toEqual({
      success: true,
      categories: [
        { id: 'ยาแก้ปวด', name: 'ยาแก้ปวด', code: 'ยาแก้ปวด' },
        { id: 'อาหารเสริม', name: 'อาหารเสริม', code: 'อาหารเสริม' },
      ],
      category_id_is_string: true,
    });
  });

  it('a query throw in the useOdoo branch -> success:false with error message', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => {
      throw new Error('shop_products unreachable');
    });
    const result = await getCategoriesAction(db, { lineAccountId: 7, useOdoo: true });
    expect(result).toEqual({ success: false, error: 'shop_products unreachable' });
  });
});
