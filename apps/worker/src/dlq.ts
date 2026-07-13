import { Queue, type ConnectionOptions, type QueueEvents } from 'bullmq';

/**
 * dlq.ts — a dead-letter queue for jobs that have genuinely exhausted their
 * configured retry attempts (not just failed once and are about to be
 * retried by BullMQ itself).
 */

export interface DlqEntry {
  id: string | undefined;
  name: string;
  data: unknown;
  failedReason: string;
  attemptsMade: number;
  timestamp: number;
}

/**
 * NOT `${mainQueueName}:dlq` — verified live against a real BullMQ + Redis
 * (see this batch's build report): BullMQ's `QueueBase` constructor throws
 * `Error: Queue name cannot contain :` unconditionally (it reserves `:` as
 * its own Redis key-namespace separator). `-dlq` is the closest equivalent
 * that's actually constructible.
 */
export function dlqQueueName(mainQueueName: string): string {
  return `${mainQueueName}-dlq`;
}

export function createDlq(mainQueueName: string, connection: ConnectionOptions): Queue<DlqEntry> {
  return new Queue<DlqEntry>(dlqQueueName(mainQueueName), { connection });
}

/**
 * Minimal duck-typed surface this file needs from a BullMQ `Queue` — kept
 * narrow (rather than importing the real `Queue` type for this parameter) so
 * wireDlq() itself stays trivially testable against a plain fake object.
 */
export interface DlqSourceQueue {
  getJob(jobId: string): Promise<{ id?: string; name: string; data: unknown; attemptsMade: number; opts: { attempts?: number } } | undefined>;
}

/**
 * Wires a QueueEvents 'failed' listener on the main queue: when a job's
 * attemptsMade has reached (or exceeded) its OWN configured max attempts —
 * i.e. genuinely exhausted, BullMQ will not retry it again — the job's
 * terminal failure is mirrored into the DLQ queue. A failure that still has
 * retries left is intentionally left alone here; it is not "exhausted" yet.
 */
export function wireDlq(queueEvents: QueueEvents, dlq: Queue<DlqEntry>, mainQueue: DlqSourceQueue): void {
  queueEvents.on('failed', ({ jobId, failedReason }) => {
    void (async () => {
      const job = await mainQueue.getJob(jobId);
      if (!job) {
        // Job already cleaned up (e.g. removeOnFail) before we could inspect
        // it — nothing left to mirror into the DLQ.
        return;
      }

      const maxAttempts = job.opts.attempts ?? 1;
      if (job.attemptsMade < maxAttempts) {
        // Not exhausted — BullMQ will retry this job itself.
        return;
      }

      await dlq.add('dlq-entry', {
        id: job.id,
        name: job.name,
        data: job.data,
        failedReason,
        attemptsMade: job.attemptsMade,
        timestamp: Date.now(),
      });
    })();
  });
}
