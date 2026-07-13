#!/usr/bin/env node
// infra/e2e/api-parity.mjs
//
// Phase 3 batch 1 (mig-infra) — JSON API parity harness for the six ported
// /api/miniapp/** endpoints (16 endpoint x action pairs total, split across
// mig-api-reads' and mig-api-writes' concurrent briefs): resolve-line-account,
// points-history:history, shop-products:{products,product_detail,categories},
// health-profile:get, member:{check,get_card,register,update_profile},
// rewards:{list,redeem,my_redemptions}, wishlist:{list,toggle,remove}.
//
// SCOPE (read before trusting the PASS line — same documented-limits pattern
// infra/e2e/parity.mjs already uses): this proves JSON-RESPONSE-AND-DB-ROW
// PARITY against ONE seeded tenant/dataset, on the REAL stack (a genuine
// MariaDB + Redis + php:8.2-apache container set, a genuine `next build` +
// standalone-server apps/admin instance) — it is NOT a live-traffic shadow
// test, does NOT prove parity across every tenant's real production data, and
// does NOT exercise every action/branch either PHP source file has (only the
// 16 actions the phase-3-batch-1 briefs actually port — see the brief's own
// "do not port" list for the explicitly out-of-scope actions on each file).
//
// IDENTITY MODEL UNDER TEST: every /api/miniapp/** request below carries NO
// tenant-pinning Host header (unlike parity.mjs's page-pair harness, which
// deliberately uses a `tenant-XXXX.re-ya.com`-shaped Host to exercise
// subdomain routing) — Host defaults to the harness's own 127.0.0.1:PORT
// address, which apps/admin's proxy.ts (@reya/tenant's resolveTenant()) and
// PHP's bootstrap/resolve_subdomain.php BOTH resolve to "no tenant" (neither
// a `*.re-ya.com` subdomain nor the bare root domain) — see
// packages/tenant/src/resolveTenant.ts's own doc table. Every request
// therefore falls through to the phase (b) `line_account_id`-based
// resolution (bootstrap/route_by_account.php / @reya/tenant's
// routeByLineAccount()), which is the SAME resolution path real line-mini-app
// traffic uses on the root domain (contractNote point 2) — this is the more
// representative choice for THIS surface, not a simplification.
//
// IDENTITY STRATEGY FOR WRITE ACTIONS: see
// infra/e2e/seed/50-phase3-batch1-miniapp-fixture.sql.tmpl's own header
// comment ("IDENTITY STRATEGY FOR WRITE ACTIONS") — two separately-seeded
// fixture identities per write action (suffixed `-php` / `-next` in
// infra/e2e/lib/api-extract.mjs's FIXTURE constants), never a
// reset-between-runs step. This lets the PHP-exercised call and the
// Next-exercised call of the SAME case run in either order with no teardown
// between them, and keeps "query the resulting DB row for each identity,
// diff them" (this script's dbCheck runner, below) a meaningful comparison.
//
// TWO-POINTS-TABLES GOTCHA (contractNote point 8, preserved on purpose, not
// fixed): member.php's/member:check's welcome-bonus write goes to
// `points_history`; points-history.php's/points-history:history's read comes
// from `points_transactions` — a DIFFERENT table. member:check's dbChecks
// below assert the welcome bonus DOES land in `points_history` (proving the
// port preserved the quirk) — points-history:history's OWN fixture (richMember,
// id=3001) is deliberately a DIFFERENT, pre-seeded identity with its balance
// established via `points_transactions` rows directly, so the two never
// interact within this harness. See docs/runbooks/phase3-batch1-miniapp-api-parity.md
// for the full write-up.
//
// Reuses infra/e2e/docker-compose.yml (mariadb+redis+php, UNMODIFIED — same
// container set infra/e2e/run.mjs and infra/e2e/parity.mjs already use) and
// infra/e2e/lib/harness-common.mjs (compose lifecycle, secrets, SQL exec,
// httpRequest). Because that compose file's container names/ports are FIXED
// (not templated per-project — see docker-compose.yml's own comments), this
// harness CANNOT run concurrently with run.mjs or parity.mjs, same
// pre-existing constraint those two already have with each other. Sequential
// use only.
//
// buildAdmin()/prepareStandaloneStatic()/startNextServer() below are a
// DOCUMENTED COPY of infra/e2e/parity.mjs's own functions of the same name
// (verbatim pattern, per this batch's brief) — NOT an import, because
// parity.mjs is a top-level script (calls its own main() unconditionally at
// module load) with no exports, and this batch's allowed-paths boundary
// forbids editing parity.mjs to add any. If parity.mjs's build steps ever
// change, this copy needs a matching manual update — same tradeoff
// infra/e2e/lib/harness-common.mjs's own module doc already accepts for
// "either extract to the shared lib, or a documented copy."
//
// This is a NEW, UNAUTHENTICATED-SURFACE harness — /api/miniapp/** carries
// no admin session (contractNote's whole "trust-on-input identity model"
// point), so unlike parity.mjs there is no phpLogin()/nextLogin() step here
// at all; only the two DB stacks + the Next server need to be up.
//
// Prints one machine-readable line: {result, endpoints, steps, failedAt}
// (mirrors parity.mjs's {result, pages, steps, failedAt} convention, renamed
// per this batch's brief). ALWAYS tears down (docker compose down -v + kill
// the Next child) in a finally block. Exit code 0 only on a full PASS.
//
// Single command to run this harness:
//   node infra/e2e/api-parity.mjs

