#!/usr/bin/env node
// infra/e2e/run.mjs
//
// Self-contained E2E harness runner (Phase 1 batch 3). Proves — on the REAL
// stack, not mocks — that @reya/auth's login() creates a session AND
// bridges it into a real PHP $_SESSION via internal/session-bridge.php,
// such that a browser presenting the resulting PHPSESSID cookie loads a
// real auth_check-gated PHP admin page without bouncing to
// /auth/login.php, and that logout() reverses this.
//
// SCOPE (read before trusting the PASS line): this proves the bridge
// MECHANISM end-to-end on ONE representative page + ONE representative
// tenant DB. It does NOT attempt the full plan Phase-1-acceptance line (5
// heavy PHP pages, Google OAuth/SSO, platform-login+switch-tenant audit
// rows) — that is a broader, separate verification pass. The JSON line
// this script prints says "bridge mechanics: PASS", never "Phase 1: PASS".
//
// Single command to run this harness:
//   node infra/e2e/run.mjs
//
// See docs/runbooks/phase0-cutover-rollback.md's "E2E bridge harness
// (Phase 1)" section for the full write-up (what this proves, the
// internal/.htaccess CIDR caveat, how to read the JSON output) and
// infra/e2e/probe-page.md for why system-status.php is the probe page.
//
// Always tears down (docker compose down -v) in a finally block, even on a
// thrown error — `docker ps` shows zero leftover harness containers after
// either a PASS or a FAIL run.

import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '../..');
const COMPOSE_FILE = path.join(__dirname, 'docker-compose.yml');
const SEED_DIR = path.join(__dirname, 'seed');
const PROJECT = 'reya-e2e-bridge';

// Host-side addresses — the Node runner in this script runs on the HOST
// (not inside the compose network), same as a developer's shell or
// mig-verify's CI runner. See docker-compose.yml's port comments.
const MARIADB_HOST = '127.0.0.1';
const MARIADB_PORT = 3306; // NOT configurable — see the "port gotcha" doc comment in docker-compose.yml
const REDIS_HOST_URL = 'redis://127.0.0.1:16379';
const PHP_BASE_URL = 'http://127.0.0.1:18092';
const BRIDGE_URL = `${PHP_BASE_URL}/internal/session-bridge.php`;
const PROBE_PATH = '/system-status.php'; // see infra/e2e/probe-page.md

const MASTER_DB_NAME = 'zrismpsz_reya_platform'; // TenantContext::PLATFORM_DB_NAME — fixed, not a secret
const TENANT_SLUG = 'e2e-bridge-harness';
const ADMIN_USERNAME = 'e2e_bridge_admin';

const MASTER_MIGRATIONS = [
  'database/migration_2026-05-25_platform_master.sql',
  'database/migration_2026-05-27_master_products.sql',
  'database/migration_2026-05-27_tenant_line_account_routes.sql',
  'database/migration_2026-06-04_platform_billing.sql',
  'database/migration_2026-06-04_platform_billing_details.sql',
  'packages/db/migrations/master/migration_2026-07-12_node_sessions.sql',
];
const TENANT_TEMPLATE = 'database/migration_2026-05-25_tenant_template.sql';

// ---------------------------------------------------------------------------
// Small result-tracking helpers
// ---------------------------------------------------------------------------

const steps = {};
let failedAt = null;

class HarnessError extends Error {
  constructor(step, message, extra) {
    super(message);
    this.step = step;
    this.extra = extra;
  }
}

function markOk(step, extra) {
  steps[step] = { ok: true, ...(extra ? { detail: extra } : {}) };
}

function fail(step, message, extra) {
  steps[step] = { ok: false, message, ...(extra ? { detail: extra } : {}) };
  if (failedAt === null) failedAt = step;
  throw new HarnessError(step, message, extra);
}

// ---------------------------------------------------------------------------
// Process helpers
// ---------------------------------------------------------------------------

function run(cmd, args, opts = {}) {
  const result = spawnSync(cmd, args, { cwd: REPO_ROOT, encoding: 'utf8', ...opts });
  return result;
}

function runOrThrow(step, cmd, args, opts = {}) {
  const result = run(cmd, args, opts);
  if (result.error) {
    fail(step, `${cmd} failed to spawn: ${result.error.message}`, { cmd, args });
  }
  if (result.status !== 0) {
    fail(step, `${cmd} exited ${result.status}`, {
      cmd,
      args,
      stdout: (result.stdout || '').slice(-4000),
      stderr: (result.stderr || '').slice(-4000),
    });
  }
  return result;
}

