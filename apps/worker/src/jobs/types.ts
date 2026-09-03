/**
 * types.ts — the typed job-definition shape every BullMQ processor in this
 * worker registers against (see registry.ts + index.ts's Worker processor,
 * which dispatches `job.name` -> `getJob(job.name)` -> `def.handler(...)`).
 */

export interface JobRetryConfig {
  attempts: number;
  backoff: {
    type: 'exponential' | 'fixed';
    delayMs: number;
  };
}

/**
 * `trigger` intentionally does NOT yet accept a cron string or a
 * cron-manifest.json entry. Phase 10 proper is where the ~33 PHP cron/*.php
 * jobs get ported with real cron schedules and a single-ownership
 * cron-manifest (plan §Phase 10) — that manifest format doesn't exist yet.
 * This pure-scaffolding batch's only job (heartbeat.ts) uses the simpler
 * interval form below; `{type:'manual'}` exists so the registry/type shape
 * doesn't need to change shape again once a manually-triggered job shows up.
 */
export type JobTrigger = { type: 'repeat'; everyMs: number } | { type: 'manual' };

export interface JobContext {
  tenantFanout: boolean;
}

export interface JobDefinition<TPayload> {
  name: string;
  trigger: JobTrigger;
  handler: (payload: TPayload, ctx: JobContext) => Promise<void>;
  retry: JobRetryConfig;
  tenantFanout: boolean;
}
