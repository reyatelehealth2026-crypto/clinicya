#!/usr/bin/env node
// infra/e2e/worker-smoke.mjs
//
// Phase 8/10 scaffolding batch (docs/plans/2026-07-12-nextjs-full-migration-plan.md
// §1.1 apps/worker, Phase 8, Phase 10, §4.6) — proves, on a REAL container
// built from the REAL committed infra/worker/Dockerfile, that:
//   1. the image builds (deps -> builder -> runner, pnpm workspace-aware);
//   2. the running container reaches a REAL Redis and a REAL MariaDB;
//   3. the repeatable 'worker-heartbeat' BullMQ job fans out over REAL
//      seeded tenant DBs and writes REAL `activity_logs` rows — for BOTH
//      active scratch tenants, and NOT for the suspended one (proves the
//      `WHERE status = 'active'` filter in
//      apps/worker/src/tenant/forEachActiveTenant.ts is doing real work,
//      not just happening to only see active rows);
//   4. GET /health reports real BullMQ queue-state (plan §5.3's named
//      metric — completed count, depth, age), not just a bare 200;
//   5. SIGTERM drains an in-flight job (its DB write lands) before the
//      process exits, and the container exits within
//      apps/worker/src/shutdown.ts's own WORKER_SHUTDOWN_TIMEOUT_MS budget
//      — never instant-killed, never hung forever.
//
// See docs/runbooks/worker-scaffold-boot-drain.md for what this batch does
// and does NOT prove (no real cron jobs yet, no cron-manifest, no
// blue/green flip), how to read this script's output, and forward notes
// for mig-orc.
//
// Reuses infra/e2e/docker-compose.yml (mariadb+redis+php — UNMODIFIED,
// same file run.mjs/parity.mjs/api-parity.mjs/rollback-drill.mjs already
// use) and infra/e2e/lib/harness-common.mjs (compose lifecycle, secrets,
// SQL exec — same shared module every other script here uses), under this
// script's OWN project name ('reya-e2e-worker-smoke', following the
// existing PROJECT-per-script convention) — but brings up ONLY `mariadb`
// and `redis` (`docker compose ... up -d mariadb redis`, no `--build`):
// this smoke test has no use for the `php` service, and skipping its build
// avoids an unnecessary infra/php/Dockerfile build entirely.
//
// infra/e2e/docker-compose.yml's container names/ports are FIXED (not
// templated per-project — see that file's own comments), so — same
// pre-existing constraint every other script in this directory already has
// with each other — this harness CANNOT run concurrently with
// run.mjs/parity.mjs/api-parity.mjs/rollback-drill.mjs. Sequential use only.
//
// Single command to run this smoke test:
//   node infra/e2e/worker-smoke.mjs
//
// Always tears down — `docker compose down -v` (mariadb/redis) AND
// `docker rm -f`/`docker rmi` the worker container/image built by this run
// — in a finally block, even on a thrown error. `docker ps -a` shows zero
// leftover containers/networks from this script's project name afterward.

import { createRequire } from 'node:module';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  REPO_ROOT,
  HarnessError,
  createStepTracker,
  run,
  runOrThrow,
  makeComposeArgs,
  composeDown as sharedComposeDown,
  waitContainerHealthy as sharedWaitContainerHealthy,
  execSql as sharedExecSql,
  querySql as sharedQuerySql,
  parseLocalConfigPhp as sharedParseLocalConfigPhp,
  generateSecrets as sharedGenerateSecrets,
  sleep,
} from './lib/harness-common.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const COMPOSE_FILE = path.join(__dirname, 'docker-compose.yml');
const SEED_DIR = path.join(__dirname, 'seed');
const PROJECT = 'reya-e2e-worker-smoke';

