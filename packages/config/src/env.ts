import { z } from 'zod';

/**
 * env.ts — zod-validated environment schema for the Next.js migration kernel.
 *
 * Mirrors the constants read directly from `config/config.php` (DB_HOST,
 * DB_USER, DB_PASS) and from `getenv()` calls in bootstrap/resolve_subdomain.php
 * (REYA_BASE_DOMAIN, REYA_ROOT_TENANT_SLUG). REDIS_URL mirrors the variable
 * already used by docker-compose.dev.yml / .env.*.example.
 *
 * Intentionally lazy: importing this module has NO side effects and does NOT
 * read process.env at import time. Call loadEnv() explicitly — this lets every
 * package (and every test) opt into validation only when it actually needs a
 * connection, instead of crashing at import time in environments (like CI unit
 * tests) that never set real DB credentials.
 */

export const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),

  /** MariaDB host — shared by master + every tenant DB (ADR-001: same instance). */
  DB_HOST: z.string().min(1).default('localhost'),
  /** MariaDB user. cPanel-prefixed in production (e.g. zrismpsz_clinicya) — see config/config.php. */
  DB_USER: z.string().min(1),
  /** MariaDB password. No default — must never silently fall back to an empty credential. */
  DB_PASS: z.string().min(1),

  /**
   * Base domain for tenant subdomain routing, e.g. "re-ya.com" — a request to
   * "tenant-0001.re-ya.com" resolves subdomain "tenant-0001" against this.
   * Mirrors bootstrap/resolve_subdomain.php::reya_base_domain().
   */
  REYA_BASE_DOMAIN: z.string().min(1).default('re-ya.com'),

  /**
   * The tenant slug the bare root domain (REYA_BASE_DOMAIN / www.<base>) maps
   * to when no explicit LINE-account routing signal is present. Raw string is
   * passed through as-is here — packages/tenant applies the
   * PHP-equivalent trim/lowercase/"empty means disabled" normalisation
   * (mirrors reya_root_tenant_slug()), since that's a tenant-resolution
   * concern, not a generic env-parsing concern.
   */
  REYA_ROOT_TENANT_SLUG: z.string().optional(),

  /** Redis connection string — session cache, pub/sub, BullMQ (later phases). */
  REDIS_URL: z.string().min(1).default('redis://localhost:6379'),

  /**
   * @reya/auth: internal-network-only URL for internal/session-bridge.php —
   * the bidirectional PHP session bridge (plan §1.4). Never exposed
   * publicly; the actual nginx/firewall enforcement that keeps this off the
   * public internet is mig-infra's job (plan Phase 0/13), not this schema's.
   */
  SESSION_BRIDGE_URL: z.string().min(1).default('http://php-internal/internal/session-bridge.php'),

  /**
   * Shared HMAC-SHA256 secret between @reya/auth's bridgeClient.ts and
   * internal/session-bridge.php's request-signature check. The default here
   * is an obvious placeholder for local/dev only — MUST be overridden with a
   * real secret everywhere else (never a real credential committed to git).
   */
  SESSION_BRIDGE_HMAC_SECRET: z.string().min(1).default('change-me-session-bridge-hmac-secret'),

  /** @reya/auth: node_sessions row lifetime in seconds — SessionCookieDescriptor.maxAge mirrors this. */
  NODE_SESSION_TTL_SECONDS: z.coerce.number().int().positive().default(86400),

  /**
   * Escape hatch for HTTP-only deployments. Session cookies are marked
   * `Secure` outside development, which is correct — but a browser silently
   * DISCARDS a Secure cookie served over plain http, so login appears to do
   * nothing at all: the POST succeeds, the 303 fires, and the next request
   * arrives with no session. Exactly what the VPS trial stack hit, where the
   * strangler edge listens on plain http.
   *
   * Set to '1' ONLY for a throwaway HTTP trial. Leaving it unset keeps the
   * secure-by-default behaviour, and any real deployment must terminate TLS
   * (see infra/compose/docker-compose.vps-tls.yml) rather than set this.
   */
  SESSION_COOKIE_INSECURE: z.enum(['0', '1']).default('0'),
});

export type Env = z.infer<typeof envSchema>;

/**
 * PLATFORM_DB_NAME — equivalent of classes/TenantContext.php::PLATFORM_DB_NAME.
 * Hardcoded (not env-driven) for the same reason as the PHP original: cPanel
 * shared-hosting requires the account prefix and this name is not expected to
 * vary per environment. Keep in sync with TenantContext::PLATFORM_DB_NAME.
 */
export const PLATFORM_DB_NAME = 'zrismpsz_reya_platform' as const;

let cachedEnv: Env | null = null;

export interface LoadEnvOptions {
  /** Bypass the module-level cache and re-parse `source` even if loadEnv() was called before. */
  fresh?: boolean;
}

/**
 * Parses + validates environment variables. Throws a descriptive Error on the
 * first invalid/missing required var (fail-fast — no silent empty-credential
 * fallback, unlike PHP's config/config.php hardcoded defaults).
 *
 * Caches the parsed result after the first successful call (per Node process,
 * same as PHP's per-request constant defines being effectively "cached" for
 * the request's lifetime) — pass `{ fresh: true }` to force re-validation,
 * e.g. in tests that vary the source between cases.
 */
export function loadEnv(source: NodeJS.ProcessEnv = process.env, options: LoadEnvOptions = {}): Env {
  if (cachedEnv && !options.fresh) {
    return cachedEnv;
  }

  const result = envSchema.safeParse(source);
  if (!result.success) {
    const details = result.error.issues
      .map((issue) => `  - ${issue.path.join('.') || '(root)'}: ${issue.message}`)
      .join('\n');
    throw new Error(`Invalid environment configuration:\n${details}`);
  }

  cachedEnv = result.data;
  return cachedEnv;
}

/** Test hook — clears the module-level cache. Mirrors TenantContext::reset()'s intent for tests. */
export function resetEnvCache(): void {
  cachedEnv = null;
}
