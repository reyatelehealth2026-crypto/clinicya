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
//
// -----------------------------------------------------------------------------
// PHASE 3 BATCH 2 EXTENSION (mig-infra) — takes this harness from 16 to 38
// covered endpoint x action pairs: appointments:{pharmacists,available_slots,
// book,my_appointments,cancel}, health-profile:{update_personal,
// update_medical_history,add_allergy,remove_allergy,add_medication,
// remove_medication}, consent:save, data-rights:{withdraw_consent,
// request_deletion,export_data}, medication-reminders:{list,add,delete,
// mark_taken} — 19 new PHP-vs-Next diffable ENDPOINT_CASES entries — PLUS 3
// STRUCTURALLY SEPARATE NEXT_ONLY_CASES entries (addresses:{list,upsert,
// delete} — NO PHP source exists for this endpoint; see
// infra/e2e/lib/api-extract.mjs's NEXT_ONLY_CASES doc comment and
// docs/runbooks/phase3-batch2-miniapp-api-parity.md for the full writeup).
// Batch-1 content above (up to and including the ENDPOINT_CASES loop in
// main()) is otherwise UNTOUCHED except where explicitly marked "PHASE 3
// BATCH 2" below.
//
// THREE THINGS THIS EXTENSION ADDS, beyond the new case config itself:
//   1. seedDatabase() applies database/migration_2026-07-04_pdpa_data_rights.sql
//      (the `data_deletion_requests` table + `users.deletion_status`/
//      `deletion_requested_at` columns — CONFIRMED ABSENT from the base
//      TENANT_TEMPLATE, present only in this separately-committed migration)
//      immediately after TENANT_TEMPLATE and before any fixture file, same
//      pattern MASTER_MIGRATIONS already uses for
//      migration_2026-06-02_route_liff_id.sql.
//   2. buildContracts() runs `pnpm --filter @reya/contracts run build` before
//      buildAdmin() — NEW, not present in batch 1 — because
//      infra/e2e/lib/api-extract.mjs now imports real zod schemas from
//      packages/contracts/dist/index.js (for NEXT_ONLY_CASES's schema
//      validation) and that import must never depend on a stale/missing
//      dist/ left over from a previous session, same "never trust a
//      possibly-stale build" philosophy buildAdmin() already applies to
//      apps/admin/.next/standalone.
//   3. callStack() accepts an optional per-case `phpHost` field (see
//      infra/e2e/lib/api-extract.mjs's PHP_HOST doc comment) — a `Host`
//      header pinned on the PHP-side request ONLY, for the 4 cases whose PHP
//      source (api/consent.php, api/data-rights.php) is missing
//      `require_once bootstrap/route_by_account.php`. Node's `http.request()`
//      (httpRequest() in harness-common.mjs) accepts an explicit Host header
//      override with no special handling — infra/e2e/parity.mjs's own
//      TENANT_HOST usage already proves this is a low-risk, already-relied-on
//      mechanism, not new plumbing.
//   4. buildAdmin() now DISCOVERS where `next build`'s standalone `server.js`
//      actually landed (resolveAdminStandaloneDir(), below) instead of
//      assuming the fixed `.next/standalone/apps/admin/` path batch 1's
//      ADMIN_STANDALONE_DIR hardcoded. Empirically verified necessary in this
//      batch's own dev environment: Next's Turbopack workspace-root
//      auto-detection picks the SHALLOWEST of every lockfile it finds on the
//      filesystem, including ones OUTSIDE this repo entirely (e.g. a stray
//      `/tmp/package-lock.json` left by an unrelated process on a shared
//      sandbox host) — when that happens, the standalone output mirrors the
//      FULL absolute path from that unrelated root instead of from
//      apps/admin's own nearest pnpm-workspace.yaml, and the batch-1
//      hardcoded path silently stops matching reality (`build_admin` fails
//      with "next build did not produce .../standalone/apps/admin/server.js"
//      even though the build itself succeeded). This is an ENVIRONMENT
//      quirk, not a batch-2-vs-batch-1 behavioral difference — the fix here
//      is a robustness improvement to THIS script's own (already-a-
//      documented-copy-not-import) buildAdmin()/prepareStandaloneStatic()/
//      startNextServer(), not a change to what they DO.
// -----------------------------------------------------------------------------
//
// PHASE 3 BATCH 3 EXTENSION (mig-infra) — takes this harness from 38 to 46
// covered endpoint x action pairs: checkout-cart:{cart,add_to_cart,
// update_cart,remove_from_cart,clear_cart}, checkout-pricing:validate_promo,
// checkout-order:{create_order,upload_slip} - 8 new PHP-vs-Next diffable
// ENDPOINT_CASES entries (see infra/e2e/lib/api-extract.mjs). Batch-1/2
// content above is otherwise UNTOUCHED except where explicitly marked
// "PHASE 3 BATCH 3" below. See docs/runbooks/phase3-batch3-miniapp-api-parity.md
// for the full writeup. TWO THINGS THIS EXTENSION ADDS, beyond the new case
// config itself:
//   1. seedDatabase() applies 65-phase3-batch3-miniapp-fixture.sql.tmpl
//      (FIXTURE_FILE_BATCH3) immediately after FIXTURE_FILE_BATCH2, same
//      "reuse the one seeded tenant, apply in order" pattern batch 2
//      established for its own fixture file.
//   2. callStack() accepts an optional per-case `multipart: true` flag
//      (checkout-order:upload_slip only) - routes through
//      httpRequestMultipart()/TINY_PNG_FIXTURE
//      (infra/e2e/lib/harness-common.mjs, NEW plumbing this batch adds -
//      see that module's own doc comment) instead of the JSON-body path
//      every prior case uses. This is the FIRST file-upload endpoint ported
//      anywhere in this migration effort.
// -----------------------------------------------------------------------------

