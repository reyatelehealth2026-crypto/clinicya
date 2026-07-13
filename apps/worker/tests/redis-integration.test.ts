import { describe, expect, it } from 'vitest';
import IORedis from 'ioredis';
import { Queue, Worker } from 'bullmq';

/**
 * redis-integration.test.ts — ONE opt-in test against a REAL Redis + REAL
 * BullMQ. Skipped by default; every other test file in this suite runs
 * fully offline against mocked ioredis/bullmq/mysql2.
 *
 * WHY THIS EXISTS: BullMQ's atomicity (waiting -> active -> completed/failed
 * transitions, repeat-job dedup, retry/backoff scheduling) runs through Lua
 * scripts executed server-side in Redis. A hand-mocked BullMQ (as every
 * other test file here uses) cannot faithfully reproduce that — a fully
 * offline suite alone would only ever be testing our own mock, never
 * BullMQ's real queueing semantics. This test exists purely so a developer
 * with a local Redis running gets one extra, fast, non-Docker signal that a
 * real enqueue -> process -> ack round trip actually works.
 *
 * THIS TEST IS NOT THE AUTHORITATIVE REAL-INFRA PROOF for this batch. That
 * is composeWiring's docker-based infra/e2e/worker-smoke.mjs (run by
 * mig-verify as the actual gate — real container, real Redis, real seeded
 * tenant DBs, real health endpoint, real SIGTERM drain). This file is a
 * narrower, opt-in developer convenience layered on top of it, and must
 * default to skipped so plain `pnpm test` / CI without Docker stays green.
 *
 * To run: start a local Redis (e.g. `docker run -p 6379:6379 redis:7-alpine`),
 * then:
 *   RUN_WORKER_REDIS_INTEGRATION=1 REDIS_URL=redis://localhost:6379 \
 *     pnpm --filter worker test -- redis-integration
 */

const ENABLED = process.env.RUN_WORKER_REDIS_INTEGRATION === '1';
const REDIS_URL = process.env.REDIS_URL ?? 'redis://localhost:6379';

// Deliberately a plain conditional (not describe.skipIf) so the skip
// condition — and the fact that this is the ONE opt-in test in the suite —
// reads unambiguously at the call site.
const maybeIt = ENABLED ? it : it.skip;

describe('redis-integration (opt-in, real Redis + real BullMQ)', () => {
  maybeIt(
    'enqueues, processes, and completes a real job end-to-end',
    async () => {
      const queueName = `worker-redis-integration-test-${Date.now()}-${Math.random().toString(36).slice(2)}`;
      const connection = new IORedis(REDIS_URL, { maxRetriesPerRequest: null });

      const queue = new Queue(queueName, { connection });
      const processed: unknown[] = [];
      const worker = new Worker(
        queueName,
        async (job) => {
          processed.push(job.data);
          return 'ok';
        },
        { connection }
      );

      try {
        await new Promise<void>((resolve, reject) => {
          worker.on('completed', () => resolve());
          worker.on('failed', (_job, err) => reject(err));
          void queue.add('probe', { hello: 'world' });
        });

        expect(processed).toEqual([{ hello: 'world' }]);
      } finally {
        await worker.close();
        await queue.obliterate({ force: true }).catch(() => {});
        await queue.close();
        connection.disconnect();
      }
    },
    20_000
  );
});
