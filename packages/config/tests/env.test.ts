import { describe, expect, it, beforeEach } from 'vitest';
import { PLATFORM_DB_NAME, loadEnv, resetEnvCache } from '../src/env';

const VALID_SOURCE = {
  DB_HOST: 'db-host.internal',
  DB_USER: 'zrismpsz_clinicya',
  DB_PASS: 'super-secret',
  REYA_BASE_DOMAIN: 're-ya.com',
  REDIS_URL: 'redis://redis:6379',
} satisfies NodeJS.ProcessEnv;

describe('PLATFORM_DB_NAME', () => {
  it('mirrors classes/TenantContext.php::PLATFORM_DB_NAME exactly', () => {
    expect(PLATFORM_DB_NAME).toBe('zrismpsz_reya_platform');
  });
});

describe('loadEnv', () => {
  beforeEach(() => {
    resetEnvCache();
  });

  it('parses a fully-specified, valid source', () => {
    const env = loadEnv(VALID_SOURCE, { fresh: true });
    expect(env).toMatchObject({
      DB_HOST: 'db-host.internal',
      DB_USER: 'zrismpsz_clinicya',
      DB_PASS: 'super-secret',
      REYA_BASE_DOMAIN: 're-ya.com',
      REDIS_URL: 'redis://redis:6379',
      NODE_ENV: 'development',
    });
  });

  it('applies documented defaults when optional vars are omitted', () => {
    const env = loadEnv(
      { DB_USER: 'u', DB_PASS: 'p' } as NodeJS.ProcessEnv,
      { fresh: true }
    );
    expect(env.DB_HOST).toBe('localhost');
    expect(env.REYA_BASE_DOMAIN).toBe('re-ya.com');
    expect(env.REDIS_URL).toBe('redis://localhost:6379');
    expect(env.NODE_ENV).toBe('development');
    expect(env.REYA_ROOT_TENANT_SLUG).toBeUndefined();
  });

  it('passes REYA_ROOT_TENANT_SLUG through untouched (normalisation is packages/tenant\'s job)', () => {
    const env = loadEnv(
      { DB_USER: 'u', DB_PASS: 'p', REYA_ROOT_TENANT_SLUG: '  Tenant-0009  ' } as NodeJS.ProcessEnv,
      { fresh: true }
    );
    expect(env.REYA_ROOT_TENANT_SLUG).toBe('  Tenant-0009  ');
  });

  it('throws a descriptive error when DB_USER is missing', () => {
    expect(() => loadEnv({ DB_PASS: 'p' } as NodeJS.ProcessEnv, { fresh: true })).toThrow(
      /Invalid environment configuration[\s\S]*DB_USER/
    );
  });

  it('throws a descriptive error when DB_PASS is missing', () => {
    expect(() => loadEnv({ DB_USER: 'u' } as NodeJS.ProcessEnv, { fresh: true })).toThrow(
      /Invalid environment configuration[\s\S]*DB_PASS/
    );
  });

  it('rejects an empty-string DB_PASS rather than silently treating it as unset', () => {
    expect(() =>
      loadEnv({ DB_USER: 'u', DB_PASS: '' } as NodeJS.ProcessEnv, { fresh: true })
    ).toThrow(/DB_PASS/);
  });

  it('caches the first successful parse across calls with different sources', () => {
    const first = loadEnv(VALID_SOURCE, { fresh: true });
    const second = loadEnv({ DB_USER: 'other', DB_PASS: 'other' } as NodeJS.ProcessEnv);
    expect(second).toBe(first);
    expect(second.DB_USER).toBe('zrismpsz_clinicya');
  });

  it('resetEnvCache() forces the next loadEnv() call to re-parse', () => {
    loadEnv(VALID_SOURCE, { fresh: true });
    resetEnvCache();
    const reparsed = loadEnv({ DB_USER: 'fresh', DB_PASS: 'fresh' } as NodeJS.ProcessEnv);
    expect(reparsed.DB_USER).toBe('fresh');
  });

  it('{ fresh: true } bypasses the cache without needing resetEnvCache()', () => {
    loadEnv(VALID_SOURCE, { fresh: true });
    const reparsed = loadEnv({ DB_USER: 'fresh2', DB_PASS: 'fresh2' } as NodeJS.ProcessEnv, { fresh: true });
    expect(reparsed.DB_USER).toBe('fresh2');
  });

  it('rejects an unrecognised NODE_ENV value', () => {
    expect(() =>
      loadEnv(
        { DB_USER: 'u', DB_PASS: 'p', NODE_ENV: 'staging' } as unknown as NodeJS.ProcessEnv,
        { fresh: true }
      )
    ).toThrow(/NODE_ENV/);
  });
});