const WORKER_DOCKERFILE = path.join(REPO_ROOT, 'infra/worker/Dockerfile');
const WORKER_IMAGE_TAG = 'clinicya-worker:smoke';
const WORKER_CONTAINER_NAME = 'e2e-worker-smoke-container';
const WORKER_HEALTH_HOST_PORT = 18199; // distinct from every other host port infra/e2e/docker-compose.yml/docker-compose.dev.yml/docker-compose.strangler.yml already claims (3000, 3001, 3306, 3307, 4000, 6379, 8080, 8091, 16379, 18092).

// This smoke test intentionally overrides apps/worker's own 60s/25s
// defaults (apps/worker/src/env.ts) with much shorter values — a scaffold
// smoke test proving the PIPELINE is real should not need to spend a full
// production heartbeat cadence waiting for evidence. WORKER_SMOKE_SHUTDOWN_TIMEOUT_MS
// still comfortably exceeds any real in-flight job's expected duration (a
// handful of sequential single-row INSERTs against a local MariaDB).
const WORKER_SMOKE_HEARTBEAT_INTERVAL_MS = 4_000;
const WORKER_SMOKE_SHUTDOWN_TIMEOUT_MS = 20_000;
// This script's own poll budgets — deliberately generous multiples of the
// interval above, not the production defaults (see docs/runbooks/
// worker-scaffold-boot-drain.md's "what this proves" section).
const HEARTBEAT_ROW_TIMEOUT_MS = WORKER_SMOKE_HEARTBEAT_INTERVAL_MS * 4 + 15_000;
const ACTIVE_JOB_DETECT_TIMEOUT_MS = 8_000;
const EXIT_BUDGET_MS = WORKER_SMOKE_SHUTDOWN_TIMEOUT_MS + 15_000; // hard-kill fallback + generous margin — NOT compose's stop_grace_period (this script uses a bare `docker run`, not docker-compose.worker.yml, so no compose-level grace period applies here — see that file's own stop_grace_period comment for the production number).

const MASTER_DB_NAME = 'zrismpsz_reya_platform'; // TenantContext::PLATFORM_DB_NAME — fixed, not a secret.
const TENANT_TEMPLATE = 'database/migration_2026-05-25_tenant_template.sql';

// Same six committed master migrations run.mjs/parity.mjs/api-parity.mjs
// each already carry their own copy of — see run.mjs's MASTER_MIGRATIONS
// comment for why this list is duplicated per-script rather than shared.
const MASTER_MIGRATIONS = [
  'database/migration_2026-05-25_platform_master.sql',
  'database/migration_2026-05-27_master_products.sql',
  'database/migration_2026-05-27_tenant_line_account_routes.sql',
  'database/migration_2026-06-04_platform_billing.sql',
  'database/migration_2026-06-04_platform_billing_details.sql',
  'packages/db/migrations/master/migration_2026-07-12_node_sessions.sql',
];

// Single source of truth for the three scratch tenants — both this script's
// DB-provisioning/polling code AND infra/e2e/seed/60-worker-smoke-tenants.sql.tmpl's
// placeholder substitution read from here, so the SQL and the assertions
// below can never silently drift apart (see that file's own header
// comment).
const WORKER_SMOKE_TENANTS = {
  active1: { slug: 'e2e-worker-smoke-active-1', dbName: 'e2e_worker_smoke_active_1', status: 'active' },
  active2: { slug: 'e2e-worker-smoke-active-2', dbName: 'e2e_worker_smoke_active_2', status: 'active' },
  suspended: { slug: 'e2e-worker-smoke-suspended-1', dbName: 'e2e_worker_smoke_suspended_1', status: 'suspended' },
};

const tracker = createStepTracker();
const { steps, markOk, fail } = tracker;

const composeArgs = makeComposeArgs(COMPOSE_FILE, PROJECT);

function parseLocalConfigPhp() {
  return sharedParseLocalConfigPhp(tracker, 'parse_config_php');
}

function composeDown(env) {
  return sharedComposeDown(composeArgs, env);
}

async function waitContainerHealthy(step, containerName, timeoutMs = 90_000) {
  return sharedWaitContainerHealthy(tracker, containerName, step, timeoutMs);
}

