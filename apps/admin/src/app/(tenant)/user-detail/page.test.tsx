import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRedirect = jest.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
jest.mock('next/navigation', () => ({
  redirect: (url: string) => mockRedirect(url),
}));

// updateUserInfoAction/addPointsAction are bound directly to <form action={...}>
// props — importing actions.ts (which imports next/cache indirectly via ../users/
// actions? no — user-detail/actions.ts only imports next/navigation) is safe here,
// but addPointsAction/updateUserInfoAction both call requireTenantPageContext(),
// which is mocked above.

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import UserDetailPage from './page';

const originalEnv = process.env.ODOO_INTEGRATION_ENABLED;

afterEach(() => {
  if (originalEnv === undefined) {
    delete process.env.ODOO_INTEGRATION_ENABLED;
  } else {
    process.env.ODOO_INTEGRATION_ENABLED = originalEnv;
  }
});

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 1, tenantId: 1 } });
}

function fullUserQueryImpl(overrides: Record<string, unknown> = {}) {
  return (sqlText: string) => {
    if (sqlText.includes('FROM users WHERE id')) {
      return [
        {
          id: 1,
          lineUserId: 'U123',
          displayName: 'Somsri',
          realName: null,
          memberId: null,
          phone: null,
          email: null,
          birthday: null,
          gender: null,
          address: null,
          province: null,
          postalCode: null,
          note: null,
          pictureUrl: null,
          statusMessage: 'Hello',
          isBlocked: 0,
          createdAt: new Date('2026-01-01'),
          weight: null,
          height: null,
          bloodType: null,
          medicalConditions: null,
          drugAllergies: null,
          lineAccountId: 1,
          ...overrides,
        },
      ];
    }
    if (sqlText.includes('FROM transactions') && sqlText.includes('ORDER BY created_at DESC')) {
      return [{ id: 10, orderNumber: 'ORD-1', createdAt: new Date(), status: 'paid', grandTotal: '250.00', shippingName: null }];
    }
    if (sqlText.includes('FROM transaction_items')) {
      return [{ productName: 'Paracetamol', quantity: 2 }];
    }
    if (sqlText.includes('COUNT(*) AS cnt')) {
      return [{ cnt: 3, total: 900 }];
    }
    if (sqlText.includes('FROM messages')) {
      return [{ count: 42 }];
    }
    if (sqlText.includes('FROM points_transactions') && sqlText.includes('SUM')) {
      return [{ totalPoints: 500, availablePoints: 400, usedPoints: 100 }];
    }
    if (sqlText.includes('SELECT points, total_points')) {
      return [{ points: 0, totalPoints: 500 }];
    }
    if (sqlText.includes('FROM shop_settings')) {
      return [{ shopName: 'Reya Pharmacy' }];
    }
    return [];
  };
}

describe('UserDetailPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    delete process.env.ODOO_INTEGRATION_ENABLED;
  });

  it('redirects to /users when id is missing/zero', async () => {
    await expect(UserDetailPage({ searchParams: Promise.resolve({}) })).rejects.toThrow('REDIRECT:/users');
    expect(mockRedirect).toHaveBeenCalledWith('/users');
  });

  it('redirects to /users when the user id does not resolve to a row', async () => {
    wireDb(() => []);
    await expect(UserDetailPage({ searchParams: Promise.resolve({ id: '999' }) })).rejects.toThrow('REDIRECT:/users');
  });

  it('renders the profile, points, stats, and orders sections for an existing user', async () => {
    wireDb(fullUserQueryImpl());
    const element = await UserDetailPage({ searchParams: Promise.resolve({ id: '1' }) });
    render(element);

    expect(screen.getByRole('heading', { name: 'Somsri', level: 1 })).toBeInTheDocument();
    expect(screen.getByText('400')).toBeInTheDocument(); // available points
    expect(screen.getByText('3 รายการ')).toBeInTheDocument(); // order count
    expect(screen.getByText(/900\.00/)).toBeInTheDocument(); // total spent
    expect(screen.getByText('#ORD-1')).toBeInTheDocument();
    expect(screen.getByText(/Paracetamol/)).toBeInTheDocument();
  });

  it('renders the health empty-state when no health columns are populated', async () => {
    wireDb(fullUserQueryImpl());
    const element = await UserDetailPage({ searchParams: Promise.resolve({ id: '1' }) });
    render(element);
    expect(screen.getByText('ยังไม่มีข้อมูลสุขภาพ')).toBeInTheDocument();
  });

  it('renders BMI + conditions when health columns are populated', async () => {
    wireDb(fullUserQueryImpl({ weight: '70', height: '175', medicalConditions: 'เบาหวาน' }));
    const element = await UserDetailPage({ searchParams: Promise.resolve({ id: '1' }) });
    render(element);
    expect(screen.getByText('22.9')).toBeInTheDocument();
    expect(screen.getByText('เบาหวาน')).toBeInTheDocument();
  });

  it('shows a success message when ?updated=1 is present', async () => {
    wireDb(fullUserQueryImpl());
    const element = await UserDetailPage({ searchParams: Promise.resolve({ id: '1', updated: '1' }) });
    render(element);
    expect(screen.getByText('บันทึกสำเร็จ!')).toBeInTheDocument();
  });

  it('omits the Odoo ERP card entirely (not just hidden) when ODOO_INTEGRATION_ENABLED is off', async () => {
    delete process.env.ODOO_INTEGRATION_ENABLED;
    wireDb(fullUserQueryImpl());
    const element = await UserDetailPage({ searchParams: Promise.resolve({ id: '1' }) });
    render(element);
    expect(screen.queryByText('Odoo ERP')).not.toBeInTheDocument();
    expect(screen.queryByText(/Odoo ERP card ยังอยู่บนระบบเดิม/)).not.toBeInTheDocument();
  });

  it('renders a deferred stub Odoo ERP card (not the real card) when ODOO_INTEGRATION_ENABLED is on', async () => {
    process.env.ODOO_INTEGRATION_ENABLED = '1';
    wireDb(fullUserQueryImpl());
    const element = await UserDetailPage({ searchParams: Promise.resolve({ id: '1' }) });
    render(element);
    expect(screen.getByText('Odoo ERP')).toBeInTheDocument();
    expect(screen.getByText(/Odoo ERP card ยังอยู่บนระบบเดิม/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /เปิดหน้ารายละเอียดลูกค้า/ })).toHaveAttribute('href', '/user-detail.php?id=1');
  });
});
