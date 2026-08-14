#!/usr/bin/env tsx
/**
 * genDocNumber.concurrency.ts — THE LIVE GATE for genDocNumber() (plan §3
 * Phase 5, this batch's brief). Invoked via `pnpm --filter @reya/core
 * test:live` (tsx, matching packages/db's `migrate-all`/`codegen` script
 * precedent) — deliberately NOT part of the default `test` script
 * (`vitest run`), so `pnpm turbo run test` stays fast and Docker-independent.
 *
 * What this proves that a mocked-Kysely unit test cannot: that
 * `INSERT IGNORE` + `SELECT ... FOR UPDATE` + `UPDATE` inside a real
 * InnoDB transaction, against a REAL MySQL-protocol server, actually
 * serializes 50 concurrent callers on the same
 * (line_account_id, doc_type, year_month) row with zero collisions and
 * zero gaps — a mocked connection has no real row-locking semantics to get
 * wrong in the first place, so this is the one property only a real
 * database can falsify.
 *
 * Lifecycle (mirrors infra/e2e/run.mjs's pattern):
 *   1. Generate throwaway secrets (root password), pick a project name +
 *      port, `docker compose up -d` (packages/core/tests-live/docker-compose.yml).
 *   2. Wait for the mariadb container to report `healthy`.
 *   3. Connect as root, CREATE DATABASE + the ONE `document_sequences`
 *      table (exact DDL from document_sequences.ddl.sql).
 *   4. Build a real mysql2 Pool + Kysely<TenantDB> pointed at the compose DB.
 *   5. Fire 50 concurrent `genDocNumber(db, sameLineAccountId, sameDocType)`
 *      calls via `Promise.all`.
 *   6. Assert: exactly 50 distinct returned doc numbers, whose 4-digit
 *      sequence tails form exactly {1..50} (zero collisions, zero gaps),
 *      and `document_sequences.last_seq` ends at exactly 50.
 *   7. ALWAYS tear the stack down (`docker compose down -v`) in a `finally`
 *      block, pass or fail.
 *
 * Exit code 0 on pass, 1 on any failure (bad result OR a thrown error) —
 * paste this script's stdout into the build report / runbook as the live
 * gate's evidence.
 */

import { randomBytes } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { createPool, type Pool } from 'mysql2/promise';
import { Kysely, MysqlDialect } from 'kysely';
import type { TenantDB } from '@reya/db';
import { genDocNumber } from '../src/genDocNumber';

// `__dirname` (CJS-native), NOT `import.meta.url` — packages/core/package.json
// declares `"type": "commonjs"`, and this script runs via `tsx` (same
// convention as packages/db/src/bin/migrate-all.ts / codegen.ts).
const COMPOSE_FILE = path.join(__dirname, 'docker-compose.yml');
const DDL_FILE = path.join(__dirname, 'document_sequences.ddl.sql');
const PROJECT = 'docvat-core-live';
const CONTAINER = 'docvat-core-live-mariadb';
const DB_NAME = 'reya_core_livetest';
const DB_PORT = Number(process.env.DOCVAT_LIVE_DB_PORT ?? 33073);
const CONCURRENCY = 50;
const LINE_ACCOUNT_ID = 999;
const DOC_TYPE = 'QT';

function log(msg: string): void {
  // eslint-disable-next-line no-console
  console.log(`[genDocNumber:live] ${msg}`);
}

function composeArgs(...rest: string[]): string[] {
  return ['compose', '-p', PROJECT, '-f', COMPOSE_FILE, ...rest];
}

