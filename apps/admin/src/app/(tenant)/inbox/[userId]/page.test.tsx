import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('./_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRedirect = jest.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
jest.mock('next/navigation', () => ({
  redirect: (url: string) => mockRedirect(url),
}));

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import InboxThreadPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { currentBotId: 1, tenantId: 1 } });
}

function fullQueryImpl(overrides: { userOverrides?: Record<string, unknown>; messageCount?: number } = {}) {
  const messageCount = overrides.messageCount ?? 2;
  return (sqlText: string) => {
    if (sqlText.includes('FROM users')) {
      return [
        {
          id: 7,
          picture_url: 'https://x/pic.png',
          display_name: 'สมศรี',
          custom_display_name: null,
          ...overrides.userOverrides,
        },
      ];
    }
    if (sqlText.includes('FROM user_tags')) {
      return [{ id: 1, name: 'VIP', color: '#ff0000' }];
    }
    if (sqlText.includes('SELECT * FROM')) {
      return Array.from({ length: messageCount }, (_, i) => ({
        id: i + 1,
        user_id: 7,
        direction: i % 2 === 0 ? 'incoming' : 'outgoing',
        message_type: 'text',
        content: `msg ${i + 1}`,
        is_read: 1,
        sent_by: null,
        created_at: new Date(2026, 6, 14, 9, 0, 0),
      }));
    }
    return [];
  };
}

describe('InboxThreadPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('redirects to /inbox when the userId param is not a positive integer', async () => {
    await expect(InboxThreadPage({ params: Promise.resolve({ userId: 'not-a-number' }) })).rejects.toThrow('REDIRECT:/inbox');
    expect(mockRedirect).toHaveBeenCalledWith('/inbox');
    expect(mockRequireTenantPageContext).not.toHaveBeenCalled();
  });

  it('redirects to /inbox when userId is zero/negative', async () => {
    await expect(InboxThreadPage({ params: Promise.resolve({ userId: '0' }) })).rejects.toThrow('REDIRECT:/inbox');
    await expect(InboxThreadPage({ params: Promise.resolve({ userId: '-5' }) })).rejects.toThrow('REDIRECT:/inbox');
  });

  it('redirects to /inbox when the user id does not resolve to a row', async () => {
    wireDb(() => []);
    await expect(InboxThreadPage({ params: Promise.resolve({ userId: '999' }) })).rejects.toThrow('REDIRECT:/inbox');
  });

  it('renders the chat header + latest messages, oldest first', async () => {
    wireDb(fullQueryImpl());
    const element = await InboxThreadPage({ params: Promise.resolve({ userId: '7' }) });
    render(element);

    expect(screen.getByRole('heading', { name: 'สมศรี' })).toBeInTheDocument();
    expect(screen.getByText('VIP')).toBeInTheDocument();
    expect(screen.getByText('msg 1')).toBeInTheDocument();
    expect(screen.getByText('msg 2')).toBeInTheDocument();
  });

  it('renders the "load older" trigger only when the initial page is a full 300 (has-more heuristic)', async () => {
    wireDb(fullQueryImpl({ messageCount: 300 }));
    const element = await InboxThreadPage({ params: Promise.resolve({ userId: '7' }) });
    render(element);
    expect(screen.getByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ })).toBeInTheDocument();
  });

  it('does not render the "load older" trigger when fewer than 300 messages exist', async () => {
    wireDb(fullQueryImpl({ messageCount: 2 }));
    const element = await InboxThreadPage({ params: Promise.resolve({ userId: '7' }) });
    render(element);
    expect(screen.queryByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ })).not.toBeInTheDocument();
  });

  it('renders an empty chat pane (no crash) for a conversation with zero messages', async () => {
    wireDb(fullQueryImpl({ messageCount: 0 }));
    const element = await InboxThreadPage({ params: Promise.resolve({ userId: '7' }) });
    render(element);
    expect(screen.getByRole('heading', { name: 'สมศรี' })).toBeInTheDocument();
    expect(document.getElementById('chatBox')).toBeEmptyDOMElement();
  });
});
