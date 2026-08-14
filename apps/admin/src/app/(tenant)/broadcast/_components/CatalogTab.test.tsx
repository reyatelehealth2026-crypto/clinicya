import { render, screen } from '@testing-library/react';
import React from 'react';
import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { CatalogTab } from './CatalogTab';

// Same next/script stub rationale as CatalogBuilderClient.test.tsx.
jest.mock('next/script', () => ({
  __esModule: true,
  default: ({ onLoad }: { onLoad?: () => void }) => {
    React.useEffect(() => {
      onLoad?.();
    }, [onLoad]);
    return null;
  },
}));

const PRODUCT_ROW = {
  id: 101,
  name: 'พาราเซตามอล 500mg',
  description: null,
  price: '45.00',
  sale_price: '35.00',
  stock: 240,
  sku: null,
  barcode: null,
  manufacturer: null,
  generic_name: null,
  usage_instructions: null,
  properties_other: null,
  unit: null,
  category_id: 3,
  image_gallery: null,
  photo_path: null,
  is_flash_sale: 0,
  image_url: 'https://cdn.example.com/para.jpg',
};

describe('CatalogTab', () => {
  it('fetches products + categories and renders the bubble-builder client with them', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) return [PRODUCT_ROW];
      if (sqlText.includes('FROM product_categories')) return [{ id: 3, name: 'ยาสามัญ' }];
      return [];
    });

    const element = await CatalogTab({ db, lineAccountId: 7 });
    render(element);

    expect(screen.getByText('พาราเซตามอล 500mg')).toBeInTheDocument();
    expect(screen.getByText('฿35')).toBeInTheDocument(); // sale_price wins over price
    expect(screen.getByRole('option', { name: 'ยาสามัญ' })).toBeInTheDocument();

    // dead-but-fetched parity reads still fire (see ../_lib/catalog-queries.ts module docs).
    expect(queries.some((q) => q.sql.includes('FROM customer_segments'))).toBe(true);
    expect(queries.some((q) => q.sql.includes('FROM user_tags'))).toBe(true);
  });

  it('renders with an empty product/category list without crashing', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const element = await CatalogTab({ db, lineAccountId: 1 });
    render(element);

    expect(screen.getByText('0 รายการ')).toBeInTheDocument();
    expect(screen.getByText('ลากสินค้ามาวางที่นี่')).toBeInTheDocument();
  });
});
