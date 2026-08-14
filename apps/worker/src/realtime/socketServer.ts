import { createServer, type IncomingMessage, type Server as HttpServer, type ServerResponse } from 'node:http';
import { Server as SocketIOServer } from 'socket.io';
import { isTypingPayload, TypingIndicatorTracker } from './typing';

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
 *
 * THIS BATCH additionally ports websocket-server.js's remaining DB-free
 * surface — see docs/runbooks/websocket-consolidation.md for the full
 * event/room/side-effect table covering both legacy files:
 *  - `typing` (broadcast-only, no per-socket auth identity — see
 *    realtime/typing.ts's module doc comment for the deliberate payload
 *    shape change: explicit `lineAccountId` instead of implicit
 *    `socket.lineAccountId`, and no `admin_id`/`admin_username` fields
 *    since there is no authenticated identity to populate them with).
 *  - `ping` -> `pong` heartbeat (byte-for-byte: `{ timestamp: Date.now() }`).
 *  - `GET /health` + `GET /status` on this SAME dedicated httpServer,
 *    registered BEFORE `new SocketIOServer(httpServer, ...)` below so
 *    engine.io's `Server#attach()` caches this listener into its own
 *    "requests whose path doesn't match mine" fallback dispatch — see the
 *    `attachHttpRoutes()` doc comment below for the empirical verification
 *    this ordering depends on.
 *  - a `server_shutdown` broadcast-before-close in `close()`, mirroring
 *    legacy's `gracefulShutdown()` notify-then-drain order.
 */

export interface JoinAccountPayload {
  lineAccountId: number;
}

export interface RealtimeServerDeps {
  /**
   * OPTIONAL — reports the ioredis pub/sub connection's live status for the
   * `redis` field of GET /health and GET /status. This module has no
   * built-in Redis dependency of its own (index.ts owns the connection via
   * redis.ts's `getRedisSubscriberClient()` and wires it into
   * `wireInboxRelay()` separately — same "duck-typed, caller owns the real
   * client" posture as inboxRelay.ts's `RelaySubscriberLike`). Omit to
   * report `'unknown'` — this is the case in every test in this batch that
   * calls `createRealtimeServer()` with no args, matching
   * tests/realtime/inboxRelay.test.ts's existing no-args call sites (that
   * file is frozen/unmodified — see this batch's brief).
   */
  getRedisStatus?: () => 'connected' | 'disconnected';
}

export interface RealtimeServer {
  io: SocketIOServer;
  httpServer: HttpServer;
  /** Starts listening on `port`. Resolves once the underlying http.Server is actually listening. */
  start(port: number): Promise<void>;
  /**
   * Broadcasts `server_shutdown` to every connected client, waits a short
   * grace period (mirroring legacy's notify-then-drain order —
   * `io.emit('server_shutdown', ...)` then a delay before actually
   * closing), then closes the Socket.io server (which also closes the
   * underlying http.Server it was constructed with — see socket.io's
   * `Server#close()`). Resolves once fully closed; rejects if close()
   * itself errors.
   */
  close(): Promise<void>;
}

/**
 * Grace period between the `server_shutdown` broadcast and actually closing
 * the server. Legacy's `gracefulShutdown()` uses 1000ms in production; this
 * is deliberately much shorter so this file's own tests (and
 * tests/realtime/inboxRelay.test.ts's real-server cases, which also call
 * `close()` in their `afterEach`) stay fast — the notify-then-drain ORDER
 * is what this batch ports, not the exact production duration. If a future
 * batch wants the literal legacy value, thread it through
 * `RealtimeServerDeps` rather than editing this constant directly.
 */
const SHUTDOWN_GRACE_MS = 150;

function isJoinAccountPayload(payload: unknown): payload is JoinAccountPayload {
  return (
    typeof payload === 'object' &&
    payload !== null &&
    'lineAccountId' in payload &&
    typeof (payload as { lineAccountId: unknown }).lineAccountId === 'number'
  );
}

function buildStatusPayload(
  io: SocketIOServer,
  typingTracker: TypingIndicatorTracker,
  getRedisStatus: (() => 'connected' | 'disconnected') | undefined
): Record<string, unknown> {
  return {
    // Deliberately the SAME shape for both GET /health and GET /status this
    // round (legacy's two endpoints diverge slightly — /health carries
    // `redis`+`database`, /status carries `version`+`rooms`+
    // `typingIndicators` — this batch's brief asks for one documented shape
    // both endpoints return, and drops legacy's `database` field entirely
    // since there is no DB wiring here yet).
    status: 'ok',
    uptime: process.uptime(),
    timestamp: Date.now(),
    connections: {
      // io.engine.clientsCount / io.sockets.adapter.rooms.size are
      // Socket.io/engine.io's OWN internal bookkeeping — reused directly
      // rather than duplicating a parallel connections Map the way legacy's
      // `connections`/`dashboardConnections` Maps do, since the target
      // shape here needs only aggregate counts, not a per-account
      // breakdown.
      total: io.engine.clientsCount,
      rooms: io.sockets.adapter.rooms.size,
    },
    typingIndicators: typingTracker.size,
    redis: getRedisStatus ? getRedisStatus() : 'unknown',
  };
}

