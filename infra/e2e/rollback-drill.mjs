#!/usr/bin/env node
// infra/e2e/rollback-drill.mjs
//
// mig-verify finding "rollback-untested" on Phase 3 batch 1 (mig-infra
// re-review). The plan's §7 verification gate, item 6, is explicit: "every
// phase must actually drill flipping back on canary before ramp"
// (docs/plans/2026-07-12-nextjs-full-migration-plan.md, "Rollback drill").
// docs/runbooks/phase3-batch1-miniapp-api-parity.md §7/§8 already documents
// that THIS surface's real canary mechanic is NOT infra/nginx/routes.json
// (which points `/api/miniapp` at `next_admin` unconditionally, by design —
// see that runbook) but line-mini-app/src/lib/config.ts's client-side
// `NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES` per-endpoint override map, and
// that §8 explicitly flagged this as UNPROVEN by api-parity.mjs ("does NOT
// exercise NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES or php-bridge.ts's
// override-map logic at all ... a separate, line-mini-app-scoped
// verification concern"). This script IS that separate verification: it
// proves, against REAL running containers/processes (never mocks), that
// flipping ONE real endpoint's override on and reverting it off is a clean,
// working, one-line-revert operation end-to-end.
//
// What it does, in order:
//   1. Stands up the SAME mariadb+redis+php stack api-parity.mjs uses
//      (infra/e2e/docker-compose.yml, UNMODIFIED) and seeds it with the SAME
//      master migrations + tenant template + this batch's own fixture
//      (infra/e2e/seed/45-phase3-batch1-plan-and-tenant.sql.tmpl +
//      50-phase3-batch1-miniapp-fixture.sql.tmpl) — reused verbatim, not
//      forked, so the richMember identity (line_user_id=e2e-mp-rich-member,
//      line_account_id=1) with its pre-seeded `user_health_profiles` row is
//      available to both stacks.
//   2. `pnpm --filter admin run build` (ALWAYS, never trusts a stale
//      standalone bundle — same policy api-parity.mjs/parity.mjs use) +
//      starts the real `next build` standalone server as a plain child
//      process.
//   3. Runs infra/e2e/lib/rollback-drill-client.mjs under
//      `node --experimental-strip-types` — a REAL Node process that imports
//      line-mini-app/src/lib/config.ts DIRECTLY AND UNMODIFIED and performs
//      three REAL network calls in sequence against the two REAL running
//      servers above:
//        (a) baseline, no override configured -> must hit PHP
//        (b) NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES set for
//            `GET /api/health-profile.php` -> must hit the real
//            /api/miniapp/health-profile Next route, and return the SAME
//            profile data as (a) (proves the flip is not just "reachable"
//            but functionally correct)
//        (c) override removed (the actual one-line revert) -> must hit PHP
//            again, response byte-identical to (a) (proves revert restores
//            the exact original behaviour, not merely "doesn't crash")
//   4. Verifies all three assertions itself (this script does not just
//      trust the client's exit code) and prints one machine-readable JSON
//      line. ALWAYS tears down (docker compose down -v + kill the Next
//      child) in a finally block, exit code 0 only on full PASS.
//
// Reuses infra/e2e/lib/harness-common.mjs (compose lifecycle, secrets, SQL
// exec) exactly like run.mjs/parity.mjs/api-parity.mjs do.
// buildAdmin()/prepareStandaloneStatic()/startNextServer() below are a
// DOCUMENTED COPY of api-parity.mjs's (itself a documented copy of
// parity.mjs's) functions of the same name — same rationale: none of those
// scripts export anything (top-level "call main() unconditionally" scripts),
// and this batch's allowed-paths boundary is additive-only within infra/e2e,
// not a license to refactor the existing harnesses.
//
// Uses its OWN docker-compose project name ('reya-e2e-rollback-drill') for
// `docker ps`/log clarity, but infra/e2e/docker-compose.yml's container
// ports are FIXED or not templated per-project (see that file's own
// comments), so — same pre-existing constraint run.mjs/parity.mjs/
// api-parity.mjs already have with each other — this harness CANNOT run
// concurrently with any of them. Sequential use only.
//
// Single command to run this drill:
//   node infra/e2e/rollback-drill.mjs

import { spawn, spawnSync } from 'node:child_process';
import { cpSync, existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  REPO_ROOT,
  HarnessError,
  createStepTracker,
  run,
  makeComposeArgs,
  composeUp,
  composeDown,
  waitContainerHealthy,
  execSql,
  parseLocalConfigPhp,
  generateSecrets,
  waitHttpReachable,
} from './lib/harness-common.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const COMPOSE_FILE = path.join(__dirname, 'docker-compose.yml');
const SEED_DIR = path.join(__dirname, 'seed');
const PROJECT = 'reya-e2e-rollback-drill';