import { spawn } from 'node:child_process';
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
  querySql,
  parseLocalConfigPhp,
  generateSecrets,
  waitHttpReachable,
  httpRequest,
} from './lib/harness-common.mjs';
import { FIXTURE, FORMAT_CHECKS, ENDPOINT_CASES } from './lib/api-extract.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const COMPOSE_FILE = path.join(__dirname, 'docker-compose.yml');
const SEED_DIR = path.join(__dirname, 'seed');
const PROJECT = 'reya-e2e-api-parity';

// ---------------------------------------------------------------------------
// Host-side addresses — same "port gotcha" reasoning as parity.mjs's own
// module doc (this harness's Node process AND the Next standalone server it
// spawns both run on the HOST, not inside the compose network). NEXT_PORT
// deliberately differs from parity.mjs's 3210 (harmless either way, since
// the two can never run concurrently — see module doc above — but keeps
// `docker ps`/`lsof` output unambiguous about which harness's Next process
// is which if one is left running after a Ctrl-C).
// ---------------------------------------------------------------------------
const MARIADB_HOST = '127.0.0.1';
const REDIS_HOST_URL = 'redis://127.0.0.1:16379';
const PHP_BASE_URL = 'http://127.0.0.1:18092';
const NEXT_PORT = 3220;
const NEXT_BASE_URL = `http://127.0.0.1:${NEXT_PORT}`;

const MASTER_DB_NAME = 'zrismpsz_reya_platform';
const TENANT_SLUG = 'e2e-api-parity-harness';

const MASTER_MIGRATIONS = [
  'database/migration_2026-05-25_platform_master.sql',
  'database/migration_2026-05-27_master_products.sql',
  'database/migration_2026-05-27_tenant_line_account_routes.sql',
  'database/migration_2026-06-02_route_liff_id.sql', // resolve-line-account's fast-path `liff_id` column.
  'database/migration_2026-06-04_platform_billing.sql',
  'database/migration_2026-06-04_platform_billing_details.sql',
  'packages/db/migrations/master/migration_2026-07-12_node_sessions.sql',
];
const TENANT_TEMPLATE = 'database/migration_2026-05-25_tenant_template.sql';
const PLAN_AND_TENANT_FILE = '45-phase3-batch1-plan-and-tenant.sql.tmpl'; // master DB — own `USE` statement.
const FIXTURE_FILE = '50-phase3-batch1-miniapp-fixture.sql.tmpl'; // tenant DB — USE-prefixed by seedFixture() below.

const ADMIN_DIR = path.join(REPO_ROOT, 'apps/admin');
const ADMIN_STANDALONE_DIR = path.join(ADMIN_DIR, '.next/standalone/apps/admin');

const tracker = createStepTracker();
const { steps, markOk, fail } = tracker;
const composeArgs = makeComposeArgs(COMPOSE_FILE, PROJECT);

