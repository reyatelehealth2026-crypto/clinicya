import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AddressInfo } from 'node:net';
import { io as ioClient, type Socket as ClientSocket } from 'socket.io-client';
import {
  INBOX_UPDATES_CHANNEL,
  wireInboxRelay,
  type RelayIoLike,
  type RelayRoomEmitterLike,
  type RelaySubscriberLike,
} from '../../src/realtime/inboxRelay';
import { createRealtimeServer, type RealtimeServer } from '../../src/realtime/socketServer';

/**
 * inboxRelay.test.ts — runs fully offline (no real Redis/Docker), per this
 * batch's brief. Cases 1-3 drive wireInboxRelay() against a duck-typed fake
 * subscriber + a fake `io` that just records `.to(room).emit(...)` calls.
 * Case 4 is the exception: it spins up a REAL http.Server + REAL socket.io
 * Server (via realtime/socketServer.ts) + REAL socket.io-client on an
 * ephemeral local port — no Docker, no real Redis, only the synthetic fake
 * subscriber's 'message' event — to prove the Socket.io room/broadcast
 * plumbing itself works end-to-end, not just that `io.to().emit()` was
 * called on a mock.
 */

interface RecordedEmit {
  room: string;
  event: string;
  payload: unknown;
}

function createFakeIo(): { io: RelayIoLike; emits: RecordedEmit[] } {
  const emits: RecordedEmit[] = [];
  const io: RelayIoLike = {
    to(room: string): RelayRoomEmitterLike {
      return {
        emit(event: string, ...args: unknown[]) {
          emits.push({ room, event, payload: args[0] });
          return true;
        },
      };
    },
  };
  return { io, emits };
}

function createFakeSubscriber(): RelaySubscriberLike & { emitMessage(channel: string, message: string): void } {
  let messageListener: ((channel: string, message: string) => void) | undefined;
  return {
    subscribe: vi.fn((_channel: string, callback?: (err: Error | null, count: number) => void) => {
      callback?.(null, 1);
    }),
    on: vi.fn((event: 'message', listener: (channel: string, message: string) => void) => {
      if (event === 'message') {
        messageListener = listener;
      }
    }),
    emitMessage(channel: string, message: string) {
      messageListener?.(channel, message);
    },
  };
}

describe('wireInboxRelay (offline, duck-typed fakes)', () => {
  it('subscribes to the literal "inbox_updates" channel', () => {
    const { io } = createFakeIo();
    const subscriber = createFakeSubscriber();

    wireInboxRelay(io, subscriber);

    expect(subscriber.subscribe).toHaveBeenCalledWith(INBOX_UPDATES_CHANNEL, expect.any(Function));
  });

  it('emits new_message (payload passed through byte-for-byte) + conversation_update, both scoped to room account_<line_account_id>', () => {
    const { io, emits } = createFakeIo();
    const subscriber = createFakeSubscriber();
    wireInboxRelay(io, subscriber);

    const before = Date.now();
    const message = {
      id: 99,
      user_id: 7,
      content: 'Hello from a customer',
      direction: 'incoming',
      type: 'text',
      created_at: '2026-07-14 10:00:00',
      is_read: 0,
      // Extra/unexpected field — proves the relay does not whitelist/reshape
      // the message sub-object, it passes it through completely unmodified.
      user_display_name: 'สมชาย',
    };
    const payload = { line_account_id: 42, message, unread_count: 3 };

    subscriber.emitMessage(INBOX_UPDATES_CHANNEL, JSON.stringify(payload));
    const after = Date.now();

    expect(emits).toHaveLength(2);

    expect(emits[0]).toEqual({ room: 'account_42', event: 'new_message', payload: message });
    // Byte-for-byte pass-through: same fields, no whitelisting/reshaping.
    expect(emits[0]!.payload).toEqual(message);

    expect(emits[1]!.room).toBe('account_42');
    expect(emits[1]!.event).toBe('conversation_update');
    const conversationUpdate = emits[1]!.payload as {
      user_id: number;
      last_message_at: string;
      last_message_preview: string;
      unread_count: number;
      timestamp: number;
    };
    expect(conversationUpdate.user_id).toBe(7);
    expect(conversationUpdate.last_message_at).toBe('2026-07-14 10:00:00');
    expect(conversationUpdate.last_message_preview).toBe('Hello from a customer');
    expect(conversationUpdate.unread_count).toBe(3);
    expect(conversationUpdate.timestamp).toBeGreaterThanOrEqual(before);
    expect(conversationUpdate.timestamp).toBeLessThanOrEqual(after);
  });

  it('derives last_message_preview via optional-chaining semantics (undefined only for null/undefined content) + truncates to 100 chars, matching the legacy `messageData.content?.substring(0, 100)` behavior', () => {
    const { io, emits } = createFakeIo();
    const subscriber = createFakeSubscriber();
    wireInboxRelay(io, subscriber);

    const longContent = 'x'.repeat(150);
    subscriber.emitMessage(
      INBOX_UPDATES_CHANNEL,
      JSON.stringify({ line_account_id: 1, message: { user_id: 1, content: longContent, created_at: 'now' }, unread_count: 0 })
    );
    const truncated = (emits[1]!.payload as { last_message_preview: string }).last_message_preview;
    expect(truncated).toHaveLength(100);
    expect(truncated).toBe(longContent.substring(0, 100));

    // Empty-string content (WebSocketNotifier.php's `$message['content'] ?? ''`
    // default for image/sticker/file messages without a text body) must
    // still produce last_message_preview: '' — optional chaining only
    // short-circuits on null/undefined, not on falsy values like ''.
    emits.length = 0;
    subscriber.emitMessage(
      INBOX_UPDATES_CHANNEL,
      JSON.stringify({ line_account_id: 1, message: { user_id: 1, content: '', created_at: 'now' }, unread_count: 0 })
    );
    expect((emits[1]!.payload as { last_message_preview: unknown }).last_message_preview).toBe('');

    // Genuinely absent/null content (message sub-object with no `content`
    // key at all, or explicit null) is the ONLY case that should yield
    // undefined, mirroring `undefined?.substring(...)` / `null?.substring(...)`.
    emits.length = 0;
    subscriber.emitMessage(
      INBOX_UPDATES_CHANNEL,
      JSON.stringify({ line_account_id: 1, message: { user_id: 1, created_at: 'now' }, unread_count: 0 })
    );
    expect((emits[1]!.payload as { last_message_preview: unknown }).last_message_preview).toBeUndefined();
  });

  it('ignores messages on any other channel name (zero emits)', () => {
    const { io, emits } = createFakeIo();
    const subscriber = createFakeSubscriber();
    wireInboxRelay(io, subscriber);

    subscriber.emitMessage('some_other_channel', JSON.stringify({ line_account_id: 1, message: { user_id: 1 }, unread_count: 0 }));

    expect(emits).toHaveLength(0);
  });

  it('swallows a malformed (non-JSON) message body without throwing', () => {
    const { io, emits } = createFakeIo();
    const subscriber = createFakeSubscriber();
    wireInboxRelay(io, subscriber);
    const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

    expect(() => subscriber.emitMessage(INBOX_UPDATES_CHANNEL, '{not valid json')).not.toThrow();

    expect(emits).toHaveLength(0);
    expect(consoleErrorSpy).toHaveBeenCalled();

    consoleErrorSpy.mockRestore();
  });
});

