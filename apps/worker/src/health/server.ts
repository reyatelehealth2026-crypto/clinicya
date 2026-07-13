import { createServer, type Server } from 'node:http';

/**
 * health/server.ts — plain node:http health endpoint. GET /health exposes
 * queue depth/age (plan §5.3's named metric) for each wired queue.
 *
 * `HealthQueueLike` is a minimal duck-typed surface (not BullMQ's real
 * `Queue` type) so this file has zero compile-time or test-time dependency
 * on the `bullmq` package — a real BullMQ `Queue` instance structurally
 * satisfies this interface already (`.name`, `.getJobCounts()`,
 * `.getWaiting()` all exist on it), so index.ts can pass real Queues in
 * directly with no adapter.
 */
export interface HealthQueueLike {
  name: string;
  getJobCounts(...types: string[]): Promise<Record<string, number>>;
  getWaiting(start?: number, end?: number): Promise<Array<{ timestamp: number }>>;
}

export interface QueueHealthSummary {
  name: string;
  waiting: number;
  active: number;
  delayed: number;
  failed: number;
  completed: number;
  oldestWaitingAgeMs: number | null;
}

export interface HealthCheckDeps {
  queues: HealthQueueLike[];
  /** Resolves true if Redis answered PING; false (never throws) otherwise. */
  pingRedis: () => Promise<boolean>;
}

async function buildQueueSummary(queue: HealthQueueLike): Promise<QueueHealthSummary> {
  const counts = await queue.getJobCounts('waiting', 'active', 'delayed', 'failed', 'completed');
  const waitingJobs = await queue.getWaiting(0, 0);
  const oldest = waitingJobs[0];
  return {
    name: queue.name,
    waiting: counts.waiting ?? 0,
    active: counts.active ?? 0,
    delayed: counts.delayed ?? 0,
    failed: counts.failed ?? 0,
    completed: counts.completed ?? 0,
    oldestWaitingAgeMs: oldest ? Date.now() - oldest.timestamp : null,
  };
}

export function createHealthServer(deps: HealthCheckDeps): Server {
  return createServer((req, res) => {
    void (async () => {
      if (req.method !== 'GET' || req.url !== '/health') {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'not_found' }));
        return;
      }

      let redisOk: boolean;
      try {
        redisOk = await deps.pingRedis();
      } catch {
        redisOk = false;
      }

      if (!redisOk) {
        res.writeHead(503, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'redis_unreachable' }));
        return;
      }

      const queues = await Promise.all(deps.queues.map(buildQueueSummary));
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ status: 'ok', queues }));
    })();
  });
}
