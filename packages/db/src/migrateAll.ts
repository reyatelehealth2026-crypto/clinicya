import { createHash } from 'node:crypto';
import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { sql } from 'kysely';
import { getMasterDb } from './masterPool';
import { tenantPoolRegistry } from './tenantPoolRegistry';

/**
 * migrateAll.ts — migrate-all runner (plan §4.1 "Schema governance").
 *
 * Applies committed tenant-schema migration files to every tenant DB (or one,
 * via --tenant), recording each application in the EXISTING `tenant_migrations`
 * ledger table in the master DB (database/migration_2026-05-25_platform_master.sql)
 * so this can safely resume/rerun (idempotent — already-applied files are skipped).
 *
 * `migrateAll()` itself is pure/injectable (MigrateAllDeps) so it can be unit
 * tested without a filesystem or a real DB — see tests/migrateAll.test.ts.
 * `createDefaultMigrateAllDeps()` wires the real fs + DB implementations for
 * actual CLI use (see src/bin/migrate-all.ts) — NOT exercised against a live
 * DB in this batch (no reachable DB in this dev container).
 */

export type MigrationStatus = 'applied' | 'failed' | 'skipped';

export interface MigrationFile {
  /** e.g. "migration_2026-05-24_documents.sql" — matches tenant_migrations.migration_file. */
  filename: string;
  sql: string;
  /** sha256 hex of `sql` — matches tenant_migrations.checksum (drift detection). */
  checksum: string;
}

export interface TenantTarget {
  id: number;
  dbName: string;
  status?: string;
}

export interface MigrateAllOptions {
  /** Limit the run to a single tenant id. Omit to run against every tenant `listTenants()` returns. */
  tenantId?: number;
  /** Compute + report the plan; never call applyMigration/recordMigration. */
  dryRun?: boolean;
  /** Keep migrating remaining tenants/files after a failure instead of aborting the whole run. */
  continueOnError?: boolean;
}

export interface LedgerEntry {
  tenantId: number;
  migrationFile: string;
  checksum: string;
  executionMs: number;
  status: MigrationStatus;
  errorMessage?: string;
  appliedBy?: number | null;
}

export interface MigrateAllDeps {
  listTenants(): Promise<TenantTarget[]>;
  loadMigrations(): Promise<MigrationFile[]>;
  /** Filenames already recorded 'applied' for this tenant in the tenant_migrations ledger. */
  getAppliedMigrationFiles(tenantId: number): Promise<Set<string>>;
  applyMigration(target: TenantTarget, migration: MigrationFile): Promise<{ executionMs: number }>;
  recordMigration(entry: LedgerEntry): Promise<void>;
}

export interface MigrationPlanItem {
  tenantId: number;
  dbName: string;
  migrationFile: string;
}

export interface MigrateAllResult {
  options: { dryRun: boolean; continueOnError: boolean; tenantId?: number };
  /** Every (tenant, migration) pair that is pending (not yet applied) — populated even in --dry-run. */
  planned: MigrationPlanItem[];
  applied: MigrationPlanItem[];
  skipped: MigrationPlanItem[];
  failed: Array<MigrationPlanItem & { error: string }>;
  /** true when the run stopped early because of a failure without --continue-on-error. */
  aborted: boolean;
}

