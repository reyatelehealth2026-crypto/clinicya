import { spawn as nodeSpawn } from 'node:child_process';

/**
 * codegen.ts — kysely-codegen invocation wrapper (plan §1.2 / §4.1).
 *
 * Introspects a live DB and writes Kysely `Database` interface types. Two
 * targets:
 *   - "master": the platform DB (zrismpsz_reya_platform)
 *   - "tenant": one tenant DB — pass --db=<name> for a real tenant
 *     (e.g. reya_tenant_0001) or a scratch DB restored from
 *     database/migration_2026-05-25_tenant_template.sql as the
 *     representative 280-table schema.
 *
 * This dev container has no reachable DB, so `runCodegen()` defaults callers
 * to --dry-run (see src/bin/codegen.ts / packages/db/README.md for the exact
 * live-DB command). Dry-run only ever prints the resolved kysely-codegen
 * invocation — it never touches child_process.spawn.
 */

export type CodegenTarget = 'master' | 'tenant';

const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';
const DEFAULT_OUT_DIR = 'src/generated';

export interface CodegenOptions {
  target: CodegenTarget;
  /** Required when target === 'tenant'. Ignored (fixed) for 'master'. */
  dbName?: string;
  dryRun?: boolean;
  outDir?: string;
}

export interface CodegenEnvLike {
  DB_HOST?: string;
  DB_USER?: string;
  DB_PASS?: string;
}

export interface ResolvedCodegenPlan {
  target: CodegenTarget;
  dbName: string;
  outFile: string;
  /** mysql://user:pass@host/db — contains real credentials, do not log directly (use redactUrl()). */
  databaseUrl: string;
  args: string[];
}

export function resolveDbName(options: Pick<CodegenOptions, 'target' | 'dbName'>): string {
  if (options.target === 'master') {
    return PLATFORM_DB_NAME;
  }
  if (!options.dbName) {
    throw new Error(
      "codegen target 'tenant' requires --db=<tenant_db_name> (e.g. --db=reya_tenant_0001, or a " +
        'scratch DB restored from database/migration_2026-05-25_tenant_template.sql). ' +
        'See packages/db/README.md.'
    );
  }
  return options.dbName;
}

export function buildDatabaseUrl(env: CodegenEnvLike, dbName: string): string {
  const host = env.DB_HOST ?? 'localhost';
  const user = env.DB_USER ?? '';
  const pass = env.DB_PASS ?? '';
  return `mysql://${encodeURIComponent(user)}:${encodeURIComponent(pass)}@${host}/${dbName}`;
}

/** Masks the password portion of a mysql:// URL for safe logging. */
export function redactUrl(url: string): string {
  return url.replace(/:\/\/([^:/@]*):([^@]*)@/, '://$1:***@');
}

export function planCodegen(options: CodegenOptions, env: CodegenEnvLike = {}): ResolvedCodegenPlan {
  const dbName = resolveDbName(options);
  const databaseUrl = buildDatabaseUrl(env, dbName);
  const outDir = options.outDir ?? DEFAULT_OUT_DIR;
  const outFile = `${outDir}/${options.target}-db.d.ts`;
  // Deliberately no flag forcing generated property names into camel
  // case: they stay snake_case, matching the real column names every raw
  // `sql` tag in this codebase already targets (see README.md "Regenerating
  // the types" for the decision record — this is intentional, not an
  // omission).
  const args = ['--url', databaseUrl, '--dialect', 'mysql', '--out-file', outFile];
  return { target: options.target, dbName, outFile, databaseUrl, args };
}

export interface RunCodegenDeps {
  spawnProcess?: typeof nodeSpawn;
  log?: (message: string) => void;
}

export interface RunCodegenResult {
  ran: boolean;
  plan: ResolvedCodegenPlan;
}

export async function runCodegen(
  options: CodegenOptions,
  env: CodegenEnvLike = {},
  deps: RunCodegenDeps = {}
): Promise<RunCodegenResult> {
  const plan = planCodegen(options, env);
  const log = deps.log ?? console.log;
  const displayArgs = plan.args.map((arg) => (arg === plan.databaseUrl ? redactUrl(arg) : arg));

  log(`[codegen] target=${plan.target} db=${plan.dbName} out=${plan.outFile}`);
  log(`[codegen] kysely-codegen ${displayArgs.join(' ')}`);

  if (options.dryRun) {
    log('[codegen] --dry-run: not invoking kysely-codegen (no live DB in this container).');
    return { ran: false, plan };
  }

  const spawnFn = deps.spawnProcess ?? nodeSpawn;
  await new Promise<void>((resolve, reject) => {
    const child = spawnFn('kysely-codegen', plan.args, { stdio: 'inherit', shell: true });
    child.on('error', reject);
    child.on('exit', (code) => {
      if (code === 0) {
        resolve();
      } else {
        reject(new Error(`kysely-codegen exited with code ${String(code)}`));
      }
    });
  });

  return { ran: true, plan };
}

const USAGE = `Usage: codegen <master|tenant> [--db=<tenant_db_name>] [--dry-run] [--out-dir=<dir>]

  master                 Introspect the platform DB (zrismpsz_reya_platform).
  tenant --db=<name>     Introspect one tenant DB, or a scratch DB restored
                          from database/migration_2026-05-25_tenant_template.sql
                          as the representative 280-table schema.
  --dry-run               Print the resolved kysely-codegen command and exit
                          without connecting to any DB.
  --out-dir=<dir>         Override output directory (default: src/generated).

Requires a reachable DB (DB_HOST/DB_USER/DB_PASS). See packages/db/README.md
for the exact live-DB run instructions — not runnable against a live DB in
the dev-container this was authored in.`;

export function parseCodegenArgs(argv: readonly string[]): CodegenOptions {
  const positional = argv.filter((a) => !a.startsWith('--'));
  const target = positional[0];
  if (target !== 'master' && target !== 'tenant') {
    throw new Error(`codegen target must be "master" or "tenant", got ${JSON.stringify(target ?? '')}`);
  }

  const options: CodegenOptions = { target };
  for (const raw of argv) {
    if (raw === '--dry-run') {
      options.dryRun = true;
    } else if (raw.startsWith('--db=')) {
      options.dbName = raw.slice('--db='.length);
    } else if (raw.startsWith('--out-dir=')) {
      options.outDir = raw.slice('--out-dir='.length);
    }
  }
  return options;
}

export async function runCodegenCli(argv: readonly string[] = process.argv.slice(2)): Promise<void> {
  if (argv.length === 0 || argv.includes('--help') || argv.includes('-h')) {
    console.log(USAGE);
    return;
  }
  const options = parseCodegenArgs(argv);
  await runCodegen(options, process.env);
}
