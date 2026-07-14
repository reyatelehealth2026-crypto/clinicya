'use client';

import { useEffect, useRef } from 'react';
import { io } from 'socket.io-client';

/**
 * useRealtimeSocket.ts — client-side connector for apps/worker's realtime
 * relay (apps/worker/src/realtime/{inboxRelay,socketServer}.ts, already
 * merged and Docker-smoke-tested by infra/e2e/worker-realtime-relay-smoke.mjs
 * this round). This hook owns ONLY the connection lifecycle + the frozen
 * wire handshake; it does not know or care what its caller does with the
 * events — see _components/RealtimeSocketProvider.tsx for the DOM-mutation
 * consumer.
 *
 * FROZEN WIRE CONTRACT (do not rename any of the strings below — see
 * socketServer.ts's own module doc, "STABLE WIRE CONTRACT"):
 *  - on `connect`, emit `join_account` with `{ lineAccountId }` — matches
 *    apps/worker/src/realtime/socketServer.ts's `JoinAccountPayload` shape
 *    (a bare `{ lineAccountId: number }`, NOT `{ line_account_id }` or any
 *    other casing) exactly. The server joins the socket to Socket.io room
 *    `account_<lineAccountId>` from that payload alone.
 *  - the server relays exactly two event names onto that room —
 *    `new_message` and `conversation_update` — both wired straight through
 *    below to this hook's optional callbacks.
 *
 * Deliberately NOT importing `JoinAccountPayload`/`InboxUpdateMessage`/etc.
 * from apps/worker: apps/worker and apps/admin are separate deployables (no
 * cross-app import), and — mirroring inboxRelay.ts's own "pass the message
 * sub-object through unmodified, don't reshape it" posture — this hook keeps
 * `onNewMessage`/`onConversationUpdate` payloads `unknown`-typed pass-throughs
 * rather than asserting a shared shape.
 *
 * `NEXT_PUBLIC_REALTIME_URL` is read directly inline (not layered through
 * packages/config's zod envSchema — that package is out of this batch's
 * scope) because `NEXT_PUBLIC_*` vars are meant to be read directly in
 * Next.js client code; the bundler inlines the literal value at build time.
 *
 * Reconnection uses socket.io-client's own default backoff (no custom retry
 * logic this round — see this batch's brief).
 */

export interface UseRealtimeSocketOptions {
  lineAccountId: number;
  /** Fired for every `new_message` event relayed into this account's room. Payload is passed through unmodified — see module doc. */
  onNewMessage?: (payload: unknown) => void;
  /** Fired for every `conversation_update` event relayed into this account's room. Payload is passed through unmodified — see module doc. */
  onConversationUpdate?: (payload: unknown) => void;
}

const DEFAULT_REALTIME_URL = 'http://localhost:8100';

/**
 * Opens one Socket.io connection for the lifetime of the mounted component,
 * joins `account_<lineAccountId>`'s room on every (re)connect, and tears the
 * socket down on unmount. Re-connects (a fresh socket, fresh `join_account`)
 * whenever `lineAccountId` itself changes; `onNewMessage`/`onConversationUpdate`
 * are read via a ref on every event so passing a new inline callback identity
 * on every render does NOT tear down/reopen the connection.
 */
export function useRealtimeSocket({ lineAccountId, onNewMessage, onConversationUpdate }: UseRealtimeSocketOptions): void {
  const onNewMessageRef = useRef(onNewMessage);
  const onConversationUpdateRef = useRef(onConversationUpdate);

  useEffect(() => {
    onNewMessageRef.current = onNewMessage;
  }, [onNewMessage]);

  useEffect(() => {
    onConversationUpdateRef.current = onConversationUpdate;
  }, [onConversationUpdate]);

  useEffect(() => {
    const socket = io(process.env.NEXT_PUBLIC_REALTIME_URL ?? DEFAULT_REALTIME_URL);

    socket.on('connect', () => {
      socket.emit('join_account', { lineAccountId });
    });

    socket.on('new_message', (payload: unknown) => {
      onNewMessageRef.current?.(payload);
    });

    socket.on('conversation_update', (payload: unknown) => {
      onConversationUpdateRef.current?.(payload);
    });

    return () => {
      socket.disconnect();
    };
  }, [lineAccountId]);
}
