import { renderHook } from '@testing-library/react';

type Handler = (...args: unknown[]) => void;

function createMockSocket() {
  const handlers = new Map<string, Handler[]>();
  return {
    on: jest.fn((event: string, cb: Handler) => {
      const list = handlers.get(event) ?? [];
      list.push(cb);
      handlers.set(event, list);
    }),
    emit: jest.fn(),
    disconnect: jest.fn(),
    close: jest.fn(),
    /** Test helper — not part of the real socket.io-client API. Fires every handler registered for `event`. */
    __trigger(event: string, ...args: unknown[]) {
      for (const cb of handlers.get(event) ?? []) {
        cb(...args);
      }
    },
  };
}

type MockSocket = ReturnType<typeof createMockSocket>;

const ioMock = jest.fn();
jest.mock('socket.io-client', () => ({
  io: (...args: unknown[]) => ioMock(...args),
}));

import { useRealtimeSocket } from './useRealtimeSocket';

describe('useRealtimeSocket', () => {
  let socket: MockSocket;

  beforeEach(() => {
    socket = createMockSocket();
    ioMock.mockReset();
    ioMock.mockReturnValue(socket);
  });

  it('connects to NEXT_PUBLIC_REALTIME_URL (or the localhost:8100 default) and emits join_account with { lineAccountId } on connect', () => {
    renderHook(() => useRealtimeSocket({ lineAccountId: 42 }));

    expect(ioMock).toHaveBeenCalledTimes(1);
    // No NEXT_PUBLIC_REALTIME_URL set in the test env — falls back to the documented default.
    expect(ioMock).toHaveBeenCalledWith('http://localhost:8100');

    expect(socket.emit).not.toHaveBeenCalled();
    socket.__trigger('connect');
    expect(socket.emit).toHaveBeenCalledTimes(1);
    expect(socket.emit).toHaveBeenCalledWith('join_account', { lineAccountId: 42 });
  });

  it('fires onNewMessage when the socket emits new_message, passing the payload through unmodified', () => {
    const onNewMessage = jest.fn();
    renderHook(() => useRealtimeSocket({ lineAccountId: 1, onNewMessage }));

    const payload = { user_id: 7, content: 'hello' };
    socket.__trigger('new_message', payload);

    expect(onNewMessage).toHaveBeenCalledTimes(1);
    expect(onNewMessage).toHaveBeenCalledWith(payload);
  });

  it('fires onConversationUpdate when the socket emits conversation_update, passing the payload through unmodified', () => {
    const onConversationUpdate = jest.fn();
    renderHook(() => useRealtimeSocket({ lineAccountId: 1, onConversationUpdate }));

    const payload = { user_id: 7, unread_count: 3 };
    socket.__trigger('conversation_update', payload);

    expect(onConversationUpdate).toHaveBeenCalledTimes(1);
    expect(onConversationUpdate).toHaveBeenCalledWith(payload);
  });

  it('calls the latest callback identity even without remounting (ref-based, no reconnect on callback-only re-render)', () => {
    const first = jest.fn();
    const second = jest.fn();
    const { rerender } = renderHook(({ cb }: { cb: (p: unknown) => void }) => useRealtimeSocket({ lineAccountId: 1, onNewMessage: cb }), {
      initialProps: { cb: first },
    });

    expect(ioMock).toHaveBeenCalledTimes(1);

    rerender({ cb: second });
    // Same lineAccountId -> no new connection.
    expect(ioMock).toHaveBeenCalledTimes(1);

    socket.__trigger('new_message', { user_id: 1 });
    expect(first).not.toHaveBeenCalled();
    expect(second).toHaveBeenCalledTimes(1);
  });

  it('tears the socket down on unmount', () => {
    const { unmount } = renderHook(() => useRealtimeSocket({ lineAccountId: 1 }));
    expect(socket.disconnect).not.toHaveBeenCalled();
    unmount();
    expect(socket.disconnect).toHaveBeenCalledTimes(1);
  });

  it('opens a fresh connection and tears down the old one when lineAccountId changes', () => {
    const { rerender } = renderHook(({ id }: { id: number }) => useRealtimeSocket({ lineAccountId: id }), {
      initialProps: { id: 1 },
    });
    expect(ioMock).toHaveBeenCalledTimes(1);

    const secondSocket = createMockSocket();
    ioMock.mockReturnValue(secondSocket);

    rerender({ id: 2 });

    expect(socket.disconnect).toHaveBeenCalledTimes(1);
    expect(ioMock).toHaveBeenCalledTimes(2);

    secondSocket.__trigger('connect');
    expect(secondSocket.emit).toHaveBeenCalledWith('join_account', { lineAccountId: 2 });
  });
});