export async function migrateAll(options: MigrateAllOptions, deps: MigrateAllDeps): Promise<MigrateAllResult> {
  const dryRun = options.dryRun ?? false;
  const continueOnError = options.continueOnError ?? false;

  const result: MigrateAllResult = {
    options: { dryRun, continueOnError, tenantId: options.tenantId },
    planned: [],
    applied: [],
    skipped: [],
    failed: [],
    aborted: false,
  };

  const allTenants = await deps.listTenants();
  const tenants =
    options.tenantId === undefined ? allTenants : allTenants.filter((t) => t.id === options.tenantId);

  const migrations = await deps.loadMigrations();

  tenantLoop: for (const tenant of tenants) {
    const applied = await deps.getAppliedMigrationFiles(tenant.id);

    for (const migration of migrations) {
      if (applied.has(migration.filename)) {
        result.skipped.push({ tenantId: tenant.id, dbName: tenant.dbName, migrationFile: migration.filename });
        continue;
      }

      const planItem: MigrationPlanItem = {
        tenantId: tenant.id,
        dbName: tenant.dbName,
        migrationFile: migration.filename,
      };
      result.planned.push(planItem);

      if (dryRun) {
        continue;
      }

      try {
        const { executionMs } = await deps.applyMigration(tenant, migration);
        await deps.recordMigration({
          tenantId: tenant.id,
          migrationFile: migration.filename,
          checksum: migration.checksum,
          executionMs,
          status: 'applied',
        });
        result.applied.push(planItem);
      } catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        result.failed.push({ ...planItem, error: message });

        try {
          await deps.recordMigration({
            tenantId: tenant.id,
            migrationFile: migration.filename,
            checksum: migration.checksum,
            executionMs: 0,
            status: 'failed',
            errorMessage: message,
          });
        } catch {
          // A ledger-write failure must never crash the runner — the failure
          // is already captured in result.failed above.
        }

        if (!continueOnError) {
          result.aborted = true;
          break tenantLoop;
        }
      }
    }
  }

  return result;
}

// ---------------------------------------------------------------------------
// CLI argument parsing (pure — no process/env access, easy to unit test).
// ---------------------------------------------------------------------------

export function parseMigrateAllArgs(argv: readonly string[]): MigrateAllOptions {
  const options: MigrateAllOptions = {};

  for (const raw of argv) {
    if (raw === '--dry-run') {
      options.dryRun = true;
    } else if (raw === '--continue-on-error') {
      options.continueOnError = true;
    } else if (raw.startsWith('--tenant=')) {
      const value = raw.slice('--tenant='.length);
      const id = Number.parseInt(value, 10);
      if (!Number.isInteger(id) || id <= 0 || String(id) !== value.trim()) {
        throw new Error(`--tenant must be a positive integer, got "${value}"`);
      }
      options.tenantId = id;
    } else if (raw === '--tenant') {
      throw new Error('--tenant requires a value, e.g. --tenant=42');
    } else if (raw === '--help' || raw === '-h') {
      // No-op here — runMigrateAllCli() handles printing usage before parsing.
    } else {
      throw new Error(`Unknown migrate-all argument: ${raw}`);
    }
  }

  return options;
}

// ---------------------------------------------------------------------------
// Default (real fs + DB) dependency wiring — used by the CLI entrypoint.
// Not exercised against a live DB in this batch; see packages/db/README.md.
// ---------------------------------------------------------------------------

const DEFAULT_MIGRATIONS_DIR = path.join(__dirname, '..', 'migrations', 'tenant');

export async function computeChecksum(sql: string): Promise<string> {
  return createHash('sha256').update(sql, 'utf8').digest('hex');
}

/**
 * Naive `;`-terminated statement splitter (drops full-line `--` comments
 * first). Safe here because the schema has no triggers/stored procedures
 * (plan Context: "ไม่มี trigger/stored procedure ใน DB เลย") — no statement
 * ever needs a DELIMITER change, so a real SQL parser is unnecessary.
 */
export function splitSqlStatements(sql: string): string[] {
  const withoutLineComments = sql
    .split('\n')
    .filter((line) => !line.trim().startsWith('--'))
    .join('\n');
  return withoutLineComments
    .split(';')
    .map((statement) => statement.trim())
    .filter((statement) => statement.length > 0);
}

export async function readMigrationsFromDir(dir: string): Promise<MigrationFile[]> {
  let entries: string[];
  try {
    entries = await readdir(dir);
  } catch {
    // migrations/tenant/ is a placeholder in this batch — populated during
    // the schema-governance drift audit (plan §4.1), out of scope here.
    return [];
  }

  const sqlFiles = entries.filter((f) => f.endsWith('.sql')).sort();
  const files: MigrationFile[] = [];
  for (const filename of sqlFiles) {
    const sqlText = await readFile(path.join(dir, filename), 'utf8');
    files.push({ filename, sql: sqlText, checksum: await computeChecksum(sqlText) });
  }
  return files;
}