function run(cmd: string, args: string[], env: NodeJS.ProcessEnv): { status: number | null; stdout: string; stderr: string } {
  const result = spawnSync(cmd, args, { encoding: 'utf8', env });
  return { status: result.status, stdout: result.stdout ?? '', stderr: result.stderr ?? '' };
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitHealthy(env: NodeJS.ProcessEnv, timeoutMs = 90_000): Promise<void> {
  const started = Date.now();
  for (;;) {
    const result = run('docker', ['inspect', '--format', '{{.State.Health.Status}}', CONTAINER], env);
    const status = result.stdout.trim();
    if (status === 'healthy') return;
    if (Date.now() - started > timeoutMs) {
      throw new Error(`${CONTAINER} did not become healthy within ${timeoutMs}ms (last status: ${status || result.stderr.trim()})`);
    }
    await sleep(1500);
  }
}

interface Outcome {
  pass: boolean;
  distinctCount: number;
  expectedCount: number;
  gaps: number[];
  collisions: string[];
  lastSeqInDb: number;
  numbers: string[];
}

async function main(): Promise<Outcome> {
  const rootPassword = randomBytes(24).toString('hex'); // throwaway, never written to a tracked file
  const env: NodeJS.ProcessEnv = {
    ...process.env,
    DOCVAT_LIVE_ROOT_PASSWORD: rootPassword,
    DOCVAT_LIVE_DB_NAME: DB_NAME,
    DOCVAT_LIVE_DB_PORT: String(DB_PORT),
  };

  log(`docker compose up -d (project=${PROJECT}, port=${DB_PORT}) ...`);
  const up = run('docker', composeArgs('up', '-d'), env);
  if (up.status !== 0) {
    throw new Error(`docker compose up failed (exit ${up.status}): ${up.stderr}`);
  }

  log('waiting for mariadb healthcheck ...');
  await waitHealthy(env);
  log('mariadb is healthy.');

  let rootPool: Pool | undefined;
  let appPool: Pool | undefined;
  try {
    rootPool = createPool({ host: '127.0.0.1', port: DB_PORT, user: 'root', password: rootPassword, database: DB_NAME, multipleStatements: true });
    const ddl = readFileSync(DDL_FILE, 'utf8');
    log('creating document_sequences table ...');
    await rootPool.query(ddl);
    log('table created.');

    appPool = createPool({ host: '127.0.0.1', port: DB_PORT, user: 'root', password: rootPassword, database: DB_NAME });
    const db = new Kysely<TenantDB>({ dialect: new MysqlDialect({ pool: appPool }) });

    log(`firing ${CONCURRENCY} concurrent genDocNumber() calls (line_account_id=${LINE_ACCOUNT_ID}, doc_type=${DOC_TYPE}) ...`);
    const results = await Promise.all(
      Array.from({ length: CONCURRENCY }, () => genDocNumber(db, LINE_ACCOUNT_ID, DOC_TYPE))
    );

    const distinct = new Set(results);
    const tails = results.map((n) => Number(n.slice(-4)));
    const expectedTails = new Set(Array.from({ length: CONCURRENCY }, (_, i) => i + 1));
    const tailSet = new Set(tails);
    const gaps = [...expectedTails].filter((t) => !tailSet.has(t));
    const collisions = [...distinct].length === results.length ? [] : results.filter((n, i) => results.indexOf(n) !== i);

    const [seqRows] = await rootPool.query(
      'SELECT last_seq FROM document_sequences WHERE line_account_id = ? AND doc_type = ?',
      [LINE_ACCOUNT_ID, DOC_TYPE]
    );
    const seqRow = (seqRows as Array<{ last_seq: number }>)[0];
    const lastSeqInDb = Number(seqRow?.last_seq ?? -1);

    await db.destroy();

    const pass = distinct.size === CONCURRENCY && gaps.length === 0 && collisions.length === 0 && lastSeqInDb === CONCURRENCY;

    return {
      pass,
      distinctCount: distinct.size,
      expectedCount: CONCURRENCY,
      gaps,
      collisions,
      lastSeqInDb,
      numbers: results.sort(),
    };
  } finally {
    await rootPool?.end().catch(() => {});
    await appPool?.end().catch(() => {});
  }
}

async function teardown(env: NodeJS.ProcessEnv): Promise<void> {
  log('docker compose down -v (teardown, always runs) ...');
  run('docker', composeArgs('down', '-v', '--remove-orphans'), env);
}

(async () => {
  const env: NodeJS.ProcessEnv = { ...process.env, DOCVAT_LIVE_DB_NAME: DB_NAME, DOCVAT_LIVE_DB_PORT: String(DB_PORT) };
  let outcome: Outcome | undefined;
  let failure: unknown;
  try {
    outcome = await main();
  } catch (err) {
    failure = err;
  } finally {
    await teardown(env);
  }

  if (failure) {
    log(`FAILED with an error: ${failure instanceof Error ? failure.stack ?? failure.message : String(failure)}`);
    process.exitCode = 1;
    return;
  }

  const o = outcome!;
  log(`distinct document numbers: ${o.distinctCount}/${o.expectedCount}`);
  log(`gaps in sequence tail {1..${CONCURRENCY}}: ${o.gaps.length === 0 ? 'NONE' : JSON.stringify(o.gaps)}`);
  log(`collisions: ${o.collisions.length === 0 ? 'NONE' : JSON.stringify(o.collisions)}`);
  log(`document_sequences.last_seq in DB: ${o.lastSeqInDb} (expected ${CONCURRENCY})`);
  log(`sample numbers: ${o.numbers.slice(0, 5).join(', ')} ... ${o.numbers.slice(-5).join(', ')}`);
  log(o.pass ? `PASS — ${CONCURRENCY}/${CONCURRENCY} distinct, zero collisions, zero gaps, last_seq=${CONCURRENCY}.` : 'FAIL — see details above.');
  process.exitCode = o.pass ? 0 : 1;
})();
