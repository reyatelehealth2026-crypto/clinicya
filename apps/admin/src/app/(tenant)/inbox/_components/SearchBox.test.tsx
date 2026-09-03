import { act, fireEvent, render, screen } from '@testing-library/react';

let mockPlatform: string | null = null;
jest.mock('next/navigation', () => ({
  useSearchParams: () => ({ get: (key: string) => (key === 'platform' ? mockPlatform : null) }),
}));

import { SearchBox } from './SearchBox';

function jsonResponse(body: unknown) {
  return Promise.resolve({ json: () => Promise.resolve(body) } as Response);
}

describe('SearchBox', () => {
  let fetchMock: jest.Mock;
  const originalFetch = global.fetch;

  beforeEach(() => {
    jest.useFakeTimers();
    mockPlatform = null;
    fetchMock = jest.fn(() =>
      jsonResponse({ success: true, data: { conversations: [], next_cursor: null, has_more: false, count: 0 } })
    );
    global.fetch = fetchMock as unknown as typeof fetch;
  });

  afterEach(() => {
    global.fetch = originalFetch;
    jest.useRealTimers();
  });

  it('does not fetch immediately on keystroke (debounced)', () => {
    render(<SearchBox />);
    fireEvent.change(screen.getByPlaceholderText('🔍 ค้นหาชื่อ, ข้อความ, แท็ก...'), { target: { value: 'somsri' } });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('fetches after the debounce delay elapses, with search + limit params', async () => {
    render(<SearchBox />);
    fireEvent.change(screen.getByPlaceholderText('🔍 ค้นหาชื่อ, ข้อความ, แท็ก...'), { target: { value: 'somsri' } });
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const calledUrl = fetchMock.mock.calls[0][0] as string;
    expect(calledUrl).toContain('/api/inbox/conversations?');
    expect(calledUrl).toContain('search=somsri');
    expect(calledUrl).toContain('limit=200');
    expect(calledUrl).not.toContain('platform=');
  });

  it('collapses rapid keystrokes into a single fetch (resets the debounce timer)', async () => {
    render(<SearchBox />);
    const input = screen.getByPlaceholderText('🔍 ค้นหาชื่อ, ข้อความ, แท็ก...');
    fireEvent.change(input, { target: { value: 's' } });
    await act(async () => {
      jest.advanceTimersByTime(100);
    });
    fireEvent.change(input, { target: { value: 'so' } });
    await act(async () => {
      jest.advanceTimersByTime(100);
    });
    fireEvent.change(input, { target: { value: 'som' } });
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toContain('search=som');
  });

  it('includes a non-default platform in the query', async () => {
    mockPlatform = 'facebook';
    render(<SearchBox />);
    fireEvent.change(screen.getByPlaceholderText('🔍 ค้นหาชื่อ, ข้อความ, แท็ก...'), { target: { value: 'x' } });
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    expect(fetchMock.mock.calls[0][0]).toContain('platform=facebook');
  });

  it('clearing the input back to empty removes the results dropdown and fires no fetch', async () => {
    render(<SearchBox />);
    const input = screen.getByPlaceholderText('🔍 ค้นหาชื่อ, ข้อความ, แท็ก...');
    fireEvent.change(input, { target: { value: 'x' } });
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    fireEvent.change(input, { target: { value: '' } });
    expect(screen.queryByText('ไม่พบผลลัพธ์')).not.toBeInTheDocument();
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('shows "no results" when the search returns an empty conversations array', async () => {
    render(<SearchBox />);
    fireEvent.change(screen.getByPlaceholderText('🔍 ค้นหาชื่อ, ข้อความ, แท็ก...'), { target: { value: 'nobody' } });
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    expect(await screen.findByText('ไม่พบผลลัพธ์')).toBeInTheDocument();
  });

  it('renders returned conversations as result rows', async () => {
    fetchMock.mockImplementation(() =>
      jsonResponse({
        success: true,
        data: {
          conversations: [
            {
              id: 1,
              display_name: 'Somsri',
              picture_url: null,
              chat_status: null,
              platform: 'line',
              platform_user_id: null,
              last_message_at: null,
              assigned_to: null,
              assignment_status: null,
              unread_count: 0,
              last_message_preview: null,
              last_message_type: null,
              tags: [],
              assignees: [],
            },
          ],
          next_cursor: null,
          has_more: false,
          count: 1,
        },
      })
    );
    render(<SearchBox />);
    fireEvent.change(screen.getByPlaceholderText('🔍 ค้นหาชื่อ, ข้อความ, แท็ก...'), { target: { value: 'somsri' } });
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    expect(await screen.findByText('Somsri')).toBeInTheDocument();
  });
});
