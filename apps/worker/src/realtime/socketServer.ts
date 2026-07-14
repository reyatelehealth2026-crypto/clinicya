import { createServer, type Server as HttpServer } from 'node:http';
import { Server as SocketIOServer } from 'socket.io';

/**
 * realtime/socketServer.ts — a DEDICATED http.Server + Socket.io Server for
 * apps/worker's realtime relay, bound to `env.WORKER_REALTIME_PORT`.
 *
 * Deliberately its own http.Server, NOT shared with health/server.ts's
 * plain node:http health server: that server's request listener already
 * calls `res.end()` on every non-`/health` request (see health/server.ts),
 * which would race Socket.io's own request/upgrade handling on the same
 * socket. Two servers, two ports — same "separate concerns" posture as
 * BullMQ's Worker vs. the health endpoint in index.ts.
 *
 * No auth in this round (the legacy `authenticateToken()` DB lookup is
 * explicitly out of scope — see this batch's agent brief). Exactly one
 * join handshake is implemented: a client emits `join_account` with
 * `{ lineAccountId: number }` and the server joins it to Socket.io room
 * `account_<lineAccountId>` — the SAME room-naming convention
 * websocket-server.js's authenticated connection handler already uses
 * (`const room = \`account_${user.line_account_id}\`; socket.join(room);`)
 * and realtime/inboxRelay.ts relays onto.
 *
 * STABLE WIRE CONTRACT: the infra brief's Docker-based smoke test is built
 * in parallel against this exact `join_account` / `{ lineAccountId }` /
 * `account_<id>` shape — do not rename any of it without flagging mig-orc.
 */

export interface JoinAccountPayload {
  lineAccountId: number;
}

export interface RealtimeServer {
  io: SocketIOServer;
  httpServer: HttpServer;
  /** Starts listening on `port`. Resolves once the underlying http.Server is actually listening. */
  start(port: number): Promise<void>;
  /** Closes the Socket.io server (which also closes the underlying http.Server it was constructed with — see socket.io's `Server#close()`). Resolves once fully closed; rejects if close() itself errors. */
  close(): Promise<void>;
}

function isJoinAccountPayload(payload: unknown): payload is JoinAccountPayload {
  return (
    typeof payload === 'object' &&
    payload !== null &&
    'lineAccountId' in payload &&
    typeof (payload as { lineAccountId: unknown }).lineAccountId === 'number'
  );
}

/**
 * Creates (but does not start) the dedicated realtime Socket.io server.
 * Callers (apps/worker/src/index.ts) are responsible for calling
 * `start(env.WORKER_REALTIME_PORT)` and wiring `close()` into shutdown.
 */
export function createRealtimeServer(): RealtimeServer {
  const httpServer = createServer();
  const io = new SocketIOServer(httpServer, {
    // Socket.io default path ('/socket.io/') — unchanged.
  });

  io.on('connection', (socket) => {
    socket.on('join_account', (payload: unknown) => {
      if (!isJoinAccountPayload(payload)) {
        return;
      }
      const room = `account_${payload.lineAccountId}`;
      void socket.join(room);
    });
  });

  return {
    io,
    httpServer,
    start(port: number): Promise<void> {
      return new Promise((resolve) => {
        httpServer.listen(port, () => resolve());
      });
    },
    close(): Promise<void> {
      return new Promise((resolve, reject) => {
        io.close((err) => {
          if (err) {
            reject(err);
            return;
          }
          resolve();
        });
      });
    },
  };
}
