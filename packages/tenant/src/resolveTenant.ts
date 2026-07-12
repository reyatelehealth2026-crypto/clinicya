import { RESERVED_SUBDOMAINS } from './reservedSubdomains';

/**
 * resolveTenant.ts — exact port of bootstrap/resolve_subdomain.php.
 *
 * PHP branch                                              -> TS result
 * ---------------------------------------------------------------------
 * reserved subdomain / unmatched host, not root             kind: 'none'
 * unknown slug (not root)                                   kind: 'not_found'      (404-equivalent)
 * suspended/terminated (not root)                            kind: 'suspended'       (503-equivalent)
 * suspended/terminated + root                                kind: 'none'            (root NEVER goes offline)
 * pending_setup (not root)                                    kind: 'tenant', demoMode: true
 * pending_setup + root                                        kind: 'tenant', demoMode: false (root never demo-flagged)
 * root, no subdomain, no explicit account signal              kind: 'tenant', isRoot: true (REYA_ROOT_TENANT_SLUG)
 * root, no subdomain, WITH explicit account/la/line_account_id kind: 'none' (falls through to routeByLineAccount)
 * root tenant slug configured but row missing                 kind: 'none' (root must never 404)
 * repo lookup throws                                          kind: 'none' (fail-safe, mirrors catch(\Throwable))
 */

export type TenantStatus = 'active' | 'suspended' | 'pending_setup' | 'terminated';

export interface TenantRow {
  id: number;
  status: TenantStatus;
  displayName: string;
}

export interface TenantRepository {
  /** SELECT id, status, display_name FROM tenants WHERE slug = ? LIMIT 1 (against the master DB). */
  findBySlug(slug: string): Promise<TenantRow | null>;
}

export interface ResolveTenantInput {
  /** Raw HTTP Host header value, e.g. "tenant-0001.re-ya.com:8443". */
  host: string | null | undefined;
  /** Parsed query-string params — the `$_GET` equivalent. */
  query?: Record<string, unknown>;
  /** Parsed POST form body — the `$_POST` equivalent. NOT the JSON body (that's routeByLineAccount's concern). */
  body?: Record<string, unknown>;
}

export interface ResolveTenantConfig {
  /** Default 're-ya.com' — mirrors reya_base_domain() / REYA_BASE_DOMAIN. */
  baseDomain?: string;
  /**
   * Raw REYA_ROOT_TENANT_SLUG env value (or undefined if unset) — normalised
   * internally exactly like reya_root_tenant_slug(): unset/empty -> default
   * 'tenant-0001'; trims + lowercases; empty-after-trim -> no root mapping.
   */
  rootTenantSlug?: string;
  reservedSubdomains?: readonly string[];
}

export type NoneReason =
  | 'empty_host'
  | 'reserved_or_no_match'
  | 'root_with_explicit_signal'
  | 'root_tenant_unconfigured'
  | 'root_status_glitch'
  | 'lookup_error';

export type ResolveTenantResult =
  | { kind: 'tenant'; tenantId: number; slug: string; isRoot: boolean; demoMode: boolean }
  | { kind: 'not_found'; slug: string }
  | { kind: 'suspended'; tenantId: number; slug: string; displayName: string; status: 'suspended' | 'terminated' }
  | { kind: 'none'; reason: NoneReason };

export const DEFAULT_BASE_DOMAIN = 're-ya.com';
export const DEFAULT_ROOT_TENANT_SLUG = 'tenant-0001';

/** Same character class as the PHP regex: lowercase letters, digits, hyphen, no leading/trailing hyphen, <=63 chars. */
const SUBDOMAIN_LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

/** account (webhook style) / la (mini-app short alias) / line_account_id — checked GET then POST. */
const ACCOUNT_SIGNAL_KEYS = ['account', 'la', 'line_account_id'] as const;

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Lowercase, trim, strip a trailing `:port` — mirrors both reya_extract_subdomain() and reya_is_root_host(). */
export function normalizeHost(host: string | null | undefined): string {
  const raw = (host ?? '').toLowerCase().trim();
  if (raw === '') {
    return '';
  }
  return raw.replace(/:\d+$/, '');
}

/**
 * Port of reya_extract_subdomain(). `host` must already be normalizeHost()'d.
 * Returns the subdomain label, or null for: no match, root domain, or a
 * reserved subdomain.
 */
export function extractSubdomain(
  host: string,
  baseDomain: string = DEFAULT_BASE_DOMAIN,
  reserved: readonly string[] = RESERVED_SUBDOMAINS
): string | null {
  if (host === '') {
    return null;
  }
  const pattern = new RegExp(`^(${SUBDOMAIN_LABEL})\\.${escapeRegExp(baseDomain.toLowerCase())}$`, 'i');
  const match = host.match(pattern);
  if (!match) {
    return null;
  }
  const subdomain = match[1]!.toLowerCase();
  return reserved.includes(subdomain) ? null : subdomain;
}