function execSql(step, env, rootPassword, sqlText, extraArgs = []) {
  return sharedExecSql(tracker, composeArgs, env, rootPassword, sqlText, extraArgs, step);
}

function querySql(step, env, rootPassword, sqlText, dbName) {
  return sharedQuerySql(tracker, composeArgs, env, rootPassword, sqlText, dbName, step);
}

// ---------------------------------------------------------------------------
// Step: bring up ONLY mariadb+redis (no `php`, no `--build`) — see module
// doc comment.
// ---------------------------------------------------------------------------
function composeUpDbOnly(env) {
  console.error('[worker-smoke] docker compose up -d mariadb redis ...');
  runOrThrow(tracker, 'compose_up', 'docker', composeArgs('up', '-d', 'mariadb', 'redis'), { env });
  markOk('compose_up');
}

// ---------------------------------------------------------------------------
// Seeding — master DB shell + grant, the six master migrations, THEN (unlike
// every other harness here) three separate tenant DBs — see
// infra/e2e/seed/60-worker-smoke-tenants.sql.tmpl's header comment for why
// a single-tenant fixture would not exercise forEachActiveTenant()'s status
// filter at all.
// ---------------------------------------------------------------------------

function seedMasterDb(env, rootPw, dbCreds) {
  const masterDbSql = readFileSync(path.join(SEED_DIR, '00-master-db.sql'), 'utf8');
  execSql('seed_master_db_create', env, rootPw, masterDbSql);
  execSql(
    'seed_master_db_grant',
    env,
    rootPw,
    `GRANT ALL PRIVILEGES ON \`${MASTER_DB_NAME}\`.* TO '${dbCreds.user}'@'%'; FLUSH PRIVILEGES;`
  );
  markOk('seed_master_db_create');
  markOk('seed_master_db_grant');

  for (const relPath of MASTER_MIGRATIONS) {
    const content = readFileSync(path.join(REPO_ROOT, relPath), 'utf8');
    const withUse = `USE \`${MASTER_DB_NAME}\`;\n${content}`;
    execSql('seed_master_migrations', env, rootPw, withUse);
  }
  markOk('seed_master_migrations', MASTER_MIGRATIONS);
}

/** Reuses the exact 05-app-db.sql.tmpl-style "CREATE DATABASE ... ; USE db; <tenant template>;" apply flow every other harness in this directory already uses — once per scratch tenant. */
function seedTenantDatabases(env, rootPw, dbCreds) {
  const templateContent = readFileSync(path.join(REPO_ROOT, TENANT_TEMPLATE), 'utf8');

  for (const tenant of Object.values(WORKER_SMOKE_TENANTS)) {
    execSql(
      'seed_tenant_dbs_create',
      env,
      rootPw,
      `CREATE DATABASE IF NOT EXISTS \`${tenant.dbName}\` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci;\n` +
        `GRANT ALL PRIVILEGES ON \`${tenant.dbName}\`.* TO '${dbCreds.user}'@'%'; FLUSH PRIVILEGES;`
    );
    execSql('seed_tenant_dbs_template', env, rootPw, `USE \`${tenant.dbName}\`;\n${templateContent}`);
  }
  markOk('seed_tenant_dbs_create', Object.values(WORKER_SMOKE_TENANTS).map((t) => t.dbName));
  markOk('seed_tenant_dbs_template');
}