/**
 * Registers the GET /health + GET /status listener on `httpServer`.
 *
 * MUST be called BEFORE `new SocketIOServer(httpServer, ...)` constructs
 * its engine.io Server. Verified by reading engine.io@6.6.9's
 * `Server#attach()` (node_modules/.pnpm/engine.io@6.6.9/.../server.js):
 * on construction it does
 *   `const listeners = server.listeners('request').slice(0);
 *    server.removeAllListeners('request');
 *    server.on('request', (req, res) => { if (check(req)) { ...handle... }
 *      else { for (const l of listeners) l.call(server, req, res); } });`
 * — i.e. it CACHES whatever 'request' listeners already exist on the
 * httpServer at construction time and re-dispatches every request whose
 * path doesn't match its own `path` option (default '/socket.io/') to
 * them. Registering this listener first is the same extension point
 * Express-on-the-same-httpServer integrations rely on. Empirically proven
 * (not just read from source) by tests/realtime/socketServer.test.ts's
 * "GET /health and GET /status ... while Socket.io is simultaneously live"
 * case, which starts a real server via `createRealtimeServer()` +
 * `start(0)` and issues real `fetch()` requests against it alongside a
 * live socket.io-client connection on the same port.
 *
 * `getIo` is a closure (not a direct `SocketIOServer` reference) because
 * this function necessarily runs BEFORE `io` is constructed (see ordering
 * requirement above) — `createRealtimeServer()` passes `() => io` closing
 * over a `let io` binding it assigns immediately afterward. The closure is
 * only ever INVOKED from an actual incoming HTTP request, which can't
 * happen synchronously during `createRealtimeServer()`'s own setup, so by
 * the time it runs `io` is always already assigned.
 */
function attachHttpRoutes(
  httpServer: HttpServer,
  getIo: () => SocketIOServer,
  typingTracker: TypingIndicatorTracker,
  getRedisStatus: (() => 'connected' | 'disconnected') | undefined
): void {
  httpServer.on('request', (req: IncomingMessage, res: ServerResponse) => {
    if (req.method !== 'GET' || !req.url) {
      return;
    }
    const pathname = req.url.split('?')[0];
    if (pathname !== '/health' && pathname !== '/status') {
      // Not one of ours — either engine.io's own listener handles it (it
      // never reaches this fallback listener for its own matching paths;
      // see this function's doc comment) or it's genuinely unhandled, same
      // as before this batch added any route here.
      return;
    }
    const body = JSON.stringify(buildStatusPayload(getIo(), typingTracker, getRedisStatus));
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(body);
  });
}

/**
 * Creates (but does not start) the dedicated realtime Socket.io server.
 * Callers (apps/worker/src/index.ts) are responsible for calling
 * `start(env.WORKER_REALTIME_PORT)` and wiring `close()` into shutdown.
 */
export function createRealtimeServer(deps: RealtimeServerDeps = {}): RealtimeServer {
  const httpServer = createServer();
  const typingTracker = new TypingIndicatorTracker();

  // `io` is assigned immediately below, before control ever returns to the
  // event loop — see attachHttpRoutes()'s doc comment on why this ordering
  // (route registration BEFORE Socket.io construction) is required, and why
  // reading `io` through a closure here is safe despite `io` not being
  // assigned yet at the moment this closure is CREATED.
  let io: SocketIOServer;
  attachHttpRoutes(httpServer, () => io, typingTracker, deps.getRedisStatus);

  io = new SocketIOServer(httpServer, {
    // Socket.io default path ('/socket.io/') — unchanged.
  });

  typingTracker.startCleanupInterval();

  io.on('connection', (socket) => {
    socket.on('join_account', (payload: unknown) => {
      if (!isJoinAccountPayload(payload)) {
        return;
      }
      const room = `account_${payload.lineAccountId}`;
      void socket.join(room);
    });

    /**
     * Typing indicator broadcast. Payload is `{ lineAccountId, user_id,
     * is_typing }` — `lineAccountId` is EXPLICIT (not read off any
     * per-socket connection state, unlike legacy's `socket.lineAccountId`)
     * because a socket here may be joined to more than one `account_<id>`
     * room. Broadcast omits legacy's `admin_id`/`admin_username` fields —
     * deliberate, not accidental: there is no authenticated identity to
     * populate them with this round (see realtime/typing.ts's module doc
     * comment).
     */
    socket.on('typing', (payload: unknown) => {
      if (!isTypingPayload(payload)) {
        return;
      }
      typingTracker.set(socket.id, payload);
      const room = `account_${payload.lineAccountId}`;
      socket.to(room).emit('typing', {
        user_id: payload.user_id,
        is_typing: payload.is_typing,
        timestamp: Date.now(),
      });
    });

    /** Heartbeat — byte-for-byte port of legacy's `socket.on('ping', () => socket.emit('pong', { timestamp: Date.now() }))`. */
    socket.on('ping', () => {
      socket.emit('pong', { timestamp: Date.now() });
    });

    socket.on('disconnect', () => {
      // Clears every typing entry this socket owned (any conversation/room)
      // and notifies each of those rooms that the typer stopped — mirrors
      // legacy's disconnect-handler loop over `typingIndicators.entries()`.
      // Uses `io.to(...)` (not `socket.to(...)`), same choice legacy's own
      // disconnect handler makes (`io.to(\`account_${lineAccountId}\`).emit(...)`).
      for (const entry of typingTracker.clearSocket(socket.id)) {
        io.to(`account_${entry.lineAccountId}`).emit('typing', {
          user_id: entry.userId,
          is_typing: false,
          timestamp: Date.now(),
        });
      }
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
      typingTracker.stopCleanupInterval();
      io.emit('server_shutdown', {
        message: 'Server is shutting down',
        timestamp: Date.now(),
      });
      return new Promise((resolve, reject) => {
        setTimeout(() => {
          io.close((err) => {
            if (err) {
              reject(err);
              return;
            }
            resolve();
          });
        }, SHUTDOWN_GRACE_MS);
      });
    },
  };
}
