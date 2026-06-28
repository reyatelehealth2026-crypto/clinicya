// ─────────────────────────────────────────────────────────────────────────────
// Runtime tenant (line_account) resolution for the SHARED Mini App.
//
// The Mini App is one static export served at https://re-ya.com/miniapp/ for
// EVERY tenant. NEXT_PUBLIC_LINE_ACCOUNT_ID is only a build-time default; the
// real tenant is determined at runtime, in priority order:
//
//   1. ?la={id} in the URL  — deep links built by includes/liff-helper.php carry
//      this. Persisted to localStorage so it survives in-app navigation.
//   2. localStorage         — a previously resolved id within this LIFF session.
//   3. Resolver API         — POST the LIFF id to api/resolve-line-account.php
//      (handled by resolveLineAccountId() once LIFF is initialised).
//   4. Build-time default   — NEXT_PUBLIC_LINE_ACCOUNT_ID (back-compat).
//
// `appConfig.lineAccountId` is a GETTER so the 13 API modules that read it pick
// up the resolved value without per-file changes, the instant it is set.
// ─────────────────────────────────────────────────────────────────────────────

// Key is scoped to the current LIFF id so different shops (different LIFF IDs)
// stored in the same browser don't clobber each other's tenant.
// The key is NOT set until we know the LIFF id (see resolveLineAccountId).
const LINE_ACCOUNT_STORAGE_KEY_PREFIX = 'reya.lineAccountId'
let _storageKey = LINE_ACCOUNT_STORAGE_KEY_PREFIX  // upgraded once LIFF id is known
function storageKey(): string { return _storageKey }
const BUILD_TIME_LINE_ACCOUNT_ID = Number(process.env.NEXT_PUBLIC_LINE_ACCOUNT_ID || '1')
const REYA_ROOT_HOSTS = new Set(['re-ya.com', 'www.re-ya.com'])

/** Runtime override; null until resolved. Falls back to the build-time default. */
let runtimeLineAccountId: number | null = null

function readLaFromUrl(): number | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = new URLSearchParams(window.location.search).get('la')
    const n = raw ? Number(raw) : NaN
    return Number.isInteger(n) && n > 0 ? n : null
  } catch {
    return null
  }
}

function readLaFromStorage(): number | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(storageKey())
    const n = raw ? Number(raw) : NaN
    return Number.isInteger(n) && n > 0 ? n : null
  } catch {
    return null
  }
}

function persistLa(id: number): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(storageKey(), String(id))
  } catch {
    /* storage may be unavailable (private mode) — non-fatal */
  }
}

/** Upgrade the storage key to be LIFF-scoped so different shops don't collide.
 *  Called once the LIFF id is known (inside resolveLineAccountId). */
function upgradeLaStorageKey(liffId: string): void {
  if (!liffId || typeof window === 'undefined') return
  const newKey = `${LINE_ACCOUNT_STORAGE_KEY_PREFIX}.${liffId}`
  if (_storageKey === newKey) return
  // Migrate any old generic-key value to the scoped key, then clear the
  // generic key so stale values don't bleed across shops on the next login.
  try {
    const old = window.localStorage.getItem(LINE_ACCOUNT_STORAGE_KEY_PREFIX)
    if (old && !window.localStorage.getItem(newKey)) {
      window.localStorage.setItem(newKey, old)
    }
    window.localStorage.removeItem(LINE_ACCOUNT_STORAGE_KEY_PREFIX)
  } catch { /* non-fatal */ }
  _storageKey = newKey
}

/**
 * Set the active line_account at runtime (URL/storage/resolver). Persists it so
 * subsequent page loads in the same LIFF session reuse it.
 */
export function setLineAccountId(id: number): void {
  if (!Number.isInteger(id) || id <= 0) return
  runtimeLineAccountId = id
  persistLa(id)
}

/**
 * Current best-known line_account id. Synchronous: URL → runtime override →
 * localStorage → build-time default. Safe to call during render.
 */