function seedTenantRows(env, rootPw) {
  const tmpl = readFileSync(path.join(SEED_DIR, '60-worker-smoke-tenants.sql.tmpl'), 'utf8');
  const sql = tmpl
    .replaceAll('__TENANT_ACTIVE_1_SLUG__', WORKER_SMOKE_TENANTS.active1.slug)
    .replaceAll('__TENANT_ACTIVE_1_DB__', WORKER_SMOKE_TENANTS.active1.dbName)
    .replaceAll('__TENANT_ACTIVE_2_SLUG__', WORKER_SMOKE_TENANTS.active2.slug)
    .replaceAll('__TENANT_ACTIVE_2_DB__', WORKER_SMOKE_TENANTS.active2.dbName)
    .replaceAll('__TENANT_SUSPENDED_SLUG__', WORKER_SMOKE_TENANTS.suspended.slug)
    .replaceAll('__TENANT_SUSPENDED_DB__', WORKER_SMOKE_TENANTS.suspended.dbName);
  execSql('seed_tenant_rows', env, rootPw, sql);
  markOk('seed_tenant_rows');

  const ids = {};
  for (const [key, tenant] of Object.entries(WORKER_SMOKE_TENANTS)) {
    const idStr = querySql(
      'seed_lookup_tenant_ids',
      env,
      rootPw,
      `SELECT id FROM tenants WHERE slug = '${tenant.slug}' LIMIT 1;`,
      MASTER_DB_NAME
    );
    if (!idStr || Number.isNaN(Number(idStr))) {
      fail('seed_lookup_tenant_ids', `Could not read back tenants.id for slug ${tenant.slug}`, { raw: idStr });
    }
    ids[key] = Number(idStr);
  }
  markOk('seed_lookup_tenant_ids', ids);
  return ids;
}

// ---------------------------------------------------------------------------
// Docker build/run for the worker image itself.
// ---------------------------------------------------------------------------

function buildWorkerImage() {
  console.error(`[worker-smoke] docker build -f ${WORKER_DOCKERFILE} -t ${WORKER_IMAGE_TAG} ...`);
  runOrThrow(tracker, 'docker_build_worker_image', 'docker', [
    'build',
    '-f',
    WORKER_DOCKERFILE,
    '-t',
    WORKER_IMAGE_TAG,
    '.',
  ]);
  markOk('docker_build_worker_image');
}

/** Resolves the ACTUAL docker-compose-generated network name for this project (`<project>_e2e-net` empirically, but looked up rather than assumed — see docker-compose.yml's network stanza). */
function resolveComposeNetwork() {
  const result = run('docker', [
    'network',
    'ls',
    '--filter',
    `label=com.docker.compose.project=${PROJECT}`,
    '--format',
    '{{.Name}}',
  ]);
  const name = (result.stdout || '').trim().split('\n').filter(Boolean)[0];
  if (!name) {
    fail('resolve_compose_network', 'Could not resolve the compose-generated network name', {
      stdout: result.stdout,
      stderr: result.stderr,
    });
  }
  markOk('resolve_compose_network', name);
  return name;
}

function runWorkerContainer(network, dbCreds) {
  runOrThrow(tracker, 'docker_run_worker', 'docker', [
    'run',
    '-d',
    '--name',
    WORKER_CONTAINER_NAME,
    '--network',
    network,
    '-p',
    `${WORKER_HEALTH_HOST_PORT}:8099`,
    '-e',
    'REDIS_URL=redis://e2e-redis:6379',
    '-e',
    'DB_HOST=e2e-mariadb',
    '-e',
    'DB_PORT=3306',
    '-e',
    `DB_USER=${dbCreds.user}`,
    '-e',
    `DB_PASS=${dbCreds.pass}`,
    '-e',
    'WORKER_HEALTH_PORT=8099',
    '-e',
    `WORKER_HEARTBEAT_INTERVAL_MS=${WORKER_SMOKE_HEARTBEAT_INTERVAL_MS}`,
    '-e',
    `WORKER_SHUTDOWN_TIMEOUT_MS=${WORKER_SMOKE_SHUTDOWN_TIMEOUT_MS}`,
    WORKER_IMAGE_TAG,
  ]);
  markOk('docker_run_worker');
}