import { spawn } from 'node:child_process';
import { cpSync, readdirSync, readFileSync } from 'node:fs';
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
  httpRequestMultipart, // PHASE 3 BATCH 3 — checkout-order:upload_slip, the one multipart case.
  TINY_PNG_FIXTURE, // PHASE 3 BATCH 3 — see harness-common.mjs's own doc comment.
} from './lib/harness-common.mjs';
import { FIXTURE, FORMAT_CHECKS, ENDPOINT_CASES, NEXT_ONLY_CASES } from './lib/api-extract.mjs';

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
// PHASE 3 BATCH 2 — data_deletion_requests / users.deletion_status /
// users.deletion_requested_at, CONFIRMED ABSENT from TENANT_TEMPLATE above
// (verified directly — no CREATE TABLE / ALTER TABLE for either anywhere in
// that file), needed by data-rights:request_deletion / :export_data. Applied
// immediately after TENANT_TEMPLATE, before any fixture file — see this
// script's own module doc and seedDatabase() below.
const PDPA_MIGRATION = 'database/migration_2026-07-04_pdpa_data_rights.sql';
const PLAN_AND_TENANT_FILE = '45-phase3-batch1-plan-and-tenant.sql.tmpl'; // master DB — own `USE` statement.
const FIXTURE_FILE = '50-phase3-batch1-miniapp-fixture.sql.tmpl'; // tenant DB — USE-prefixed by seedFixture() below.
const FIXTURE_FILE_BATCH2 = '55-phase3-batch2-miniapp-fixture.sql.tmpl'; // tenant DB — applied AFTER FIXTURE_FILE, same tenant/line_accounts rows.
const FIXTURE_FILE_BATCH3 = '65-phase3-batch3-miniapp-fixture.sql.tmpl'; // tenant DB — applied AFTER FIXTURE_FILE_BATCH2, same tenant/line_accounts rows (PHASE 3 BATCH 3).

