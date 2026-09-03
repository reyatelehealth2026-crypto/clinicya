#!/usr/bin/env node
// infra/e2e/lib/harness-common.mjs
//
// Shared docker-compose-lifecycle / secrets / SQL-exec helpers used by BOTH
// infra/e2e/run.mjs (Phase 1 batch 3 — session-bridge mechanics proof) and
// infra/e2e/parity.mjs (Phase 2 batch 1 — PHP/Next data-point parity).
// Extracted here (Phase 2 batch 1, per that batch's brief: "factor shared
// helpers out of run.mjs into a small common module if that keeps both
// scripts readable") so the two harnesses don't drift on the mechanics they
// genuinely share — process spawning, container health-wait, `mysql -uroot`
// piping, config.php parsing, secret generation, PHP bcrypt hashing.
//
// This module is deliberately NOT the place for:
//   - run.mjs's own bridge-specific steps (login/logout/probe-page fetches)
//   - parity.mjs's own fixture seeding / page extraction / diffing
// Both stay in their own files — only the generic "stand up a
// mariadb+redis+php stack and talk to it" mechanics live here.
//
// Every exported step-tracking helper takes an explicit `tracker` (the
// object createStepTracker() returns) rather than closing over module-level
// state, so two independent harness runs (e.g. a future test importing both
// run.mjs's and parity.mjs's logic in the same process) never share a
// `steps`/`failedAt` object by accident.

import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath, URL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const REPO_ROOT = path.resolve(__dirname, '../../..');

// ---------------------------------------------------------------------------
// Step-tracking — same {ok, message?, detail?} shape run.mjs's JSON output
// already documents, factored out so parity.mjs's `steps` envelope reads
// identically.
// ---------------------------------------------------------------------------

export class HarnessError extends Error {
  constructor(step, message, extra) {
    super(message);
    this.step = step;
    this.extra = extra;
  }
}

export function createStepTracker() {
  const steps = {};
  let failedAt = null;

  function markOk(step, extra) {
    steps[step] = { ok: true, ...(extra !== undefined ? { detail: extra } : {}) };
  }

  /** Always throws (HarnessError) — callers rely on this for control flow, same as run.mjs's original fail(). */
  function fail(step, message, extra) {
    steps[step] = { ok: false, message, ...(extra ? { detail: extra } : {}) };
    if (failedAt === null) failedAt = step;
    throw new HarnessError(step, message, extra);
  }

  /** Sets failedAt (if not already set) WITHOUT throwing — for a caller's own catch-all branch that already has an Error from somewhere other than fail() (e.g. a thrown error outside any tracked step) and just needs the bookkeeping updated. */
  function setFailedAt(step) {
    if (failedAt === null) failedAt = step;
  }

  return { steps, markOk, fail, setFailedAt, getFailedAt: () => failedAt };
}

// ---------------------------------------------------------------------------
// Process helpers
// ---------------------------------------------------------------------------

export function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export function run(cmd, args, opts = {}) {
  return spawnSync(cmd, args, { cwd: REPO_ROOT, encoding: 'utf8', ...opts });
}

export function runOrThrow(tracker, step, cmd, args, opts = {}) {
  const result = run(cmd, args, opts);
  if (result.error) {
    tracker.fail(step, `${cmd} failed to spawn: ${result.error.message}`, { cmd, args });
  }
  if (result.status !== 0) {
    tracker.fail(step, `${cmd} exited ${result.status}`, {
      cmd,
      args,
      stdout: (result.stdout || '').slice(-4000),
      stderr: (result.stderr || '').slice(-4000),
    });
  }
  return result;
}

/** Returns a `(...rest) => ['compose', '-p', project, '-f', composeFile, ...rest]` closure — one per harness, since run.mjs and parity.mjs use different project names (isolated `docker compose down -v` blast radius) but the SAME infra/e2e/docker-compose.yml file. */
export function makeComposeArgs(composeFile, project) {
  return (...rest) => ['compose', '-p', project, '-f', composeFile, ...rest];
}

