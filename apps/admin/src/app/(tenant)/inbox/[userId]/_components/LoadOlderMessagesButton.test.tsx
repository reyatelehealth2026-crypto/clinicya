import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LoadOlderMessagesButton } from './LoadOlderMessagesButton';

function jsonResponse(body: unknown) {
  return { json: () => Promise.resolve(body) } as Response;
}

describe('LoadOlderMessagesButton', () => {
  const originalFetch = global.fetch;

  afterEach(() => {
    global.fetch = originalFetch;
    jest.restoreAllMocks();
  });

  it('renders no button when initialHasMore is false', () => {
    render(<LoadOlderMessagesButton userId={7} oldestMessageId={5} initialHasMore={false} />);
    expect(screen.queryByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ })).not.toBeInTheDocument();
  });

  it('renders no button when oldestMessageId is null (empty conversation), even if initialHasMore were true', () => {
    render(<LoadOlderMessagesButton userId={7} oldestMessageId={null} initialHasMore={true} />);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('clicking fetches the cursor endpoint with the oldest id as cursor, prepends results, updates cursor/has_more', async () => {
    const user = userEvent.setup();
    const mockFetch = jest.fn().mockResolvedValueOnce(
      jsonResponse({
        success: true,
        data: {
          messages: [
            { id: 8, user_id: 7, direction: 'incoming', message_type: 'text', content: 'older 1', is_read: 1, sent_by: null, created_at: '2026-07-14 08:00:00' },
            { id: 9, user_id: 7, direction: 'incoming', message_type: 'text', content: 'older 2', is_read: 1, sent_by: null, created_at: '2026-07-14 08:01:00' },
          ],
          next_cursor: '8',
          has_more: true,
        },
      })
    );
    global.fetch = mockFetch as unknown as typeof fetch;

    render(<LoadOlderMessagesButton userId={7} oldestMessageId={10} initialHasMore={true} />);
    await user.click(screen.getByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ }));

    await waitFor(() => expect(screen.getByText('older 1')).toBeInTheDocument());
    expect(screen.getByText('older 2')).toBeInTheDocument();
    expect(mockFetch).toHaveBeenCalledWith('/api/inbox/messages?user_id=7&cursor=10&limit=50');
    // still has_more:true -> button stays visible
    expect(screen.getByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ })).toBeInTheDocument();
  });

  it('a second click uses the updated cursor and PREPENDS the new page before the previously loaded page (ascending overall order)', async () => {
    const user = userEvent.setup();
    const mockFetch = jest
      .fn()
      .mockResolvedValueOnce(
        jsonResponse({
          success: true,
          data: {
            messages: [{ id: 9, user_id: 7, direction: 'incoming', message_type: 'text', content: 'batch1', is_read: 1, sent_by: null, created_at: '2026-07-14 08:00:00' }],
            next_cursor: '9',
            has_more: true,
          },
        })
      )
      .mockResolvedValueOnce(
        jsonResponse({
          success: true,
          data: {
            messages: [{ id: 5, user_id: 7, direction: 'incoming', message_type: 'text', content: 'batch2', is_read: 1, sent_by: null, created_at: '2026-07-14 07:00:00' }],
            next_cursor: null,
            has_more: false,
          },
        })
      );
    global.fetch = mockFetch as unknown as typeof fetch;

    render(<LoadOlderMessagesButton userId={7} oldestMessageId={10} initialHasMore={true} />);
    await user.click(screen.getByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ }));
    await waitFor(() => expect(screen.getByText('batch1')).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ }));
    await waitFor(() => expect(screen.getByText('batch2')).toBeInTheDocument());

    expect(mockFetch).toHaveBeenNthCalledWith(2, '/api/inbox/messages?user_id=7&cursor=9&limit=50');
    // has_more:false on the second page -> button disappears
    expect(screen.queryByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ })).not.toBeInTheDocument();

    const texts = screen.getAllByText(/batch\d/).map((el) => el.textContent);
    expect(texts).toEqual(['batch2', 'batch1']); // batch2 (older) rendered above batch1 (newer)
  });

  it('a failure response shows an error message and keeps the button visible for retry', async () => {
    const user = userEvent.setup();
    global.fetch = jest.fn().mockResolvedValueOnce(jsonResponse({ success: false, error: 'Invalid user ID' })) as unknown as typeof fetch;

    render(<LoadOlderMessagesButton userId={7} oldestMessageId={10} initialHasMore={true} />);
    await user.click(screen.getByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ }));

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Invalid user ID'));
    expect(screen.getByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ })).toBeInTheDocument();
  });

  it('a network throw shows a generic Thai error message', async () => {
    const user = userEvent.setup();
    global.fetch = jest.fn().mockRejectedValueOnce(new Error('network down')) as unknown as typeof fetch;

    render(<LoadOlderMessagesButton userId={7} oldestMessageId={10} initialHasMore={true} />);
    await user.click(screen.getByRole('button', { name: /โหลดข้อความเก่ากว่านี้/ }));

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
  });
});