const ADMIN_DIR = path.join(REPO_ROOT, 'apps/admin');
// PHASE 3 BATCH 2 — `let`, not `const`: buildAdmin() below OVERWRITES this
// with resolveAdminStandaloneDir()'s discovered path once the build actually
// completes. The literal here is only the batch-1-assumed DEFAULT (used if
// discovery somehow finds nothing — see resolveAdminStandaloneDir()'s own
// doc comment for why a fixed assumption is no longer safe to rely on alone).
let ADMIN_STANDALONE_DIR = path.join(ADMIN_DIR, '.next/standalone/apps/admin');

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

  // PHASE 3 BATCH 2 — applied immediately after TENANT_TEMPLATE, before
  // PLAN_AND_TENANT_FILE/FIXTURE_FILE/FIXTURE_FILE_BATCH2 (all of which may
  // depend on data_deletion_requests / users.deletion_status existing) — see
  // PDPA_MIGRATION's own doc comment above.
  const pdpaMigrationContent = readFileSync(path.join(REPO_ROOT, PDPA_MIGRATION), 'utf8');
  execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${pdpaMigrationContent}`, [], 'seed_app_db_pdpa_migration');
  markOk('seed_app_db_pdpa_migration', PDPA_MIGRATION);

  const planTenantSql = readFileSync(path.join(SEED_DIR, PLAN_AND_TENANT_FILE), 'utf8').replaceAll('__APP_DB_NAME__', dbCreds.name);
  execSql(tracker, composeArgs, env, rootPw, planTenantSql, [], 'seed_plan_and_tenant');
  markOk('seed_plan_and_tenant');

  const fixtureSql = readFileSync(path.join(SEED_DIR, FIXTURE_FILE), 'utf8');
  execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${fixtureSql}`, [], 'seed_fixture');
  markOk('seed_fixture', FIXTURE_FILE);

  // PHASE 3 BATCH 2 — reuses the SAME tenant/line_accounts rows FIXTURE_FILE
  // above just seeded; does NOT create a second tenant.
  const fixtureSqlBatch2 = readFileSync(path.join(SEED_DIR, FIXTURE_FILE_BATCH2), 'utf8');
  execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${fixtureSqlBatch2}`, [], 'seed_fixture_batch2');
  markOk('seed_fixture_batch2', FIXTURE_FILE_BATCH2);

  // PHASE 3 BATCH 3 — reuses the SAME tenant/line_accounts rows, applied
  // AFTER FIXTURE_FILE_BATCH2 (its own UPDATE statements against
  // line_accounts/shop_settings depend on those rows already existing — see
  // 65-...'s own header comment).
  const fixtureSqlBatch3 = readFileSync(path.join(SEED_DIR, FIXTURE_FILE_BATCH3), 'utf8');
  execSql(tracker, composeArgs, env, rootPw, `USE \`${dbCreds.name}\`;\n${fixtureSqlBatch3}`, [], 'seed_fixture_batch3');
  markOk('seed_fixture_batch3', FIXTURE_FILE_BATCH3);

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

// PHASE 3 BATCH 2 — NEW, not present in batch 1. apps/admin's OWN Next build
// (buildAdmin(), below) imports @reya/contracts transitively (every ported
// miniapp route's response shape) via its compiled `dist/index.js`
// (package.json "main" — apps/admin/next.config.ts does NOT transpilePackages
// it from source), so a fresh contracts build must exist before buildAdmin()
// runs, same "never trust a possibly-stale build" philosophy buildAdmin()
// itself already applies to apps/admin/.next/standalone.
//
// KNOWN LIMITATION (documented, not a bug): infra/e2e/lib/api-extract.mjs's
// OWN `import {...} from '../../../packages/contracts/dist/index.js'` (for
// NEXT_ONLY_CASES's zod validation) is a STATIC top-level ES module import,
// resolved during api-parity.mjs's own module-load — which happens BEFORE
// main() (and therefore this function) ever runs. This function's rebuild
// therefore cannot retroactively freshen that already-resolved import within
// the SAME process invocation; it only guarantees freshness for buildAdmin()
// below and any subsequent run. In practice this is a non-issue: dist/ is a
// build artifact that changes rarely, and if it's missing entirely (not just
// stale) the static import fails LOUDLY at process start (a hard
// module-not-found crash, not a silent misbehavior) rather than papering
// over the gap.
function buildContracts() {
  console.error('[api-parity] pnpm --filter @reya/contracts run build ...');
  const result = run('pnpm', ['--filter', '@reya/contracts', 'run', 'build'], { stdio: 'inherit' });
  if (result.status !== 0) {
    fail('build_contracts', `pnpm --filter @reya/contracts run build exited ${result.status}`);
  }
  markOk('build_contracts');
}

