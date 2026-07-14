import { render, screen } from '@testing-library/react';
import type { UseRealtimeSocketOptions } from '../_lib/useRealtimeSocket';

let mockPathname = '/inbox';
const mockRefresh = jest.fn();

jest.mock('next/navigation', () => ({
  usePathname: () => mockPathname,
  useRouter: () => ({ refresh: mockRefresh }),
}));

let lastOptions: UseRealtimeSocketOptions | undefined;
const mockUseRealtimeSocket = jest.fn((options: UseRealtimeSocketOptions) => {
  lastOptions = options;
});
jest.mock('../_lib/useRealtimeSocket', () => ({
  useRealtimeSocket: (options: UseRealtimeSocketOptions) => mockUseRealtimeSocket(options),
}));

import { RealtimeSocketProvider } from './RealtimeSocketProvider';

describe('RealtimeSocketProvider', () => {
  beforeEach(() => {
    mockPathname = '/inbox';
    mockRefresh.mockClear();
    mockUseRealtimeSocket.mockClear();
    lastOptions = undefined;
    document.body.innerHTML = '';
  });

  it('renders children with no wrapper UI of its own', () => {
    render(
      <RealtimeSocketProvider lineAccountId={7}>
        <div data-testid="child">hello</div>
      </RealtimeSocketProvider>
    );
    expect(screen.getByTestId('child')).toBeInTheDocument();
  });

  it('wires useRealtimeSocket with the given lineAccountId', () => {
    render(
      <RealtimeSocketProvider lineAccountId={99}>
        <div />
      </RealtimeSocketProvider>
    );
    expect(mockUseRealtimeSocket).toHaveBeenCalledTimes(1);
    expect(lastOptions?.lineAccountId).toBe(99);
    expect(typeof lastOptions?.onNewMessage).toBe('function');
  });

  it('calls router.refresh() when an incoming new_message user_id matches the currently-open /inbox/{id} pathname', () => {
    mockPathname = '/inbox/42';
    render(
      <RealtimeSocketProvider lineAccountId={1}>
        <div />
      </RealtimeSocketProvider>
    );

    lastOptions?.onNewMessage?.({ user_id: 42, content: 'hi' });

    expect(mockRefresh).toHaveBeenCalledTimes(1);
  });

  it('does NOT call router.refresh() when the incoming message user_id does not match the open thread', () => {
    mockPathname = '/inbox/42';
    render(
      <RealtimeSocketProvider lineAccountId={1}>
        <div />
      </RealtimeSocketProvider>
    );

    lastOptions?.onNewMessage?.({ user_id: 43, content: 'hi' });

    expect(mockRefresh).not.toHaveBeenCalled();
  });

  it('does NOT call router.refresh() when no thread is open (pathname is /inbox with no segment)', () => {
    mockPathname = '/inbox';
    render(
      <RealtimeSocketProvider lineAccountId={1}>
        <div />
      </RealtimeSocketProvider>
    );

    lastOptions?.onNewMessage?.({ user_id: 42, content: 'hi' });

    expect(mockRefresh).not.toHaveBeenCalled();
  });

  it('matches user_id across number/string coercion (pathname segment is always a string)', () => {
    mockPathname = '/inbox/42';
    render(
      <RealtimeSocketProvider lineAccountId={1}>
        <div />
      </RealtimeSocketProvider>
    );

    lastOptions?.onNewMessage?.({ user_id: '42', content: 'hi' });

    expect(mockRefresh).toHaveBeenCalledTimes(1);
  });

  it('does not throw and does not refresh when the new_message payload has no user_id', () => {
    mockPathname = '/inbox/42';
    render(
      <RealtimeSocketProvider lineAccountId={1}>
        <div />
      </RealtimeSocketProvider>
    );

    expect(() => lastOptions?.onNewMessage?.({ content: 'no user id' })).not.toThrow();
    expect(() => lastOptions?.onNewMessage?.(null)).not.toThrow();
    expect(() => lastOptions?.onNewMessage?.(undefined)).not.toThrow();
    expect(mockRefresh).not.toHaveBeenCalled();
  });

  it('applies the DOM bump for a matching #userList row on new_message (bumpConversationToTop integration, not mocked)', () => {
    document.body.innerHTML = `
      <div id="userList">
        <a class="user-item" data-user-id="5"><div class="relative flex-shrink-0"></div><p class="last-msg">old</p></a>
      </div>
    `;
    render(
      <RealtimeSocketProvider lineAccountId={1}>
        <div />
      </RealtimeSocketProvider>
    );

    lastOptions?.onNewMessage?.({ user_id: 5, content: 'new preview' });

    expect(document.querySelector('[data-user-id="5"] .last-msg')?.textContent).toBe('new preview');
  });
});
