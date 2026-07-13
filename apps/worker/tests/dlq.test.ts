import { describe, expect, it, vi } from 'vitest';

const { queueInstances, QueueMock } = vi.hoisted(() => {
  const instances: Array<{ name: string; opts: unknown; add: ReturnType<typeof vi.fn> }> = [];
  class FakeQueue {
    name: string;
    opts: unknown;
    add = vi.fn(async (jobName: string, data: unknown) => ({ id: 'dlq-1', name: jobName, data }));
    constructor(name: string, opts: unknown) {
      this.name = name;
      this.opts = opts;
      instances.push(this as unknown as { name: string; opts: unknown; add: ReturnType<typeof vi.fn> });
    }
  }
  return { queueInstances: instances, QueueMock: FakeQueue };
});

vi.mock('bullmq', () => ({
  Queue: QueueMock,
}));

/** A minimal fake QueueEvents — just enough surface for wireDlq()'s `.on('failed', ...)`. */
function makeFakeQueueEvents(): { on: (event: string, cb: (payload: unknown) => unknown) => void; emit: (event: string, payload: unknown) => Promise<void> } {
  const handlers: Record<string, Array<(payload: unknown) => unknown>> = {};
  return {
    on(event, cb) {
      (handlers[event] ??= []).push(cb);
    },
    async emit(event, payload) {
      const results = (handlers[event] ?? []).map((cb) => cb(payload));
      await Promise.all(results);
      // wireDlq's listener body is a fire-and-forget async IIFE — give its
      // microtasks a chance to flush even though the listener itself
      // returns undefined synchronously.
      await Promise.resolve();
      await Promise.resolve();
    },
  };
}

describe('dlqQueueName', () => {
  it('appends "-dlq" to the main queue name (not ":dlq" — BullMQ rejects ":" in queue names, see dlq.ts)', async () => {
    const { dlqQueueName } = await import('../src/dlq');
    expect(dlqQueueName('worker-main')).toBe('worker-main-dlq');
  });
});

describe('createDlq', () => {
  it('constructs a bullmq Queue named "<queueName>-dlq" with the given connection', async () => {
    const { createDlq } = await import('../src/dlq');
    const connection = { marker: 'fake-connection' };

    const dlq = createDlq('worker-main', connection as never);

    expect(dlq.name).toBe('worker-main-dlq');
    const created = queueInstances[queueInstances.length - 1]!;
    expect(created.opts).toEqual({ connection });
  });
});

describe('wireDlq', () => {
  it('adds exactly one DLQ entry when a job has exhausted its configured attempts', async () => {
    const { wireDlq } = await import('../src/dlq');
    const queueEvents = makeFakeQueueEvents();
    const dlqAdd = vi.fn(async () => ({}));
    const fakeDlq = { add: dlqAdd };
    const fakeJob = { id: 'job-1', name: 'worker-heartbeat', data: { foo: 'bar' }, attemptsMade: 3, opts: { attempts: 3 } };
    const mainQueue = { getJob: vi.fn(async (id: string) => (id === 'job-1' ? fakeJob : undefined)) };

    wireDlq(queueEvents as never, fakeDlq as never, mainQueue);
    await queueEvents.emit('failed', { jobId: 'job-1', failedReason: 'boom' });

    expect(dlqAdd).toHaveBeenCalledTimes(1);
    expect(dlqAdd).toHaveBeenCalledWith('dlq-entry', {
      id: 'job-1',
      name: 'worker-heartbeat',
      data: { foo: 'bar' },
      failedReason: 'boom',
      attemptsMade: 3,
      timestamp: expect.any(Number),
    });
  });

  it('adds zero DLQ entries when the job has not yet exhausted its configured attempts', async () => {
    const { wireDlq } = await import('../src/dlq');
    const queueEvents = makeFakeQueueEvents();
    const dlqAdd = vi.fn(async () => ({}));
    const fakeDlq = { add: dlqAdd };
    const fakeJob = { id: 'job-2', name: 'worker-heartbeat', data: {}, attemptsMade: 1, opts: { attempts: 3 } };
    const mainQueue = { getJob: vi.fn(async () => fakeJob) };

    wireDlq(queueEvents as never, fakeDlq as never, mainQueue);
    await queueEvents.emit('failed', { jobId: 'job-2', failedReason: 'transient' });

    expect(dlqAdd).not.toHaveBeenCalled();
  });

  it('is a no-op when the job cannot be found (already cleaned up)', async () => {
    const { wireDlq } = await import('../src/dlq');
    const queueEvents = makeFakeQueueEvents();
    const dlqAdd = vi.fn(async () => ({}));
    const fakeDlq = { add: dlqAdd };
    const mainQueue = { getJob: vi.fn(async () => undefined) };

    wireDlq(queueEvents as never, fakeDlq as never, mainQueue);
    await queueEvents.emit('failed', { jobId: 'ghost', failedReason: 'boom' });

    expect(dlqAdd).not.toHaveBeenCalled();
  });

  it('treats attemptsMade > configured attempts as exhausted too (defensive >=)', async () => {
    const { wireDlq } = await import('../src/dlq');
    const queueEvents = makeFakeQueueEvents();
    const dlqAdd = vi.fn(async () => ({}));
    const fakeDlq = { add: dlqAdd };
    const fakeJob = { id: 'job-3', name: 'worker-heartbeat', data: {}, attemptsMade: 5, opts: { attempts: 3 } };
    const mainQueue = { getJob: vi.fn(async () => fakeJob) };

    wireDlq(queueEvents as never, fakeDlq as never, mainQueue);
    await queueEvents.emit('failed', { jobId: 'job-3', failedReason: 'boom' });

    expect(dlqAdd).toHaveBeenCalledTimes(1);
  });
});