describe('wireInboxRelay (real Socket.io wire protocol)', () => {
  let server: RealtimeServer | undefined;
  let client: ClientSocket | undefined;

  afterEach(async () => {
    client?.disconnect();
    client = undefined;
    if (server) {
      await server.close();
      server = undefined;
    }
  });

  it('a connected client that emitted join_account receives real new_message + conversation_update events over the wire', async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;

    const subscriber = createFakeSubscriber();
    wireInboxRelay(server.io, subscriber);

    // Resolves once the SERVER has processed 'join_account' (same socket,
    // registered as a second listener on top of socketServer.ts's own —
    // both fire synchronously in registration order, so by the time this
    // resolves, socket.join() has already run against the in-memory
    // adapter). Avoids an arbitrary sleep for test synchronization.
    const serverSawJoin = new Promise<void>((resolve) => {
      server!.io.on('connection', (socket) => {
        socket.on('join_account', () => resolve());
      });
    });

    client = ioClient(`http://127.0.0.1:${port}`, { transports: ['websocket'] });
    await new Promise<void>((resolve, reject) => {
      client!.once('connect', () => resolve());
      client!.once('connect_error', reject);
    });
    client.emit('join_account', { lineAccountId: 42 });
    await serverSawJoin;

    const newMessagePromise = new Promise((resolve) => client!.once('new_message', resolve));
    const conversationUpdatePromise = new Promise((resolve) => client!.once('conversation_update', resolve));

    const message = { id: 1, user_id: 7, content: 'สวัสดีครับ', created_at: '2026-07-14 12:00:00' };
    subscriber.emitMessage(INBOX_UPDATES_CHANNEL, JSON.stringify({ line_account_id: 42, message, unread_count: 5 }));

    const [newMessage, conversationUpdate] = await Promise.all([newMessagePromise, conversationUpdatePromise]);

    expect(newMessage).toEqual(message);
    expect(conversationUpdate).toMatchObject({
      user_id: 7,
      last_message_at: '2026-07-14 12:00:00',
      last_message_preview: 'สวัสดีครับ',
      unread_count: 5,
    });
  });

  it('a client that never joined the room does not receive events emitted to a different account room', async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;

    const subscriber = createFakeSubscriber();
    wireInboxRelay(server.io, subscriber);

    client = ioClient(`http://127.0.0.1:${port}`, { transports: ['websocket'] });
    await new Promise<void>((resolve, reject) => {
      client!.once('connect', () => resolve());
      client!.once('connect_error', reject);
    });
    // Deliberately joins a DIFFERENT account room than the one the message targets.
    client.emit('join_account', { lineAccountId: 999 });

    const received: unknown[] = [];
    client.on('new_message', (payload) => received.push(payload));

    subscriber.emitMessage(
      INBOX_UPDATES_CHANNEL,
      JSON.stringify({ line_account_id: 42, message: { user_id: 7, content: 'not for you' }, unread_count: 0 })
    );

    // No ack-based way to prove a negative instantly — briefly yield to the
    // event loop so a wrongly-delivered event would have had a chance to arrive.
    await new Promise((resolve) => setTimeout(resolve, 100));

    expect(received).toHaveLength(0);
  });
});
