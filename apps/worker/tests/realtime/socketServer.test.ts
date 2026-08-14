import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AddressInfo } from 'node:net';
import { io as ioClient, type Socket as ClientSocket } from 'socket.io-client';
import { createRealtimeServer, type RealtimeServer } from '../../src/realtime/socketServer';
import { TypingIndicatorTracker } from '../../src/realtime/typing';

/**
 * socketServer.test.ts — covers THIS batch's additive DB-free surface on
 * top of realtime/socketServer.ts's frozen `join_account` contract.
 * tests/realtime/inboxRelay.test.ts covers that contract (and the Redis
 * `inbox_updates` relay) and is NOT touched by this batch — it must keep
 * passing unmodified as a regression guard.
 *
 * Same "real Socket.io wire protocol, ephemeral port via start(0), no
 * Docker" style as inboxRelay.test.ts's second describe block, plus one
 * fully offline unit-style block for realtime/typing.ts's TTL cleanup
 * (driven directly with `vi.useFakeTimers()` against the
 * `TypingIndicatorTracker` class — no live server/socket needed, and
 * mixing fake timers with a real Socket.io server's own internal timers
 * would be fragile, so that case is deliberately kept offline).
 */

function connectClient(port: number): Promise<ClientSocket> {
  return new Promise((resolve, reject) => {
    const client = ioClient(`http://127.0.0.1:${port}`, { transports: ['websocket'] });
    client.once('connect', () => resolve(client));
    client.once('connect_error', reject);
  });
}

/** Resolves once the server has processed `count` `join_account` events across any number of sockets — same synchronization idiom inboxRelay.test.ts's "real Socket.io wire protocol" block uses for a single join, generalized to N so multi-client tests don't need an arbitrary sleep. */
function waitForServerJoins(server: RealtimeServer, count: number): Promise<void> {
  return new Promise((resolve) => {
    let seen = 0;
    server.io.on('connection', (socket) => {
      socket.on('join_account', () => {
        seen += 1;
        if (seen >= count) {
          resolve();
        }
      });
    });
  });
}

describe('typing + ping/pong + disconnect cleanup (real Socket.io wire protocol)', () => {
  let server: RealtimeServer | undefined;
  let clients: ClientSocket[] = [];

  afterEach(async () => {
    for (const client of clients) {
      client.disconnect();
    }
    clients = [];
    if (server) {
      await server.close();
      server = undefined;
    }
  });

  it('typing broadcast reaches other sockets in the SAME account room, and excludes the sender', async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;

    const bothJoined = waitForServerJoins(server, 2);
    const sender = await connectClient(port);
    const receiver = await connectClient(port);
    clients = [sender, receiver];

    sender.emit('join_account', { lineAccountId: 42 });
    receiver.emit('join_account', { lineAccountId: 42 });
    await bothJoined;

    const senderReceived: unknown[] = [];
    sender.on('typing', (payload) => senderReceived.push(payload));
    const receiverTyping = new Promise((resolve) => receiver.once('typing', resolve));

    const before = Date.now();
    sender.emit('typing', { lineAccountId: 42, user_id: 'U123', is_typing: true });
    const received = await receiverTyping;
    const after = Date.now();

    expect(received).toMatchObject({ user_id: 'U123', is_typing: true });
    // Deliberate parity gap vs legacy (documented in socketServer.ts / the
    // runbook): no admin_id/admin_username — there is no authenticated
    // identity to populate them with this round.
    expect(received).not.toHaveProperty('admin_id');
    expect(received).not.toHaveProperty('admin_username');
    const typedReceived = received as { timestamp: number };
    expect(typedReceived.timestamp).toBeGreaterThanOrEqual(before);
    expect(typedReceived.timestamp).toBeLessThanOrEqual(after);

    // Sender must NOT receive its own broadcast (socket.to(room), not io.to(room)).
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(senderReceived).toHaveLength(0);
  });

  it('a socket in a DIFFERENT account room does not receive the typing event', async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;

    const bothJoined = waitForServerJoins(server, 2);
    const sender = await connectClient(port);
    const outsider = await connectClient(port);
    clients = [sender, outsider];

    sender.emit('join_account', { lineAccountId: 42 });
    outsider.emit('join_account', { lineAccountId: 999 });
    await bothJoined;

    const outsiderReceived: unknown[] = [];
    outsider.on('typing', (payload) => outsiderReceived.push(payload));

    sender.emit('typing', { lineAccountId: 42, user_id: 'U123', is_typing: true });

    await new Promise((resolve) => setTimeout(resolve, 100));
    expect(outsiderReceived).toHaveLength(0);
  });

  it('ping -> pong round trip', async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;
    const client = await connectClient(port);
    clients = [client];

    const before = Date.now();
    const pongPromise = new Promise((resolve) => client.once('pong', resolve));
    client.emit('ping');
    const pong = (await pongPromise) as { timestamp: number };
    const after = Date.now();

    expect(pong.timestamp).toBeGreaterThanOrEqual(before);
    expect(pong.timestamp).toBeLessThanOrEqual(after);
  });

  it("disconnect clears that socket's typing entries and notifies the room", async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;

    const bothJoined = waitForServerJoins(server, 2);
    const typer = await connectClient(port);
    const observer = await connectClient(port);
    clients = [observer]; // typer is disconnected mid-test below — don't double-disconnect it in afterEach

    typer.emit('join_account', { lineAccountId: 42 });
    observer.emit('join_account', { lineAccountId: 42 });
    await bothJoined;

    const firstTyping = new Promise((resolve) => observer.once('typing', resolve));
    typer.emit('typing', { lineAccountId: 42, user_id: 'U9', is_typing: true });
    await firstTyping;

    const stopNotice = new Promise((resolve) => observer.once('typing', resolve));
    typer.disconnect();

    const notice = await stopNotice;
    expect(notice).toMatchObject({ user_id: 'U9', is_typing: false });
  });

  it('a malformed typing payload does not throw (server stays responsive)', async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;
    const client = await connectClient(port);
    clients = [client];

    client.emit('typing', { nonsense: true });
    client.emit('typing', null);
    client.emit('typing', 'not-an-object');
    client.emit('typing', { lineAccountId: 'not-a-number', user_id: 'U1', is_typing: true });
    client.emit('typing'); // no payload at all

    // Prove the connection (and the server's event loop) is still alive
    // right after the malformed payloads via a real ping/pong round trip.
    const pongPromise = new Promise((resolve) => client.once('pong', resolve));
    client.emit('ping');
    const pong = await pongPromise;
    expect(pong).toHaveProperty('timestamp');
  });

  it('close() broadcasts server_shutdown to connected clients before closing (notify-then-drain order)', async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;
    const client = await connectClient(port);
    clients = [client];

    const shutdownNotice = new Promise((resolve) => client.once('server_shutdown', resolve));
    const closing = server.close();

    const notice = (await shutdownNotice) as { message: string; timestamp: number };
    expect(typeof notice.message).toBe('string');
    expect(typeof notice.timestamp).toBe('number');

    await closing;
    server = undefined; // already closed above — afterEach must not close it again
  });
});