export function composeUp(tracker, composeArgsFn, env, step = 'compose_up') {
  console.error('[e2e] docker compose up -d --build ...');
  runOrThrow(tracker, step, 'docker', composeArgsFn('up', '-d', '--build'), { env });
  tracker.markOk(step);
}

/** Best-effort — teardown must never throw past this point (finally block). */
export function composeDown(composeArgsFn, env) {
  console.error('[e2e] docker compose down -v ...');
  run('docker', composeArgsFn('down', '-v', '--remove-orphans'), { env, stdio: 'inherit' });
}

export async function waitContainerHealthy(tracker, containerName, step, timeoutMs = 90_000) {
  const started = Date.now();
  for (;;) {
    const result = run('docker', ['inspect', '--format', '{{.State.Health.Status}}', containerName]);
    const status = (result.stdout || '').trim();
    if (status === 'healthy') {
      tracker.markOk(step);
      return;
    }
    if (Date.now() - started > timeoutMs) {
      tracker.fail(step, `${containerName} did not become healthy within ${timeoutMs}ms`, {
        lastStatus: status || result.stderr?.trim(),
      });
    }
    await sleep(1500);
  }
}

// ---------------------------------------------------------------------------
// SQL seeding — always via `docker compose exec -T mariadb mysql -uroot`
// (root credentials, harness-internal only) so grants/DDL never depend on
// the app user's privileges existing yet.
// ---------------------------------------------------------------------------

export function execSql(tracker, composeArgsFn, env, rootPassword, sqlText, extraArgs = [], step = 'exec_sql') {
  const result = spawnSync(
    'docker',
    composeArgsFn('exec', '-T', '-e', `MYSQL_PWD=${rootPassword}`, 'mariadb', 'mysql', '-uroot', ...extraArgs),
    { cwd: REPO_ROOT, encoding: 'utf8', input: sqlText, env }
  );
  if (result.status !== 0) {
    tracker.fail(step, `mysql exec failed (exit ${result.status})`, {
      stderr: (result.stderr || '').slice(-4000),
    });
  }
  return result;
}

export function querySql(tracker, composeArgsFn, env, rootPassword, sqlText, dbName, step = 'query_sql') {
  const args = dbName ? ['-N', '-B', dbName] : ['-N', '-B'];
  const result = execSql(tracker, composeArgsFn, env, rootPassword, sqlText, args, step);
  return result.stdout.trim();
}

// ---------------------------------------------------------------------------
// config/config.php parsing — DB_NAME/DB_USER/DB_PASS. NEVER write these
// values into any tracked file (this repo's secrets-discipline guardrail) —
// they only ever live in this process's memory/env, sourced fresh from the
// local (gitignored) config/config.php on every run.
// ---------------------------------------------------------------------------

