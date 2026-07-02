# ADR 0002: Shared LINE Mini App Runtime Tenant Resolution

## Status

Inferred, needs confirmation.

## Context

`line-mini-app/src/lib/config.ts` states the Mini App is one shared static/exported app served for every tenant. It resolves `line_account_id` from URL `?la=`, LIFF-scoped localStorage, resolver API, and build-time fallback. It resolves LIFF ID from `?liff_id=`, current-origin `api/miniapp-bootstrap.php`, and only then build-time default on root/localhost.

## Decision

Tenant and LIFF identity should be resolved at runtime rather than relying only on build-time `NEXT_PUBLIC_*` values.

## Consequences

- One build can serve many tenants.
- Tenant subdomains must call same-origin PHP APIs so subdomain routing can pin the tenant database.
- Client runtime tenant resolution must be paired with server-side identity validation for sensitive actions.

## Evidence

- `line-mini-app/src/lib/config.ts`: comments and functions `getLineAccountId`, `resolvePhpApiBaseUrl`, `resolveLiffId`, `resolveLineAccountId`.
- `api/miniapp-bootstrap.php`: current host LIFF bootstrap endpoint.
- `api/resolve-line-account.php`: LIFF to account resolver endpoint.
- `line-mini-app/src/lib/__tests__/config.test.ts`: tests for bootstrap URL/tenant signal.

## Last Verified From Code

Verified on 2026-07-03 from `line-mini-app/src/lib/config.ts`, `line-mini-app/src/lib/__tests__/config.test.ts`, `api/miniapp-bootstrap.php`, `api/resolve-line-account.php`.
