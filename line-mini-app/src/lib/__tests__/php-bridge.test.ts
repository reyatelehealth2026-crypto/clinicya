/**
 * Unit tests for php-bridge.ts's endpoint-override prereq (Phase 3 batch 1).
 *
 * Run with: node --experimental-strip-types --test src/lib/__tests__/*.test.ts
 *
 * These test `buildPhpRequestUrl`/`resolveEndpointTarget` in config.ts — the
 * pure, alias-import-free logic phpGet/phpPost delegate to — rather than
 * phpGet/phpPost themselves. php-bridge.ts imports config.ts via the `@/lib/config`
 * path alias (like every sibling *-api.ts wrapper file), which only Next's
 * bundler resolves; the plain `node --test` runner used here does not. This
 * mirrors the project's existing convention (see data-rights-request.ts's own
 * doc comment: "Kept free of the `@/` alias imports ... so the endpoint
 * contract is unit-testable under the project's node --test runner").
 * `buildPhpRequestUrl` IS exactly what phpGet/phpPost call to build the
 * request URL, so this covers phpGet/phpPost's observable behaviour completely.
 *
 * Covers the three behaviours the phase-3-batch-1 brief calls out explicitly:
 *   1. no-override default behaviour is byte-for-byte unchanged
 *   2. an override present in NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES redirects
 *      both origin and path
 *   3. malformed override JSON falls back to the default, never throws
 */

import { test, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { buildPhpRequestUrl, resolveEndpointTarget } from '../config.ts'

const originalOverrides = process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES
const originalApiBase = process.env.NEXT_PUBLIC_PHP_API_BASE_URL

afterEach(() => {
  if (originalOverrides === undefined) {
    delete process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES
  } else {
    process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = originalOverrides
  }
  if (originalApiBase === undefined) {
    delete process.env.NEXT_PUBLIC_PHP_API_BASE_URL
  } else {
    process.env.NEXT_PUBLIC_PHP_API_BASE_URL = originalApiBase
  }
  delete (globalThis as { window?: unknown }).window
})

// ── resolveEndpointTarget ───────────────────────────────────────────────────

test('resolveEndpointTarget: with no override configured, falls back to {origin: resolvePhpApiBaseUrl(), path: legacyPath}', () => {
  delete process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'

  assert.deepEqual(resolveEndpointTarget('/api/checkout.php', 'GET /api/checkout.php'), {
    origin: 'https://re-ya.com',
    path: '/api/checkout.php'
  })
})

test('resolveEndpointTarget: an override present in NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES is returned verbatim', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = JSON.stringify({
    'GET /api/resolve-line-account.php': {
      origin: 'https://tenant-0003.re-ya.com',
      path: '/api/miniapp/resolve-line-account'
    }
  })

  assert.deepEqual(resolveEndpointTarget('/api/resolve-line-account.php', 'GET /api/resolve-line-account.php'), {
    origin: 'https://tenant-0003.re-ya.com',
    path: '/api/miniapp/resolve-line-account'
  })
})

test('resolveEndpointTarget: an endpointKey with no matching entry falls back to default (not an error)', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = JSON.stringify({
    'GET /api/some-other-endpoint.php': { origin: 'https://elsewhere.example', path: '/x' }
  })

  assert.deepEqual(resolveEndpointTarget('/api/checkout.php', 'GET /api/checkout.php'), {
    origin: 'https://re-ya.com',
    path: '/api/checkout.php'
  })
})

test('resolveEndpointTarget: malformed override JSON falls back to the default target, never throws', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = '{not valid json,,,'

  assert.doesNotThrow(() => resolveEndpointTarget('/api/checkout.php', 'GET /api/checkout.php'))
  assert.deepEqual(resolveEndpointTarget('/api/checkout.php', 'GET /api/checkout.php'), {
    origin: 'https://re-ya.com',
    path: '/api/checkout.php'
  })
})

test('resolveEndpointTarget: override JSON that is valid JSON but not an object (e.g. an array) is ignored', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = '[1,2,3]'

  assert.deepEqual(resolveEndpointTarget('/api/checkout.php', 'GET /api/checkout.php'), {
    origin: 'https://re-ya.com',
    path: '/api/checkout.php'
  })
})