export function parseLocalConfigPhp(tracker, step = 'parse_config_php') {
  const configPath = path.join(REPO_ROOT, 'config/config.php');
  if (!existsSync(configPath)) {
    tracker.fail(
      step,
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
    tracker.fail(step, 'Could not parse DB_NAME/DB_USER/DB_PASS out of config/config.php', {
      found: { name, user, passPresent: pass !== null },
    });
  }
  tracker.markOk(step, { name, user });
  return { name, user, pass };
}

// ---------------------------------------------------------------------------
// Secrets — generated fresh every run, never written to disk.
// ---------------------------------------------------------------------------

export function generateSecrets(extra = {}) {
  return {
    mariadbRootPassword: randomBytes(24).toString('base64url'),
    sessionBridgeHmacSecret: randomBytes(32).toString('hex'),
    adminPassword: randomBytes(18).toString('base64url'),
    ...extra,
  };
}

// ---------------------------------------------------------------------------
// PHP-side helpers
// ---------------------------------------------------------------------------

/** Generates a REAL PHP bcrypt hash by invoking password_hash() inside the harness's own php container (php:8.2-apache — the production runtime), never bcryptjs and never hand-written. */
export function generatePhpBcryptHash(tracker, composeArgsFn, env, plainPassword, step = 'generate_php_hash') {
  const result = spawnSync(
    'docker',
    composeArgsFn('exec', '-T', 'php', 'php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', '--', plainPassword),
    { cwd: REPO_ROOT, encoding: 'utf8', env }
  );
  if (result.status !== 0 || !result.stdout || !result.stdout.startsWith('$2y$')) {
    tracker.fail(step, 'php -r password_hash(...) did not return a $2y$ bcrypt hash', {
      status: result.status,
      stdout: result.stdout,
      stderr: (result.stderr || '').slice(-2000),
    });
  }
  tracker.markOk(step);
  return result.stdout;
}

export async function waitHttpReachable(tracker, url, step, timeoutMs = 120_000) {
  const started = Date.now();
  for (;;) {
    try {
      const resp = await fetch(url, { redirect: 'manual' });
      // ANY response (including a 500 from a not-yet-seeded DB) means the
      // upstream is up and serving.
      tracker.markOk(step, resp.status);
      return resp;
    } catch {
      if (Date.now() - started > timeoutMs) {
        tracker.fail(step, `${url} did not answer HTTP within ${timeoutMs}ms`);
      }
      await sleep(1000);
    }
  }
}

export function isRedirectTo(resp, substring) {
  if (resp.status < 300 || resp.status >= 400) {
    return false;
  }
  const location = resp.headers.get('location') || '';
  return location.includes(substring);
}

/**
 * Low-level HTTP request helper used wherever a caller needs an explicit
 * `Host` header that differs from the URL's own authority (host×path tenant
 * routing — bootstrap/resolve_subdomain.php on the PHP side,
 * @reya/tenant's resolveTenant() via apps/admin's proxy.ts on the Next
 * side). Node's global `fetch()` (undici) silently ignores/overrides a
 * caller-supplied `Host` header — verified empirically against this repo's
 * own Node 22 runtime (an explicit `headers: { Host: '...' }` on a `fetch()`
 * call is NOT what ends up on the wire; the request always carries the
 * URL's own host:port instead). `node:http`'s `http.request()` has no such
 * restriction, so this wraps that instead. Deliberately NOT a fetch()
 * polyfill — only the handful of fields infra/e2e/parity.mjs actually needs
 * (status, headers incl. a real `set-cookie` array, text body). Never
 * follows redirects (same "manual" semantics callers get from
 * `fetch(url, { redirect: 'manual' })`).
 */
export function httpRequest({ url, method = 'GET', headers = {}, body = null }) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const req = http.request(
      {
        protocol: u.protocol,
        hostname: u.hostname,
        port: u.port,
        path: `${u.pathname}${u.search}`,
        method,
        headers,
      },
      (res) => {
        const chunks = [];
        res.on('data', (chunk) => chunks.push(chunk));
        res.on('end', () => {
          resolve({
            status: res.statusCode,
            headers: res.headers, // lowercase keys; `set-cookie` is always an array (node:http's own normalization).
            text: Buffer.concat(chunks).toString('utf8'),
          });
        });
      }
    );
    req.on('error', reject);
    if (body !== null) {
      req.write(body);
    }
    req.end();
  });
}

