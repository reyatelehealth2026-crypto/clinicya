/**
 * @jest-environment node
 */
jest.mock('@/lib/miniapp/tenant', () => ({
  resolveMiniappTenantContext: jest.fn(),
  TENANT_UNRESOLVED_RESPONSE: { success: false, error: 'tenant_unresolved' },
  TENANT_UNRESOLVED_STATUS: 400,
}));
jest.mock('./_lib/query', () => ({
  getProductsAction: jest.fn(),
  getProductDetailAction: jest.fn(),
  getCategoriesAction: jest.fn(),
}));
jest.mock('./_lib/catalogSource', () => ({
  useShopProductCatalog: jest.fn(),
}));

import type { NextRequest } from 'next/server';
import { resolveMiniappTenantContext } from '@/lib/miniapp/tenant';
import { getCategoriesAction, getProductDetailAction, getProductsAction } from './_lib/query';
import { useShopProductCatalog } from './_lib/catalogSource';
import { GET, OPTIONS } from './route';

const mockResolveTenant = resolveMiniappTenantContext as jest.MockedFunction<typeof resolveMiniappTenantContext>;
const mockGetProducts = getProductsAction as jest.MockedFunction<typeof getProductsAction>;
const mockGetDetail = getProductDetailAction as jest.MockedFunction<typeof getProductDetailAction>;
const mockGetCategories = getCategoriesAction as jest.MockedFunction<typeof getCategoriesAction>;
const mockUseOdoo = useShopProductCatalog as jest.MockedFunction<typeof useShopProductCatalog>;

const FAKE_DB = { __fakeTenantDb: true };

function req(search: string): NextRequest {
  const url = `https://re-ya.com/api/miniapp/shop-products${search}`;
  return { nextUrl: new URL(url), headers: new Headers(), url } as unknown as NextRequest;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockResolveTenant.mockResolvedValue({ ok: true, context: { tenantId: 1, db: FAKE_DB as never } });
});

describe('GET /api/miniapp/shop-products — tenant resolution', () => {
  it('tenant_unresolved -> 400, no action handler called', async () => {
    mockResolveTenant.mockResolvedValue({ ok: false });
    const res = await GET(req('?action=products'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'tenant_unresolved' });
    expect(mockGetProducts).not.toHaveBeenCalled();
  });
});

describe('GET /api/miniapp/shop-products — action=products', () => {
  it('parses + clamps query params, forwards to getProductsAction', async () => {
    mockGetProducts.mockResolvedValue({
      success: true,
      message: '',
      products: [],
      categories: [],
      brands: [],
      offset: 0,
      limit: 12,
      total: 0,
      has_more: false,
    });

    const res = await GET(
      req('?action=products&line_account_id=1&category_id=3&search=para&sort=price_asc&brand=GPO&line_user_id=U1&limit=100&offset=5')
    );

    expect(res.status).toBe(200);
    expect(mockGetProducts).toHaveBeenCalledWith(FAKE_DB, {
      lineAccountId: '1',
      categoryId: '3',
      search: 'para',
      sort: 'price_asc',
      brand: 'GPO',
      lineUserId: 'U1',
      limit: 24, // clamped from 100
      offset: 5,
    });
  });

  it('defaults limit to 12 and offset to 0 when omitted/invalid', async () => {
    mockGetProducts.mockResolvedValue({
      success: true,
      message: '',
      products: [],
      categories: [],
      brands: [],
      offset: 0,
      limit: 12,
      total: 0,
      has_more: false,
    });

    await GET(req('?action=products'));

    expect(mockGetProducts).toHaveBeenCalledWith(
      FAKE_DB,
      expect.objectContaining({ limit: 12, offset: 0, lineAccountId: null, categoryId: null, search: '', brand: '' })
    );
  });

  it('dead client-side params (include_zero_price/catalog_mode/…) are accepted but never reach getProductsAction', async () => {
    mockGetProducts.mockResolvedValue({
      success: true,
      message: '',
      products: [],
      categories: [],
      brands: [],
      offset: 0,
      limit: 12,
      total: 0,
      has_more: false,
    });

    const res = await GET(req('?action=products&include_zero_price=1&include_inactive=0&catalog_mode=all&catalog_bucket=featured'));

    expect(res.status).toBe(200);
    const calledParams = mockGetProducts.mock.calls[0]?.[1];
    expect(calledParams).not.toHaveProperty('include_zero_price');
    expect(calledParams).not.toHaveProperty('catalog_mode');
  });
});

describe('GET /api/miniapp/shop-products — action=product_detail', () => {
  it('parses product_id as integer, forwards to getProductDetailAction', async () => {
    mockGetDetail.mockResolvedValue({ success: false, message: 'Product not found' });

    const res = await GET(req('?action=product_detail&product_id=101&line_account_id=1&line_user_id=U1'));

    expect(res.status).toBe(200);
    expect(mockGetDetail).toHaveBeenCalledWith(FAKE_DB, 101, '1', 'U1');
    expect(await res.json()).toEqual({ success: false, message: 'Product not found' });
  });

  it('missing product_id -> 0, still forwarded (the _lib layer owns the "Missing product_id" branch)', async () => {
    mockGetDetail.mockResolvedValue({ success: false, message: 'Missing product_id' });
    await GET(req('?action=product_detail'));
    expect(mockGetDetail).toHaveBeenCalledWith(FAKE_DB, 0, null, null);
  });
});

describe('GET /api/miniapp/shop-products — action=categories', () => {
  it('resolves useShopProductCatalog() first, then forwards its result', async () => {
    mockUseOdoo.mockResolvedValue(true);
    mockGetCategories.mockResolvedValue({ success: true, categories: [], category_id_is_string: true });

    const res = await GET(req('?action=categories&account=7'));

    expect(res.status).toBe(200);
    expect(mockUseOdoo).toHaveBeenCalledWith(FAKE_DB, 7);
    expect(mockGetCategories).toHaveBeenCalledWith(FAKE_DB, { lineAccountId: 7, useOdoo: true });
  });

  it('`account` takes priority over `line_account_id` (mirrors PHP\'s ?? chain)', async () => {
    mockUseOdoo.mockResolvedValue(false);
    mockGetCategories.mockResolvedValue({ success: true, categories: [], category_id_is_string: false });

    await GET(req('?action=categories&account=7&line_account_id=99'));

    expect(mockUseOdoo).toHaveBeenCalledWith(FAKE_DB, 7);
  });
});

describe('GET /api/miniapp/shop-products — unsupported action', () => {
  it('missing/unknown action -> 400 Invalid action', async () => {
    const res = await GET(req('?action=cart'));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid action' });
  });

  it('no action param at all -> 400 (checkout.php actions default to null, not "products")', async () => {
    const res = await GET(req(''));
    expect(res.status).toBe(400);
  });
});

describe('OPTIONS /api/miniapp/shop-products', () => {
  it('204 with CORS headers', () => {
    const res = OPTIONS();
    expect(res.status).toBe(204);
    expect(res.headers.get('Access-Control-Allow-Origin')).toBe('*');
  });
});