const MARIADB_HOST = '127.0.0.1';
const REDIS_HOST_URL = 'redis://127.0.0.1:16379';
const PHP_BASE_URL = 'http://127.0.0.1:18092';
const NEXT_PORT = 3221; // distinct from api-parity.mjs's 3220 / parity.mjs's 3210 — log clarity only (never run concurrently regardless, see module doc).
const NEXT_BASE_URL = `http://127.0.0.1:${NEXT_PORT}`;

const MASTER_DB_NAME = 'zrismpsz_reya_platform';
const TENANT_SLUG = 'e2e-api-parity-harness'; // reuses api-parity.mjs's own seed files verbatim — same tenant.

const MASTER_MIGRATIONS = [
  'database/migration_2026-05-25_platform_master.sql',
  'database/migration_2026-05-27_master_products.sql',
  'database/migration_2026-05-27_tenant_line_account_routes.sql',
  'database/migration_2026-06-02_route_liff_id.sql',
  'database/migration_2026-06-04_platform_billing.sql',
  'database/migration_2026-06-04_platform_billing_details.sql',
  'packages/db/migrations/master/migration_2026-07-12_node_sessions.sql',
];
const TENANT_TEMPLATE = 'database/migration_2026-05-25_tenant_template.sql';
const PLAN_AND_TENANT_FILE = '45-phase3-batch1-plan-and-tenant.sql.tmpl';
const FIXTURE_FILE = '50-phase3-batch1-miniapp-fixture.sql.tmpl';

const DRILL_LINE_USER_ID = 'e2e-mp-rich-member';
const DRILL_LINE_ACCOUNT_ID = 1;

const ADMIN_DIR = path.join(REPO_ROOT, 'apps/admin');
const ADMIN_STANDALONE_DIR = path.join(ADMIN_DIR, '.next/standalone/apps/admin');
const CLIENT_SCRIPT = path.join(__dirname, 'lib/rollback-drill-client.mjs');

const tracker = createStepTracker();
const { steps, markOk, fail } = tracker;
const composeArgs = makeComposeArgs(COMPOSE_FILE, PROJECT);

// ---------------------------------------------------------------------------
// Database seeding — same steps/files api-parity.mjs uses (documented copy;
// no export surface to import from, see module doc).
// ---------------------------------------------------------------------------

function seedDatabase(env, secrets, dbCreds) {
  const rootPw = secrets.mariadbRootPassword;

  const masterDbSql = readFileSync(path.join(SEED_DIR, '00-master-db.sql'), 'utf8');
  execSql(tracker, composeArgs, env, rootPw, masterDbSql, [], 'seed_master_db_create');
  execSql(
    tracker,
    composeArgs,
    env,
    rootPw,
    `GRANT ALL PRIVILEGES ON \`${MASTER_DB_NAME}\`.* TO '${dbCreds.user}'@'%'; FLUSH PRIVILEGES;`,
    [],
    'seed_master_db_grant'
  );
  markOk('seed_master_db_create');
  markOk('seed_master_db_grant');

  for (const relPath of MASTER_MIGRATIONS) {
    const content = readFileSync(path.join(REPO_ROOT, relPath), 'utf8');
    execSql(tracker, composeArgs, env, rootPw, `USE \`${MASTER_DB_NAME}\`;\n${content}`, [], 'seed_master_migrations');
  }
  markOk('seed_master_migrations', MASTER_MIGRATIONS);

  const appDbSql = readFileSync(path.join(SEED_DIR, '05-app-db.sql.tmpl'), 'utf8').replaceAll('__APP_DB_NAME__', dbCreds.name);
  execSql(tracker, composeArgs, env, rootPw, appDbSql, [], 'seed_app_db_create');
  const templateContent = readFileSync(path.join(REPO_ROOT, TENANT_TEMPLATE), 'utf8');
  execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${templateContent}`, [], 'seed_app_db_template');
  markOk('seed_app_db_create');
  markOk('seed_app_db_template');

  const planTenantSql = readFileSync(path.join(SEED_DIR, PLAN_AND_TENANT_FILE), 'utf8').replaceAll('__APP_DB_NAME__', dbCreds.name);
  execSql(tracker, composeArgs, env, rootPw, planTenantSql, [], 'seed_plan_and_tenant');
  markOk('seed_plan_and_tenant');

  const fixtureSql = readFileSync(path.join(SEED_DIR, FIXTURE_FILE), 'utf8');
  execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${fixtureSql}`, [], 'seed_fixture');
  markOk('seed_fixture', FIXTURE_FILE);
}