describe('GET /health and GET /status on the same dedicated httpServer (real Socket.io wire protocol)', () => {
  let server: RealtimeServer | undefined;

  afterEach(async () => {
    if (server) {
      await server.close();
      server = undefined;
    }
  });

  it('both return 200 JSON with the documented shape while Socket.io is simultaneously live', async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;

    // A live Socket.io connection on the SAME port/httpServer, concurrent
    // with the plain HTTP requests below — proves the two request paths
    // (engine.io's own dispatch vs. this batch's /health+/status listener)
    // genuinely coexist on one httpServer, not just in isolation.
    const client = await connectClient(port);
    try {
      for (const path of ['/health', '/status']) {
        const res = await fetch(`http://127.0.0.1:${port}${path}`);
        expect(res.status).toBe(200);
        expect(res.headers.get('content-type')).toMatch(/application\/json/);

        const body = (await res.json()) as Record<string, unknown>;
        expect(body).toMatchObject({
          status: 'ok',
          connections: { total: expect.any(Number), rooms: expect.any(Number) },
          typingIndicators: expect.any(Number),
          redis: 'unknown', // no getRedisStatus dep supplied — documented default
        });
        expect(typeof body.uptime).toBe('number');
        expect(typeof body.timestamp).toBe('number');
        // The connected client above counts toward the live connection total.
        expect((body.connections as { total: number }).total).toBeGreaterThanOrEqual(1);
        // Explicitly NOT present this round — no DB/auth wiring (see socketServer.ts's buildStatusPayload doc comment).
        expect(body).not.toHaveProperty('database');
      }
    } finally {
      client.disconnect();
    }
  });

  it('a query string on the request path does not break routing', async () => {
    server = createRealtimeServer();
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;

    const res = await fetch(`http://127.0.0.1:${port}/health?foo=bar`);
    expect(res.status).toBe(200);
    const body = (await res.json()) as Record<string, unknown>;
    expect(body.status).toBe('ok');
  });

  it('reflects a supplied getRedisStatus dependency instead of the "unknown" default', async () => {
    server = createRealtimeServer({ getRedisStatus: () => 'connected' });
    await server.start(0);
    const port = (server.httpServer.address() as AddressInfo).port;

    const res = await fetch(`http://127.0.0.1:${port}/status`);
    const body = (await res.json()) as Record<string, unknown>;
    expect(body.redis).toBe('connected');
  });
});

describe('TypingIndicatorTracker TTL cleanup (offline, fake timers — no live server)', () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it('silently expires a stale typing indicator after ~5s, observed across the 2s cleanup cadence', () => {
    vi.useFakeTimers();
    const tracker = new TypingIndicatorTracker();
    tracker.set('socket-1', { lineAccountId: 42, user_id: 'U1', is_typing: true });
    expect(tracker.size).toBe(1);

    tracker.startCleanupInterval();

    vi.advanceTimersByTime(2000); // 1st tick, t=2000ms elapsed since set() — still fresh (< 5000ms TTL)
    expect(tracker.size).toBe(1);

    vi.advanceTimersByTime(2000); // 2nd tick, t=4000ms elapsed — still fresh
    expect(tracker.size).toBe(1);

    vi.advanceTimersByTime(2000); // 3rd tick, t=6000ms elapsed — now stale, silently removed
    expect(tracker.size).toBe(0);

    tracker.stopCleanupInterval();
  });

  it('an explicit is_typing:false clears the indicator immediately, without waiting for TTL', () => {
    const tracker = new TypingIndicatorTracker();
    tracker.set('socket-1', { lineAccountId: 42, user_id: 'U1', is_typing: true });
    expect(tracker.size).toBe(1);
    tracker.set('socket-1', { lineAccountId: 42, user_id: 'U1', is_typing: false });
    expect(tracker.size).toBe(0);
  });
});
