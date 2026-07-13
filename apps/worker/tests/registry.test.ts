import { beforeEach, describe, expect, it } from 'vitest';
import { getJob, listJobs, registerJob, resetRegistry } from '../src/jobs/registry';
import type { JobDefinition } from '../src/jobs/types';

function makeJobDef<TPayload>(name: string): JobDefinition<TPayload> {
  return {
    name,
    trigger: { type: 'manual' },
    tenantFanout: false,
    retry: { attempts: 1, backoff: { type: 'fixed', delayMs: 1000 } },
    handler: async () => {},
  };
}

beforeEach(() => {
  resetRegistry();
});

describe('registry — registerJob/getJob/listJobs', () => {
  it('registers a job and retrieves it by name', () => {
    const def = makeJobDef('example-job');
    registerJob(def);

    expect(getJob('example-job')).toBe(def);
    expect(listJobs()).toHaveLength(1);
  });

  it('returns undefined for a name that was never registered', () => {
    expect(getJob('nonexistent')).toBeUndefined();
  });

  it('throws on a duplicate job-name registration', () => {
    registerJob(makeJobDef('dup'));
    expect(() => registerJob(makeJobDef('dup'))).toThrow(/already registered/i);
    // The first registration must survive the failed second attempt.
    expect(listJobs()).toHaveLength(1);
  });

  it('allows re-registering the same name after resetRegistry()', () => {
    registerJob(makeJobDef('reusable'));
    resetRegistry();
    expect(() => registerJob(makeJobDef('reusable'))).not.toThrow();
    expect(listJobs()).toHaveLength(1);
  });

  it('accepts distinct payload types across different job names', () => {
    registerJob(makeJobDef<{ tenantId: number }>('typed-a'));
    registerJob(makeJobDef<Record<string, never>>('typed-b'));
    expect(listJobs().map((j) => j.name).sort()).toEqual(['typed-a', 'typed-b']);
  });
});
