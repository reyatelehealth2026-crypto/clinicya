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

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import LineGroupDetailPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('LineGroupDetailPage', () => {
  it('redirects to /line-groups when ?id= is missing (mirrors `$groupId = $_GET[\'id\'] ?? 0`)', async () => {
    await expect(LineGroupDetailPage({ searchParams: Promise.resolve({}) })).rejects.toThrow('REDIRECT:/line-groups');
  });

  it('redirects to /line-groups when the id does not resolve to a group', async () => {
    wireDb(() => []);
    await expect(LineGroupDetailPage({ searchParams: Promise.resolve({ id: '999' }) })).rejects.toThrow('REDIRECT:/line-groups');
  });

  it('renders group info, members, and messages for a resolved id', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM line_groups g')) {
        return [
          {
            id: 5,
            groupId: 'C1',
            groupType: 'group',
            groupName: 'หมอดี คลินิก',
            pictureUrl: null,
            memberCount: 2,
            totalMessages: 10,
            isActive: 1,
            joinedAt: new Date('2026-01-01T10:00:00+07:00'),
            botName: 'Bot A',
          },
        ];
      }
      if (sqlText.includes('FROM line_group_members')) {
        return [{ id: 1, displayName: 'สมศรี', pictureUrl: null, isActive: 1, totalMessages: 5, lastMessageAt: new Date('2026-01-02T09:30:00+07:00') }];
      }
      if (sqlText.includes('FROM line_group_messages')) {
        return [{ id: 1, displayName: 'สมศรี', createdAt: new Date('2026-01-02T09:30:00+07:00'), messageType: 'text', content: 'สวัสดีครับ' }];
      }
      return [];
    });

    const element = await LineGroupDetailPage({ searchParams: Promise.resolve({ id: '5' }) });
    render(element);

    expect(screen.getByRole('heading', { name: 'หมอดี คลินิก' })).toBeInTheDocument();
    expect(screen.getByText(/Bot A/)).toBeInTheDocument();
    expect(screen.getByText('👥 สมาชิก (1)')).toBeInTheDocument();
    // "สมศรี" appears twice — once as the member row, once as the message author.
    expect(screen.getAllByText('สมศรี')).toHaveLength(2);
    expect(screen.getByText('สวัสดีครับ')).toBeInTheDocument();
  });

  it('truncates a long message to 100 code points with "..." and shows a non-text type tag', async () => {
    const longContent = 'ก'.repeat(150);
    wireDb((sqlText) => {
      if (sqlText.includes('FROM line_groups g')) {
        return [{ id: 5, groupId: 'C1', groupType: 'room', groupName: 'X', pictureUrl: null, memberCount: 0, totalMessages: 0, isActive: 0, joinedAt: new Date(), botName: null }];
      }
      if (sqlText.includes('FROM line_group_messages')) {
        return [{ id: 1, displayName: null, createdAt: new Date(), messageType: 'image', content: longContent }];
      }
      return [];
    });

    const element = await LineGroupDetailPage({ searchParams: Promise.resolve({ id: '5' }) });
    render(element);

    expect(screen.getByText('[image]', { exact: false })).toBeInTheDocument();
    expect(screen.getByText(`${'ก'.repeat(100)}...`, { exact: false })).toBeInTheDocument();
    expect(screen.getByText('Left')).toBeInTheDocument();
    expect(screen.getByText('ยังไม่มีข้อมูลสมาชิก')).toBeInTheDocument();
  });

  it('shows the empty-messages placeholder when there are no messages', async () => {
    wireDb((sqlText) =>
      sqlText.includes('FROM line_groups g')
        ? [{ id: 5, groupId: 'C1', groupType: 'group', groupName: 'X', pictureUrl: null, memberCount: 0, totalMessages: 0, isActive: 1, joinedAt: new Date(), botName: null }]
        : []
    );
    const element = await LineGroupDetailPage({ searchParams: Promise.resolve({ id: '5' }) });
    render(element);
    expect(screen.getByText('ยังไม่มีข้อความ')).toBeInTheDocument();
  });
});