// PHASE 3 BATCH 2 — see this script's own module doc, point 4, for the full
// "why": Turbopack's workspace-root auto-detection can pick a root OUTSIDE
// this repo entirely (any shallower lockfile anywhere on the filesystem
// wins), in which case `.next/standalone/<mirrored-full-path>/apps/admin/`
// replaces the batch-1-assumed `.next/standalone/apps/admin/`. Recursively
// searches `.next/standalone` for the one `server.js` a single-app Next
// build produces, and returns its containing directory — correct regardless
// of whether Turbopack picked apps/admin's own workspace root (the common
// case; discovery still finds it, just one hop deeper/shallower) or some
// unrelated shallower root (the environment-quirk case this was written
// for). Returns `null` (never throws) if nothing is found — buildAdmin()
// turns that into its own diagnosable `fail()`.
function findServerJs(dir) {
  let entries;
  try {
    entries = readdirSync(dir, { withFileTypes: true });
  } catch {
    return null;
  }
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isFile() && entry.name === 'server.js') {
      return full;
    }
    if (entry.isDirectory()) {
      const found = findServerJs(full);
      if (found) return found;
    }
  }
  return null;
}

function resolveAdminStandaloneDir() {
  const standaloneRoot = path.join(ADMIN_DIR, '.next/standalone');
  const serverEntry = findServerJs(standaloneRoot);
  return serverEntry ? path.dirname(serverEntry) : null;
}

