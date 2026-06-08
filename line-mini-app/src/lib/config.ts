// Build-time fallback base URL for the PHP backend (local dev / root-domain /
// cross-origin hosting). In production the mini app is served per-tenant from a
// SaaS subdomain and resolves the API origin at runtime — see resolveApiBaseUrl.
const ENV_API_BASE = (process.env.NEXT_PUBLIC_PHP_API_BASE_URL || 'https://re-ya.com').replace(/\/$/, '')

// Root SaaS domain that tenant subdomains hang off of (tenant-XXXX.re-ya.com).
const BASE_DOMAIN = (process.env.NEXT_PUBLIC_REYA_BASE_DOMAIN || 're-ya.com').toLowerCase()

// Subdomains that are NEVER tenant slugs. Mirrors the reserved list in
// bootstrap/resolve_subdomain.php so the client doesn't mistake an infra host
// for a tenant and route the API to itself.
const RESERVED_SUBDOMAINS = new Set([
  'www', 'api', 'admin', 'platform', 'cdn', 'static', 'assets',
  'mail', 'webmail', 'webhook', 'webhooks',
  'app', 'dashboard', 'pharmacy', 'inventory', 'inbox',
  'liff', 'miniapp', 'docs', 'help', 'support', 'status',
  'shop', 'odoo', 'stg', 'dev',
  'auth', 'login', 'signup', 'register', 'billing', 'pay'
])

/**
 * True when `host` is a real tenant subdomain of the SaaS base domain.
 *   "tenant-0001.re-ya.com" → true
 *   "re-ya.com" / "www.re-ya.com" / "localhost" → false
 */
export function isTenantSubdomainHost(host: string): boolean {
  const h = host.toLowerCase().replace(/:\d+$/, '')
  if (h === BASE_DOMAIN || !h.endsWith('.' + BASE_DOMAIN)) {
    return false
  }
  const label = h.slice(0, -(BASE_DOMAIN.length + 1))
  // Exactly one sub-label (no further dots), not a reserved infra host.
  if (label === '' || label.includes('.')) {
    return false
  }
  return !RESERVED_SUBDOMAINS.has(label)
}

/**
 * Resolve the PHP API base URL at call time.
 *
 * SaaS subdomain separation (Wave-3 / ADR-001): when the mini app is loaded
 * from a tenant subdomain (tenant-XXXX.re-ya.com), call the PHP API on the SAME
 * origin so bootstrap/resolve_subdomain.php pins the correct tenant DB
 * automatically — no reliance on a baked line_account_id. This is what stops
 * every customer from falling through to the legacy/default DB.
 *
 * Off a tenant subdomain (local dev, root domain, cross-origin static hosting)
 * it falls back to the build-time NEXT_PUBLIC_PHP_API_BASE_URL.
 */
export function resolveApiBaseUrl(): string {
  if (typeof window !== 'undefined' && isTenantSubdomainHost(window.location.hostname)) {
    return window.location.origin.replace(/\/$/, '')
  }
  return ENV_API_BASE
}

export const appConfig = {
  miniAppName: process.env.NEXT_PUBLIC_MINIAPP_NAME || 'Re-Ya LINE Mini App',
  liffId: process.env.NEXT_PUBLIC_LINE_LIFF_ID || '',
  channelId: process.env.NEXT_PUBLIC_LINE_CHANNEL_ID || '',
  // Dynamic: resolves to the current tenant subdomain origin in the browser,
  // env fallback otherwise. Kept as a getter so existing readers stay correct.
  get apiBaseUrl() {
    return resolveApiBaseUrl()
  },
  lineAccountId: Number(process.env.NEXT_PUBLIC_LINE_ACCOUNT_ID || '1'),
  shopCatalog: {
    hideZeroPriceProducts: process.env.NEXT_PUBLIC_SHOP_HIDE_ZERO_PRICE === '1',
    hideInactiveProducts: process.env.NEXT_PUBLIC_SHOP_HIDE_INACTIVE === '1',
    mode: process.env.NEXT_PUBLIC_SHOP_CATALOG_MODE || 'all',
    defaultBucket: process.env.NEXT_PUBLIC_SHOP_CATALOG_BUCKET || ''
  }
}

export function apiUrl(path: string) {
  return `${resolveApiBaseUrl()}${path.startsWith('/') ? path : `/${path}`}`
}
