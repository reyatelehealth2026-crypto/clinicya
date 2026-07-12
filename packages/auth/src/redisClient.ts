import Redis from 'ioredis';
import { loadEnv } from '@reya/config';
import type { RedisLikeClient } from './sessionStore';

/**
 * redisClient.ts — lazy process-wide ioredis singleton, mirroring the
 * lazy-singleton pattern @reya/db's masterPool.ts uses for mysql2. Tests
 * never touch this file's real construction path: they `vi.mock('ioredis',
 * ...)` exactly like @reya/db/@reya/tenant tests `vi.mock('mysql2', ...)`,
 * so `new Redis(...)` below resolves to a fake, never a real socket.
 */

let client: Redis | null = null;

export function getRedisClient(): RedisLikeClient {
  if (client) {
    return client;
  }

  const env = loadEnv();
  client = new Redis(env.REDIS_URL, {
    lazyConnect: true, // don't open a socket until the first command runs
    maxRetriesPerRequest: 1, // fail fast — SessionCache's try/catch falls back to memory quickly
    retryStrategy: () => null, // no auto-reconnect loop; a down Redis stays down until process restart
  });

  // ioredis emits 'error' as a normal EventEmitter event; with zero
  // listeners attached, Node treats an unhandled 'error' event as an
  // uncaught exception and crashes the process. This no-op listener is
  // required, not optional — real failures are still surfaced because every
  // call site (SessionCache) awaits the command and catches its rejection.
  client.on('error', () => {});

  return client;
}

/** Test/shutdown hook — mirrors @reya/db's resetMasterDb(). */
export function resetRedisClient(): void {
  if (client) {
    client.disconnect();
  }
  client = null;
}