export function getLineAccountId(): number {
  const fromUrl = readLaFromUrl()
  if (fromUrl !== null) {
    // First read of a fresh deep link — pin + persist it.
    if (runtimeLineAccountId !== fromUrl) setLineAccountId(fromUrl)
    return fromUrl
  }
  if (runtimeLineAccountId !== null) return runtimeLineAccountId
  const fromStorage = readLaFromStorage()
  if (fromStorage !== null) {
    runtimeLineAccountId = fromStorage
    return fromStorage
  }
  return BUILD_TIME_LINE_ACCOUNT_ID
}

export const appConfig = {
  miniAppName: process.env.NEXT_PUBLIC_MINIAPP_NAME || 'Re-Ya LINE Mini App',
  liffId: process.env.NEXT_PUBLIC_LINE_LIFF_ID || '',
  channelId: process.env.NEXT_PUBLIC_LINE_CHANNEL_ID || '',
  get apiBaseUrl(): string {
    return resolvePhpApiBaseUrl()
  },
  // Resolved at runtime for the shared deployment — see getLineAccountId().
  get lineAccountId(): number {
    return getLineAccountId()
  },
  shopCatalog: {
    hideZeroPriceProducts: process.env.NEXT_PUBLIC_SHOP_HIDE_ZERO_PRICE === '1',
    hideInactiveProducts: process.env.NEXT_PUBLIC_SHOP_HIDE_INACTIVE === '1',
    mode: process.env.NEXT_PUBLIC_SHOP_CATALOG_MODE || 'all',
    defaultBucket: process.env.NEXT_PUBLIC_SHOP_CATALOG_BUCKET || ''
  }
}

function normaliseBaseUrl(value: string): string {
  return value.replace(/\/$/, '')
}

export function resolvePhpApiBaseUrl(): string {
  const configured = process.env.NEXT_PUBLIC_PHP_API_BASE_URL || ''

  if (typeof window !== 'undefined') {
    const hostname = window.location.hostname.toLowerCase()
    const isReyaSubdomain = hostname.endsWith('.re-ya.com') && !REYA_ROOT_HOSTS.has(hostname)

    // SaaS tenant hosts must call their own origin so PHP subdomain routing pins
    // the tenant DB. A baked root-domain API base would route duplicate
    // line_account_id=1 tenants to the first/default route.
    if (isReyaSubdomain) {
      return normaliseBaseUrl(window.location.origin)
    }

    if (!configured) {
      return normaliseBaseUrl(window.location.origin)
    }
  }

  return normaliseBaseUrl(configured || 'https://re-ya.com')
}

export function apiUrl(path: string) {
  return `${appConfig.apiBaseUrl}${path.startsWith('/') ? path : `/${path}`}`
}

/**
 * True when the page is being served from a tenant subdomain (e.g.
 * banyarimchol.re-ya.com), as opposed to the root domain / localhost. On a
 * tenant subdomain the build-time NEXT_PUBLIC_LINE_LIFF_ID is ANOTHER tenant's
 * (tenant-0001's) LIFF id, so it must NEVER be used as a fallback here.
 */
export function isReyaTenantSubdomainHost(): boolean {
  if (typeof window === 'undefined') return false
  const h = window.location.hostname.toLowerCase()
  return h.endsWith('.re-ya.com') && !REYA_ROOT_HOSTS.has(h)
}

// ── LIFF id resolution for the shared Mini App ──────────────────────────────
// The bundle is served on each tenant's OWN subdomain (clinicya.re-ya.com/miniapp,
// tenant-0001.re-ya.com/miniapp, ...). The baked NEXT_PUBLIC_LINE_LIFF_ID is only
// ONE tenant's id — using it on every host makes login bounce to the wrong tenant.
// So resolve the LIFF id from the CURRENT host before liff.init():
//   1. ?liff_id= in the URL (explicit deep link)
//   2. {current origin}/api/miniapp-bootstrap.php → that host's tenant primary LIFF
//   3. build-time default (back-compat)
let _resolvedLiffId: string | null = null

export function getResolvedLiffId(): string {
  return _resolvedLiffId || appConfig.liffId
}

export function buildMiniappBootstrapUrl(origin?: string, search?: string): string {
  const baseOrigin =
    origin ||
    (typeof window !== 'undefined' ? window.location.origin : 'https://re-ya.com')
  const url = new URL('/api/miniapp-bootstrap.php', baseOrigin)
  const params = new URLSearchParams(
    search !== undefined
      ? search
      : (typeof window !== 'undefined' ? window.location.search : '')
  )
  const lineAccountId = params.get('la') || params.get('line_account_id') || params.get('account')
  const liffId = params.get('liff_id')

  if (lineAccountId) {
    url.searchParams.set('la', lineAccountId)
  }
  if (liffId) {
    url.searchParams.set('liff_id', liffId)
  }

  return url.toString()
}

