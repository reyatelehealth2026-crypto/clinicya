import { loadEnv as loadSharedEnv, type Env as SharedEnv } from '@reya/config';

/**
 * env.ts — thin local wrapper around @reya/config's loadEnv().
 *
 * DB_HOST/DB_USER/DB_PASS/REDIS_URL/NODE_ENV (and everything else in
 * @reya/config's envSchema) come straight from loadSharedEnv() below,
 * unmodified.
 *
 * FLAGGED FOR A FUTURE packages/config BATCH (mig-orc: route to mig-kernel):
 * WORKER_HEALTH_PORT, WORKER_HEARTBEAT_INTERVAL_MS, and
 * WORKER_SHUTDOWN_TIMEOUT_MS are read directly off process.env here, NOT
 * added to @reya/config's zod envSchema, because packages/config/** is a
 * READ-ONLY input to this batch (see this repo's mig-worker agent brief's
 * "allowed paths: apps/worker/** only"). Long-term these three belong in the
 * shared schema alongside REDIS_URL so every consumer gets the same
 * validated/typed shape — this file is a deliberately temporary work-around,
 * not the intended final home for these vars.
 */

export interface WorkerEnv extends SharedEnv {
  /** Port the plain node:http health server (health/server.ts) listens on. Default 8099 — chosen to avoid every port already claimed by docker-compose.dev.yml / infra/compose/docker-compose.strangler.yml / infra/e2e/docker-compose.yml (3000, 3001, 3306, 3307, 4000, 6379, 8080, 8091, 16379, 18092). */
  WORKER_HEALTH_PORT: number;
  /** How often (ms) the repeatable 'worker-heartbeat' job fires. Default 60_000. */
  WORKER_HEARTBEAT_INTERVAL_MS: number;
  /** Hard-kill fallback window (ms) for shutdown.ts's SIGTERM/SIGINT drain — see that file's doc comment. Default 25_000. */
  WORKER_SHUTDOWN_TIMEOUT_MS: number;
}

const DEFAULT_WORKER_HEALTH_PORT = 8099;
const DEFAULT_WORKER_HEARTBEAT_INTERVAL_MS = 60_000;
const DEFAULT_WORKER_SHUTDOWN_TIMEOUT_MS = 25_000;

function parsePositiveInt(raw: string | undefined, fallback: number, varName: string): number {
  if (raw === undefined || raw === '') {
    return fallback;
  }
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed <= 0) {
    throw new Error(`Invalid ${varName}: "${raw}" is not a positive integer`);
  }
  return parsed;
}

/**
 * Parses + validates @reya/config's shared env (fail-fast, same posture as
 * loadSharedEnv itself) plus the three worker-only vars above. Unlike
 * @reya/config's loadEnv(), this wrapper does NOT cache its own result —
 * the three local reads are cheap, and @reya/config's loadEnv() already
 * caches the shared portion internally.
 */
export function loadWorkerEnv(source: NodeJS.ProcessEnv = process.env): WorkerEnv {
  const shared = loadSharedEnv(source);
  return {
    ...shared,
    WORKER_HEALTH_PORT: parsePositiveInt(source.WORKER_HEALTH_PORT, DEFAULT_WORKER_HEALTH_PORT, 'WORKER_HEALTH_PORT'),
    WORKER_HEARTBEAT_INTERVAL_MS: parsePositiveInt(
      source.WORKER_HEARTBEAT_INTERVAL_MS,
      DEFAULT_WORKER_HEARTBEAT_INTERVAL_MS,
      'WORKER_HEARTBEAT_INTERVAL_MS'
    ),
    WORKER_SHUTDOWN_TIMEOUT_MS: parsePositiveInt(
      source.WORKER_SHUTDOWN_TIMEOUT_MS,
      DEFAULT_WORKER_SHUTDOWN_TIMEOUT_MS,
      'WORKER_SHUTDOWN_TIMEOUT_MS'
    ),
  };
}
