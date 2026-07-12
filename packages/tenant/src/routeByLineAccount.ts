/**
 * routeByLineAccount.ts — exact port of bootstrap/route_by_account.php +
 * classes/TenantContext.php::routeByLineAccount()/resolveTenantByLineAccount().
 *
 * Used by root-domain entry points (webhook.php, api/checkout.php,
 * api/member.php, api/orders.php, ...) that can't resolve a tenant from the
 * Host header — LINE webhook URLs and the LIFF Mini App both load from the
 * root domain, so `?account=N` / `?la=N` / `line_account_id` in the body is
 * the only signal available.
 */

export const LINE_ACCOUNT_SIGNAL_KEYS = ['line_account_id', 'la', 'account'] as const;

export interface RouteByLineAccountInput {
  /** Current tenant pin (e.g. TenantContext.getCurrentTenantId()) — null means "not yet pinned". */
  pinnedTenantId: number | null;
  method: string;
  /** `$_GET` equivalent. */
  query?: Record<string, unknown>;
  /** `$_POST` equivalent (form fields). */
  body?: Record<string, unknown>;
  /** Parsed JSON body — only consulted when method === 'POST', mirrors decoding php://input. */
  jsonBody?: Record<string, unknown> | null;
}

export interface LineAccountRouteRepository {
  /**
   * SELECT tenant_id FROM tenant_line_account_routes
   *   WHERE line_account_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1
   */
  findTenantIdByLineAccountId(lineAccountId: number): Promise<number | null>;
  /**
   * Best-effort telemetry: UPDATE ... SET last_seen_at = NOW() ... — mirrors
   * TenantContext::routeByLineAccount()'s fire-and-forget UPDATE. Optional;
   * failures here are always swallowed and never affect the routing result.
   */
  touchLastSeen?(lineAccountId: number, tenantId: number): Promise<void>;
}

export type RouteByLineAccountReason =
  | 'already_pinned'
  | 'no_signal'
  | 'not_numeric'
  | 'not_positive'
  | 'no_route'
  | 'lookup_error';

export type RouteByLineAccountResult =
  | { applied: true; tenantId: number; lineAccountId: number }
  | { applied: false; reason: RouteByLineAccountReason };

function isSetValue(value: unknown): boolean {
  return value !== undefined && value !== null;
}

/**
 * Mirrors PHP's `$source['a'] ?? $source['b'] ?? $source['c'] ?? null`:
 * the FIRST key that is *set* (isset — present and non-null) wins, even if
 * its value is an empty string. This is deliberately NOT "first non-empty
 * key" — see extractLineAccountCandidate()'s doc comment for why that
 * distinction matters and is preserved on purpose.
 */
function coalesceFirstSet(source: Record<string, unknown> | null | undefined, keys: readonly string[]): unknown {
  if (!source) {
    return null;
  }
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(source, key) && isSetValue(source[key])) {
      return source[key];
    }
  }
  return null;
}

function isEmptyCandidate(value: unknown): boolean {
  return value === null || value === undefined || value === '';
}

/**
 * Port of route_by_account.php's candidate resolution:
 *
 *   $candidate = $_GET['line_account_id'] ?? $_GET['la'] ?? $_GET['account'] ?? null;
 *   if ($candidate === null || $candidate === '') { ...try $_POST the same way... }
 *   if ((still empty) && method === POST) { ...try the JSON body the same way... }
 *
 * IMPORTANT quirk preserved on purpose: within one source (GET/POST/JSON),
 * the FIRST *set* key wins even if it's an empty string — e.g.
 * `?line_account_id=&la=5` yields `''` from GET (line_account_id is set,
 * so `la` is never consulted), and because that GET-stage result is empty,
 * the whole GET source is abandoned in favour of POST — NOT "fall through
 * to la within GET". Only once a whole SOURCE yields empty do we move to
 * the next source.
 */
export function extractLineAccountCandidate(input: RouteByLineAccountInput): unknown {
  let candidate = coalesceFirstSet(input.query, LINE_ACCOUNT_SIGNAL_KEYS);
  if (isEmptyCandidate(candidate)) {
    candidate = coalesceFirstSet(input.body, LINE_ACCOUNT_SIGNAL_KEYS);
  }
  if (isEmptyCandidate(candidate) && input.method === 'POST') {
    candidate = coalesceFirstSet(input.jsonBody, LINE_ACCOUNT_SIGNAL_KEYS);
  }
  return isEmptyCandidate(candidate) ? null : candidate;
}

/** PHP is_numeric() equivalent for the realistic candidate shapes (string|number) coming from query/body/JSON. */
export function isNumericCandidate(value: unknown): boolean {
  if (typeof value === 'number') {
    return Number.isFinite(value);
  }
  if (typeof value !== 'string') {
    return false;
  }
  const trimmed = value.trim();
  if (trimmed === '') {
    return false;
  }
  return /^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/.test(trimmed);
}

/**
 * Port of TenantContext::routeByLineAccount() combined with the
 * bootstrap/route_by_account.php entrypoint. Never throws — a repository
 * error is caught and treated as "no route found", mirroring the PHP
 * `catch (\Throwable $e) { error_log(...); }` fail-safe.
 */
export async function routeByLineAccount(
  input: RouteByLineAccountInput,
  repo: LineAccountRouteRepository
): Promise<RouteByLineAccountResult> {
  // Already pinned by subdomain resolution or an earlier hop — respect it.
  if (input.pinnedTenantId !== null) {
    return { applied: false, reason: 'already_pinned' };
  }

  const candidate = extractLineAccountCandidate(input);
  if (candidate === null) {
    return { applied: false, reason: 'no_signal' };
  }
  if (!isNumericCandidate(candidate)) {
    return { applied: false, reason: 'not_numeric' };
  }

  const lineAccountId = Math.trunc(Number(candidate));
  if (lineAccountId <= 0) {
    return { applied: false, reason: 'not_positive' };
  }

  let tenantId: number | null;
  try {
    tenantId = await repo.findTenantIdByLineAccountId(lineAccountId);
  } catch {
    return { applied: false, reason: 'lookup_error' };
  }

  if (tenantId === null) {
    return { applied: false, reason: 'no_route' };
  }

  if (repo.touchLastSeen) {
    try {
      await repo.touchLastSeen(lineAccountId, tenantId);
    } catch {
      // Telemetry only — never let this fail the routing decision.
    }
  }

  return { applied: true, tenantId, lineAccountId };
}
