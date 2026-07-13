import { describe, expect, it, vi } from 'vitest';
import {
  buildDatabaseUrl,
  parseCodegenArgs,
  planCodegen,
  redactUrl,
  resolveDbName,
  runCodegen,
} from '../src/codegen';

const ENV = { DB_HOST: 'db-host.internal', DB_USER: 'reya_user', DB_PASS: 'sup3r-secret' };

describe('resolveDbName', () => {
  it('resolves the master target to the hardcoded platform DB name', () => {
    expect(resolveDbName({ target: 'master' })).toBe('zrismpsz_reya_platform');
  });

  it('resolves the tenant target to the given --db name', () => {
    expect(resolveDbName({ target: 'tenant', dbName: 'reya_tenant_0001' })).toBe('reya_tenant_0001');
  });

  it('throws for the tenant target without --db', () => {
    expect(() => resolveDbName({ target: 'tenant' })).toThrow(/requires --db=/);
  });
});

describe('buildDatabaseUrl / redactUrl', () => {
  it('builds a mysql:// URL from env + db name', () => {
    expect(buildDatabaseUrl(ENV, 'reya_tenant_0001')).toBe(
      'mysql://reya_user:sup3r-secret@db-host.internal/reya_tenant_0001'
    );
  });

  it('defaults DB_HOST to localhost when unset', () => {
    expect(buildDatabaseUrl({}, 'reya_tenant_0001')).toBe('mysql://:@localhost/reya_tenant_0001');
  });

  it('redacts the password but keeps the user + host + db visible', () => {
    const url = buildDatabaseUrl(ENV, 'reya_tenant_0001');
    expect(redactUrl(url)).toBe('mysql://reya_user:***@db-host.internal/reya_tenant_0001');
  });
});

describe('planCodegen', () => {
  it('builds the full kysely-codegen argv for the master target', () => {
    const plan = planCodegen({ target: 'master' }, ENV);
    expect(plan.dbName).toBe('zrismpsz_reya_platform');
    expect(plan.outFile).toBe('src/generated/master-db.d.ts');
    expect(plan.args).toEqual([
      '--url',
      plan.databaseUrl,
      '--dialect',
      'mysql',
      '--out-file',
      'src/generated/master-db.d.ts',
    ]);
  });

  it('never includes --camel-case — generated types stay snake_case to match the raw-sql house style', () => {
    const masterPlan = planCodegen({ target: 'master' }, ENV);
    const tenantPlan = planCodegen({ target: 'tenant', dbName: 'reya_tenant_0001' }, ENV);
    expect(masterPlan.args).not.toContain('--camel-case');
    expect(tenantPlan.args).not.toContain('--camel-case');
  });

  it('honours a custom --out-dir', () => {
    const plan = planCodegen({ target: 'tenant', dbName: 'reya_tenant_0001', outDir: 'custom/dir' }, ENV);
    expect(plan.outFile).toBe('custom/dir/tenant-db.d.ts');
  });
});

describe('parseCodegenArgs', () => {
  it('parses target + --db + --dry-run + --out-dir', () => {
    expect(parseCodegenArgs(['tenant', '--db=reya_tenant_0001', '--dry-run', '--out-dir=out'])).toEqual({
      target: 'tenant',
      dbName: 'reya_tenant_0001',
      dryRun: true,
      outDir: 'out',
    });
  });

  it('parses the master target with no extra flags', () => {
    expect(parseCodegenArgs(['master'])).toEqual({ target: 'master' });
  });

  it('rejects a missing/invalid target', () => {
    expect(() => parseCodegenArgs([])).toThrow(/must be "master" or "tenant"/);
    expect(() => parseCodegenArgs(['--dry-run'])).toThrow(/must be "master" or "tenant"/);
    expect(() => parseCodegenArgs(['bogus'])).toThrow(/must be "master" or "tenant"/);
  });
});

describe('runCodegen', () => {
  it('--dry-run never invokes child_process.spawn (safe in a container with no live DB)', async () => {
    const spawnProcess = vi.fn();
    const result = await runCodegen({ target: 'master', dryRun: true }, ENV, { spawnProcess, log: () => {} });

    expect(spawnProcess).not.toHaveBeenCalled();
    expect(result.ran).toBe(false);
    expect(result.plan.dbName).toBe('zrismpsz_reya_platform');
  });

  it('never logs the raw password, even in dry-run', async () => {
    const messages: string[] = [];
    await runCodegen({ target: 'master', dryRun: true }, ENV, { spawnProcess: vi.fn(), log: (m) => messages.push(m) });

    expect(messages.join('\n')).not.toContain('sup3r-secret');
    expect(messages.join('\n')).toContain('***');
  });

  it('spawns kysely-codegen with the resolved args when not a dry-run, resolving on exit code 0', async () => {
    const handlers: Record<string, (arg?: unknown) => void> = {};
    const fakeChild = {
      on: vi.fn((event: string, handler: (arg?: unknown) => void) => {
        handlers[event] = handler;
      }),
    };
    const spawnProcess = vi.fn().mockReturnValue(fakeChild);

    const promise = runCodegen({ target: 'tenant', dbName: 'reya_tenant_0001' }, ENV, {
      spawnProcess: spawnProcess as never,
      log: () => {},
    });

    expect(spawnProcess).toHaveBeenCalledWith(
      'kysely-codegen',
      expect.arrayContaining(['--dialect', 'mysql', '--out-file', 'src/generated/tenant-db.d.ts']),
      expect.objectContaining({ stdio: 'inherit' })
    );

    handlers.exit?.(0);
    const result = await promise;
    expect(result.ran).toBe(true);
  });

  it('rejects when kysely-codegen exits non-zero', async () => {
    const handlers: Record<string, (arg?: unknown) => void> = {};
    const fakeChild = {
      on: vi.fn((event: string, handler: (arg?: unknown) => void) => {
        handlers[event] = handler;
      }),
    };
    const spawnProcess = vi.fn().mockReturnValue(fakeChild);

    const promise = runCodegen({ target: 'master' }, ENV, { spawnProcess: spawnProcess as never, log: () => {} });
    handlers.exit?.(1);

    await expect(promise).rejects.toThrow(/exited with code 1/);
  });

  it('rejects the tenant target with no --db before ever touching spawnProcess', async () => {
    const spawnProcess = vi.fn();
    await expect(runCodegen({ target: 'tenant' }, ENV, { spawnProcess })).rejects.toThrow(/requires --db=/);
    expect(spawnProcess).not.toHaveBeenCalled();
  });
});
