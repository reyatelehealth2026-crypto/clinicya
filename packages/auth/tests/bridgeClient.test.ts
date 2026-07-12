import { createHmac } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const FAKE_ENV = {
  NODE_ENV: 'test',
  DB_HOST: 'db-host.internal',
  DB_USER: 'reya_user',
  DB_PASS: 'reya_pass',
  REYA_BASE_DOMAIN: 're-ya.com',
  REDIS_URL: 'redis://redis:6379',
  SESSION_BRIDGE_URL: 'http://php-internal.test/internal/session-bridge.php',
  SESSION_BRIDGE_HMAC_SECRET: 'test-only-bridge-secret-not-real',
  NODE_SESSION_TTL_SECONDS: 86400,
};

vi.mock('@reya/config', () => ({
  loadEnv: vi.fn(() => FAKE_ENV),
  PLATFORM_DB_NAME: 'zrismpsz_reya_platform',
}));

describe('syncToPhpBridge', () => {
  beforeEach(() => {
    vi.resetModules();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('signs the exact JSON body with HMAC-SHA256 and sends it as X-Reya-Signature', async () => {
    const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
      const expected = createHmac('sha256', FAKE_ENV.SESSION_BRIDGE_HMAC_SECRET)
        .update(init.body as string)
        .digest('hex');
      expect((init.headers as Record<string, string>)['X-Reya-Signature']).toBe(expected);
      return new Response(JSON.stringify({ acknowledged: true }), { status: 200 });
    });
    vi.stubGlobal('fetch', fetchMock);

    const { syncToPhpBridge } = await import('../src/bridgeClient');
    const result = await syncToPhpBridge({
      action: 'login-sync',
      sid: 'sid-1',
      phpSessionKeys: { current_bot_id: null },
      issuedAt: Math.floor(Date.now() / 1000),
    });

    expect(result).toEqual({ ok: true, value: { acknowledged: true } });
    expect(fetchMock).toHaveBeenCalledWith(
      FAKE_ENV.SESSION_BRIDGE_URL,
      expect.objectContaining({ method: 'POST' })
    );
  });

  it('resolves {ok:false, error:{code:"bridge_unreachable"}} on a network failure — never throws', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new Error('ECONNREFUSED');
      })
    );

    const { syncToPhpBridge } = await import('../src/bridgeClient');
    const result = await syncToPhpBridge({
      action: 'destroy',
      sid: 'sid-1',
      phpSessionKeys: {},
      issuedAt: Math.floor(Date.now() / 1000),
    });

    expect(result).toEqual({ ok: false, error: { code: 'bridge_unreachable' } });
  });

  it('resolves bridge_unreachable on a non-2xx HTTP response (e.g. 403 bad signature)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(JSON.stringify({ acknowledged: false }), { status: 403 }))
    );

    const { syncToPhpBridge } = await import('../src/bridgeClient');
    const result = await syncToPhpBridge({
      action: 'set_bot',
      sid: 'sid-1',
      phpSessionKeys: { current_bot_id: 5 },
      issuedAt: Math.floor(Date.now() / 1000),
    });

    expect(result).toEqual({ ok: false, error: { code: 'bridge_unreachable' } });
  });

  it('resolves bridge_unreachable when the response body is malformed JSON', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('not json', { status: 200 }))
    );

    const { syncToPhpBridge } = await import('../src/bridgeClient');
    const result = await syncToPhpBridge({
      action: 'introspect',
      sid: 'sid-1',
      phpSessionKeys: {},
      issuedAt: Math.floor(Date.now() / 1000),
    });

    expect(result).toEqual({ ok: false, error: { code: 'bridge_unreachable' } });
  });

  it('resolves bridge_unreachable when acknowledged is not exactly true', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(JSON.stringify({ acknowledged: false }), { status: 200 }))
    );

    const { syncToPhpBridge } = await import('../src/bridgeClient');
    const result = await syncToPhpBridge({
      action: 'set_tenant',
      sid: 'sid-1',
      phpSessionKeys: { admin_switched_to_tenant_id: 42 },
      issuedAt: Math.floor(Date.now() / 1000),
    });

    expect(result).toEqual({ ok: false, error: { code: 'bridge_unreachable' } });
  });
});
