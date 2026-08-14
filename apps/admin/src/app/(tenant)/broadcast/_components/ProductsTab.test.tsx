import { render, screen } from '@testing-library/react';

jest.mock('next/navigation', () => ({
  redirect: jest.fn((url: string) => {
    throw new Error(`REDIRECT:${url}`);
  }),
}));
jest.mock('@reya/line', () => ({
  multicastMessage: jest.fn(),
  broadcastMessage: jest.fn(),
}));

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { ProductsTab } from './ProductsTab';

function fakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown = () => []) {
  return makeFakeTenantDb(queryImpl).db;
}

describe('ProductsTab', () => {
  it('renders the create-campaign form and an empty products/campaigns state', async () => {
    const db = fakeDb();
    const element = await ProductsTab({ db, lineAccountId: 9, searchParams: {} });
    render(element);
    expect(screen.getByText('สร้าง Broadcast ใหม่')).toBeInTheDocument();
    expect(screen.getByText('ยังไม่มีสินค้า')).toBeInTheDocument();
    expect(screen.getByText('ยังไม่มี Broadcast')).toBeInTheDocument();
  });

  it('renders in-stock products as checkboxes with sale-price-aware pricing', async () => {
    const db = fakeDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) {
        return [{ id: 1, name: 'ยาแก้ปวด', price: '100.00', sale_price: '80.00', image_url: null }];
      }
      return [];
    });
    const element = await ProductsTab({ db, lineAccountId: 9, searchParams: {} });
    render(element);
    expect(screen.getByText('ยาแก้ปวด')).toBeInTheDocument();
    expect(screen.getByText('฿80')).toBeInTheDocument();
    const checkbox = screen.getByRole('checkbox', { name: /ยาแก้ปวด/ });
    expect(checkbox).toHaveAttribute('name', 'products[]');
    expect(checkbox).toHaveAttribute('value', '1');
  });

  it('renders campaigns with a status badge, item count, and Auto Tag indicator when enabled', async () => {
    const db = fakeDb((sqlText) => {
      if (sqlText.includes('FROM broadcast_campaigns')) {
        return [
          { id: 5, name: 'แคมเปญ A', status: 'draft', auto_tag_enabled: 1, created_at: new Date('2026-08-01T03:00:00Z') },
        ];
      }
      if (sqlText.includes('FROM broadcast_items')) {
        return [{ id: 1, item_name: 'สินค้า A', item_image: null }];
      }
      return [];
    });
    const element = await ProductsTab({ db, lineAccountId: 9, searchParams: {} });
    render(element);
    expect(screen.getByText('แคมเปญ A')).toBeInTheDocument();
    expect(screen.getByText('รอส่ง')).toBeInTheDocument();
    expect(screen.getByText('1 สินค้า')).toBeInTheDocument();
    expect(screen.getByText('Auto Tag')).toBeInTheDocument();
    // status !== 'sent' -> a send trigger button is present (ProductsSendModal's own button)
    expect(screen.getByRole('button', { name: /ส่ง/ })).toBeInTheDocument();
    // delete form present
    expect(screen.getByRole('button', { name: '' })).toBeInTheDocument(); // trash icon-only button
  });

  it('does NOT render a send trigger for a campaign whose status is already "sent"', async () => {
    const db = fakeDb((sqlText) => {
      if (sqlText.includes('FROM broadcast_campaigns')) {
        return [{ id: 5, name: 'แคมเปญ B', status: 'sent', auto_tag_enabled: 0, created_at: new Date() }];
      }
      return [];
    });
    const element = await ProductsTab({ db, lineAccountId: 9, searchParams: {} });
    render(element);
    expect(screen.getByText('ส่งแล้ว')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^ส่ง$/ })).not.toBeInTheDocument();
  });

  it('renders the success banner for ?success=created', async () => {
    const db = fakeDb();
    const element = await ProductsTab({ db, lineAccountId: 9, searchParams: { success: 'created' } });
    render(element);
    expect(screen.getByText('สร้าง Broadcast สำเร็จ!')).toBeInTheDocument();
  });

  it('renders the success banner for ?success=sent&count=N with the count interpolated', async () => {
    const db = fakeDb();
    const element = await ProductsTab({ db, lineAccountId: 9, searchParams: { success: 'sent', count: '42' } });
    render(element);
    expect(screen.getByText('ส่ง Broadcast สำเร็จ! (42 คน)')).toBeInTheDocument();
  });

  it('renders the success banner for ?success=deleted', async () => {
    const db = fakeDb();
    const element = await ProductsTab({ db, lineAccountId: 9, searchParams: { success: 'deleted' } });
    render(element);
    expect(screen.getByText('ลบ Broadcast สำเร็จ!')).toBeInTheDocument();
  });

  it('renders the ?error= banner verbatim', async () => {
    const db = fakeDb();
    const element = await ProductsTab({ db, lineAccountId: 9, searchParams: { error: 'กรุณากรอกชื่อ Broadcast' } });
    render(element);
    expect(screen.getByText('กรุณากรอกชื่อ Broadcast')).toBeInTheDocument();
  });
});