function composeArgs(...rest) {
  return ['compose', '-p', PROJECT, '-f', COMPOSE_FILE, ...rest];
}

// ---------------------------------------------------------------------------
// Step 0 — dist/ prerequisite
// ---------------------------------------------------------------------------

function ensureAuthDistBuilt() {
  const authDistIndex = path.join(REPO_ROOT, 'packages/auth/dist/index.js');
  if (existsSync(authDistIndex)) {
    markOk('dist_prerequisite', 'packages/auth/dist already present');
    return;
  }
  console.error('[e2e] packages/auth/dist missing — running `pnpm --filter @reya/auth run build` first...');
  const result = run('pnpm', ['--filter', '@reya/auth', 'run', 'build'], { stdio: 'inherit' });
  if (result.status !== 0 || !existsSync(authDistIndex)) {
    fail(
      'dist_prerequisite',
      'pnpm --filter @reya/auth run build did not produce packages/auth/dist/index.js — ' +
        'run it manually and re-run this harness.'
    );
  }
  markOk('dist_prerequisite', 'built by this run');
}

// ---------------------------------------------------------------------------
// config/config.php parsing — DB_NAME/DB_USER/DB_PASS. NEVER write these
// values into any tracked file (this repo's secrets-discipline guardrail) —
// they only ever live in this process's memory/env, sourced fresh from the
// local (gitignored) config/config.php on every run.
// ---------------------------------------------------------------------------

function parseLocalConfigPhp() {
  const configPath = path.join(REPO_ROOT, 'config/config.php');
  if (!existsSync(configPath)) {
    fail(
      'parse_config_php',
      'config/config.php not found. This harness requires the local (gitignored) ' +
        'config/config.php to exist in this checkout — see CLAUDE.md: "The local ' +
        'config/config.php exists in this checkout (gitignored)".'
    );
  }
  const src = readFileSync(configPath, 'utf8');
  const extract = (name) => {
    const m = src.match(new RegExp(`define\\(\\s*'${name}'\\s*,\\s*'([^']*)'\\s*\\)`));
    return m ? m[1] : null;
  };
  const name = extract('DB_NAME');
  const user = extract('DB_USER');
  const pass = extract('DB_PASS');
  if (!name || !user || pass === null) {
    fail('parse_config_php', 'Could not parse DB_NAME/DB_USER/DB_PASS out of config/config.php', {
      found: { name, user, passPresent: pass !== null },
    });
  }
  markOk('parse_config_php', { name, user });
  return { name, user, pass };
}

// ---------------------------------------------------------------------------
// Secrets — generated fresh every run, never written to disk.
// ---------------------------------------------------------------------------

function generateSecrets() {
  return {
    mariadbRootPassword: randomBytes(24).toString('base64url'),
    sessionBridgeHmacSecret: randomBytes(32).toString('hex'),
    adminPassword: randomBytes(18).toString('base64url'),
  };
}

// ---------------------------------------------------------------------------
// docker compose lifecycle
// ---------------------------------------------------------------------------

function composeUp(env) {
  console.error('[e2e] docker compose up -d --build ...');
  runOrThrow('compose_up', 'docker', composeArgs('up', '-d', '--build'), { env });
  markOk('compose_up');
}

function composeDown(env) {
  console.error('[e2e] docker compose down -v ...');
  // Best-effort — teardown must never throw past this point (finally block).
  run('docker', composeArgs('down', '-v', '--remove-orphans'), { env, stdio: 'inherit' });
}

