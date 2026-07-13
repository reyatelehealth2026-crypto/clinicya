import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@reya/config', () => ({
  loadEnv: vi.fn((source: NodeJS.ProcessEnv) => ({
    NODE_ENV: source.NODE_ENV ?? 'development',
    DB_HOST: source.DB_HOST ?? 'localhost',
    DB_USER: source.DB_USER ?? 'reya_user',
    DB_PASS: source.DB_PASS ?? 'reya_pass',
    REYA_BASE_DOMAIN: 're-ya.com',
    REDIS_URL: source.REDIS_URL ?? 'redis://localhost:6379',
  })),
}));

beforeEach(() => {
  vi.resetModules();
});

describe('loadWorkerEnv', () => {
  it('defaults WORKER_HEALTH_PORT/WORKER_HEARTBEAT_INTERVAL_MS/WORKER_SHUTDOWN_TIMEOUT_MS when unset', async () => {
    const { loadWorkerEnv } = await import('../src/env');
    const env = loadWorkerEnv({});

    expect(env.WORKER_HEALTH_PORT).toBe(8099);
    expect(env.WORKER_HEARTBEAT_INTERVAL_MS).toBe(60_000);
    expect(env.WORKER_SHUTDOWN_TIMEOUT_MS).toBe(25_000);
  });

  it('parses explicit overrides as integers', async () => {
    const { loadWorkerEnv } = await import('../src/env');
    const env = loadWorkerEnv({
      WORKER_HEALTH_PORT: '9000',
      WORKER_HEARTBEAT_INTERVAL_MS: '15000',
      WORKER_SHUTDOWN_TIMEOUT_MS: '5000',
    });

    expect(env.WORKER_HEALTH_PORT).toBe(9000);
    expect(env.WORKER_HEARTBEAT_INTERVAL_MS).toBe(15_000);
    expect(env.WORKER_SHUTDOWN_TIMEOUT_MS).toBe(5_000);
  });

  it('throws on a non-integer WORKER_HEALTH_PORT', async () => {
    const { loadWorkerEnv } = await import('../src/env');
    expect(() => loadWorkerEnv({ WORKER_HEALTH_PORT: 'not-a-port' })).toThrow(/WORKER_HEALTH_PORT/);
  });

  it('throws on a zero/negative WORKER_HEARTBEAT_INTERVAL_MS', async () => {
    const { loadWorkerEnv } = await import('../src/env');
    expect(() => loadWorkerEnv({ WORKER_HEARTBEAT_INTERVAL_MS: '0' })).toThrow(/WORKER_HEARTBEAT_INTERVAL_MS/);
    expect(() => loadWorkerEnv({ WORKER_HEARTBEAT_INTERVAL_MS: '-5' })).toThrow(/WORKER_HEARTBEAT_INTERVAL_MS/);
  });

  it('passes through @reya/config\'s shared env fields unmodified', async () => {
    const { loadWorkerEnv } = await import('../src/env');
    const env = loadWorkerEnv({ DB_HOST: 'custom-host', REDIS_URL: 'redis://custom:6379' });

    expect(env.DB_HOST).toBe('custom-host');
    expect(env.REDIS_URL).toBe('redis://custom:6379');
  });
});