// ---------------------------------------------------------------------------
// apps/admin build — documented copy of api-parity.mjs's buildAdmin()/
// prepareStandaloneStatic()/startNextServer().
// ---------------------------------------------------------------------------

function buildAdmin() {
  console.error('[rollback-drill] pnpm --filter admin run build ...');
  const result = run('pnpm', ['--filter', 'admin', 'run', 'build'], { stdio: 'inherit' });
  if (result.status !== 0) {
    fail('build_admin', `pnpm --filter admin run build exited ${result.status}`);
  }
  const serverEntry = path.join(ADMIN_STANDALONE_DIR, 'server.js');
  if (!existsSync(serverEntry)) {
    fail('build_admin', `next build did not produce ${serverEntry} — is next.config.ts's output:'standalone' still set?`);
  }
  markOk('build_admin');
}

function prepareStandaloneStatic() {
  const src = path.join(ADMIN_DIR, '.next/static');
  const dest = path.join(ADMIN_STANDALONE_DIR, '.next/static');
  cpSync(src, dest, { recursive: true, force: true });
  markOk('prepare_standalone_static');
}

function startNextServer(env) {
  console.error('[rollback-drill] starting apps/admin standalone server ...');
  const child = spawn('node', ['server.js'], {
    cwd: ADMIN_STANDALONE_DIR,
    env,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (d) => {
    stdout += d.toString();
  });
  child.stderr.on('data', (d) => {
    stderr += d.toString();
  });
  child.getLogs = () => ({ stdout: stdout.slice(-4000), stderr: stderr.slice(-4000) });
  markOk('start_next_server', { pid: child.pid });
  return child;
}

// ---------------------------------------------------------------------------
// Drill runner — spawns rollback-drill-client.mjs as a REAL child process
// (its own env, its own module cache) so config.ts's memoised
// `_lastOverridesRaw` cache and this orchestrator's own process env can
// never leak into each other; the child is the sole source of truth for
// what NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES was set to at each step.
// ---------------------------------------------------------------------------

function runDrillClient(env) {
  const result = spawnSync('node', ['--experimental-strip-types', CLIENT_SCRIPT], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    env,
  });
  if (result.status !== 0) {
    fail('run_drill_client', `rollback-drill-client.mjs exited ${result.status}`, {
      stdout: (result.stdout || '').slice(-4000),
      stderr: (result.stderr || '').slice(-4000),
    });
  }
  const lastLine = result.stdout.trim().split('\n').filter(Boolean).pop();
  let parsed;
  try {
    parsed = JSON.parse(lastLine);
  } catch (err) {
    fail('run_drill_client', `could not parse drill client JSON output: ${err.message}`, {
      stdout: (result.stdout || '').slice(-4000),
      stderr: (result.stderr || '').slice(-4000),
    });
  }
  markOk('run_drill_client');
  return parsed.steps;
}

function stableStringify(value) {
  return JSON.stringify(value, Object.keys(value ?? {}).sort());
}

