import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import LineGroupsPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown, currentBotId: number | null = 7) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId } });
}

describe('LineGroupsPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders stats + every group row', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM line_groups g')) {
        return [
          {
            id: 1,
            groupId: 'C1',
            groupType: 'group',
            groupName: 'หมอดี คลินิก',
            pictureUrl: null,
            memberCount: 5,
            isActive: 1,
            joinedAt: new Date('2026-01-01T10:00:00+07:00'),
            totalMessages: 20,
            lineAccountId: 7,
            botName: 'Bot A',
          },
        ];
      }
      if (sqlText.includes('COUNT(*)') && sqlText.includes('is_active')) return [{ count: 1 }];
      if (sqlText.includes('COUNT(*)')) return [{ count: 1 }];
      if (sqlText.includes('SUM(member_count)')) return [{ total: 5 }];
      if (sqlText.includes('SUM(total_messages)')) return [{ total: 20 }];
      return [];
    });

    const element = await LineGroupsPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByRole('heading', { name: /จัดการกลุ่ม LINE/ })).toBeInTheDocument();
    expect(screen.getByText('หมอดี คลินิก')).toBeInTheDocument();
    expect(screen.getByText('Bot A')).toBeInTheDocument();
    expect(screen.getByText('Active')).toBeInTheDocument();
  });

  it('shows the empty state when there are no groups', async () => {
    wireDb(() => []);
    const element = await LineGroupsPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByText('ยังไม่มีกลุ่มที่บอทเข้าร่วม')).toBeInTheDocument();
  });

  it('renders the ?message= flash banner', async () => {
    wireDb(() => []);
    const element = await LineGroupsPage({ searchParams: Promise.resolve({ message: 'ออกจากกลุ่ม X แล้ว' }) });
    render(element);
    expect(screen.getByText('ออกจากกลุ่ม X แล้ว')).toBeInTheDocument();
  });

  it('renders the ?error= flash banner', async () => {
    wireDb(() => []);
    const element = await LineGroupsPage({ searchParams: Promise.resolve({ error: 'เกิดข้อผิดพลาด: boom' }) });
    render(element);
    expect(screen.getByText('เกิดข้อผิดพลาด: boom')).toBeInTheDocument();
  });

  it('does NOT render an action column for an inactive (left) group', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM line_groups g')) {
        return [
          {
            id: 2,
            groupId: 'C2',
            groupType: 'room',
            groupName: 'Left Room',
            pictureUrl: null,
            memberCount: 0,
            isActive: 0,
            joinedAt: new Date(),
            totalMessages: 0,
            lineAccountId: 7,
            botName: null,
          },
        ];
      }
      return [];
    });
    const element = await LineGroupsPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByText('Left')).toBeInTheDocument();
    expect(screen.queryByTitle('ออกจากกลุ่ม')).not.toBeInTheDocument();
    expect(screen.queryByTitle(/ส่งข้อความ/)).not.toBeInTheDocument();
  });
});
