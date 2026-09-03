import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import GroupsPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown, currentBotId: number | null = 7) {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId } });
  return queries;
}

describe('GroupsPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders the group list without a ?view= param, showing the "select a group" placeholder', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM groups g')) return [{ id: 1, name: 'VIP', description: null, color: '#3B82F6', createdAt: new Date(), lineAccountId: null, memberCount: 3 }];
      return [];
    });

    const element = await GroupsPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByRole('heading', { name: 'Groups Manager' })).toBeInTheDocument();
    expect(screen.getByText('VIP')).toBeInTheDocument();
    expect(screen.getByText('3 สมาชิก')).toBeInTheDocument();
    expect(screen.getByText('เลือกกลุ่มเพื่อดูรายละเอียด')).toBeInTheDocument();
  });

  it('renders the detail panel + members when ?view= resolves to a real group', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM groups g')) return [{ id: 5, name: 'VIP', description: 'desc', color: '#3B82F6', createdAt: new Date(), lineAccountId: null, memberCount: 1 }];
      if (sqlText.includes('FROM groups WHERE id')) return [{ id: 5, name: 'VIP', description: 'desc', color: '#3B82F6', createdAt: new Date(), lineAccountId: null }];
      if (sqlText.includes('JOIN user_groups ug')) return [{ id: 9, displayName: 'สมศรี', pictureUrl: null }];
      return [];
    });

    const element = await GroupsPage({ searchParams: Promise.resolve({ view: '5' }) });
    render(element);

    expect(screen.getByText('desc')).toBeInTheDocument();
    expect(screen.getByText('สมศรี')).toBeInTheDocument();
    expect(screen.queryByText('เลือกกลุ่มเพื่อดูรายละเอียด')).not.toBeInTheDocument();
  });

  it('binds currentBotId (not a hardcoded value) into the scoped user queries', async () => {
    const queries = wireDb(() => [], 42);
    const element = await GroupsPage({ searchParams: Promise.resolve({}) });
    render(element);
    const usersQuery = queries.find((q) => q.sql.includes('is_blocked = 0'));
    expect(usersQuery?.params).toEqual([42]);
  });
});
