import type { JobDefinition } from './types';

/**
 * registry.ts — in-memory Map-backed job registry.
 *
 * The duplicate-name guard in registerJob() is a cheap guard against the
 * exact double-registration failure mode Phase 10's cron-manifest is
 * designed to prevent later at a larger scale (single ownership: a cron job
 * must run on exactly one of {crond, BullMQ}, never both — plan Phase 10 /
 * risk #6). This registry only prevents the in-process version of that bug
 * (two `registerJob()` calls for the same job name within one worker
 * process); it does not (yet) know anything about crond.
 *
 * Storage type note: jobs are stored keyed by `JobDefinition<never>` rather
 * than `JobDefinition<unknown>`. This isn't a typo — `never` is TypeScript's
 * bottom type, so for a contravariant function-parameter position (which is
 * exactly where TPayload appears, in `handler(payload: TPayload, ...)`),
 * *every* concrete `JobDefinition<TPayload>` is structurally assignable to
 * `JobDefinition<never>` (a function accepting `TPayload` can always be
 * called with a `never` — that call just can never actually happen). Using
 * `unknown` here instead would NOT type-check without an explicit cast,
 * since `(payload: TPayload) => ...` is not assignable to
 * `(payload: unknown) => ...` for an arbitrary TPayload.
 */

const jobs = new Map<string, JobDefinition<never>>();

export function registerJob<TPayload>(def: JobDefinition<TPayload>): void {
  if (jobs.has(def.name)) {
    throw new Error(
      `Job "${def.name}" is already registered — refusing a duplicate registerJob() call. ` +
        'Each job name must be registered exactly once per worker process.'
    );
  }
  jobs.set(def.name, def);
}

export function listJobs(): JobDefinition<never>[] {
  return [...jobs.values()];
}

export function getJob(name: string): JobDefinition<never> | undefined {
  return jobs.get(name);
}

/** Test hook — clears the registry between test cases. Not used by index.ts. */
export function resetRegistry(): void {
  jobs.clear();
}
