import Redis from 'ioredis';
import { loadEnv } from '@reya/config';

/**
 * redis.ts — lazy process-wide ioredis singleton, used both as BullMQ's
 * `connection` option and for the health server's raw PING check.
 *
 * Duplicates the ~15-line lazy-singleton + single no-op 'error' listener
 * pattern from packages/auth/src/redisClient.ts on purpose, rather than
 * importing that file: @reya/auth is not a listed dependency of apps/worker
 * (see this repo's mig-worker agent brief) — same "duplicated on purpose"
 * posture packages/tenant/tests/helpers/fakeMysqlPool.ts documents for its
 * own duplication of packages/db/tests/helpers/fakeMysqlPool.ts.
 *
 * One deliberate deviation from packages/auth/src/redisClient.ts's config:
 * `maxRetriesPerRequest: null` instead of `1`. This is not a style choice —
 * BullMQ requires it on any ioredis connection it's handed (it throws at
 * construction otherwise), because BullMQ relies on Redis blocking commands
 * that must not be abandoned by ioredis's own retry cap.
 *
 * package.json also pins `ioredis` to the EXACT version bullmq's own
 * package.json depends on (5.10.1 as of bullmq@5.80.2), not a caret range
 * like packages/auth's `^5.3.2`. bullmq's `ConnectionOptions` type accepts a
 * `Redis` instance, but that type is structurally checked against bullmq's
 * OWN nested ioredis dependency — a different resolved ioredis version (even
 * a semver-compatible one) fails to type-check as a `ConnectionOptions` due
 * to a protected-field mismatch between minor versions. Pinning the exact
 * version lets pnpm dedupe both requirements to one physical package so the
 * classes are nominally, not just structurally, the same type. Bump this
 * pin only in lockstep with whatever ioredis version a future bullmq bump
 * itself depends on.
 */

let client: Redis | null = null;

export function getRedisClient(): Redis {
  if (client) {
    return client;
  }

  const env = loadEnv();
  client = new Redis(env.REDIS_URL, {
    lazyConnect: true, // don't open a socket until the first command runs
    maxRetriesPerRequest: null, // required by BullMQ — see module doc comment above
  });

  // ioredis emits 'error' as a normal EventEmitter event; with zero listeners
  // attached, Node treats an unhandled 'error' event as an uncaught
  // exception and crashes the process. This no-op listener is required, not
  // optional — real failures still surface through whatever command
  // rejected (BullMQ's own retry/backoff, or health/server.ts's PING catch).
  client.on('error', () => {});

  return client;
}

/** Test/shutdown hook — mirrors @reya/db's resetMasterDb() / @reya/auth's resetRedisClient(). */
export function resetRedisClient(): void {
  if (client) {
    client.disconnect();
  }
  client = null;
}