export async function resolveLiffId(): Promise<string> {
  if (_resolvedLiffId) return _resolvedLiffId

  if (typeof window !== 'undefined') {
    try {
      const u = new URLSearchParams(window.location.search).get('liff_id')
      if (u && u.trim()) {
        _resolvedLiffId = u.trim()
        return _resolvedLiffId
      }
    } catch {
      /* ignore */
    }

    // Ask the CURRENT host which LIFF it serves (host → tenant subdomain routing).
    // This is the ONLY correct source on a tenant subdomain, so retry a couple
    // of times: a single transient fetch miss must NOT fall through to the
    // build-time default, which is another tenant's (tenant-0001) LIFF — initing
    // that here makes login bounce to tenant-0001 and throws "Invalid LIFF ID".
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        const res = await fetch(buildMiniappBootstrapUrl(), { cache: 'no-store' })
        const d = (await res.json()) as { success?: boolean; liff_id?: string; line_account_id?: number }
        if (d && d.success && d.liff_id && d.liff_id.trim()) {
          _resolvedLiffId = d.liff_id.trim()
          if (Number.isInteger(d.line_account_id) && (d.line_account_id as number) > 0) {
            setLineAccountId(d.line_account_id as number)
          }
          return _resolvedLiffId
        }
        // success:false → tenant genuinely has no LIFF; stop retrying.
        break
      } catch {
        // network/parse failure — short backoff, then retry.
        await new Promise((resolve) => setTimeout(resolve, 300 * (attempt + 1)))
      }
    }

    // On a tenant subdomain the build-time default belongs to a DIFFERENT tenant.
    // Never init it (that is the cross-tenant bounce bug) — return empty and let
    // the caller surface a real error instead.
    if (isReyaTenantSubdomainHost()) {
      _resolvedLiffId = null
      return ''
    }
  }

  // Root domain / localhost: the build-time default IS the correct tenant.
  _resolvedLiffId = appConfig.liffId
  return _resolvedLiffId
}

/**
 * Resolve the active line_account id, using the resolver API as a fallback.
 *
 * Call AFTER LIFF init. If a ?la= / stored id is already known it is returned
 * immediately. Otherwise the given LIFF id is mapped via
 * api/resolve-line-account.php and the result is pinned + persisted.
 *
 * Always resolves (never throws) — on any failure it returns the current
 * best-known id so the app keeps working against the build-time default.
 */
export async function resolveLineAccountId(liffId: string | null): Promise<number> {
  // Scope the localStorage key to THIS LIFF id so different shops (different
  // LIFF ids opened in the same browser) don't read each other's cached tenant.
  if (liffId) upgradeLaStorageKey(liffId)

  // URL param always wins — deep links from PHP include ?la={id}.
  const fromUrl = readLaFromUrl()
  if (fromUrl !== null) {
    setLineAccountId(fromUrl)
    return fromUrl
  }
  if (runtimeLineAccountId !== null) return runtimeLineAccountId
  // Read storage AFTER key upgrade so we only see this LIFF's stored value.
  const fromStorage = readLaFromStorage()
  if (fromStorage !== null) {
    runtimeLineAccountId = fromStorage
    return fromStorage
  }

  // Fall back to the resolver API using the LIFF id.
  if (liffId) {
    try {
      const url = new URL(apiUrl('/api/resolve-line-account.php'))
      url.searchParams.set('liff_id', liffId)
      const res = await fetch(url.toString(), { method: 'GET', cache: 'no-store' })
      const data = (await res.json()) as { success?: boolean; line_account_id?: number }
      if (data?.success && Number.isInteger(data.line_account_id) && (data.line_account_id as number) > 0) {
        setLineAccountId(data.line_account_id as number)
        return data.line_account_id as number
      }
    } catch {
      /* network/parse failure — fall through to default */
    }
  }

  return BUILD_TIME_LINE_ACCOUNT_ID
}