async function waitContainerHealthy(step, containerName, timeoutMs = 90_000) {
  const started = Date.now();
  for (;;) {
    const result = run('docker', ['inspect', '--format', '{{.State.Health.Status}}', containerName]);
    const status = (result.stdout || '').trim();
    if (status === 'healthy') {
      markOk(step);
      return;
    }
    if (Date.now() - started > timeoutMs) {
      fail(step, `${containerName} did not become healthy within ${timeoutMs}ms`, {
        lastStatus: status || result.stderr?.trim(),
      });
    }
    await sleep(1500);
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// ---------------------------------------------------------------------------
// SQL seeding — always via `docker compose exec -T mariadb mysql -uroot`
// (root credentials, harness-internal only) so grants/DDL never depend on
// the app user's privileges existing yet.
// ---------------------------------------------------------------------------

function execSql(step, env, rootPassword, sqlText, extraArgs = []) {
  const result = spawnSync(
    'docker',
    composeArgs('exec', '-T', '-e', `MYSQL_PWD=${rootPassword}`, 'mariadb', 'mysql', '-uroot', ...extraArgs),
    { cwd: REPO_ROOT, encoding: 'utf8', input: sqlText, env }
  );
  if (result.status !== 0) {
    fail(step, `mysql exec failed (exit ${result.status})`, {
      stderr: (result.stderr || '').slice(-4000),
    });
  }
  return result;
}

function querySql(step, env, rootPassword, sqlText, dbName) {
  const args = dbName ? ['-N', '-B', dbName] : ['-N', '-B'];
  const result = execSql(step, env, rootPassword, sqlText, args);
  return result.stdout.trim();
}

function seedDatabase(env, secrets, dbCreds) {
  const rootPw = secrets.mariadbRootPassword;

  // 1) master DB shell + grant the app user access to it (MARIADB_DATABASE/
  //    USER/PASSWORD env vars on the mariadb service already created the app
  //    db + granted the app user access to THAT db on first init — this
  //    covers the SECOND db, zrismpsz_reya_platform, the same user also
  //    needs).
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

  // 2) the six committed master migrations, in order, each prefixed with a
  //    USE statement (docker compose exec piping means there's no
  //    MYSQL_DATABASE-default trick to lean on for these — see
  //    infra/e2e/seed/00-master-db.sql's header comment).
  for (const relPath of MASTER_MIGRATIONS) {
    const content = readFileSync(path.join(REPO_ROOT, relPath), 'utf8');
    const withUse = `USE \`${MASTER_DB_NAME}\`;\n${content}`;
    execSql('seed_master_migrations', env, rootPw, withUse);
  }
  markOk('seed_master_migrations', MASTER_MIGRATIONS);

  // 3) app/tenant DB shell (MARIADB_DATABASE env var on the mariadb service
  //    already created this on first init, via the bootstrap env vars — this
  //    CREATE DATABASE IF NOT EXISTS is a defensive no-op restating intent,
  //    not the actual first creation) + the ~280-table tenant template.
  const appDbSql = readFileSync(path.join(SEED_DIR, '05-app-db.sql.tmpl'), 'utf8').replaceAll(
    '__APP_DB_NAME__',
    dbCreds.name
  );
  execSql('seed_app_db_create', env, rootPw, appDbSql);
  const templateContent = readFileSync(path.join(REPO_ROOT, TENANT_TEMPLATE), 'utf8');
  execSql('seed_app_db_template', env, rootPw, `USE \`${dbCreds.name}\`;\n${templateContent}`);
  markOk('seed_app_db_create');
  markOk('seed_app_db_template');

  // 4) one plans row + one tenants row (master DB).
  const planTenantSql = readFileSync(path.join(SEED_DIR, '10-plan-and-tenant.sql.tmpl'), 'utf8').replaceAll(
    '__APP_DB_NAME__',
    dbCreds.name
  );
  execSql('seed_plan_and_tenant', env, rootPw, planTenantSql);
  markOk('seed_plan_and_tenant');

  const tenantId = querySql(
    'seed_lookup_tenant_id',
    env,
    rootPw,
    `SELECT id FROM tenants WHERE slug = '${TENANT_SLUG}' LIMIT 1;`,
    MASTER_DB_NAME
  );
  if (!tenantId || Number.isNaN(Number(tenantId))) {
    fail('seed_lookup_tenant_id', 'Could not read back the seeded tenants.id', { raw: tenantId });
  }
  markOk('seed_lookup_tenant_id', tenantId);
  return Number(tenantId);
}

function seedAdminUser(env, secrets, dbCreds, passwordHash) {
  const rootPw = secrets.mariadbRootPassword;
  const escapedHash = passwordHash.replaceAll("'", "''");
  const sql = readFileSync(path.join(SEED_DIR, '20-admin-user.sql.tmpl'), 'utf8')
    .replaceAll('__ADMIN_USERNAME__', ADMIN_USERNAME)
    .replaceAll('__ADMIN_PASSWORD_HASH__', escapedHash);
  execSql('seed_admin_user', env, rootPw, `USE \`${dbCreds.name}\`;\n${sql}`);
  markOk('seed_admin_user');
}

// ---------------------------------------------------------------------------
// PHP-side helpers
// ---------------------------------------------------------------------------

/** Generates a REAL PHP bcrypt hash by invoking password_hash() inside the
 * harness's own php container (php:8.2-apache — the production runtime),
 * never bcryptjs and never hand-written. This is what genuinely exercises
 * passwords.ts's verifyLegacyPassword() cross-runtime claim. */
function generatePhpBcryptHash(env, plainPassword) {
  const result = spawnSync(
    'docker',
    composeArgs(
      'exec',
      '-T',
      'php',
      'php',
      '-r',
      'echo password_hash($argv[1], PASSWORD_DEFAULT);',
      '--',
      plainPassword
    ),
    { cwd: REPO_ROOT, encoding: 'utf8', env }
  );
  if (result.status !== 0 || !result.stdout || !result.stdout.startsWith('$2y$')) {
    fail('generate_php_hash', 'php -r password_hash(...) did not return a $2y$ bcrypt hash', {
      status: result.status,
      stdout: result.stdout,
      stderr: (result.stderr || '').slice(-2000),
    });
  }
  markOk('generate_php_hash');
  return result.stdout;
}

async function waitPhpReachable(timeoutMs = 120_000) {
  const started = Date.now();
  for (;;) {
    try {
      const resp = await fetch(`${PHP_BASE_URL}/`, { redirect: 'manual' });
      // ANY response (including a 500 from a not-yet-seeded DB) means Apache
      // is up and serving — see docker-compose.yml's php healthcheck
      // comment for why we don't gate on Docker's own health verdict here.
      markOk('php_reachable', resp.status);
      return;
    } catch {
      if (Date.now() - started > timeoutMs) {
        fail('php_reachable', `php service did not answer HTTP within ${timeoutMs}ms`);
      }
      await sleep(1000);
    }
  }
}

/** Fires the sequencing-trap throwaway request — see
 * infra/e2e/seed/20-admin-user.sql.tmpl's header comment. Unauthenticated,
 * so it also doubles as (redundant, intentionally re-verified later in
 * step 3) evidence that the probe page gates on auth at all. */
async function fireThrowawayProbeRequest() {
  const resp = await fetch(`${PHP_BASE_URL}${PROBE_PATH}`, { redirect: 'manual' });
  markOk('throwaway_probe_request', resp.status);
  return resp;
}

async function fetchProbe(cookieValue) {
  const headers = {};
  if (cookieValue !== null) {
    headers.Cookie = `PHPSESSID=${cookieValue}`;
  }
  return fetch(`${PHP_BASE_URL}${PROBE_PATH}`, { headers, redirect: 'manual' });
}

function isLoginRedirect(resp) {
  if (resp.status < 300 || resp.status >= 400) {
    return false;
  }
  const location = resp.headers.get('location') || '';
  return location.includes('auth/login.php');
}

/** Diagnostic-only direct HMAC-signed POST to the bridge (action
 * 'introspect', read-only) — used to print the bridge's actual HTTP
 * status/body when login()'s bridgeSynced comes back false, per this
 * batch's brief ("print the bridge's actual HTTP status/body on failure,
 * not just a bare boolean"). */
async function diagnoseBridge(hmacSecret, sid) {
  const { createHmac } = await import('node:crypto');
  const payload = JSON.stringify({
    action: 'introspect',
    sid: sid || 'a'.repeat(64),
    phpSessionKeys: {},
    issuedAt: Math.floor(Date.now() / 1000),
  });
  const signature = createHmac('sha256', hmacSecret).update(payload).digest('hex');
  try {
    const resp = await fetch(BRIDGE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Reya-Signature': signature },
      body: payload,
    });
    const text = await resp.text();
    return { status: resp.status, body: text.slice(0, 2000) };
  } catch (err) {
    return { status: null, body: String(err) };
  }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  ensureAuthDistBuilt();
  const dbCreds = parseLocalConfigPhp();
  const secrets = generateSecrets();

  const composeEnv = {
    ...process.env,
    E2E_MARIADB_ROOT_PASSWORD: secrets.mariadbRootPassword,
    E2E_APP_DB_NAME: dbCreds.name,
    E2E_APP_DB_USER: dbCreds.user,
    E2E_APP_DB_PASSWORD: dbCreds.pass,
    E2E_SESSION_BRIDGE_HMAC_SECRET: secrets.sessionBridgeHmacSecret,
  };

  let result = 'FAIL';

  try {
    composeUp(composeEnv);
    await waitContainerHealthy('mariadb_healthy', 'e2e-mariadb');
    await waitContainerHealthy('redis_healthy', 'e2e-redis');

    const tenantId = seedDatabase(composeEnv, secrets, dbCreds);

    await waitPhpReachable();
    await fireThrowawayProbeRequest(); // creates admin_users/admin_bot_access/admin_activity_log

    const passwordHash = generatePhpBcryptHash(composeEnv, secrets.adminPassword);
    seedAdminUser(composeEnv, secrets, dbCreds, passwordHash);

    // ---- Node-side env, set BEFORE the first login() call (packages/config's
    // loadEnv() is lazy/cached-per-process — "before the first login() call"
    // is sufficient, per packages/auth/README.md). ----
    process.env.NODE_ENV = 'test';
    process.env.DB_HOST = MARIADB_HOST;
    process.env.DB_USER = dbCreds.user;
    process.env.DB_PASS = dbCreds.pass;
    process.env.REDIS_URL = REDIS_HOST_URL;
    process.env.SESSION_BRIDGE_URL = BRIDGE_URL;
    process.env.SESSION_BRIDGE_HMAC_SECRET = secrets.sessionBridgeHmacSecret;
    process.env.NODE_SESSION_TTL_SECONDS = '600';

    const authDistUrl = pathToFileURL(path.join(REPO_ROOT, 'packages/auth/dist/index.js')).href;
    const dbDistUrl = pathToFileURL(path.join(REPO_ROOT, 'packages/db/dist/index.js')).href;
    const { login, logout, runWithTenantDb } = await import(authDistUrl);
    const { getTenantDb } = await import(dbDistUrl);
    markOk('import_dist_modules');

    // ---- Step 2: login() for the seeded admin, wrapped per
    // packages/auth/README.md's required integration note. ----
    const tenantDb = await getTenantDb(tenantId);
    const loginResult = await runWithTenantDb({ tenantId, db: tenantDb }, () =>
      login({ realm: 'tenant', username: ADMIN_USERNAME, password: secrets.adminPassword })
    );

    if (!loginResult.ok) {
      fail('login', `login() returned ok:false — ${JSON.stringify(loginResult.error)}`, loginResult.error);
    }
    markOk('login', { adminUserId: loginResult.value.session.adminUserId });

    if (loginResult.value.bridgeSynced !== true) {
      const diag = await diagnoseBridge(secrets.sessionBridgeHmacSecret, loginResult.value.cookie.value);
      fail('bridge_synced', 'login() succeeded but bridgeSynced !== true', diag);
    }
    markOk('bridge_synced');

    const sid = loginResult.value.cookie.value;

    // ---- Step 3a: authed fetch — must be 200, no Location to auth/login.php. ----
    const authedResp = await fetchProbe(sid);
    if (authedResp.status !== 200 || isLoginRedirect(authedResp)) {
      fail('authed_probe_fetch', `expected 200 with no auth/login.php redirect, got ${authedResp.status}`, {
        status: authedResp.status,
        location: authedResp.headers.get('location'),
      });
    }
    markOk('authed_probe_fetch', authedResp.status);

    // ---- Step 3b: unauthed control — garbage PHPSESSID must redirect to auth/login.php. ----
    const garbageSid = randomBytes(32).toString('hex');
    const unauthedResp = await fetchProbe(garbageSid);
    if (!isLoginRedirect(unauthedResp)) {
      fail('unauthed_probe_control', `expected a 3xx redirect to auth/login.php, got ${unauthedResp.status}`, {
        status: unauthedResp.status,
        location: unauthedResp.headers.get('location'),
      });
    }
    markOk('unauthed_probe_control', unauthedResp.status);

    // ---- Step 4: logout() reverses the bridge. ----
    const logoutResult = await logout(sid, 'tenant');
    if (!logoutResult.ok) {
      fail('logout', `logout() returned ok:false — ${JSON.stringify(logoutResult)}`);
    }
    markOk('logout', { bridgeSynced: logoutResult.value.bridgeSynced });

    const postLogoutResp = await fetchProbe(sid);
    if (!isLoginRedirect(postLogoutResp)) {
      fail(
        'post_logout_probe_fetch',
        `expected the SAME PHPSESSID to now redirect to auth/login.php like the logged-out control, got ${postLogoutResp.status}`,
        { status: postLogoutResp.status, location: postLogoutResp.headers.get('location') }
      );
    }
    markOk('post_logout_probe_fetch', postLogoutResp.status);

    result = 'PASS';
  } catch (err) {
    result = 'FAIL';
    if (!(err instanceof HarnessError)) {
      // Unexpected/thrown error outside the fail() helper's own bookkeeping.
      if (failedAt === null) failedAt = 'unexpected_error';
      steps.unexpected_error = { ok: false, message: String(err && err.stack ? err.stack : err) };
    }
  } finally {
    composeDown(composeEnv);
  }

  const output = { result, steps, failedAt };
  console.log(JSON.stringify(output));
  process.exitCode = result === 'PASS' ? 0 : 1;
}

main();
