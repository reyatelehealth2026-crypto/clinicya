/**
 * Tests for SaaS subdomain → API origin resolution.
 *
 * Run with: `node --import tsx --test src/lib/__tests__/config.test.ts`
 * (tsx is not currently installed; this guards the tenant-separation logic so a
 *  future runner — Vitest / node strip-types — catches regressions.)
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { isTenantSubdomainHost, resolveApiBaseUrl } from '../config.ts'

test('treats tenant slugs on the base domain as tenant subdomains', () => {
  assert.equal(isTenantSubdomainHost('tenant-0001.re-ya.com'), true)
  assert.equal(isTenantSubdomainHost('tenant-9.re-ya.com'), true)
  assert.equal(isTenantSubdomainHost('cny.re-ya.com'), true)
  assert.equal(isTenantSubdomainHost('TENANT-7.RE-YA.COM'), true)
  assert.equal(isTenantSubdomainHost('tenant-1.re-ya.com:443'), true)
})

test('rejects root domain, reserved hosts, deep subdomains and foreign hosts', () => {
  assert.equal(isTenantSubdomainHost('re-ya.com'), false)
  assert.equal(isTenantSubdomainHost('www.re-ya.com'), false)
  assert.equal(isTenantSubdomainHost('api.re-ya.com'), false)
  assert.equal(isTenantSubdomainHost('miniapp.re-ya.com'), false)
  assert.equal(isTenantSubdomainHost('shop.re-ya.com'), false)
  assert.equal(isTenantSubdomainHost('a.b.re-ya.com'), false)
  assert.equal(isTenantSubdomainHost('localhost'), false)
  assert.equal(isTenantSubdomainHost('localhost:3000'), false)
  assert.equal(isTenantSubdomainHost('evil.com'), false)
})

test('resolveApiBaseUrl uses same-origin on a tenant subdomain', () => {
  const original = (globalThis as { window?: unknown }).window
  ;(globalThis as { window?: unknown }).window = {
    location: { hostname: 'tenant-0042.re-ya.com', origin: 'https://tenant-0042.re-ya.com' }
  }
  try {
    assert.equal(resolveApiBaseUrl(), 'https://tenant-0042.re-ya.com')
  } finally {
    ;(globalThis as { window?: unknown }).window = original
  }
})

test('resolveApiBaseUrl falls back to env base off a tenant subdomain', () => {
  const original = (globalThis as { window?: unknown }).window
  ;(globalThis as { window?: unknown }).window = {
    location: { hostname: 'localhost', origin: 'http://localhost:3000' }
  }
  try {
    // ENV default is https://re-ya.com when NEXT_PUBLIC_PHP_API_BASE_URL is unset.
    assert.notEqual(resolveApiBaseUrl(), 'http://localhost:3000')
  } finally {
    ;(globalThis as { window?: unknown }).window = original
  }
})