// ---------------------------------------------------------------------------
// Database seeding
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

  const tenantId = querySql(
    tracker,
    composeArgs,
    env,
    rootPw,
    `SELECT id FROM tenants WHERE slug = '${TENANT_SLUG}' LIMIT 1;`,
    MASTER_DB_NAME,
    'seed_lookup_tenant_id'
  );
  if (!tenantId || Number.isNaN(Number(tenantId))) {
    fail('seed_lookup_tenant_id', 'Could not read back the seeded tenants.id', { raw: tenantId });
  }
  markOk('seed_lookup_tenant_id', tenantId);
  return Number(tenantId);
}

// ---------------------------------------------------------------------------
// apps/admin build — DOCUMENTED COPY of parity.mjs's buildAdmin()/
// prepareStandaloneStatic()/startNextServer() — see module doc above for why
// this is a copy, not an import.
// ---------------------------------------------------------------------------

function buildAdmin() {
  console.error('[api-parity] pnpm --filter admin run build ...');
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
  console.error('[api-parity] starting apps/admin standalone server ...');
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
// HTTP helpers
// ---------------------------------------------------------------------------

function toQueryString(params) {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params ?? {})) {
    if (v === undefined || v === null) continue;
    qs.set(k, String(v));
  }
  const s = qs.toString();
  return s ? `?${s}` : '';
}