/** Verifies the three drill steps prove an actual working flip-and-revert. Returns {ok, checks}. */
function verifyDrill(drillSteps) {
  const checks = [];
  const [baseline, flipped, reverted] = drillSteps;

  const record = (name, ok, detail) => checks.push({ name, ok, detail });

  // Step 1 — baseline resolved to the real PHP container, and got a real,
  // successful, non-empty profile for the seeded identity.
  record('baseline_hits_php_origin', baseline.url.startsWith(PHP_BASE_URL), { url: baseline.url });
  record('baseline_status_200', baseline.status === 200, { status: baseline.status });
  record('baseline_success_true', baseline.body?.success === true, { body: baseline.body });
  record('baseline_profile_present', !!baseline.body?.profile, { profile: baseline.body?.profile });

  // Step 2 — the FLIP. Must resolve to the real Next origin/path, not PHP,
  // and must return the SAME profile data (functional parity across the
  // flip, not just "some 200").
  record('flip_hits_next_origin', flipped.url.startsWith(NEXT_BASE_URL), { url: flipped.url });
  record('flip_hits_miniapp_path', flipped.url.includes('/api/miniapp/health-profile'), { url: flipped.url });
  record('flip_status_200', flipped.status === 200, { status: flipped.status });
  record('flip_success_true', flipped.body?.success === true, { body: flipped.body });
  record('flip_cors_header_is_next_shape', flipped.headers['access-control-allow-methods'] === 'GET, POST, OPTIONS', {
    header: flipped.headers['access-control-allow-methods'],
  });
  record('flip_body_matches_baseline', stableStringify(flipped.body) === stableStringify(baseline.body), {
    baseline: baseline.body,
    flipped: flipped.body,
  });

  // Step 3 — the REVERT. Must resolve back to PHP and be byte-identical to
  // the baseline call (proves revert restores the EXACT original behaviour).
  record('revert_hits_php_origin', reverted.url.startsWith(PHP_BASE_URL), { url: reverted.url });
  record('revert_url_matches_baseline_url', reverted.url === baseline.url, { baseline: baseline.url, reverted: reverted.url });
  record('revert_status_200', reverted.status === 200, { status: reverted.status });
  record('revert_body_matches_baseline', stableStringify(reverted.body) === stableStringify(baseline.body), {
    baseline: baseline.body,
    reverted: reverted.body,
  });
  record('revert_cors_header_is_php_shape', reverted.headers['access-control-allow-methods'] === 'GET, POST, PUT, DELETE, OPTIONS', {
    header: reverted.headers['access-control-allow-methods'],
  });

  return { ok: checks.every((c) => c.ok), checks };
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  const dbCreds = parseLocalConfigPhp(tracker);
  const secrets = generateSecrets();

  const composeEnv = {
    ...process.env,
    E2E_MARIADB_ROOT_PASSWORD: secrets.mariadbRootPassword,
    E2E_APP_DB_NAME: dbCreds.name,
    E2E_APP_DB_USER: dbCreds.user,
    E2E_APP_DB_PASSWORD: dbCreds.pass,
    E2E_SESSION_BRIDGE_HMAC_SECRET: secrets.sessionBridgeHmacSecret, // unused here, php service requires it (${...:?}).
  };

  let result = 'FAIL';
  let nextProc = null;
  let drillSteps = null;
  let verification = null;

  try {
    composeUp(tracker, composeArgs, composeEnv, 'compose_up');
    await waitContainerHealthy(tracker, 'e2e-mariadb', 'mariadb_healthy');
    await waitContainerHealthy(tracker, 'e2e-redis', 'redis_healthy');

    seedDatabase(composeEnv, secrets, dbCreds);

    await waitHttpReachable(tracker, `${PHP_BASE_URL}/`, 'php_reachable');

    buildAdmin();
    prepareStandaloneStatic();

    const nextEnv = {
      ...process.env,
      NODE_ENV: 'production',
      PORT: String(NEXT_PORT),
      HOSTNAME: '127.0.0.1',
      DB_HOST: MARIADB_HOST,
      DB_USER: dbCreds.user,
      DB_PASS: dbCreds.pass,
      REDIS_URL: REDIS_HOST_URL,
      NEXT_TELEMETRY_DISABLED: '1',
    };
    nextProc = startNextServer(nextEnv);
    await waitHttpReachable(tracker, `${NEXT_BASE_URL}/api/health`, 'next_reachable');

    const clientEnv = {
      ...process.env,
      PHP_BASE_URL,
      NEXT_BASE_URL,
      DRILL_LINE_USER_ID,
      DRILL_LINE_ACCOUNT_ID: String(DRILL_LINE_ACCOUNT_ID),
    };
    drillSteps = runDrillClient(clientEnv);
    verification = verifyDrill(drillSteps);

    result = verification.ok ? 'PASS' : 'FAIL';
    if (result === 'PASS') {
      markOk('rollback_drill_verified', verification.checks.map((c) => c.name));
    } else {
      fail(
        'rollback_drill_verified',
        `${verification.checks.filter((c) => !c.ok).length} of ${verification.checks.length} check(s) failed`,
        verification.checks.filter((c) => !c.ok)
      );
    }
  } catch (err) {
    result = 'FAIL';
    if (!(err instanceof HarnessError)) {
      tracker.setFailedAt('unexpected_error');
      steps.unexpected_error = { ok: false, message: String(err && err.stack ? err.stack : err) };
    }
  } finally {
    if (nextProc) {
      try {
        const logs = nextProc.getLogs ? nextProc.getLogs() : null;
        if (result !== 'PASS' && logs) {
          console.error('[rollback-drill] next server logs (tail):', logs.stdout, logs.stderr);
        }
        nextProc.kill('SIGTERM');
      } catch {
        // best-effort — teardown must never throw past this point.
      }
    }
    composeDown(composeArgs, composeEnv);
  }

  const output = {
    result,
    endpointDrilled: 'GET /api/health-profile.php -> /api/miniapp/health-profile',
    steps: drillSteps,
    checks: verification ? verification.checks : null,
    harnessSteps: steps,
    failedAt: tracker.getFailedAt(),
  };
  console.log(JSON.stringify(output));
  process.exitCode = result === 'PASS' ? 0 : 1;
}

main();
