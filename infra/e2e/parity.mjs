#!/usr/bin/env node
// infra/e2e/parity.mjs
//
// Phase 2 batch 1 (mig-infra) — data-point parity harness for the three
// page-pairs this batch ports: PHP /users (LINE tab) vs Next /users; PHP
// /user-detail.php?id=N vs Next /user-detail?id=N; PHP /dashboard?tab=
// executive|crm vs Next /dashboard?tab=executive|crm.
//
// Phase 2 batch 2 (mig-infra) EXTENDS this same harness (same file, same
// process, same single JSON-line output — not a second script) with THREE
// more page-pairs: PHP /analytics?tab={overview|advanced|crm|account} vs
// Next /analytics?tab=...; PHP /activity-logs.php vs Next /activity-logs;
// PHP /loyalty-members.php vs Next /loyalty-members. Every batch-2 page/
// filter-combo list below follows the exact same top-level-array +
// runPagePair()-per-entry shape batch-1 already established (USERS_FILTER_
// COMBOS / USER_DETAIL_IDS / DASHBOARD_TABS) — see docs/runbooks/
// phase2-batch1-users-dashboard-parity.md's "Phase 2 batch 2" section for
// the full write-up of what's new.
//
// SCOPE (read before trusting the PASS line — same documented-limits
// pattern infra/e2e/run.mjs already uses): this proves DATA-POINT PARITY
// against ONE seeded tenant/dataset, on the REAL stack (a genuine MariaDB +
// Redis + php:8.2-apache container set, a genuine `next build` +
// `next start` standalone server) — it is NOT a live-traffic shadow test,
// does NOT prove parity across every tenant's real production data, and
// does NOT gate on pixel/HTML equality (see infra/e2e/lib/extract.mjs's
// module doc for why: it diffs a small EXTRACTION LIST of data points per
// page, never raw HTML). See docs/runbooks/phase2-batch1-users-dashboard-
// parity.md for the full write-up, the exact extraction lists, and how
// mig-orchestrator flips infra/nginx/routes.json's upstream once this batch's
// pages have ramped through canary.
//
// Single command to run this harness:
//   node infra/e2e/parity.mjs
//
// Reuses infra/e2e/docker-compose.yml (mariadb+redis+php — Phase 1 batch 3,
// UNMODIFIED) and infra/e2e/lib/harness-common.mjs (compose lifecycle,
// secrets, SQL exec — shared with run.mjs, see that module's header
// comment) for the PHP side, then additionally builds + starts apps/admin's
// `next build` standalone server as a plain host child process (no Docker
// image for apps/admin exists yet — that's a later phase) wired to the SAME
// MariaDB/Redis via the same 127.0.0.1 host ports run.mjs already
// documents the "port gotcha" for.
//
// Because infra/e2e/docker-compose.yml's container names/ports are fixed
// (not templated per-project), this harness and run.mjs CANNOT run
// concurrently — same pre-existing constraint run.mjs already has with
// itself. Sequential use only; mig-verify's own run order already reflects
// this (this brief's acceptance runs LAST among the three batch-1 briefs).
//
// The Next side is fetched via a REAL `next build` + `next start
// --standalone`-equivalent server — never mocked, never stubbed. If a page
// this batch depends on doesn't exist yet (apps/admin/** is owned by the
// two concurrent page-porting agents, not this one), that page's fetch
// fails loudly with a diagnosable step/mismatch — see runPagePair() below —
// rather than being silently skipped or faked.
//
// Always tears down (docker compose down -v + kill the Next child process)
// in a finally block, even on a thrown error.

import { spawn } from 'node:child_process';
import { cpSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
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
  generatePhpBcryptHash,
  waitHttpReachable,
  httpRequest,
} from './lib/harness-common.mjs';
import {
  extractUsersPage,
  extractUserDetailPage,
  extractExecutiveDashboard,
  extractCrmDashboard,
  extractActivityLogsPage,
  extractLoyaltyMembersPage,
  extractAnalyticsOverview,
  extractAnalyticsAdvanced,
  extractAnalyticsCrm,
  extractAnalyticsAccount,
} from './lib/extract.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const COMPOSE_FILE = path.join(__dirname, 'docker-compose.yml');
const SEED_DIR = path.join(__dirname, 'seed');
const PROJECT = 'reya-e2e-parity';

