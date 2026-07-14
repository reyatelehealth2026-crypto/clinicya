import { Queue, QueueEvents, Worker, type Job } from 'bullmq';
import { loadWorkerEnv } from './env';
import { getRedisClient, getRedisSubscriberClient } from './redis';
import { registerJob, getJob } from './jobs/registry';
import { heartbeatJob, HEARTBEAT_JOB_NAME } from './jobs/heartbeat';
import { createDlq, wireDlq } from './dlq';
import { createHealthServer } from './health/server';
import { createRealtimeServer } from './realtime/socketServer';
import { wireInboxRelay } from './realtime/inboxRelay';
import { registerShutdown } from './shutdown';

/**
 * index.ts — apps/worker entrypoint. Pure scaffolding batch: one real queue
 * ('worker-main'), one real job ('worker-heartbeat'), a DLQ, a health
 * endpoint, and a graceful SIGTERM/SIGINT drain. Phase 8 (Odoo)/Phase 10
 * (cron -> BullMQ) batches add real jobs on top of this registry — none of
 * that job logic is ported here.
 *
 * Also starts realtime/socketServer.ts's dedicated Socket.io server on
 * env.WORKER_REALTIME_PORT and wires realtime/inboxRelay.ts's Redis
 * 'inbox_updates' relay onto it, using a SECOND dedicated ioredis
 * connection (getRedisSubscriberClient()) — ioredis's pub/sub SUBSCRIBE
 * requires its own connection, distinct from the one issuing BullMQ's other
 * commands (mirrors websocket-server.js's `redisClient.duplicate()`).
 */

export const MAIN_QUEUE_NAME = 'worker-main';

export async function main(): Promise<void> {
  const env = loadWorkerEnv();
  const connection = getRedisClient();

  registerJob(heartbeatJob);

  const queue = new Queue(MAIN_QUEUE_NAME, { connection });
  const queueEvents = new QueueEvents(MAIN_QUEUE_NAME, { connection });
  const dlq = createDlq(MAIN_QUEUE_NAME, connection);
  wireDlq(queueEvents, dlq, queue);

  const worker = new Worker(
    MAIN_QUEUE_NAME,
    async (job: Job) => {
      const def = getJob(job.name);
      if (!def) {
        throw new Error(`No job definition registered for BullMQ job name "${job.name}" — is it registerJob()'d in index.ts?`);
      }
      // job.data's real shape depends on job.name at runtime, which TS can't
      // narrow from a string lookup — `as never` is the same intentional
      // type-erasure boundary registry.ts's module doc comment explains for
      // JobDefinition<never>'s storage type (never trick).
      await def.handler(job.data as never, { tenantFanout: def.tenantFanout });
    },
    { connection }
  );

  // Repeatable — env.WORKER_HEARTBEAT_INTERVAL_MS overrides heartbeatJob's
  // own `trigger.everyMs` default at enqueue time (see heartbeat.ts's doc
  // comment on why the definition object's own value is just a fallback).
  await queue.add(
    HEARTBEAT_JOB_NAME,
    {},
    {
      repeat: { every: env.WORKER_HEARTBEAT_INTERVAL_MS },
      attempts: heartbeatJob.retry.attempts,
      backoff: heartbeatJob.retry.backoff,
    }
  );

  const healthServer = createHealthServer({
    queues: [queue, dlq],
    pingRedis: async () => {
      try {
        const pong = await connection.ping();
        return pong === 'PONG';
      } catch {
        return false;
      }
    },
  });
  healthServer.listen(env.WORKER_HEALTH_PORT);

  const redisSubscriber = getRedisSubscriberClient();
  const realtimeServer = createRealtimeServer();
  wireInboxRelay(realtimeServer.io, redisSubscriber);
  await realtimeServer.start(env.WORKER_REALTIME_PORT);

  registerShutdown({
    worker,
    healthServer,
    shutdownTimeoutMs: env.WORKER_SHUTDOWN_TIMEOUT_MS,
    closeRealtimeServer: realtimeServer.close,
    redisSubscriber,
  });
}

// Only auto-run when executed directly (`node dist/index.js` / `tsx watch
// src/index.ts`) — not when some future test or tool `require()`s this
// module for its exported `main`/`MAIN_QUEUE_NAME`.
if (require.main === module) {
  main().catch((err: unknown) => {
    console.error('apps/worker failed to start:', err);
    process.exit(1);
  });
}