export async function listTenantsFromMaster(): Promise<TenantTarget[]> {
  const master = getMasterDb();
  const result = await sql<{ id: number; db_name: string; status: string }>`
    SELECT id, db_name, status FROM tenants WHERE status IN ('active', 'pending_setup') ORDER BY id ASC
  `.execute(master);
  return result.rows.map((r) => ({ id: r.id, dbName: r.db_name, status: r.status }));
}

export async function getAppliedMigrationFilesFromLedger(tenantId: number): Promise<Set<string>> {
  const master = getMasterDb();
  const result = await sql<{ migration_file: string }>`
    SELECT migration_file FROM tenant_migrations WHERE tenant_id = ${tenantId} AND status = 'applied'
  `.execute(master);
  return new Set(result.rows.map((r) => r.migration_file));
}

export async function applyMigrationViaPool(
  target: TenantTarget,
  migration: MigrationFile
): Promise<{ executionMs: number }> {
  const db = await tenantPoolRegistry.getTenantDb(target.id);
  const statements = splitSqlStatements(migration.sql);
  const start = Date.now();
  for (const statement of statements) {
    // `sql.raw()`, not the tagged template — these come from our own
    // migration files verbatim (no caller-supplied interpolation), and DDL
    // can't be parameter-bound anyway.
    await sql.raw(statement).execute(db);
  }
  return { executionMs: Date.now() - start };
}

/** INSERT ... ON DUPLICATE KEY UPDATE against tenant_migrations (unique on tenant_id + migration_file). */
export async function recordMigrationToLedger(entry: LedgerEntry): Promise<void> {
  const master = getMasterDb();
  await sql`
    INSERT INTO tenant_migrations
      (tenant_id, migration_file, checksum, execution_ms, status, error_message, applied_by)
    VALUES (${entry.tenantId}, ${entry.migrationFile}, ${entry.checksum}, ${entry.executionMs}, ${entry.status}, ${entry.errorMessage ?? null}, ${entry.appliedBy ?? null})
    ON DUPLICATE KEY UPDATE
      checksum = VALUES(checksum),
      execution_ms = VALUES(execution_ms),
      status = VALUES(status),
      error_message = VALUES(error_message),
      applied_at = CURRENT_TIMESTAMP
  `.execute(master);
}

export function createDefaultMigrateAllDeps(): MigrateAllDeps {
  return {
    listTenants: listTenantsFromMaster,
    loadMigrations: () => readMigrationsFromDir(DEFAULT_MIGRATIONS_DIR),
    getAppliedMigrationFiles: getAppliedMigrationFilesFromLedger,
    applyMigration: applyMigrationViaPool,
    recordMigration: recordMigrationToLedger,
  };
}

const USAGE = `Usage: migrate-all [--tenant=<id>] [--dry-run] [--continue-on-error]

  --tenant=<id>         Limit the run to a single tenant id (default: every
                         tenant with status active|pending_setup in
                         master.tenants).
  --dry-run              Compute + print the migration plan; apply nothing
                         and write nothing to the tenant_migrations ledger.
  --continue-on-error    Keep migrating remaining tenants/files after a
                         failure instead of aborting the whole run (a
                         'failed' row is still recorded in the ledger for
                         the failing migration either way).

Requires a reachable master DB (DB_HOST/DB_USER/DB_PASS) — this cannot be
run against a live DB in the dev-container this was authored in. See
packages/db/README.md for exact run instructions once one is available.`;

export async function runMigrateAllCli(argv: readonly string[] = process.argv.slice(2)): Promise<void> {
  if (argv.includes('--help') || argv.includes('-h')) {
    console.log(USAGE);
    return;
  }

  const options = parseMigrateAllArgs(argv);
  const deps = createDefaultMigrateAllDeps();
  const result = await migrateAll(options, deps);
  console.log(JSON.stringify(result, null, 2));

  if (result.failed.length > 0) {
    process.exitCode = 1;
  }
}