// ---------------------------------------------------------------------------
// Host-side addresses — see docker-compose.yml's port comments / run.mjs's
// "port gotcha" note. This harness's Node process, AND the Next standalone
// server it spawns, both run on the HOST (not inside the compose network),
// so both reach MariaDB/Redis/PHP the same way run.mjs's Node runner does.
// ---------------------------------------------------------------------------
const MARIADB_HOST = '127.0.0.1';
const REDIS_HOST_URL = 'redis://127.0.0.1:16379';
const PHP_BASE_URL = 'http://127.0.0.1:18092';
const NEXT_PORT = 3210;
const NEXT_BASE_URL = `http://127.0.0.1:${NEXT_PORT}`;
const PROBE_PATH = '/system-status.php'; // see infra/e2e/probe-page.md — same sequencing-trap probe run.mjs uses.

const MASTER_DB_NAME = 'zrismpsz_reya_platform';
const TENANT_SLUG = 'e2e-parity-harness';
const TENANT_HOST = `${TENANT_SLUG}.re-ya.com`; // Host header for EVERY page/login request on BOTH stacks — see module doc.
const ADMIN_USERNAME = 'e2e_parity_admin';

const MASTER_MIGRATIONS = [
  'database/migration_2026-05-25_platform_master.sql',
  'database/migration_2026-05-27_master_products.sql',
  'database/migration_2026-05-27_tenant_line_account_routes.sql',
  'database/migration_2026-06-04_platform_billing.sql',
  'database/migration_2026-06-04_platform_billing_details.sql',
  'packages/db/migrations/master/migration_2026-07-12_node_sessions.sql',
];
const TENANT_TEMPLATE = 'database/migration_2026-05-25_tenant_template.sql';
// Applied in order — batch 2's fixture is additive on top of batch 1's (same
// tenant DB, same seedDatabase() call), never a replacement. See
// infra/e2e/seed/40-phase2-batch2-fixture.sql.tmpl's own header comment for
// why it's a new numbered file rather than an append to 30-*.
const FIXTURE_FILES = ['30-phase2-batch1-fixture.sql.tmpl', '40-phase2-batch2-fixture.sql.tmpl'];

const ADMIN_DIR = path.join(REPO_ROOT, 'apps/admin');
const ADMIN_STANDALONE_DIR = path.join(ADMIN_DIR, '.next/standalone/apps/admin');

const tracker = createStepTracker();
const { steps, markOk, fail } = tracker;
const composeArgs = makeComposeArgs(COMPOSE_FILE, PROJECT);

// ---------------------------------------------------------------------------
// PAGE LIST — config-driven per the brief ("the two page agents are building
// concurrently — your runner must not assume their pages exist while you
// develop; make the page list config-driven so mig-verify runs it after all
// builds land"). Nothing below hardcodes a page's existence; every entry is
// just fetch-and-extract instructions, run through runPagePair()'s uniform
// try/catch so a missing/broken page fails loudly as ITS OWN entry, never
// aborting the other entries or the harness as a whole.
// ---------------------------------------------------------------------------

const USERS_FILTER_COMBOS = [
  { name: 'baseline', qs: '' },
  { name: 'search', qs: 'search=Sakura' },
  { name: 'tag', qs: 'tag=1' },
  { name: 'tier', qs: 'tier=gold' },
  { name: 'points', qs: 'points=500-1000' },
  { name: 'activity', qs: 'activity=inactive' },
  { name: 'purchase-purchased', qs: 'purchase=purchased' },
  { name: 'purchase-never', qs: 'purchase=never' },
  { name: 'status', qs: 'status=blocked' },
];

// user 1 = rich (tags + points_transactions + transactions + video call),
// user 2 = medium (1 tag, 1 transaction, points_transactions earn-only),
// user 11 = empty/new (no tags, no transactions in either table, no
// points_transactions, no loyalty_points row) — see the fixture file's
// per-user comments (infra/e2e/seed/30-phase2-batch1-fixture.sql.tmpl).
const USER_DETAIL_IDS = [1, 2, 11];