// ---------------------------------------------------------------------------
// PHASE 3 BATCH 3 (mig-infra) — multipart/form-data support. NEW plumbing,
// not a copy of httpRequest()'s JSON-body path above: `upload_slip`
// (infra/e2e/api-parity.mjs's `checkout-order:upload_slip` case) is the
// FIRST file-upload endpoint ported anywhere in this migration effort, and
// every prior ENDPOINT_CASES entry (batch 1/2) sends either a query string
// (GET) or a single `JSON.stringify(...)` body (POST) — neither shape can
// carry a binary file part or a `multipart/form-data; boundary=...`
// Content-Type. This is hand-rolled (no `form-data`/`undici` FormData
// dependency pulled into infra/e2e/, which has its own package.json
// separate from the workspace — see api-extract.mjs's own module doc on why
// infra/e2e/ resolves packages differently from apps/*/packages/*) — RFC
// 7578's wire format is simple enough that a ~20-line encoder is more
// auditable than a new dependency for a single call site.
// ---------------------------------------------------------------------------

const MULTIPART_BOUNDARY_PREFIX = 'ReyaE2eMultipartBoundary';

/**
 * Encodes `fields` (plain string form fields) + an optional `file` part
 * (`{name, filename, contentType, data: Buffer}`) as a single
 * `multipart/form-data` body, RFC 7578-shaped (CRLF line endings, each part
 * preceded by `--boundary`, the whole body closed by `--boundary--`). Field
 * order is insertion order (`Object.entries`) — PHP's `$_POST`/`$_FILES`
 * parsing does not care about part order, so this is not a compatibility
 * concern, just deterministic output for a stable request body across runs.
 * Returns `{ boundary, body }` — `body` is a single concatenated `Buffer`
 * (binary-safe for the file part; `httpRequest()`'s `req.write(body)`
 * already accepts a `Buffer` with no changes needed there).
 */
export function buildMultipartBody(fields, file) {
  const boundary = `${MULTIPART_BOUNDARY_PREFIX}${randomBytes(16).toString('hex')}`;
  const parts = [];

  for (const [key, value] of Object.entries(fields ?? {})) {
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${key}"\r\n\r\n${value}\r\n`, 'utf8'));
  }

  if (file) {
    parts.push(
      Buffer.from(
        `--${boundary}\r\nContent-Disposition: form-data; name="${file.name}"; filename="${file.filename}"\r\n` +
          `Content-Type: ${file.contentType}\r\n\r\n`,
        'utf8'
      )
    );
    parts.push(file.data);
    parts.push(Buffer.from('\r\n', 'utf8'));
  }

  parts.push(Buffer.from(`--${boundary}--\r\n`, 'utf8'));
  return { boundary, body: Buffer.concat(parts) };
}

/**
 * Convenience wrapper over `httpRequest()` for a multipart POST: builds the
 * body via `buildMultipartBody()`, sets `Content-Type: multipart/form-data;
 * boundary=...` and an explicit `Content-Length` (avoids chunked
 * transfer-encoding — `php:8.2-apache`'s multipart parser and Next's
 * `request.formData()` both handle chunked bodies fine, but a known
 * `Content-Length` is the more representative shape for a real
 * browser/LIFF-webview `fetch(url, {body: new FormData()})` call, which
 * always sends one).
 */
export function httpRequestMultipart({ url, method = 'POST', headers = {}, fields = {}, file = null }) {
  const { boundary, body } = buildMultipartBody(fields, file);
  return httpRequest({
    url,
    method,
    headers: {
      ...headers,
      'Content-Type': `multipart/form-data; boundary=${boundary}`,
      'Content-Length': String(body.length),
    },
    body,
  });
}

/**
 * The smallest valid PNG that exists (a 1x1 transparent pixel, 68 bytes) —
 * the well-known public-domain "tiny PNG" test fixture, not a real image
 * asset. Used as `upload_slip`'s uploaded file: `handleUploadSlip()`
 * (api/checkout.php) only validates `$_FILES['slip']['type']` (must be one
 * of image/jpeg|png|gif|webp) and `['size']` (<=5MB) before saving it —
 * there is no image-content/dimension validation on either stack, so a
 * minimal PNG exercises the real code path with no need for a larger fixture
 * asset committed to the repo.
 */
export const TINY_PNG_FIXTURE = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
  'base64'
);
