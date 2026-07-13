import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import ActivityLogsPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 1, tenantId: 1 } });
}

describe('ActivityLogsPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders the title, total count, and every log row', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('COUNT(*)')) return [{ count: 2 }];
      if (sqlText.includes('FROM activity_logs')) {
        return [
          {
            id: 1,
            log_type: 'auth',
            action: 'login',
            description: 'เข้าสู่ระบบสำเร็จ',
            user_id: null,
            user_name: null,
            admin_id: 5,
            admin_name: 'admin1',
            entity_type: null,
            entity_id: null,
            ip_address: '1.2.3.4',
            line_account_id: 1,
            created_at: new Date('2026-01-01T03:00:00Z'),
          },
          {
            id: 2,
            log_type: 'order',
            action: 'create',
            description: 'สร้างคำสั่งซื้อ #100',
            user_id: 9,
            user_name: 'ลูกค้า A',
            admin_id: null,
            admin_name: null,
            entity_type: 'order',
            entity_id: 100,
            ip_address: null,
            line_account_id: 1,
            created_at: new Date('2026-01-02T03:00:00Z'),
          },
        ];
      }
      return [];
    });

    const element = await ActivityLogsPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByRole('heading', { name: 'Activity Logs' })).toBeInTheDocument();
    expect(screen.getByText('2 รายการ')).toBeInTheDocument();
    expect(screen.getByText('เข้าสู่ระบบสำเร็จ')).toBeInTheDocument();
    expect(screen.getByText('สร้างคำสั่งซื้อ #100')).toBeInTheDocument();
    expect(screen.getByText('order #100')).toBeInTheDocument();
    expect(screen.getByText('ลูกค้า: ลูกค้า A')).toBeInTheDocument();
    expect(screen.getByText('admin1')).toBeInTheDocument();
    expect(screen.getByText('1.2.3.4')).toBeInTheDocument();
  });

  it('shows the empty state when there are no matching logs', async () => {
    wireDb(() => []);
    const element = await ActivityLogsPage({ searchParams: Promise.resolve({ search: 'nobody' }) });
    render(element);
    expect(screen.getByText('ไม่พบข้อมูล')).toBeInTheDocument();
  });

  it('renders pagination when there is more than one page', async () => {
    wireDb((sqlText) => (sqlText.includes('COUNT(*)') ? [{ count: 500 }] : []));
    const element = await ActivityLogsPage({ searchParams: Promise.resolve({}) });
    render(element);
    const href = screen.getByRole('link', { name: '3' }).getAttribute('href') ?? '';
    expect(href).toContain('page=3');
    expect(href).toContain('/activity-logs');
  });

  it('preserves filter params on pagination links', async () => {
    wireDb((sqlText) => (sqlText.includes('COUNT(*)') ? [{ count: 500 }] : []));
    const element = await ActivityLogsPage({ searchParams: Promise.resolve({ type: 'auth', page: '2' }) });
    render(element);
    const href = screen.getByRole('link', { name: '3' }).getAttribute('href') ?? '';
    expect(href).toContain('type=auth');
  });
});