async function callStack(baseUrl, caseDef, variant) {
  const method = caseDef.method;
  if (method === 'GET') {
    const query = caseDef.query(variant);
    const url = `${baseUrl}${variant === 'php' ? caseDef.phpPath : caseDef.nextPath}${toQueryString(query)}`;
    return httpRequest({ url, method: 'GET' });
  }
  const body = caseDef.body(variant);
  const url = `${baseUrl}${variant === 'php' ? caseDef.phpPath : caseDef.nextPath}`;
  return httpRequest({
    url,
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

/**
 * Strips a leading UTF-8 BOM (U+FEFF) before parsing — matches how a REAL
 * browser/LIFF-webview client actually sees these responses. Verified
 * empirically against this batch's real PHP container: `api/checkout.php`
 * and `api/shop-products.php` both carry a raw UTF-8 BOM before their
 * opening `<?php` tag (pre-existing PHP source hygiene issue, unrelated to
 * this migration — out of scope to fix here, see this batch's build report).
 * `Response.json()` / `TextDecoder`'s default UTF-8 decode strips a leading
 * BOM per the WHATWG spec, so a real mini-app client's `fetch(...).json()`
 * never sees it and parses these responses fine; Node's raw
 * `Buffer#toString('utf8')` (what `httpRequest()` uses) does NOT strip it,
 * so treating a leading BOM as a genuine PHP-vs-Next mismatch would be a
 * FALSE POSITIVE this harness must not report. Applied to BOTH php and next
 * text (harmless no-op on next, which never emits one).
 */
function stripBom(text) {
  return text.charCodeAt(0) === 0xfeff ? text.slice(1) : text;
}

function safeJsonParse(text) {
  try {
    return { ok: true, value: JSON.parse(stripBom(text)) };
  } catch (err) {
    return { ok: false, error: err && err.message ? err.message : String(err) };
  }
}

// ---------------------------------------------------------------------------
// Diff engine — field-NAME allowlist (not full JSON-path), per the brief's
// own `member:check -> ['created_at','registered_at','id']` example. This is
// a DOCUMENTED COPY of parity.mjs's diff()/diffValues() shape (structural
// deepEqual over small JSON-safe values, array-length-then-index recursion),
// EXTENDED with the allowlist parameter parity.mjs's own diff() doesn't need
// — see parity.mjs's diff()/diffValues() for the un-extended original this
// was grown from. Not imported (parity.mjs exports nothing — see module doc).
// ---------------------------------------------------------------------------

function fieldNameOf(pathStr) {
  const last = pathStr.split('.').pop() ?? '';
  return last.replace(/\[\d+\]$/, '');
}

function diffAllowValues(php, next, allowSet, pathStr, out) {
  const name = fieldNameOf(pathStr);
  if (name && allowSet.has(name)) {
    const phpHas = php !== undefined;
    const nextHas = next !== undefined;
    if (phpHas !== nextHas) {
      out.push(`${pathStr}: presence differs (allowlisted field) php=${phpHas} next=${nextHas}`);
    }
    return;
  }
  if (Array.isArray(php) || Array.isArray(next)) {
    if (!Array.isArray(php) || !Array.isArray(next)) {
      out.push(`${pathStr}: php=${JSON.stringify(php)} next=${JSON.stringify(next)}`);
      return;
    }
    if (php.length !== next.length) {
      out.push(`${pathStr}: array length php=${php.length} next=${next.length}`);
      return;
    }
    php.forEach((v, i) => diffAllowValues(v, next[i], allowSet, `${pathStr}[${i}]`, out));
    return;
  }
  if (php !== null && typeof php === 'object' && next !== null && typeof next === 'object') {
    const keys = new Set([...Object.keys(php), ...Object.keys(next)]);
    for (const key of keys) {
      diffAllowValues(php[key], next[key], allowSet, pathStr ? `${pathStr}.${key}` : key, out);
    }
    return;
  }
  if (php !== next) {
    out.push(`${pathStr}: php=${JSON.stringify(php)} next=${JSON.stringify(next)}`);
  }
}

function diffAllow(php, next, allowFields) {
  const out = [];
  diffAllowValues(php, next, new Set(allowFields ?? []), '', out);
  return out;
}

/** Recursively collects every value stored under key `fieldName` anywhere in `obj`. */
function collectFieldValues(obj, fieldName, out = []) {
  if (Array.isArray(obj)) {
    for (const item of obj) collectFieldValues(item, fieldName, out);
    return out;
  }
  if (obj !== null && typeof obj === 'object') {
    for (const [k, v] of Object.entries(obj)) {
      if (k === fieldName) out.push(v);
      else collectFieldValues(v, fieldName, out);
    }
  }
  return out;
}

function runFormatChecks(allowFields, phpJson, nextJson) {
  const out = [];
  for (const field of allowFields ?? []) {
    const regex = FORMAT_CHECKS[field];
    if (!regex) continue;
    for (const [label, json] of [['php', phpJson], ['next', nextJson]]) {
      for (const value of collectFieldValues(json, field)) {
        if (typeof value === 'string' && !regex.test(value)) {
          out.push(`${label} field "${field}" value ${JSON.stringify(value)} does not match expected format ${regex}`);
        }
      }
    }
  }
  return out;
}

// ---------------------------------------------------------------------------
// DB row checks (post-write assertions) — JSON_OBJECT(...) so a single
// querySql() round-trip returns an already-structured value to diff with the
// SAME diffAllow() engine used for the HTTP response bodies.
// ---------------------------------------------------------------------------

function buildJsonRowSql(table, whereSql, columns) {
  const safeTable = table.replace(/[^a-zA-Z0-9_]/g, '');
  if (columns.length === 1 && columns[0] === '__row_count__') {
    return `SELECT JSON_OBJECT('row_count', COUNT(*)) FROM \`${safeTable}\` WHERE ${whereSql};`;
  }
  const pairs = columns.map((c) => `'${c}', \`${c.replace(/[^a-zA-Z0-9_]/g, '')}\``).join(', ');
  return `SELECT JSON_OBJECT(${pairs}) FROM \`${safeTable}\` WHERE ${whereSql} LIMIT 1;`;
}

function queryJsonRow(env, secrets, dbCreds, sql) {
  const raw = querySql(tracker, composeArgs, env, secrets.mariadbRootPassword, sql, dbCreds.name, 'db_check_query');
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return { __unparseable_row__: raw };
  }
}

async function runDbChecks(caseDef, env, secrets, dbCreds) {
  const mismatches = [];
  for (const check of caseDef.dbChecks ?? []) {
    const phpSql = buildJsonRowSql(check.table, check.where('php'), check.columns);
    const nextSql = buildJsonRowSql(check.table, check.where('next'), check.columns);
    const phpRow = queryJsonRow(env, secrets, dbCreds, phpSql);
    const nextRow = queryJsonRow(env, secrets, dbCreds, nextSql);
    const rowMismatches = diffAllow(phpRow, nextRow, check.allow);
    for (const m of rowMismatches) {
      mismatches.push(`dbCheck[${check.label}] ${m}`);
    }
  }
  return mismatches;
}

// ---------------------------------------------------------------------------
// Per-case runner — NEVER throws (mirrors parity.mjs's runPagePair(): any
// failure becomes {ok:false, mismatches:[...]} for THIS entry only, so one
// broken/missing endpoint can never abort the rest of the run or skip
// teardown — same acceptance bar parity.mjs already proves for pages).
// ---------------------------------------------------------------------------

async function runApiCase(caseDef, env, secrets, dbCreds) {
  try {
    const [phpResp, nextResp] = await Promise.all([
      callStack(PHP_BASE_URL, caseDef, 'php'),
      callStack(NEXT_BASE_URL, caseDef, 'next'),
    ]);

    const mismatches = [];

    if (phpResp.status !== nextResp.status) {
      mismatches.push(`http_status: php=${phpResp.status} next=${nextResp.status}`);
    }

    for (const headerName of caseDef.compareHeaders ?? []) {
      const phpHeader = phpResp.headers[headerName];
      const nextHeader = nextResp.headers[headerName];
      if (phpHeader !== nextHeader) {
        mismatches.push(`header[${headerName}]: php=${JSON.stringify(phpHeader)} next=${JSON.stringify(nextHeader)}`);
      }
    }

    const phpParsed = safeJsonParse(phpResp.text);
    const nextParsed = safeJsonParse(nextResp.text);
    if (!phpParsed.ok) mismatches.push(`php response is not valid JSON: ${phpParsed.error} (body: ${phpResp.text.slice(0, 500)})`);
    if (!nextParsed.ok) mismatches.push(`next response is not valid JSON: ${nextParsed.error} (body: ${nextResp.text.slice(0, 500)})`);

    let phpJson = null;
    let nextJson = null;
    if (phpParsed.ok && nextParsed.ok) {
      phpJson = phpParsed.value;
      nextJson = nextParsed.value;
      mismatches.push(...diffAllow(phpJson, nextJson, caseDef.allow));
      mismatches.push(...runFormatChecks(caseDef.allow, phpJson, nextJson));
    }

    if ((caseDef.dbChecks ?? []).length > 0) {
      mismatches.push(...(await runDbChecks(caseDef, env, secrets, dbCreds)));
    }

    return {
      endpoint: caseDef.name,
      ok: mismatches.length === 0,
      mismatches,
      phpStatus: phpResp.status,
      nextStatus: nextResp.status,
      phpBody: phpJson,
      nextBody: nextJson,
    };
  } catch (err) {
    return {
      endpoint: caseDef.name,
      ok: false,
      mismatches: [`fetch/diff error: ${err && err.stack ? err.stack : String(err)}`],
    };
  }
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
    // Unused by this harness's own logic (no PHP session bridge here — see
    // module doc) but infra/e2e/docker-compose.yml's php service requires it
    // (${...:?}); reuse generateSecrets()'s value like every other harness does.
    E2E_SESSION_BRIDGE_HMAC_SECRET: secrets.sessionBridgeHmacSecret,
  };

  let result = 'FAIL';
  let nextProc = null;
  const endpoints = [];

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

    for (const caseDef of ENDPOINT_CASES) {
      endpoints.push(await runApiCase(caseDef, composeEnv, secrets, dbCreds));
    }

    result = endpoints.every((e) => e.ok) ? 'PASS' : 'FAIL';
    if (result === 'PASS') {
      markOk('all_endpoints_matched', endpoints.map((e) => e.endpoint));
    } else {
      fail(
        'endpoint_parity',
        `${endpoints.filter((e) => !e.ok).length} of ${endpoints.length} endpoint(s) did not match`,
        endpoints.filter((e) => !e.ok).map((e) => ({ endpoint: e.endpoint, mismatches: e.mismatches }))
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
          console.error('[api-parity] next server logs (tail):', logs.stdout, logs.stderr);
        }
        nextProc.kill('SIGTERM');
      } catch {
        // best-effort — teardown must never throw past this point.
      }
    }
    composeDown(composeArgs, composeEnv);
  }

  // Strip the (potentially large) phpBody/nextBody debugging payloads off
  // PASSING entries before printing — keep them on failing entries, where
  // they're the diagnosable evidence mig-verify needs (same convention
  // parity.mjs uses for pages' phpData/nextData).
  const printedEndpoints = endpoints.map((e) =>
    e.ok
      ? { endpoint: e.endpoint, ok: e.ok, mismatches: e.mismatches }
      : e
  );

  const output = { result, endpoints: printedEndpoints, steps, failedAt: tracker.getFailedAt() };
  console.log(JSON.stringify(output));
  process.exitCode = result === 'PASS' ? 0 : 1;
}

main();
