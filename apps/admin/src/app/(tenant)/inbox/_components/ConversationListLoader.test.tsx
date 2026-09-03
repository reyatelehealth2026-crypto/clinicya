import { act, render, screen } from '@testing-library/react';
import type { ConversationRow } from '@/app/api/inbox/conversations/_lib/query';

let mockPlatform: string | null = null;
let mockPathname = '/inbox';
jest.mock('next/navigation', () => ({
  useSearchParams: () => ({ get: (key: string) => (key === 'platform' ? mockPlatform : null) }),
  usePathname: () => mockPathname,
}));

import { BATCH_DELAY_MS, ConversationListLoader, MAX_BATCHES } from './ConversationListLoader';

function row(id: number, overrides: Partial<ConversationRow> = {}): ConversationRow {
  return {
    id,
    display_name: `User ${id}`,
    picture_url: null,
    chat_status: null,
    platform: 'line',
    platform_user_id: null,
    last_message_at: '2026-07-10 09:00:00',
    assigned_to: null,
    assignment_status: null,
    unread_count: 0,
    last_message_preview: null,
    last_message_type: null,
    tags: [],
    assignees: [],
    ...overrides,
  };
}

function jsonResponse(body: unknown) {
  return Promise.resolve({ json: () => Promise.resolve(body) } as Response);
}

/** Flushes pending microtasks/promise chains without needing fake-timer-sensitive real-timer polling (waitFor/findBy). */
async function flush() {
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
  });
}

describe('ConversationListLoader', () => {
  let fetchMock: jest.Mock;
  const originalFetch = global.fetch;

  beforeEach(() => {
    jest.useFakeTimers();
    mockPlatform = null;
    mockPathname = '/inbox';
    fetchMock = jest.fn(() => jsonResponse({ success: true, data: { conversations: [], next_cursor: null, has_more: false } }));
    global.fetch = fetchMock as unknown as typeof fetch;
  });

  afterEach(() => {
    global.fetch = originalFetch;
    jest.useRealTimers();
  });

  it('renders the seeded initial conversations immediately (SSR-first-paint), no fetch when initialHasMore=false', async () => {
    render(
      <ConversationListLoader
        initialConversations={[row(1), row(2)]}
        initialCursor={null}
        initialHasMore={false}
        initialPlatform="line"
      />
    );
    expect(screen.getByText('User 1')).toBeInTheDocument();
    expect(screen.getByText('User 2')).toBeInTheDocument();
    await flush();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByText('โหลดครบ 2 รายการ')).toBeInTheDocument();
  });

  it('shows the empty state when there are no conversations and nothing is loading', async () => {
    render(<ConversationListLoader initialConversations={[]} initialCursor={null} initialHasMore={false} initialPlatform="line" />);
    await flush();
    expect(screen.getByText('ยังไม่มีแชท')).toBeInTheDocument();
  });

  it('auto-loads the next batch in the background, appends rows, and updates the sentinel once done', async () => {
    fetchMock.mockImplementationOnce(() =>
      jsonResponse({ success: true, data: { conversations: [row(3)], next_cursor: '2026-07-09 00:00:00', has_more: false } })
    );
    render(
      <ConversationListLoader initialConversations={[row(1)]} initialCursor="2026-07-10 00:00:00" initialHasMore initialPlatform="line" />
    );
    await flush();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const url = new URL(fetchMock.mock.calls[0][0] as string, 'https://example.com');
    expect(url.pathname).toBe('/api/inbox/conversations');
    expect(url.searchParams.get('limit')).toBe('200');
    expect(url.searchParams.get('cursor')).toBe('2026-07-10 00:00:00');
    expect(url.searchParams.get('platform')).toBeNull();

    expect(screen.getByText('User 1')).toBeInTheDocument();
    expect(screen.getByText('User 3')).toBeInTheDocument();
    expect(screen.getByText('โหลดครบ 2 รายการ')).toBeInTheDocument();
    const sentinel = document.getElementById('loadMoreSentinel')!;
    expect(sentinel.getAttribute('data-has-more')).toBe('false');
  });

  it('marks the row matching the current /inbox/{id} pathname as active', async () => {
    mockPathname = '/inbox/2';
    render(
      <ConversationListLoader initialConversations={[row(1), row(2)]} initialCursor={null} initialHasMore={false} initialPlatform="line" />
    );
    await flush();
    expect(screen.getByText('User 2').closest('a')).toHaveClass('active');
    expect(screen.getByText('User 1').closest('a')).not.toHaveClass('active');
  });

  it('switching to a non-default platform (mounting directly on ?platform=facebook) discards seeded rows and refetches page 1 with platform=facebook', async () => {
    mockPlatform = 'facebook';
    fetchMock.mockImplementationOnce(() =>
      jsonResponse({ success: true, data: { conversations: [row(99)], next_cursor: null, has_more: false } })
    );
    render(
      <ConversationListLoader initialConversations={[row(1)]} initialCursor={null} initialHasMore={false} initialPlatform="line" />
    );
    await flush();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const url = fetchMock.mock.calls[0][0] as string;
    expect(url).toContain('platform=facebook');
    expect(screen.queryByText('User 1')).not.toBeInTheDocument();
    expect(screen.getByText('User 99')).toBeInTheDocument();
  });

  it('caps the background walk at MAX_BATCHES when the server keeps saying has_more=true', async () => {
    fetchMock.mockImplementation((url: string) =>
      jsonResponse({ success: true, data: { conversations: [row(Math.random())], next_cursor: 'cursor', has_more: true } })
    );
    render(<ConversationListLoader initialConversations={[]} initialCursor={null} initialHasMore initialPlatform="line" />);

    await act(async () => {
      for (let i = 0; i < MAX_BATCHES + 3; i += 1) {
        await jest.advanceTimersByTimeAsync(BATCH_DELAY_MS);
      }
    });

    expect(fetchMock).toHaveBeenCalledTimes(MAX_BATCHES);
  });
});