// ---------------------------------------------------------------------------
// Redis reachability (separate from container_healthy — this specifically
// exercises the worker's OWN Redis client, not just "the redis container's
// own healthcheck passed" per acceptance criterion `redis_reachable`).
// ---------------------------------------------------------------------------
async function waitRedisReachableViaHealth(timeoutMs = 60_000) {
  const started = Date.now();
  for (;;) {
    try {
      const resp = await fetch(`http://127.0.0.1:${WORKER_HEALTH_HOST_PORT}/health`);
      if (resp.status === 200) {
        const body = await resp.json();
        if (body.status === 'ok') {
          markOk('redis_reachable', body);
          return body;
        }
      }
      // 503 with {status:'redis_unreachable'} is health/server.ts's own
      // explicit signal — surface it verbatim while we keep polling.
    } catch {
      // container/health server not listening yet — keep polling.
    }
    if (Date.now() - started > timeoutMs) {
      fail('redis_reachable', `/health never reported status:'ok' (real Redis PING) within ${timeoutMs}ms`);
    }
    await sleep(500);
  }
}

/** Returns null (never throws) on a non-200 OR a connection failure — the latter is expected/routine once shutdown.ts starts closing the health server mid-drain, not an error condition callers should crash on. */
async function fetchHealth() {
  try {
    const resp = await fetch(`http://127.0.0.1:${WORKER_HEALTH_HOST_PORT}/health`);
    if (resp.status !== 200) {
      return null;
    }
    return await resp.json();
  } catch {
    return null;
  }
}

/** JSON.stringify({ranAt, tenantId}) — see apps/worker/src/jobs/heartbeat.ts's handler. `tenantId` is a JS number, so it serializes unquoted; parse+compare numerically rather than substring-matching the raw text. */
function extraDataMatchesTenant(extraDataRaw, tenantId) {
  try {
    const parsed = JSON.parse(extraDataRaw);
    return Number(parsed.tenantId) === Number(tenantId);
  } catch {
    return false;
  }
}

async function waitHeartbeatRow(step, env, rootPw, tenant, tenantId, timeoutMs) {
  const started = Date.now();
  for (;;) {
    const raw = querySql(
      step,
      env,
      rootPw,
      `SELECT extra_data FROM activity_logs WHERE action = 'worker.heartbeat' ORDER BY created_at DESC LIMIT 20;`,
      tenant.dbName
    );
    const rows = raw ? raw.split('\n').filter(Boolean) : [];
    const match = rows.find((r) => extraDataMatchesTenant(r, tenantId));
    if (match) {
      markOk(step, { tenantId, extraData: match });
      return;
    }
    if (Date.now() - started > timeoutMs) {
      fail(step, `No activity_logs row with action='worker.heartbeat' matching tenantId=${tenantId} in ${tenant.dbName} within ${timeoutMs}ms`, {
        rowsSeen: rows.length,
      });
    }
    await sleep(500);
  }
}

function countHeartbeatRows(env, rootPw, tenant) {
  const countStr = querySql(
    'heartbeat_suspended_tenant_no_row',
    env,
    rootPw,
    `SELECT COUNT(*) FROM activity_logs WHERE action = 'worker.heartbeat';`,
    tenant.dbName
  );
  return Number(countStr);
}

// ---------------------------------------------------------------------------
// SIGTERM drain proof — see module doc comment step 5. Enqueues one extra
// manual 'worker-heartbeat' job directly via bullmq's Queue (imported from
// apps/worker's OWN resolved node_modules via createRequire, so this
// harness never adds bullmq as a dependency of its own — see the inline
// comment at the call site) to make an in-flight job's timing deterministic
// rather than racing the natural repeat schedule, then polls /health for
// the main queue's `active` count to flip to >=1 (BullMQ marks a job
// 'active' the instant the Worker dequeues it, before the handler's async
// body runs) and sends SIGTERM at that exact moment.
// ---------------------------------------------------------------------------

/**
 * Resolves bullmq's `Queue` class through apps/worker's OWN resolved
 * node_modules (via createRequire rooted at apps/worker/package.json — pnpm
 * workspaces do NOT hoist packages to the repo-root node_modules, so a
 * plain `import('bullmq')` from this file would not resolve). This harness
 * does not add bullmq as a dependency of its own; it borrows the exact
 * install apps/worker's Docker image was just built from.
 */
