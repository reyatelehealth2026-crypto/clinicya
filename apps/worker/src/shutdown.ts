import type { Server } from 'node:http';

/**
 * shutdown.ts — graceful SIGTERM/SIGINT drain for blue-green deploys (plan
 * §4.6).
 *
 * `WorkerLike.close()` is BullMQ's real `Worker#close()` in production —
 * it already awaits any in-flight job's completion before resolving, so
 * runShutdown() below deliberately does NOT force-kill immediately on
 * signal. A `WORKER_SHUTDOWN_TIMEOUT_MS` hard-kill fallback guards against a
 * genuinely stuck job hanging the process forever.
 *
 * IMPORTANT for mig-infra (production's blue-green compose config): whatever
 * `stop_grace_period` the orchestrator uses for this container MUST be set
 * comfortably longer than WORKER_SHUTDOWN_TIMEOUT_MS, or the container
 * runtime's own SIGKILL will land before this file's own hard-kill fallback
 * ever gets a chance to run (and log/exit cleanly).
 *
 * `closeRealtimeServer`/`redisSubscriber` are ADDITIVE and OPTIONAL —
 * realtime/socketServer.ts's dedicated Socket.io server and
 * redis.ts's dedicated pub/sub connection (realtime/inboxRelay.ts's
 * subscriber). Existing callers/tests that omit them see no behavior
 * change. When present, both are drained after healthServer.close(),
 * mirroring websocket-server.js's own gracefulShutdown() order (HTTP/
 * Socket.io server close, then `await redisSubscriber.quit()`).
 */

export interface WorkerLike {
  close(): Promise<void>;
}

export interface RedisSubscriberLike {
  quit(): Promise<unknown>;
}

export interface ShutdownDeps {
  worker: WorkerLike;
  healthServer: Server;
  shutdownTimeoutMs: number;
  /** OPTIONAL — realtime/socketServer.ts's `RealtimeServer#close()` (Socket.io `Server#close()`, which also closes its own dedicated http.Server). */
  closeRealtimeServer?: () => Promise<void>;
  /** OPTIONAL — the dedicated ioredis pub/sub connection from redis.ts's getRedisSubscriberClient(). Closed via `.quit()` for a graceful UNSUBSCRIBE + QUIT round trip. */
  redisSubscriber?: RedisSubscriberLike;
  /** Test seam — defaults to `process.exit`. Tests inject a spy so they don't kill the test runner. */
  exit?: (code: number) => void;
}

/**
 * The actual drain logic, decoupled from `process.on('SIGTERM', ...)` so
 * tests can invoke it directly with fake deps/timers instead of emitting a
 * real process signal. registerShutdown() below is the thin wiring that
 * calls this on a real signal.
 */
export async function runShutdown(deps: ShutdownDeps): Promise<void> {
  const exit = deps.exit ?? ((code: number) => process.exit(code));
  let settled = false;

  const hardKill = setTimeout(() => {
    if (settled) {
      return;
    }
    settled = true;
    exit(1);
  }, deps.shutdownTimeoutMs);
  hardKill.unref?.();

  try {
    // Worker#close() already awaits in-flight job completion before
    // resolving — do NOT force-kill immediately, that would kill a job
    // mid-write (e.g. heartbeat.ts's in-flight per-tenant INSERT loop).
    await deps.worker.close();
    await new Promise<void>((resolve) => deps.healthServer.close(() => resolve()));
    if (deps.closeRealtimeServer) {
      await deps.closeRealtimeServer();
    }
    if (deps.redisSubscriber) {
      await deps.redisSubscriber.quit();
    }
    if (!settled) {
      settled = true;
      clearTimeout(hardKill);
      exit(0);
    }
  } catch {
    if (!settled) {
      settled = true;
      clearTimeout(hardKill);
      exit(1);
    }
  }
}

export function registerShutdown(deps: ShutdownDeps): void {
  let shuttingDown = false;
  const handleSignal = (): void => {
    if (shuttingDown) {
      return;
    }
    shuttingDown = true;
    void runShutdown(deps);
  };

  process.on('SIGTERM', handleSignal);
  process.on('SIGINT', handleSignal);
}
