import {
  createMasterTenantRepository,
  resolveTenant,
  type ResolveTenantConfig,
  type ResolveTenantResult,
  type TenantRepository,
} from '@reya/tenant';

/**
 * resolveRequestTenant.ts — thin wrapper around @reya/tenant's resolveTenant()
 * used by proxy.ts (Node.js runtime — see proxy.ts's module doc for why this
 * is `proxy.ts`, not `middleware.ts`, on the installed Next 16 line).
 *
 * Exported and independently unit-testable WITHOUT invoking real Next
 * middleware/proxy machinery: pass a fake TenantRepository (no mysql2, no
 * real master DB) and call this function directly. proxy.ts itself is a thin
 * adapter that extracts `host`/`query` from a real NextRequest and maps the
 * ResolveTenantResult to an HTTP response — none of that request/response
 * plumbing lives here.
 */

export interface ResolveRequestTenantOptions {
  /** Defaults to createMasterTenantRepository() (real master-DB-backed lookup). Inject a fake for tests. */
  repo?: TenantRepository;
  /** Passed straight through to resolveTenant() — baseDomain / rootTenantSlug / reservedSubdomains overrides. */
  config?: ResolveTenantConfig;
}

/**
 * `host` mirrors the raw HTTP Host header; `query` mirrors `$_GET` (parsed
 * query-string params) — same shape resolveTenant() itself expects. Never
 * throws (resolveTenant() is fail-safe internally); returns a typed
 * ResolveTenantResult for the caller (proxy.ts) to act on.
 */
export async function resolveRequestTenant(
  host: string | null | undefined,
  query: Record<string, unknown> | undefined,
  options: ResolveRequestTenantOptions = {}
): Promise<ResolveTenantResult> {
  const repo = options.repo ?? createMasterTenantRepository();
  return resolveTenant({ host, query }, repo, options.config);
}
