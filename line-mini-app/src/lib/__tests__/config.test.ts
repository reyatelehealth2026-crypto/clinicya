import { test, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import {
  buildMiniappBootstrapUrl,
  resolvePhpApiBaseUrl,
} from '../config.ts'

const originalApiBase = process.env.NEXT_PUBLIC_PHP_API_BASE_URL

afterEach(() => {
  if (originalApiBase === undefined) {
    delete process.env.NEXT_PUBLIC_PHP_API_BASE_URL
  } else {
    process.env.NEXT_PUBLIC_PHP_API_BASE_URL = originalApiBase
  }
  delete (globalThis as { window?: unknown }).window
})

function setWindowLocation(origin: string) {
  const url = new URL(origin)
  ;(globalThis as { window?: unknown }).window = {
    location: {
      hostname: url.hostname,
      origin: url.origin,
      search: url.search,
    },
  }
}

test('tenant subdomains use their own origin even when PHP API env points at root', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  setWindowLocation('https://tenant-0003.re-ya.com/miniapp/')

  assert.equal(resolvePhpApiBaseUrl(), 'https://tenant-0003.re-ya.com')
})

test('root domain still uses the configured PHP API base', () => {
  process.env.NEXT_PUBLIC_PHP_API_BASE_URL = 'https://re-ya.com'
  setWindowLocation('https://re-ya.com/miniapp/')

  assert.equal(resolvePhpApiBaseUrl(), 'https://re-ya.com')
})

test('miniapp bootstrap request forwards the LIFF tenant signal', () => {
  const url = buildMiniappBootstrapUrl(
    'https://re-ya.com',
    '?la=7&liff_id=2008477880-wmRN2Aln&utm=ignored'
  )

  assert.equal(
    url,
    'https://re-ya.com/api/miniapp-bootstrap.php?la=7&liff_id=2008477880-wmRN2Aln'
  )
})

test('miniapp bootstrap accepts long-form line_account_id as tenant signal', () => {
  const url = buildMiniappBootstrapUrl('https://shop-a.re-ya.com', '?line_account_id=12')

  assert.equal(url, 'https://shop-a.re-ya.com/api/miniapp-bootstrap.php?la=12')
})