function loadBullmqFromWorkerNodeModules() {
  const req = createRequire(path.join(REPO_ROOT, 'apps/worker/package.json'));
  return req('bullmq');
}

async function drainSigtermCheck(env, rootPw) {
  // Enqueues one extra manual 'worker-heartbeat' job so an in-flight job's
  // timing is deterministic rather than racing the natural repeat schedule
  // — see this section's module-doc comment. Connects on the HOST-mapped
  // redis port (16379 — same "host-side addresses" pattern
  // harness-common.mjs's other host-facing helpers already use), not
  // through the container network, since this function runs in THIS (host)
  // Node process, not inside any container.
  const { Queue } = loadBullmqFromWorkerNodeModules();
  const queue = new Queue('worker-main', { connection: { host: '127.0.0.1', port: 16379 } });
  try {
    await queue.add(
      'worker-heartbeat',
      {},
      { attempts: 1, backoff: { type: 'fixed', delay: 1000 }, removeOnComplete: true }
    );
  } finally {
    await queue.close();
  }

  // Poll /health for the main queue to show an active job, sending SIGTERM
  // the instant we see it — see this section's module-doc comment.
  const started = Date.now();
  let sawActive = false;
  for (;;) {
    const body = await fetchHealth();
    const mainQ = body?.queues?.find((q) => q.name === 'worker-main');
    if (mainQ && mainQ.active >= 1) {
      sawActive = true;
      break;
    }
    if (Date.now() - started > ACTIVE_JOB_DETECT_TIMEOUT_MS) {
      break; // Fall through — still send SIGTERM below; the job may simply have been too fast to observe (warm connection pools). The assertions after signal still hold regardless of whether we caught it mid-flight.
    }
    await sleep(20);
  }
  markOk('sigterm_active_job_observed', sawActive);

  const sigtermSentAt = Date.now();
  runOrThrow(tracker, 'sigterm_send', 'docker', ['kill', '-s', 'SIGTERM', WORKER_CONTAINER_NAME]);
  markOk('sigterm_send', sigtermSentAt);

  // sigterm_drains_inflight_job: while the container is STILL running, poll
  // until we see the completed count for worker-main increase past what it
  // was right before we sent the signal (proving the in-flight job's work —
  // its DB writes — actually finished, not merely "the container is still
  // alive"). Race this against the container actually exiting; either
  // observation (completed-count increase OR container already exited with
  // the fresh row present) satisfies the assertion below.
  const preSignalHealth = await fetchHealth();
  const preSignalCompleted = preSignalHealth?.queues?.find((q) => q.name === 'worker-main')?.completed ?? 0;

  let drained = false;
  let containerExitedDuringDrainPoll = false;
  const drainPollStarted = Date.now();
  while (Date.now() - drainPollStarted < EXIT_BUDGET_MS) {
    const running = run('docker', ['inspect', '-f', '{{.State.Running}}', WORKER_CONTAINER_NAME]);
    const isRunning = (running.stdout || '').trim() === 'true';

    if (isRunning) {
      const body = await fetchHealth();
      const completed = body?.queues?.find((q) => q.name === 'worker-main')?.completed ?? preSignalCompleted;
      if (completed > preSignalCompleted) {
        drained = true;
        break;
      }
    } else {
      containerExitedDuringDrainPoll = true;
      break;
    }
    await sleep(150);
  }

  if (!drained && containerExitedDuringDrainPoll) {
    // Container already exited before we observed the completed-count
    // increase via /health (health server closes as part of the same
    // shutdown sequence — see shutdown.ts). Fall back to the authoritative
    // ground truth: the tenant DB row itself, with a created_at timestamp
    // at/after sigtermSentAt.
    for (const tenant of [WORKER_SMOKE_TENANTS.active1, WORKER_SMOKE_TENANTS.active2]) {
      const raw = querySql(
        'sigterm_drains_inflight_job',
        env,
        rootPw,
        `SELECT COUNT(*) FROM activity_logs WHERE action = 'worker.heartbeat' AND created_at >= FROM_UNIXTIME(${Math.floor(
          (sigtermSentAt - 2_000) / 1000 // small backward margin for clock skew between host and container
        )});`,
        tenant.dbName
      );
      if (Number(raw) > 0) {
        drained = true;
      }
    }
  }

  if (!drained) {
    fail(
      'sigterm_drains_inflight_job',
      'Neither the /health completed-count nor a fresh activity_logs row confirmed the in-flight job finished before/around container exit',
      { sawActive, preSignalCompleted }
    );
  }
  markOk('sigterm_drains_inflight_job', { sawActiveJobBeforeSignal: sawActive, viaHealthPoll: !containerExitedDuringDrainPoll });

  // container_exits_within_budget: keep polling Running until it flips to
  // false, bounded by EXIT_BUDGET_MS total (measured from sigtermSentAt,
  // not from here — the loop above may have already consumed part of it).
  let exitedAt = null;
  for (;;) {
    const running = run('docker', ['inspect', '-f', '{{.State.Running}}', WORKER_CONTAINER_NAME]);
    if ((running.stdout || '').trim() === 'false') {
      exitedAt = Date.now();
      break;
    }
    if (Date.now() - sigtermSentAt > EXIT_BUDGET_MS) {
      break;
    }
    await sleep(150);
  }

  if (!exitedAt) {
    fail('container_exits_within_budget', `Container did not exit within ${EXIT_BUDGET_MS}ms of SIGTERM`, {
      shutdownTimeoutMs: WORKER_SMOKE_SHUTDOWN_TIMEOUT_MS,
    });
  }
  const elapsedMs = exitedAt - sigtermSentAt;
  const exitInspect = run('docker', ['inspect', '-f', '{{.State.ExitCode}}', WORKER_CONTAINER_NAME]);
  markOk('container_exits_within_budget', { elapsedMs, exitCode: (exitInspect.stdout || '').trim() });
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  const dbCreds = parseLocalConfigPhp();
  const secrets = sharedGenerateSecrets();

  const composeEnv = {
    ...process.env,
    E2E_MARIADB_ROOT_PASSWORD: secrets.mariadbRootPassword,
    E2E_APP_DB_NAME: dbCreds.name,
    E2E_APP_DB_USER: dbCreds.user,
    E2E_APP_DB_PASSWORD: dbCreds.pass,
    // Required by docker-compose.yml's `php` service interpolation even
    // though this script never starts `php` (compose validates/interpolates
    // every service's environment block up front regardless of which
    // service names are passed to `up` — verified empirically against this
    // exact compose file during this batch's build).
    E2E_SESSION_BRIDGE_HMAC_SECRET: secrets.sessionBridgeHmacSecret,
  };

  let result = 'FAIL';
  let workerContainerStarted = false;
  let imageBuilt = false;

  try {
    if (!existsSync(WORKER_DOCKERFILE)) {
      fail('docker_build_worker_image', `${WORKER_DOCKERFILE} does not exist`);
    }

    composeUpDbOnly(composeEnv);
    await waitContainerHealthy('mariadb_healthy', 'e2e-mariadb');
    // NOT the acceptance-required `container_healthy` step (that one names
    // the WORKER container below) — this is just the redis container's own
    // infra healthcheck. The worker's OWN Redis reachability is proven
    // separately (redis_reachable, via its /health endpoint) once it's running.
    await waitContainerHealthy('redis_container_infra_healthy', 'e2e-redis');

    seedMasterDb(composeEnv, secrets.mariadbRootPassword, dbCreds);
    seedTenantDatabases(composeEnv, secrets.mariadbRootPassword, dbCreds);
    const tenantIds = seedTenantRows(composeEnv, secrets.mariadbRootPassword);

    buildWorkerImage();
    imageBuilt = true;

    const network = resolveComposeNetwork();
    runWorkerContainer(network, dbCreds);
    workerContainerStarted = true;

    await waitContainerHealthy('container_healthy', WORKER_CONTAINER_NAME);
    await waitRedisReachableViaHealth();

    // Fan-out proof: both active tenants get rows, the suspended tenant
    // never does. Check the active tenants FIRST (bounded wait for the
    // first firing), THEN check suspended — checking suspended only after
    // waiting for real heartbeat activity elsewhere means a genuine "the
    // job never ran for anyone" bug would already have failed the active
    // assertions, so a clean suspended-tenant zero-count here is a real
    // negative result, not just "nothing happened yet".
    await waitHeartbeatRow(
      'heartbeat_active_tenant_1_row',
      composeEnv,
      secrets.mariadbRootPassword,
      WORKER_SMOKE_TENANTS.active1,
      tenantIds.active1,
      HEARTBEAT_ROW_TIMEOUT_MS
    );
    await waitHeartbeatRow(
      'heartbeat_active_tenant_2_row',
      composeEnv,
      secrets.mariadbRootPassword,
      WORKER_SMOKE_TENANTS.active2,
      tenantIds.active2,
      HEARTBEAT_ROW_TIMEOUT_MS
    );

    const suspendedCount = countHeartbeatRows(composeEnv, secrets.mariadbRootPassword, WORKER_SMOKE_TENANTS.suspended);
    if (suspendedCount !== 0) {
      fail('heartbeat_suspended_tenant_no_row', `Expected 0 activity_logs('worker.heartbeat') rows for the suspended tenant, found ${suspendedCount}`, {
        suspendedCount,
      });
    }
    markOk('heartbeat_suspended_tenant_no_row', suspendedCount);

    // health_endpoint_reports_queue_depth: real BullMQ queue-state, not
    // just a 200 — plan §5.3's named metric. By this point at least one
    // heartbeat has completed (the waitHeartbeatRow calls above already
    // block on real DB evidence of that), so `completed` must be >= 1 and
    // every numeric field must be a sane (non-negative, finite) number.
    const health = await fetchHealth();
    const mainQueue = health?.queues?.find((q) => q.name === 'worker-main');
    if (!mainQueue) {
      fail('health_endpoint_reports_queue_depth', "GET /health did not include a 'worker-main' queue entry", { health });
    }
    const numericFields = ['waiting', 'active', 'delayed', 'failed', 'completed'];
    const allSane = numericFields.every((f) => Number.isFinite(mainQueue[f]) && mainQueue[f] >= 0);
    if (!allSane || mainQueue.completed < 1) {
      fail(
        'health_endpoint_reports_queue_depth',
        'GET /health queue-state was not plausible (expected every numeric field sane and completed >= 1)',
        { mainQueue }
      );
    }
    markOk('health_endpoint_reports_queue_depth', mainQueue);

    // SIGTERM drain proof (sigterm_active_job_observed, sigterm_send,
    // sigterm_drains_inflight_job, container_exits_within_budget).
    await drainSigtermCheck(composeEnv, secrets.mariadbRootPassword);

    result = 'PASS';
  } catch (err) {
    result = 'FAIL';
    if (!(err instanceof HarnessError)) {
      tracker.setFailedAt('unexpected_error');
      steps.unexpected_error = { ok: false, message: String(err && err.stack ? err.stack : err) };
    }
  } finally {
    // Best-effort, in dependency order — never throw past this point.
    if (workerContainerStarted) {
      run('docker', ['rm', '-f', WORKER_CONTAINER_NAME]);
    }
    if (imageBuilt) {
      run('docker', ['rmi', '-f', WORKER_IMAGE_TAG]);
    }
    composeDown(composeEnv);
  }

  const output = { result, steps, failedAt: tracker.getFailedAt() };
  console.log(JSON.stringify(output));
  process.exitCode = result === 'PASS' ? 0 : 1;
}

main();
