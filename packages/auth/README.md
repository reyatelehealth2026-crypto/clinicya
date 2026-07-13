# @reya/auth

Two-realm (tenant/platform) stateful session kernel + PHP session-bridge
client for the Next.js migration (plan §1.4, §4 cross-cutting auth slice).
Port of `classes/AdminAuth.php`, `admin/platform-login.php`,
`admin/switch-tenant.php`, backed by a new `node_sessions` table in the
master DB instead of PHP `$_SESSION` files.

| PHP | @reya/auth |
|---|---|
| `AdminAuth::login()` / `logout()` | `login()` / `logout()` |
| `$_SESSION['admin_user']` / `$_SESSION['platform_user_*']` | `node_sessions` row (MySQL, source of truth) + Redis/in-memory cache |
| `AdminAuth::canAccessBot()` / `setCurrentBot()` | `canAccessBot()` / `switchBot()` |
| `admin/switch-tenant.php`'s enter/exit + `$writeAudit` | `switchTenant()` + `writeSuperAdminAudit()` |
| `session_regenerate_id(true)` on privilege elevation | `login()` and `switchTenant({type:'enter'|'exit'})` issue a fresh sid |
| N/A (new) | `internal/session-bridge.php` + `bridgeClient.ts`'s `syncToPhpBridge()` — mirrors Node's session state into legacy PHP `$_SESSION` so un-migrated PHP pages keep working |

## Build / test

```bash
pnpm --filter @reya/auth build   # tsc -b (builds @reya/config, @reya/db first via project references)
pnpm --filter @reya/auth test    # vitest — entirely offline: mysql2, ioredis, and fetch are all mocked
```

No test in this package opens a real DB, Redis, or HTTP connection.
`mysql2`/`ioredis` are `vi.mock()`'d exactly like `packages/db`/`packages/tenant`'s
existing tests (fake callback-style pool, see `tests/helpers/fakeMysqlPool.ts`);
`bridgeClient.ts` is tested against a stubbed global `fetch`.

## internal/session-bridge.php syntax check

`php` is on `PATH` in this dev container (`php -l internal/session-bridge.php`
exits 0 directly). If a future environment doesn't have `php` available,
use the dockerized one-liner instead:

```bash
docker run --rm -v "$PWD":/app php:8.2-cli php -l /app/internal/session-bridge.php
```

## Two flagged, additive-only deviations from the literal interfaceContract

Both are documented in code (`src/types.ts`) and neither renames/removes
anything mig-ui's apps/admin shell was briefed against:

1. **`RoleOf<S>` conditional type** instead of the contract's literal
   `S['role']` constraint on `requireRole()`. `PlatformSession` has no
   `.role` field (it's `.platformRole`), so `S['role']` doesn't actually
   type-check for `S = PlatformSession` (or the raw `Session` union) as
   written — `RoleOf<S>` resolves to `TenantRole` for a `TenantSession` and
   `PlatformRole` for a `PlatformSession`, preserving the exact call-site
   shape (`requireRole(session, allowed)`, same return type) while making it
   compile.
2. **`BridgeSyncPayload.sid: string`** (required) — the contract's
   `BridgeSyncPayload` (`action`, `phpSessionKeys`, `issuedAt`) never says
   how `internal/session-bridge.php` knows *which* PHP session to mutate.
   The Node opaque sid is reused as the PHP session id
   (`session_id($sid)` in the bridge, before `session_start()`), so this
   field carries it. **Consequence for mig-ui**: for a browser to actually
   see the bridged `$_SESSION` on a legacy PHP page, the login Route Handler
   must also set a `PHPSESSID` cookie equal to the same value as the
   returned `SessionCookieDescriptor.value` — that's outside this package
   (apps/admin shell / nginx strangler config), not implemented here.

## Required integration note for mig-ui (apps/admin)

`login({realm:'tenant', ...})` has no `tenantId` parameter (per contract —
`admin_users` lives in a per-tenant DB, but `LoginInput` only carries
`username`/`password`). The Route Handler **must** resolve the tenant from
the request (the `x-tenant-id` header `@reya/tenant`'s `resolveTenant()`
sets via `middleware.ts`), call `@reya/db`'s `getTenantDb(tenantId)`, and
wrap the call:

```ts
import { runWithTenantDb, login } from '@reya/auth';
import { getTenantDb } from '@reya/db';

const db = await getTenantDb(tenantId);
const result = await runWithTenantDb({ tenantId, db }, () => login(input));
```

`login()` throws (a programmer/wiring error, not an `AuthResult`) if called
for `realm:'tenant'` outside a `runWithTenantDb()` scope. `switchBot()` does
**not** need this — it derives the tenant DB from the existing session's
`tenantId` via `@reya/db` directly.

## Known Phase-1-scope simplifications

- `SessionStore.rotate()` is a sequential `DELETE` + `INSERT`, not wrapped in
  a DB transaction — a small race window exists between the two statements.
  Acceptable for this batch; revisit with `db.transaction().execute()` once
  this is exercised against a real pool (not just the offline `mysql2` mock).
- `login()` enforces **single-active-session-per-identity**: a fresh login
  deletes any other `node_sessions` rows already held by that identity+realm
  (`SessionStore.deleteAllForIdentity()`, using the migration's
  `(realm, admin_user_id)` / `(realm, platform_user_id)` indexes), and
  `CachedSessionStore` evicts the corresponding cache entries too (via
  `findByIdentity()` before the bulk delete — otherwise a stale sid would
  keep resolving out of cache after its DB row was gone). This is stricter
  than the PHP original (`session_regenerate_id(true)` only rotates the
  *current* browser's session; it doesn't invalidate a user's other
  sessions) — read as the acceptance criterion's literal requirement
  ("a getSession() call with the OLD sid afterward returns null").
- `platform_login` is not itself audited by `login()` (only `switchTenant()`
  enter/exit are, per the interfaceContract) — the legacy
  `admin/platform-login.php` does write a `platform_login` `super_admin_audit`
  row on every login; porting that is a straightforward follow-up if wanted,
  intentionally left out here to avoid inventing an undocumented side effect.

## Follow-up flagged for the schema-governance workstream owner (not built here)

`packages/db/migrations/master/migration_2026-07-12_node_sessions.sql` is a
committed, checksum-able artifact only — `packages/db/src/migrateAll.ts` /
`src/bin/migrate-all.ts` only walk `migrations/tenant/` against tenant DBs
(untouched in this batch, verify with `git diff` on those two files). Wiring
an equivalent master-DB runner is separate, not-yet-built schema-governance
work.