function buildAdmin() {
  console.error('[api-parity] pnpm --filter admin run build ...');
  const result = run('pnpm', ['--filter', 'admin', 'run', 'build'], { stdio: 'inherit' });
  if (result.status !== 0) {
    fail('build_admin', `pnpm --filter admin run build exited ${result.status}`);
  }
  const discovered = resolveAdminStandaloneDir();
  if (!discovered) {
    fail(
      'build_admin',
      `next build did not produce a server.js anywhere under ${path.join(ADMIN_DIR, '.next/standalone')} — ` +
        `is next.config.ts's output:'standalone' still set?`
    );
  }
  ADMIN_STANDALONE_DIR = discovered; // PHASE 3 BATCH 2 — overwrites the batch-1-assumed default with reality.
  markOk('build_admin', { adminStandaloneDir: ADMIN_STANDALONE_DIR });
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

// PHASE 3 BATCH 2 — `caseDef.phpHost` (see infra/e2e/lib/api-extract.mjs's
// PHP_HOST doc comment) is an OPTIONAL per-case Host-header override, applied
// ONLY to the PHP-side ('php' variant) request — undefined/absent on every
// case except the 4 consent/data-rights ones whose PHP source is missing
// `require_once bootstrap/route_by_account.php`. The Next-side ('next'
// variant) call is NEVER affected by this field, on every case, by
// construction (the `variant === 'php'` guard below).
function phpHostHeader(caseDef, variant) {
  return variant === 'php' && caseDef.phpHost ? { Host: caseDef.phpHost } : {};
}

// PHASE 3 BATCH 3 — `caseDef.multipart` (see infra/e2e/lib/api-extract.mjs's
// `checkout-order:upload_slip` case) routes through `httpRequestMultipart()`
// instead of the JSON-body path below, using `caseDef.fields(variant)` (plain
// form fields — mirrors `caseDef.body(variant)`'s shape/role for the JSON
// cases) plus a fixed `TINY_PNG_FIXTURE` file part named per `caseDef.file`
// (`{name, filename, contentType}` — no `data`; the actual bytes always come
// from the one shared fixture constant, since this harness has exactly ONE
// multipart case, so there is nothing to gain from letting per-case config
// supply arbitrary bytes). GET is never multipart (no case combines the two),
// so this only needs to branch inside the POST arm.
async function callStack(baseUrl, caseDef, variant) {
  const method = caseDef.method;
  if (method === 'GET') {
    const query = caseDef.query(variant);
    const url = `${baseUrl}${variant === 'php' ? caseDef.phpPath : caseDef.nextPath}${toQueryString(query)}`;
    return httpRequest({ url, method: 'GET', headers: phpHostHeader(caseDef, variant) });
  }
  const url = `${baseUrl}${variant === 'php' ? caseDef.phpPath : caseDef.nextPath}`;
  if (caseDef.multipart) {
    const fields = caseDef.fields(variant);
    const file = { ...caseDef.file, data: TINY_PNG_FIXTURE };
    return httpRequestMultipart({ url, method: 'POST', headers: phpHostHeader(caseDef, variant), fields, file });
  }
  const body = caseDef.body(variant);
  return httpRequest({
    url,
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...phpHostHeader(caseDef, variant) },
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
      // PHASE 3 BATCH 2 — `caseDef.skipResponseBodyDiff` (optional, default
      // false/absent on every case except consent:save — see that case's own
      // extensive doc comment in infra/e2e/lib/api-extract.mjs for the
      // verified, deterministic, pre-existing PHP bug this exists for):
      // skips the body-level diffAllow()/runFormatChecks() comparison for a
      // case whose RESPONSE SHAPE legitimately, deterministically differs
      // between the two stacks for a documented reason unrelated to a Next
      // port defect — http_status/header comparisons and dbChecks below
      // still run unconditionally, so this never silently hides a REAL
      // regression in the one thing that actually matters for that case
      // (the underlying database write).
      if (!caseDef.skipResponseBodyDiff) {
        mismatches.push(...diffAllow(phpJson, nextJson, caseDef.allow));
        mismatches.push(...runFormatChecks(caseDef.allow, phpJson, nextJson));
      }
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
// PHASE 3 BATCH 2 — runNextOnlyCase() — the addresses:{list,upsert,delete}
// SELF-CONSISTENCY runner. STRUCTURALLY SEPARATE from runApiCase() above (a
// DELIBERATE choice, not accidental duplication — see
// infra/e2e/lib/api-extract.mjs's NEXT_ONLY_CASES doc comment for why a
// shared runner would be misleading here): there is no PHP call at all, so
// there is nothing to Promise.all()/diff against. Instead:
//   1. Calls the Next endpoint only.
//   2. Parses the JSON body, then validates it against `caseDef.schema` (a
//      REAL zod schema imported from @reya/contracts, not a hand-rolled
//      shape) via `.safeParse()` — every failing zod issue becomes one
//      diagnosable mismatch line, same "one line per problem" convention
//      runApiCase()'s own diff engine uses.
//   3. Runs `caseDef.extraCheck(json)` if present (addresses:list's "the two
//      pre-seeded rows actually came back" assertion — schema validation
//      alone only proves shape, not content).
//   4. Runs `caseDef.dbCheck` if present (addresses:upsert/delete) — reuses
//      buildJsonRowSql()/queryJsonRow() exactly like runDbChecks() above,
//      but diffs the resulting row against a literal `expect`ed value
//      instead of a second (nonexistent) php row. diffAllow()'s own
//      "php=.../next=..." message wording is relabeled to "actual=.../
//      expected=..." here (a `.replace()` on the returned strings, not a
//      second diff engine) since there is no php/next pair in this mode.
//
// NEVER throws past its own try/catch, same isolation guarantee
// runApiCase() provides — one broken addresses action can never abort the
// rest of the run. Every returned result carries `mode:
// 'next-only-self-consistency'` so it prints visibly distinct from the 35
// real PHP-vs-Next entries (see main()'s own comment on where this gets
// merged into the printed `endpoints` array).
// ---------------------------------------------------------------------------

async function runNextOnlyCase(caseDef, env, secrets, dbCreds) {
  try {
    const resp =
      caseDef.method === 'GET'
        ? await httpRequest({ url: `${NEXT_BASE_URL}${caseDef.nextPath}${toQueryString(caseDef.query())}`, method: 'GET' })
        : await httpRequest({
            url: `${NEXT_BASE_URL}${caseDef.nextPath}`,
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(caseDef.body()),
          });

    const mismatches = [];
    if (resp.status !== 200) {
      mismatches.push(`http_status: next=${resp.status} (expected 200 — /api/miniapp/** is always-implicit-200 per addresses.ts's envelope doc comment)`);
    }

    const parsed = safeJsonParse(resp.text);
    let json = null;
    if (!parsed.ok) {
      mismatches.push(`next response is not valid JSON: ${parsed.error} (body: ${resp.text.slice(0, 500)})`);
    } else {
      json = parsed.value;
      const zodResult = caseDef.schema.safeParse(json);
      if (!zodResult.success) {
        for (const issue of zodResult.error.issues) {
          mismatches.push(`zod[${issue.path.join('.') || '<root>'}]: ${issue.message}`);
        }
      }
      if (caseDef.extraCheck) {
        mismatches.push(...caseDef.extraCheck(json));
      }
    }

    if (caseDef.dbCheck) {
      const check = caseDef.dbCheck;
      const sql = buildJsonRowSql(check.table, check.where, check.columns);
      const row = queryJsonRow(env, secrets, dbCreds, sql);
      const rowMismatches = diffAllow(row, check.expect, check.allow ?? []).map((m) =>
        m.replace('php=', 'actual=').replace('next=', 'expected=')
      );
      for (const m of rowMismatches) mismatches.push(`dbCheck[${check.label}] ${m}`);
    }

    return {
      endpoint: caseDef.name,
      mode: 'next-only-self-consistency',
      ok: mismatches.length === 0,
      mismatches,
      nextStatus: resp.status,
      nextBody: json,
    };
  } catch (err) {
    return {
      endpoint: caseDef.name,
      mode: 'next-only-self-consistency',
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

    buildContracts(); // PHASE 3 BATCH 2 — see this function's own doc comment.
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

    // PHASE 3 BATCH 2 — addresses:{list,upsert,delete} run through the
    // SEPARATE runNextOnlyCase() path (see that function's own doc comment)
    // but land in the SAME `endpoints` array / same printed summary, so
    // mig-verify's "38 total covered pairs" acceptance check sees one flat
    // list — each entry's own `mode` field (absent on the 35 real diff
    // entries, `'next-only-self-consistency'` on these 3) is what keeps a
    // reader from mistaking one for the other, not a separate top-level key.
    for (const caseDef of NEXT_ONLY_CASES) {
      endpoints.push(await runNextOnlyCase(caseDef, composeEnv, secrets, dbCreds));
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

  // Strip the (potentially large) phpBody/nextBody debugging
  // payloads off PASSING entries before printing — keep them on failing
  // entries, where they're the diagnosable evidence mig-verify needs (same
  // convention parity.mjs uses for pages' phpData/nextData). PHASE 3 BATCH 2:
  // `mode` (present only on the 3 addresses next-only entries) is
  // DELIBERATELY preserved even on a passing entry — the whole point of that
  // field is that a reader must never mistake a next-only-self-consistency
  // PASS for a PHP-vs-Next parity PASS, on EITHER outcome, not just on FAIL.
  const printedEndpoints = endpoints.map((e) =>
    e.ok
      ? { endpoint: e.endpoint, ...(e.mode ? { mode: e.mode } : {}), ok: e.ok, mismatches: e.mismatches }
      : e
  );

  const output = { result, endpoints: printedEndpoints, steps, failedAt: tracker.getFailedAt() };
  console.log(JSON.stringify(output));
  process.exitCode = result === 'PASS' ? 0 : 1;
}

main();
