import type { NextRequest } from 'next/server';
import type { Kysely } from 'kysely';
import { createMasterLineAccountRouteRepository, routeByLineAccount } from '@reya/tenant';
import { getTenantDb, type TenantDB } from '@reya/db';
// NOTE: only the ambient tenant-db context (runWithTenantDb/TenantDbContext) is imported from
// @reya/auth here — never getSession/requireRole/login. These /api/miniapp/** routes replicate the PHP
// originals' trust-on-input identity model (line_user_id + line_account_id taken at face value, no
// server-side LIFF token verification) and must NEVER gain a session/auth gate the PHP didn't have.
import { runWithTenantDb, type TenantDbContext } from '@reya/auth';

/**
 * tenant.ts — the two-phase tenant pin shared by every `/api/miniapp/**`
 * Route Handler except `resolve-line-account` (deliberately tenant-agnostic —
 * see its own route.ts doc comment), per contractNote point 2 "TENANT
 * RESOLUTION", replicating bootstrap/resolve_subdomain.php +
 * bootstrap/route_by_account.php's real ordering:
 *
 *   a. proxy.ts already ran resolveTenant() against the Host header for
 *      every request (its matcher covers /api/miniapp/** too) and set
 *      `x-tenant-id` if it resolved a tenant. If present here, treat it as
 *      ALREADY PINNED — mirrors `TenantContext::getCurrentTenantId() !==
 *      null -> return` in bootstrap/route_by_account.php. Do NOT re-derive
 *      tenant from a line_account_id elsewhere in the request in this case,
 *      even if the JSON body carries a different one — an existing PHP
 *      quirk (see contractNote point 8), preserved on purpose, not a bug.
 *   b. If `x-tenant-id` is absent, call `routeByLineAccount()` from
 *      `@reya/tenant` with `{pinnedTenantId: null, method, query, jsonBody}`
 *      using `createMasterLineAccountRouteRepository()` — extracting
 *      `line_account_id`/`la`/`account` from the query string (GET) or the
 *      parsed JSON body (POST; line-mini-app always sends JSON, never
 *      form-urlencoded).
 *   c. If neither resolves a tenant: the caller responds
 *      `{success:false, error:'tenant_unresolved'}` HTTP 400 — see
 *      TENANT_UNRESOLVED_RESPONSE/TENANT_UNRESOLVED_STATUS below. There is
 *      NO legacy-DB fallback in the Next stack (PHP silently falls back to a
 *      shared legacy DB here — deliberately NOT replicated; real mini-app
 *      traffic always carries either a Host-pinned subdomain or a
 *      line_account_id, so this should only ever be hit by
 *      malformed/adversarial requests).
 *
 * `signals` (method/query/jsonBody) is a caller-supplied parameter rather
 * than derived internally from `request` because a `Request` body stream
 * can only be consumed ONCE — every POST route handler also needs the
 * parsed JSON body for its own action dispatch/validation, so the route
 * handler parses it once and passes it to both this function and its own
 * logic, rather than this file re-reading (and exhausting) the stream.
 */

export interface MiniappRequestSignals {
  method: string;
  /** `$_GET` equivalent (GET requests: the parsed query string). */
  query?: Record<string, unknown>;
  /** Parsed JSON body — line-mini-app always sends JSON on POST, never form-urlencoded. */
  jsonBody?: Record<string, unknown> | null;
}

export type ResolveMiniappTenantResult = { ok: true; context: TenantDbContext } | { ok: false };

/** The literal response body for an unresolved tenant, per contractNote point 2c. HTTP 400. */
export const TENANT_UNRESOLVED_RESPONSE = { success: false, error: 'tenant_unresolved' } as const;
export const TENANT_UNRESOLVED_STATUS = 400;

/**
 * Resolves which tenant DB this /api/miniapp/** request belongs to, per the
 * two-phase pin above. Never throws — a lookup failure inside
 * routeByLineAccount() is already swallowed there (mirrors PHP's
 * `catch (\Throwable $e) { error_log(...); }` fail-safe) and surfaces as
 * `{ ok: false }` here.
 */
export async function resolveMiniappTenantContext(
  request: NextRequest,
  signals: MiniappRequestSignals
): Promise<ResolveMiniappTenantResult> {
  const pinnedHeader = request.headers.get('x-tenant-id');
  let tenantId: number | null = null;

  if (pinnedHeader !== null) {
    const parsedHeader = Number(pinnedHeader);
    if (Number.isFinite(parsedHeader) && parsedHeader > 0) {
      tenantId = parsedHeader;
    }
  }

  if (tenantId === null) {
    const routed = await routeByLineAccount(
      { pinnedTenantId: null, method: signals.method, query: signals.query, jsonBody: signals.jsonBody },
      createMasterLineAccountRouteRepository()
    );
    if (routed.applied) {
      tenantId = routed.tenantId;
    }
  }

  if (tenantId === null) {
    return { ok: false };
  }

  const db: Kysely<TenantDB> = await getTenantDb(tenantId);
  return { ok: true, context: { tenantId, db } };
}

/**
 * Resolves the tenant, then runs `handler` inside the ambient
 * AsyncLocalStorage scope (@reya/auth's `runWithTenantDb`) — reuses the
 * EXACT context object/shape login()'s tenant-realm branch already
 * established, per contractNote's "do not invent a second ambient-context
 * mechanism". Returns `{ ok: false }` (no `handler` call) when the tenant
 * could not be resolved; the caller is expected to respond with
 * `TENANT_UNRESOLVED_RESPONSE`/`TENANT_UNRESOLVED_STATUS` in that case.
 */
export async function withMiniappTenant<T>(
  request: NextRequest,
  signals: MiniappRequestSignals,
  handler: (context: TenantDbContext) => Promise<T>
): Promise<{ ok: true; value: T } | { ok: false }> {
  const resolved = await resolveMiniappTenantContext(request, signals);
  if (!resolved.ok) {
    return { ok: false };
  }
  const value = await runWithTenantDb(resolved.context, () => handler(resolved.context));
  return { ok: true, value };
}
