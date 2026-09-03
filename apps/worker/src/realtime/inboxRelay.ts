/**
 * realtime/inboxRelay.ts — ports websocket-server.js's Redis `inbox_updates`
 * subscribe/relay logic (that file's lines ~350-384) into apps/worker,
 * unmodified in behavior. This is an ADDITIVE new relay, not a rewrite of
 * websocket-server.js and not a change to classes/WebSocketNotifier.php —
 * both keep running/publishing unchanged during the coexistence period.
 *
 * Channel name is the literal string 'inbox_updates', matching
 * classes/WebSocketNotifier.php's private `$channelName`.
 *
 * Byte-for-byte parity notes (deliberate, not oversights):
 *  - The legacy relay does NOT branch on the `type` field that
 *    WebSocketNotifier.php publishes onto the SAME channel for three
 *    different notification kinds ('new_message', 'conversation_update',
 *    'typing_indicator'). It unconditionally destructures
 *    `{ line_account_id, message, unread_count }` and always emits
 *    `new_message` + `conversation_update`. Replicating that omission is
 *    the porting requirement for this round, not a bug to fix here.
 *  - `last_message_preview` mirrors the legacy `messageData.content?.substring(0,
 *    100)` optional-chaining semantics exactly: only `null`/`undefined`
 *    `content` produce `undefined`. An empty string (the literal default
 *    `WebSocketNotifier.php::notifyNewMessage()` uses for messages without
 *    a text body — `$message['content'] ?? ''`, e.g. image/sticker/file
 *    messages) still yields `last_message_preview: ''`, matching legacy —
 *    NOT a truthy check, which would also swallow `''`/`0`.
 */

/**
 * Minimal duck-typed surface of the ioredis subscriber connection this
 * module needs — no compile-time dependency on a real `ioredis` instance
 * (same posture as health/server.ts's `HealthQueueLike`).
 *
 * `subscribe`'s signature is deliberately a loose `(...args: unknown[])` —
 * ioredis's real `subscribe()` is overloaded with variadic-channel + a
 * required-trailing-callback tuple form that a narrower two-parameter
 * signature can't structurally match (TypeScript only compares against a
 * multi-overload source's LAST signature for assignability, which drops
 * the callback param entirely). This module only ever calls it with
 * `(channel, callback)`, which real ioredis and the fakes in
 * tests/realtime/inboxRelay.test.ts both happily accept.
 */
export interface RelaySubscriberLike {
  subscribe(...args: unknown[]): unknown;
  on(event: 'message', listener: (channel: string, message: string) => void): unknown;
}

/** Minimal duck-typed surface of a Socket.io `Server` (or any object with a compatible `.to(room).emit(...)`) — no compile-time dependency on a real `socket.io` instance. */
export interface RelayRoomEmitterLike {
  emit(event: string, ...args: unknown[]): unknown;
}

export interface RelayIoLike {
  to(room: string): RelayRoomEmitterLike;
}

export const INBOX_UPDATES_CHANNEL = 'inbox_updates';

/** Shape of the `message` sub-object published by WebSocketNotifier.php's `notifyNewMessage()`. Passed through to Socket.io completely unmodified — this interface exists only so this file can read the couple of fields it needs to derive `conversation_update`, not to reshape or whitelist it. */
interface InboxUpdateMessage {
  user_id?: unknown;
  created_at?: unknown;
  content?: unknown;
  [key: string]: unknown;
}

interface InboxUpdatePayload {
  line_account_id: number | string;
  message: InboxUpdateMessage;
  unread_count?: unknown;
}

/**
 * Subscribes `subscriber` to the 'inbox_updates' Redis channel and relays
 * every message onto `io`'s Socket.io rooms, mirroring
 * websocket-server.js's `redisSubscriber.on('message', ...)` handler
 * exactly (room naming, event names, and payload shapes below all match
 * that file byte-for-byte).
 */
export function wireInboxRelay(io: RelayIoLike, subscriber: RelaySubscriberLike): void {
  subscriber.subscribe(INBOX_UPDATES_CHANNEL, (err: Error | null) => {
    if (err) {
      console.error(`[inboxRelay] Failed to subscribe to ${INBOX_UPDATES_CHANNEL}:`, err);
    } else {
      console.log(`[inboxRelay] Subscribed to ${INBOX_UPDATES_CHANNEL} channel`);
    }
  });

  subscriber.on('message', (channel: string, message: string) => {
    // Mirrors legacy `if (channel === 'inbox_updates')` guard. Only one
    // channel is ever subscribed above, so this branch is provably always
    // true today — kept explicit (not incidental) per the porting brief.
    if (channel !== INBOX_UPDATES_CHANNEL) {
      return;
    }

    let payload: InboxUpdatePayload;
    try {
      payload = JSON.parse(message) as InboxUpdatePayload;
    } catch (error) {
      // Mirrors legacy catch-and-console.error — never throw out of the
      // subscriber's 'message' handler for a malformed payload.
      console.error('[inboxRelay] Error processing Redis message:', error);
      return;
    }

    const { line_account_id, message: messageData, unread_count } = payload;

    // Broadcast to all admins in this LINE account.
    const room = `account_${line_account_id}`;

    io.to(room).emit('new_message', messageData);

    io.to(room).emit('conversation_update', {
      user_id: messageData?.user_id,
      last_message_at: messageData?.created_at,
      last_message_preview: messageData?.content != null ? String(messageData.content).substring(0, 100) : undefined,
      unread_count,
      timestamp: Date.now(),
    });
  });
}