test('resolveEndpointTarget: an override entry missing `path` (or `origin`) is ignored', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = JSON.stringify({
    'GET /api/checkout.php': { origin: 'https://elsewhere.example' }
  })

  assert.deepEqual(resolveEndpointTarget('/api/checkout.php', 'GET /api/checkout.php'), {
    origin: 'https://re-ya.com',
    path: '/api/checkout.php'
  })
})

// ── buildPhpRequestUrl (exactly what phpGet/phpPost call) ──────────────────

test('buildPhpRequestUrl: phpGet-shaped call with no override resolves to the exact pre-refactor URL', () => {
  delete process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'

  const url = buildPhpRequestUrl(
    '/api/checkout.php',
    { action: 'products', limit: 12 },
    'GET /api/checkout.php'
  )

  const parsed = new URL(url)
  assert.equal(parsed.origin, 'https://re-ya.com')
  assert.equal(parsed.pathname, '/api/checkout.php')
  assert.equal(parsed.searchParams.get('action'), 'products')
  assert.equal(parsed.searchParams.get('limit'), '12')
})

test('buildPhpRequestUrl: phpPost-shaped call (no params) with no override resolves to the exact pre-refactor URL', () => {
  delete process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'

  const url = buildPhpRequestUrl('/api/member.php', undefined, 'POST /api/member.php')

  assert.equal(url, 'https://re-ya.com/api/member.php')
})

test('buildPhpRequestUrl: empty-string / undefined / null param values are omitted (matches phpGet\'s pre-refactor filtering)', () => {
  delete process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'

  const url = buildPhpRequestUrl(
    '/api/checkout.php',
    { action: 'products', search: '', category_id: undefined },
    'GET /api/checkout.php'
  )

  const parsed = new URL(url)
  assert.equal(parsed.searchParams.has('search'), false)
  assert.equal(parsed.searchParams.has('category_id'), false)
  assert.equal(parsed.searchParams.get('action'), 'products')
})

test('buildPhpRequestUrl: an override redirects both origin and path, params still appended', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = JSON.stringify({
    'GET /api/resolve-line-account.php': {
      origin: 'https://tenant-0003.re-ya.com',
      path: '/api/miniapp/resolve-line-account'
    }
  })

  const url = buildPhpRequestUrl(
    '/api/resolve-line-account.php',
    { liff_id: 'abc-123' },
    'GET /api/resolve-line-account.php'
  )

  const parsed = new URL(url)
  assert.equal(parsed.origin, 'https://tenant-0003.re-ya.com')
  assert.equal(parsed.pathname, '/api/miniapp/resolve-line-account')
  assert.equal(parsed.searchParams.get('liff_id'), 'abc-123')
})

test('buildPhpRequestUrl: an explicit per-action endpointKey looks up its own override entry, independent of the default "POST {path}" key', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = JSON.stringify({
    'POST /api/member.php?action=get_card': {
      origin: 'https://admin.internal',
      path: '/api/miniapp/member'
    }
  })

  const url = buildPhpRequestUrl('/api/member.php', undefined, 'POST /api/member.php?action=get_card')

  assert.equal(url, 'https://admin.internal/api/miniapp/member')
})

test('buildPhpRequestUrl: malformed override JSON falls back to the default target, never throws', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES = '{not valid json,,,'

  let url = ''
  assert.doesNotThrow(() => {
    url = buildPhpRequestUrl('/api/checkout.php', { action: 'products' }, 'GET /api/checkout.php')
  })
  const parsed = new URL(url)
  assert.equal(parsed.origin, 'https://re-ya.com')
  assert.equal(parsed.pathname, '/api/checkout.php')
})

test('buildPhpRequestUrl: default endpointKey (undefined) falls back to legacyPath as the lookup key and still resolves to the default target', () => {
  delete process.env.NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'

  const url = buildPhpRequestUrl('/api/checkout.php', { action: 'products' })

  const parsed = new URL(url)
  assert.equal(parsed.origin, 'https://re-ya.com')
  assert.equal(parsed.pathname, '/api/checkout.php')
})
