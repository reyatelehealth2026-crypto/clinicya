/**
 * @jest-environment node
 */
import { describe, expect, it } from '@jest/globals';
import { handleMiniappOptions, miniappCorsHeaders, miniappJson, withMiniappCors } from './cors';

const EXPECTED_HEADERS = {
  'access-control-allow-origin': '*',
  'access-control-allow-methods': 'GET, POST, OPTIONS',
  'access-control-allow-headers': 'Content-Type',
};

describe('miniappCorsHeaders', () => {
  it('returns the exact fixed CORS header set', () => {
    expect(miniappCorsHeaders()).toEqual({
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
    });
  });
});

describe('miniappJson', () => {
  it('defaults to HTTP 200 and carries CORS headers on a success body', async () => {
    const res = miniappJson({ success: true, foo: 'bar' });

    expect(res.status).toBe(200);
    for (const [key, value] of Object.entries(EXPECTED_HEADERS)) {
      expect(res.headers.get(key)).toBe(value);
    }
    expect(await res.json()).toEqual({ success: true, foo: 'bar' });
  });

  it('carries CORS headers on an ERROR body too (not just success)', async () => {
    const res = miniappJson({ success: false, error: 'tenant_unresolved' }, { status: 400 });

    expect(res.status).toBe(400);
    for (const [key, value] of Object.entries(EXPECTED_HEADERS)) {
      expect(res.headers.get(key)).toBe(value);
    }
    expect(await res.json()).toEqual({ success: false, error: 'tenant_unresolved' });
  });

  it('honors an explicit non-2xx status (e.g. 500)', () => {
    const res = miniappJson({ success: false }, { status: 500 });
    expect(res.status).toBe(500);
  });
});

describe('handleMiniappOptions', () => {
  it('answers a CORS preflight with 204, empty body, CORS headers set', async () => {
    const res = handleMiniappOptions();

    expect(res.status).toBe(204);
    for (const [key, value] of Object.entries(EXPECTED_HEADERS)) {
      expect(res.headers.get(key)).toBe(value);
    }
    const text = await res.text();
    expect(text).toBe('');
  });
});

describe('withMiniappCors', () => {
  it('mutates and returns the same response, adding CORS headers without disturbing the body/status', async () => {
    const original = new Response(JSON.stringify({ ok: true }), { status: 201 });
    const decorated = withMiniappCors(original as never);

    expect(decorated).toBe(original);
    expect(decorated.status).toBe(201);
    for (const [key, value] of Object.entries(EXPECTED_HEADERS)) {
      expect(decorated.headers.get(key)).toBe(value);
    }
  });
});