/** Port of reya_is_root_host(). `host` must already be normalizeHost()'d. */
export function isRootHost(host: string, baseDomain: string = DEFAULT_BASE_DOMAIN): boolean {
  if (host === '') {
    return false;
  }
  const base = baseDomain.toLowerCase();
  return host === base || host === `www.${base}`;
}

function isPresentSignalValue(value: unknown): boolean {
  return value !== undefined && value !== null && value !== '';
}

/**
 * Port of reya_has_explicit_account_signal(): true iff `account`, `la`, or
 * `line_account_id` is present + non-empty in query OR body (GET checked
 * before POST for each key, but PHP's boolean result only cares whether
 * ANY of the six (key x source) checks hit — order doesn't change the outcome).
 */
export function hasExplicitAccountSignal(
  query: Record<string, unknown> | undefined,
  body: Record<string, unknown> | undefined
): boolean {
  for (const key of ACCOUNT_SIGNAL_KEYS) {
    if (query && Object.prototype.hasOwnProperty.call(query, key) && isPresentSignalValue(query[key])) {
      return true;
    }
    if (body && Object.prototype.hasOwnProperty.call(body, key) && isPresentSignalValue(body[key])) {
      return true;
    }
  }
  return false;
}

/**
 * Port of reya_root_tenant_slug(): unset/empty env -> default 'tenant-0001';
 * trim + lowercase; empty-after-trim -> null (no root mapping at all).
 */
export function normalizeRootTenantSlug(raw: string | undefined): string | null {
  const source = raw !== undefined && raw !== '' ? raw : DEFAULT_ROOT_TENANT_SLUG;
  const slug = source.toLowerCase().trim();
  return slug !== '' ? slug : null;
}

/**
 * Port of reya_resolve_tenant_from_host(). Does NOT itself emit HTTP
 * responses (no 404/503 page rendering) — that's the caller's job
 * (apps/admin/middleware.ts), this only returns a typed result for it to act
 * on. Never throws — DB errors are caught and treated as fail-safe fall-through,
 * exactly like the PHP `catch (\Throwable $e)` block.
 */
export async function resolveTenant(
  input: ResolveTenantInput,
  repo: TenantRepository,
  config: ResolveTenantConfig = {}
): Promise<ResolveTenantResult> {
  const baseDomain = config.baseDomain ?? DEFAULT_BASE_DOMAIN;
  const reserved = config.reservedSubdomains ?? RESERVED_SUBDOMAINS;

  const host = normalizeHost(input.host);
  if (host === '') {
    return { kind: 'none', reason: 'empty_host' };
  }

  let slug = extractSubdomain(host, baseDomain, reserved);
  let isRoot = false;

  if (slug === null) {
    const rootHost = isRootHost(host, baseDomain);
    const explicitSignal = hasExplicitAccountSignal(input.query, input.body);
    const rootSlug = normalizeRootTenantSlug(config.rootTenantSlug);

    if (rootHost && !explicitSignal && rootSlug !== null) {
      slug = rootSlug;
      isRoot = true;
    }

    if (slug === null) {
      return {
        kind: 'none',
        reason: rootHost && explicitSignal ? 'root_with_explicit_signal' : 'reserved_or_no_match',
      };
    }
  }

  let row: TenantRow | null;
  try {
    row = await repo.findBySlug(slug);
  } catch {
    // Fail-safe — mirrors resolve_subdomain.php's catch(\Throwable): log + fall through.
    return { kind: 'none', reason: 'lookup_error' };
  }

  if (!row) {
    if (isRoot) {
      // The root domain must never 404 on a misconfigured/unprovisioned default tenant.
      return { kind: 'none', reason: 'root_tenant_unconfigured' };
    }
    // A subdomain that LOOKS like a tenant slug but doesn't exist -> 404-equivalent,
    // never silently falls back to serving root-domain content under it.
    return { kind: 'not_found', slug };
  }

  if (row.status === 'pending_setup') {
    // Self-serve shops sit in pending_setup until approved: full access, but
    // demo-flagged for non-root tenants. The root/master tenant is never
    // demo-flagged even if its row happens to be pending_setup.
    return { kind: 'tenant', tenantId: row.id, slug, isRoot, demoMode: !isRoot };
  }

  if (row.status === 'suspended' || row.status === 'terminated') {
    if (isRoot) {
      // Never take the root domain offline on a status glitch.
      return { kind: 'none', reason: 'root_status_glitch' };
    }
    return { kind: 'suspended', tenantId: row.id, slug, displayName: row.displayName, status: row.status };
  }

  return { kind: 'tenant', tenantId: row.id, slug, isRoot, demoMode: false };
}
