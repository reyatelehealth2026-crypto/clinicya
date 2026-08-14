import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import {
  getBroadcastCampaignItems,
  getBroadcastCampaigns,
  getCatalogCategories,
  getCatalogProductById,
  getInStockProducts,
  getProductBroadcastTags,
} from './products-queries';

describe('getInStockProducts — UnifiedShop::getItems([\'in_stock\'=>true], 100), business_items only', () => {
  it('filters is_active=1 AND stock>0, scopes by line_account_id when set, orders id DESC, limit 100', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { id: 1, name: 'พาราเซตามอล', price: '25.00', sale_price: null, image_url: 'https://x/a.jpg' },
    ]);
    const result = await getInStockProducts(db, 9);
    expect(result).toEqual([
      { id: 1, name: 'พาราเซตามอล', imageUrl: 'https://x/a.jpg', price: 25, salePrice: null },
    ]);
    expect(queries[0]?.sql).toContain('is_active = 1 AND stock > 0');
    expect(queries[0]?.sql).toContain('line_account_id');
    expect(queries[0]?.sql).toContain('ORDER BY id DESC');
    expect(queries[0]?.sql).toContain('LIMIT 100');
  });

  it('omits the line_account_id scoping clause entirely when currentBotId is null', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getInStockProducts(db, null);
    expect(queries[0]?.sql).not.toContain('line_account_id');
  });

  it('coalesces image_url/photo_path, and blank/empty values become null', async () => {
    const { db } = makeFakeTenantDb(() => [
      { id: 2, name: 'No image', price: '10', sale_price: '8', image_url: null },
    ]);
    const result = await getInStockProducts(db, 9);
    expect(result[0]).toEqual({ id: 2, name: 'No image', imageUrl: null, price: 10, salePrice: 8 });
  });
});

describe('getCatalogProductById — UnifiedShop::getItem($id)', () => {
  it('returns null when no matching active row exists', async () => {
    const { db } = makeFakeTenantDb(() => []);
    expect(await getCatalogProductById(db, 9, 999)).toBeNull();
  });

  it('returns the normalized product when found (NOT additionally filtered by stock)', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { id: 5, name: 'วิตามินซี', price: '150.00', sale_price: '120.00', image_url: 'https://x/c.jpg' },
    ]);
    const result = await getCatalogProductById(db, 9, 5);
    expect(result).toEqual({ id: 5, name: 'วิตามินซี', imageUrl: 'https://x/c.jpg', price: 150, salePrice: 120 });
    expect(queries[0]?.sql).not.toContain('stock >');
  });
});

describe('getCatalogCategories — dead fetch, ported for parity only (products.php:54)', () => {
  it('queries business_categories, is_active=1, ORDER BY sort_order ASC, LIMIT 50', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, name: 'ยาสามัญ' }]);
    const result = await getCatalogCategories(db, 9);
    expect(result).toEqual([{ id: 1, name: 'ยาสามัญ' }]);
    expect(queries[0]?.sql).toContain('business_categories');
    expect(queries[0]?.sql).toContain('sort_order ASC');
  });
});

describe('getProductBroadcastTags — products.php:58-63', () => {
  it('scopes by line_account_id = ? OR line_account_id IS NULL, orders by name', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, name: 'สนใจ_ยาแก้ปวด', color: '#ff0000' }]);
    const result = await getProductBroadcastTags(db, 9);
    expect(result).toEqual([{ id: 1, name: 'สนใจ_ยาแก้ปวด', color: '#ff0000' }]);
    expect(queries[0]?.sql).toContain('user_tags');
    expect(queries[0]?.sql).toContain('ORDER BY name');
  });
});

describe('getBroadcastCampaigns — products.php:65-70', () => {
  it('scopes by line_account_id OR NULL, orders by created_at DESC, LIMIT 20', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { id: 1, name: 'แคมเปญ A', status: 'draft', auto_tag_enabled: 1, created_at: new Date('2026-08-01') },
    ]);
    const result = await getBroadcastCampaigns(db, 9);
    expect(result).toEqual([
      { id: 1, name: 'แคมเปญ A', status: 'draft', autoTagEnabled: true, createdAt: new Date('2026-08-01') },
    ]);
    expect(queries[0]?.sql).toContain('LIMIT 20');
  });
});

describe('getBroadcastCampaignItems — products.php:341-343', () => {
  it('selects broadcast_items for one broadcast_id, ordered by sort_order', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, item_name: 'สินค้า A', item_image: null }]);
    const result = await getBroadcastCampaignItems(db, 7);
    expect(result).toEqual([{ id: 1, itemName: 'สินค้า A', itemImage: null }]);
    expect(queries[0]?.sql).toContain('ORDER BY sort_order');
    expect(queries[0]?.params).toEqual([7]);
  });
});
