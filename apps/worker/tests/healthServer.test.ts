import { afterEach, describe, expect, it } from 'vitest';
import type { AddressInfo } from 'node:net';
import http from 'node:http';
import { createHealthServer, type HealthQueueLike } from '../src/health/server';

/**
 * createHealthServer() builds a real node:http server against fake
 * (duck-typed) queues — no BullMQ/Redis mocking needed, see health/server.ts's
 * own doc comment on why `HealthQueueLike` is intentionally narrow.
 */

let server: http.Server | undefined;

afterEach(async () => {
  if (server) {
    await new Promise<void>((resolve) => server!.close(() => resolve()));
    server = undefined;
  }
});

function fakeQueue(name: string, counts: Record<string, number>, waiting: Array<{ timestamp: number }>): HealthQueueLike {
  return {
    name,
    getJobCounts: async () => counts,
    getWaiting: async () => waiting,
  };
}

async function listenEphemeral(s: http.Server): Promise<number> {
  await new Promise<void>((resolve) => s.listen(0, '127.0.0.1', resolve));
  return (s.address() as AddressInfo).port;
}

function getJson(port: number, path: string): Promise<{ statusCode: number; body: unknown }> {
  return new Promise((resolve, reject) => {
    http
      .get({ host: '127.0.0.1', port, path }, (res) => {
        let raw = '';
        res.on('data', (chunk: Buffer) => {
          raw += chunk.toString();
        });
        res.on('end', () => {
          resolve({ statusCode: res.statusCode ?? 0, body: JSON.parse(raw) });
        });
      })
      .on('error', reject);
  });
}

describe('health/server GET /health', () => {
  it('returns 200 {status:"ok", queues:[...]} with waiting/active/delayed/failed/completed + oldestWaitingAgeMs', async () => {
    const now = Date.now();
    const queue = fakeQueue(
      'worker-main',
      { waiting: 2, active: 1, delayed: 0, failed: 3, completed: 40 },
      [{ timestamp: now - 5_000 }]
    );
    server = createHealthServer({ queues: [queue], pingRedis: async () => true });
    const port = await listenEphemeral(server);

    const { statusCode, body } = await getJson(port, '/health');

    expect(statusCode).toBe(200);
    expect(body).toMatchObject({
      status: 'ok',
      queues: [
        {
          name: 'worker-main',
          waiting: 2,
          active: 1,
          delayed: 0,
          failed: 3,
          completed: 40,
        },
      ],
    });
    const oldestWaitingAgeMs = (body as { queues: Array<{ oldestWaitingAgeMs: number }> }).queues[0]!
      .oldestWaitingAgeMs;
    expect(oldestWaitingAgeMs).toBeGreaterThanOrEqual(5_000);
  });

  it('returns oldestWaitingAgeMs: null when the queue has no waiting jobs', async () => {
    const queue = fakeQueue('worker-main', { waiting: 0, active: 0, delayed: 0, failed: 0, completed: 0 }, []);
    server = createHealthServer({ queues: [queue], pingRedis: async () => true });
    const port = await listenEphemeral(server);

    const { body } = await getJson(port, '/health');

    expect((body as { queues: Array<{ oldestWaitingAgeMs: unknown }> }).queues[0]!.oldestWaitingAgeMs).toBeNull();
  });

  it('summarises multiple queues (e.g. main + dlq)', async () => {
    const main = fakeQueue('worker-main', { waiting: 1, active: 0, delayed: 0, failed: 0, completed: 5 }, []);
    const dlq = fakeQueue('worker-main-dlq', { waiting: 0, active: 0, delayed: 0, failed: 2, completed: 0 }, []);
    server = createHealthServer({ queues: [main, dlq], pingRedis: async () => true });
    const port = await listenEphemeral(server);

    const { body } = await getJson(port, '/health');

    expect((body as { queues: Array<{ name: string }> }).queues.map((q) => q.name)).toEqual([
      'worker-main',
      'worker-main-dlq',
    ]);
  });

  it('returns 503 {status:"redis_unreachable"} when the Redis ping fails', async () => {
    const queue = fakeQueue('worker-main', { waiting: 0, active: 0, delayed: 0, failed: 0, completed: 0 }, []);
    server = createHealthServer({
      queues: [queue],
      pingRedis: async () => {
        throw new Error('ECONNREFUSED');
      },
    });
    const port = await listenEphemeral(server);

    const { statusCode, body } = await getJson(port, '/health');

    expect(statusCode).toBe(503);
    expect(body).toEqual({ status: 'redis_unreachable' });
  });

  it('returns 503 when pingRedis resolves false (no throw)', async () => {
    const queue = fakeQueue('worker-main', { waiting: 0, active: 0, delayed: 0, failed: 0, completed: 0 }, []);
    server = createHealthServer({ queues: [queue], pingRedis: async () => false });
    const port = await listenEphemeral(server);

    const { statusCode, body } = await getJson(port, '/health');

    expect(statusCode).toBe(503);
    expect(body).toEqual({ status: 'redis_unreachable' });
  });

  it('returns 404 for any other path/method', async () => {
    const queue = fakeQueue('worker-main', { waiting: 0, active: 0, delayed: 0, failed: 0, completed: 0 }, []);
    server = createHealthServer({ queues: [queue], pingRedis: async () => true });
    const port = await listenEphemeral(server);

    const { statusCode } = await getJson(port, '/not-health');

    expect(statusCode).toBe(404);
  });
});