const DASHBOARD_TABS = ['executive', 'crm'];

// ---------------------------------------------------------------------------
// Phase 2 batch 2 page/filter configs — same "top-level array, looped with
// runPagePair()" shape as the batch-1 configs above (config-driven per the
// brief: mig-verify runs this harness only after both mig-ui page agents
// have landed, so nothing here may assume a page already exists while this
// file is being developed — runPagePair()'s own try/catch is what makes a
// still-missing route fail as its own {ok:false} entry instead of aborting).
// ---------------------------------------------------------------------------

const BANGKOK_TZ = 'Asia/Bangkok';

/** 'YYYY-MM-DD' for (now + offsetDays) in Asia/Bangkok — mirrors apps/admin's analytics/_lib/period.ts's own daysAgoInBangkok() helper, reimplemented here (not imported — this file has no dependency on apps/admin's source, only on its BUILT OUTPUT via HTTP, same as every other extraction in this harness). */
function bangkokDateString(offsetDays, now = new Date()) {
  const shifted = new Date(now.getTime() + offsetDays * 86_400_000);
  return new Intl.DateTimeFormat('en-CA', { timeZone: BANGKOK_TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(shifted);
}

/**
 * Every activity-logs combo below applies a `date_to` bound of "yesterday"
 * (Asia/Bangkok) — see infra/e2e/seed/40-phase2-batch2-fixture.sql.tmpl's
 * activity_logs section header comment for why: classes/AdminAuth.php's
 * login() writes a log_type='auth'/action='login' row to `activity_logs` on
 * every PHP login (this harness's own `phpLogin()` triggers it), but Next's
 * `internal/session-bridge.php` 'login-sync' path does not — a genuine,
 * dated-"today" backend asymmetry that would otherwise make PHP's totalLogs
 * exactly 1 higher than Next's on every combo. Bounding date_to to
 * yesterday excludes that row on both stacks while including every
 * fixture-seeded row (all dated 2-21 days ago, never "today").
 */
function activityLogsDateBounds(now = new Date()) {
  return { from: bangkokDateString(-25, now), to: bangkokDateString(-1, now) };
}
function activityLogsNarrowBounds(now = new Date()) {
  return { from: bangkokDateString(-12, now), to: bangkokDateString(-6, now) };
}

/**
 * One entry per activity-logs.php filter branch actually read from
 * classes/ActivityLogger.php / queries.ts's buildWhere()/countLogs()
 * (type, action, search, date_from/date_to) plus pagination — see
 * infra/e2e/seed/40-phase2-batch2-fixture.sql.tmpl's own comments for which
 * fixture rows each combo is expected to intersect. `qs(bounds, narrow)` is
 * a function (not a static string) because the date bound is computed once
 * per run from the real wall clock, never hardcoded.
 */
const ACTIVITY_LOGS_COMBOS = [
  { name: 'baseline', qs: (b) => `date_from=${b.from}&date_to=${b.to}` },
  { name: 'type', qs: (b) => `type=pharmacy&date_from=${b.from}&date_to=${b.to}` },
  { name: 'action', qs: (b) => `action=login&date_from=${b.from}&date_to=${b.to}` },
  { name: 'search', qs: (b) => `search=BATCH2SEARCHMARK&date_from=${b.from}&date_to=${b.to}` },
  { name: 'date-range', qs: (_b, n) => `date_from=${n.from}&date_to=${n.to}` },
  { name: 'combined', qs: (b) => `type=consent&action=login&date_from=${b.from}&date_to=${b.to}` },
  { name: 'page2', qs: (b) => `date_from=${b.from}&date_to=${b.to}&page=2` },
];

/** loyalty-members.php's only filter is `?q=` (search). Both combos assert the SAME empty-state parity in this harness — see extract.mjs's extractLoyaltyMembersPage doc for why (no `line_accounts` rows seeded, so `lineAccountId`/`currentBotId` is always 0 on both stacks and the underlying query never runs). */
const LOYALTY_MEMBERS_SEARCHES = [
  { name: 'baseline', qs: '' },
  { name: 'search', qs: 'q=ทดสอบ' },
];

/** analytics.php's 4 tabs — each fetched with an explicit `?tab=` on both stacks (same convention DASHBOARD_TABS above already uses). */
const ANALYTICS_TABS = [
  { name: 'overview', qs: 'tab=overview', extractPhp: extractAnalyticsOverview, extractNext: extractAnalyticsOverview },
  { name: 'advanced', qs: 'tab=advanced', extractPhp: extractAnalyticsAdvanced, extractNext: extractAnalyticsAdvanced },
  { name: 'crm', qs: 'tab=crm', extractPhp: extractAnalyticsCrm, extractNext: extractAnalyticsCrm },
  { name: 'account', qs: 'tab=account', extractPhp: extractAnalyticsAccount, extractNext: extractAnalyticsAccount },
];

// ---------------------------------------------------------------------------
// Database seeding — mirrors run.mjs's seedDatabase()/seedAdminUser() shape
// (same shared helpers, see harness-common.mjs), PLUS this batch's own
// 15-plan-and-tenant.sql.tmpl (own tenant slug — see that file's header
// comment for why it's not a reuse of run.mjs's 10-*) and the two data-point
// fixtures in FIXTURE_FILES (30-phase2-batch1-fixture.sql.tmpl +
// 40-phase2-batch2-fixture.sql.tmpl, applied in order onto the SAME tenant DB).
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

  const planTenantSql = readFileSync(path.join(SEED_DIR, '15-plan-and-tenant.sql.tmpl'), 'utf8').replaceAll(
    '__APP_DB_NAME__',
    dbCreds.name
  );
  execSql(tracker, composeArgs, env, rootPw, planTenantSql, [], 'seed_plan_and_tenant');
  markOk('seed_plan_and_tenant');

  for (const fixtureFile of FIXTURE_FILES) {
    const fixtureSql = readFileSync(path.join(SEED_DIR, fixtureFile), 'utf8');
    execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${fixtureSql}`, [], 'seed_fixture');
  }
  markOk('seed_fixture', FIXTURE_FILES);

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

function seedAdminUser(env, secrets, dbCreds, passwordHash) {
  const rootPw = secrets.mariadbRootPassword;
  const escapedHash = passwordHash.replaceAll("'", "''");
  const sql = readFileSync(path.join(SEED_DIR, '20-admin-user.sql.tmpl'), 'utf8')
    .replaceAll('__ADMIN_USERNAME__', ADMIN_USERNAME)
    .replaceAll('__ADMIN_PASSWORD_HASH__', escapedHash);
  execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${sql}`, [], 'seed_admin_user');
  markOk('seed_admin_user');
}

/** Same sequencing-trap probe run.mjs uses — includes/auth_check.php's `new AdminAuth($db)` auto-creates admin_users/admin_bot_access/admin_activity_log on first hit. Unauthenticated, so also doubles as a control that the probe page gates on auth at all. */
async function fireThrowawayProbeRequest() {
  const resp = await httpRequest({ url: `${PHP_BASE_URL}${PROBE_PATH}`, headers: { Host: TENANT_HOST } });
  markOk('throwaway_probe_request', resp.status);
  return resp;
}

// ---------------------------------------------------------------------------
// apps/admin — real `next build` (always, for determinism — a stale
// standalone bundle left over from a previous checkout state would be a
// silent parity-harness footgun) + standalone server as a plain child
// process on the host.
// ---------------------------------------------------------------------------

function buildAdmin() {
  console.error('[parity] pnpm --filter admin run build ...');
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

/** Next's `output: 'standalone'` does NOT copy `.next/static` into the standalone bundle (documented Next.js behavior) — this harness's own responsibility to copy it before starting the server, every build. */
function prepareStandaloneStatic() {
  const src = path.join(ADMIN_DIR, '.next/static');
  const dest = path.join(ADMIN_STANDALONE_DIR, '.next/static');
  cpSync(src, dest, { recursive: true, force: true });
  markOk('prepare_standalone_static');
}

function startNextServer(env) {
  console.error('[parity] starting apps/admin standalone server ...');
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
// Login — PHP via a REAL POST to auth/login.php (plain username/password
// form, no CSRF token — verified by reading that file); Next via a REAL
// POST to /api/auth/login (the interface contract's own Route Handler).
// Both requests (and every page fetch below) carry Host: TENANT_HOST so
// BOTH stacks resolve the SAME tenant via subdomain routing — a stricter,
// more production-like test than run.mjs's own bridge harness, which
// deliberately relies on PHP's legacy-DB-fallback instead (see that
// script's own comments); safe here because this fixture's tenant row's
// db_name equals the SAME physical database run.mjs also uses that pattern
// for, so either resolution path lands on identical data.
// ---------------------------------------------------------------------------

function firstSetCookieValue(headers, cookieName) {
  const raw = headers['set-cookie'];
  if (!raw) return null;
  const list = Array.isArray(raw) ? raw : [raw];
  for (const entry of list) {
    const m = entry.match(new RegExp(`^${cookieName}=([^;]+)`));
    if (m) return m[1];
  }
  return null;
}

async function phpLogin(password) {
  const resp = await httpRequest({
    url: `${PHP_BASE_URL}/auth/login.php`,
    method: 'POST',
    headers: {
      Host: TENANT_HOST,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({ username: ADMIN_USERNAME, password }).toString(),
  });
  const sid = firstSetCookieValue(resp.headers, 'PHPSESSID');
  if (!sid || resp.status < 300 || resp.status >= 400 || !(resp.headers.location || '').includes('dashboard')) {
    fail('php_login', 'auth/login.php did not respond with a PHPSESSID cookie + redirect to dashboard', {
      status: resp.status,
      location: resp.headers.location,
      sidPresent: sid !== null,
    });
  }
  markOk('php_login');
  return sid;
}

async function nextLogin(password) {
  const resp = await httpRequest({
    url: `${NEXT_BASE_URL}/api/auth/login`,
    method: 'POST',
    headers: {
      Host: TENANT_HOST,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({ realm: 'tenant', username: ADMIN_USERNAME, password }).toString(),
  });
  const sid = firstSetCookieValue(resp.headers, 'reya_sid');
  if (!sid || resp.status < 300 || resp.status >= 400 || !(resp.headers.location || '').includes('/dashboard')) {
    fail('next_login', 'POST /api/auth/login did not respond with a reya_sid cookie + redirect to /dashboard', {
      status: resp.status,
      location: resp.headers.location,
      sidPresent: sid !== null,
    });
  }
  markOk('next_login');
  return sid;
}

async function fetchPhpPage(pathAndQuery, sid) {
  const resp = await httpRequest({
    url: `${PHP_BASE_URL}${pathAndQuery}`,
    headers: { Host: TENANT_HOST, Cookie: `PHPSESSID=${sid}` },
  });
  return resp;
}

async function fetchNextPage(pathAndQuery, sid) {
  const resp = await httpRequest({
    url: `${NEXT_BASE_URL}${pathAndQuery}`,
    headers: { Host: TENANT_HOST, Cookie: `reya_sid=${sid}` },
  });
  return resp;
}

function assertAuthedOk(resp, label) {
  if (resp.status !== 200) {
    throw new Error(`${label}: expected 200, got ${resp.status} (location=${resp.headers.location ?? 'n/a'})`);
  }
}

// ---------------------------------------------------------------------------
// Diffing — plain structural deepEqual over the small JSON-safe objects
// infra/e2e/lib/extract.mjs's extractors return. Never touches raw HTML.
// ---------------------------------------------------------------------------

function diffValues(php, next, path, out) {
  if (Array.isArray(php) || Array.isArray(next)) {
    const a = JSON.stringify(php ?? null);
    const b = JSON.stringify(next ?? null);
    if (a !== b) out.push(`${path}: php=${a} next=${b}`);
    return;
  }
  if (php !== null && typeof php === 'object' && next !== null && typeof next === 'object') {
    const keys = new Set([...Object.keys(php), ...Object.keys(next)]);
    for (const key of keys) {
      diffValues(php[key], next[key], path ? `${path}.${key}` : key, out);
    }
    return;
  }
  if (php !== next) {
    out.push(`${path}: php=${JSON.stringify(php)} next=${JSON.stringify(next)}`);
  }
}

function diff(php, next) {
  const out = [];
  diffValues(php, next, '', out);
  return out;
}

/**
 * Runs one page-pair: fetches PHP + Next, extracts, diffs. NEVER throws —
 * any failure (fetch error, non-200, extraction throwing because a label it
 * expected isn't in the HTML at all) is caught and turned into
 * `{page, ok:false, mismatches:[...]}` so ONE broken/missing page can never
 * abort the other page-pairs or skip teardown (acceptance criterion: a
 * deliberately-broken Next build must still tear down cleanly and report
 * FAIL with a diagnosable mismatch, not hang/crash).
 */
async function runPagePair(name, fetchBoth, extractBoth) {
  try {
    const { phpResp, nextResp } = await fetchBoth();
    assertAuthedOk(phpResp, `${name} (php)`);
    assertAuthedOk(nextResp, `${name} (next)`);
    const { phpData, nextData } = extractBoth(phpResp.text, nextResp.text);
    const mismatches = diff(phpData, nextData);
    if (mismatches.length > 0 && process.env.PARITY_DUMP_HTML) {
      const safe = name.replace(/[^a-zA-Z0-9_.=-]/g, '_');
      writeFileSync(`/tmp/parity-debug-${safe}-php.html`, phpResp.text);
      writeFileSync(`/tmp/parity-debug-${safe}-next.html`, nextResp.text);
    }
    return { page: name, ok: mismatches.length === 0, mismatches, phpData, nextData };
  } catch (err) {
    return { page: name, ok: false, mismatches: [`extraction/fetch error: ${err && err.message ? err.message : String(err)}`] };
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
    E2E_SESSION_BRIDGE_HMAC_SECRET: secrets.sessionBridgeHmacSecret, // unused by this harness's own logic, but infra/e2e/docker-compose.yml's php service requires it (${...:?}).
  };

  let result = 'FAIL';
  let nextProc = null;
  const pages = [];

  try {
    composeUp(tracker, composeArgs, composeEnv, 'compose_up');
    await waitContainerHealthy(tracker, 'e2e-mariadb', 'mariadb_healthy');
    await waitContainerHealthy(tracker, 'e2e-redis', 'redis_healthy');

    seedDatabase(composeEnv, secrets, dbCreds);

    await waitHttpReachable(tracker, `${PHP_BASE_URL}/`, 'php_reachable');
    await fireThrowawayProbeRequest();

    const passwordHash = generatePhpBcryptHash(tracker, composeArgs, composeEnv, secrets.adminPassword, 'generate_php_hash');
    seedAdminUser(composeEnv, secrets, dbCreds, passwordHash);

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

    const phpSid = await phpLogin(secrets.adminPassword);
    const nextSid = await nextLogin(secrets.adminPassword);
    markOk('login_both_stacks');

    // --- /users (LINE tab), one page-pair entry per exercised filter combo ---
    for (const combo of USERS_FILTER_COMBOS) {
      const phpQs = combo.qs ? `?tab=line&${combo.qs}` : '?tab=line';
      const nextQs = combo.qs ? `?${combo.qs}` : '';
      pages.push(
        await runPagePair(
          `users:${combo.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/users.php${phpQs}`, phpSid),
            nextResp: await fetchNextPage(`/users${nextQs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractUsersPage(phpHtml), nextData: extractUsersPage(nextHtml) })
        )
      );
    }

    // --- /user-detail?id=N ---
    for (const id of USER_DETAIL_IDS) {
      pages.push(
        await runPagePair(
          `user-detail:id=${id}`,
          async () => ({
            phpResp: await fetchPhpPage(`/user-detail.php?id=${id}`, phpSid),
            nextResp: await fetchNextPage(`/user-detail?id=${id}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractUserDetailPage(phpHtml), nextData: extractUserDetailPage(nextHtml) })
        )
      );
    }

    // --- /dashboard?tab=executive|crm ---
    for (const tab of DASHBOARD_TABS) {
      pages.push(
        await runPagePair(
          `dashboard:tab=${tab}`,
          async () => ({
            phpResp: await fetchPhpPage(`/dashboard.php?tab=${tab}`, phpSid),
            nextResp: await fetchNextPage(`/dashboard?tab=${tab}`, nextSid),
          }),
          (phpHtml, nextHtml) =>
            tab === 'executive'
              ? {
                  phpData: extractExecutiveDashboard(phpHtml, 'php'),
                  nextData: extractExecutiveDashboard(nextHtml, 'next'),
                }
              : { phpData: extractCrmDashboard(phpHtml), nextData: extractCrmDashboard(nextHtml) }
        )
      );
    }

    // --- Phase 2 batch 2: /analytics?tab={overview|advanced|crm|account} ---
    for (const tab of ANALYTICS_TABS) {
      pages.push(
        await runPagePair(
          `analytics:tab=${tab.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/analytics.php?${tab.qs}`, phpSid),
            nextResp: await fetchNextPage(`/analytics?${tab.qs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: tab.extractPhp(phpHtml), nextData: tab.extractNext(nextHtml) })
        )
      );
    }

    // --- Phase 2 batch 2: /activity-logs (5 filter branches + pagination) ---
    const activityLogsNow = new Date();
    const activityLogsBounds = activityLogsDateBounds(activityLogsNow);
    const activityLogsNarrow = activityLogsNarrowBounds(activityLogsNow);
    for (const combo of ACTIVITY_LOGS_COMBOS) {
      const qs = combo.qs(activityLogsBounds, activityLogsNarrow);
      pages.push(
        await runPagePair(
          `activity-logs:${combo.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/activity-logs.php?${qs}`, phpSid),
            nextResp: await fetchNextPage(`/activity-logs?${qs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractActivityLogsPage(phpHtml, 'php'), nextData: extractActivityLogsPage(nextHtml, 'next') })
        )
      );
    }

    // --- Phase 2 batch 2: /loyalty-members (?q= search) ---
    for (const combo of LOYALTY_MEMBERS_SEARCHES) {
      const phpQs = combo.qs ? `?${combo.qs}` : '';
      const nextQs = combo.qs ? `?${combo.qs}` : '';
      pages.push(
        await runPagePair(
          `loyalty-members:${combo.name}`,
          async () => ({
            phpResp: await fetchPhpPage(`/loyalty-members.php${phpQs}`, phpSid),
            nextResp: await fetchNextPage(`/loyalty-members${nextQs}`, nextSid),
          }),
          (phpHtml, nextHtml) => ({ phpData: extractLoyaltyMembersPage(phpHtml), nextData: extractLoyaltyMembersPage(nextHtml) })
        )
      );
    }

    result = pages.every((p) => p.ok) ? 'PASS' : 'FAIL';
    if (result === 'PASS') {
      markOk('all_pages_matched', pages.map((p) => p.page));
    } else {
      fail(
        'page_parity',
        `${pages.filter((p) => !p.ok).length} of ${pages.length} page(s) did not match`,
        pages.filter((p) => !p.ok).map((p) => ({ page: p.page, mismatches: p.mismatches }))
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
          console.error('[parity] next server logs (tail):', logs.stdout, logs.stderr);
        }
        nextProc.kill('SIGTERM');
      } catch {
        // best-effort — teardown must never throw past this point.
      }
    }
    composeDown(composeArgs, composeEnv);
  }

  // Strip the (potentially large) phpData/nextData debugging payloads off
  // PASSING pages before printing — keep them on failing pages, where
  // they're the diagnosable evidence mig-verify needs.
  const printedPages = pages.map((p) => (p.ok ? { page: p.page, ok: p.ok, mismatches: p.mismatches } : p));

  const output = { result, pages: printedPages, steps, failedAt: tracker.getFailedAt() };
  console.log(JSON.stringify(output));
  process.exitCode = result === 'PASS' ? 0 : 1;
}

main();
