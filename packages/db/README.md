# @reya/db

Kysely + mysql2 data-access kernel for the Next.js migration (plan §1.2, §4.1).
Replaces `modules/Core/Database.php`'s per-request PDO factory:

| PHP | @reya/db |
|---|---|
| `Database::platform()->getConnection()` | `getMasterDb()` |
| `Database::forTenant($id)->getConnection()` | `await getTenantDb(tenantId)` |
| per-request PDO (opened + thrown away every request) | process-wide LRU pool registry (plan §1.2: ~50 pools, idle-evict 10 min, connectionLimit 3-5) |

Every new physical connection (master or tenant) runs `SET time_zone =
'+07:00'` and uses `utf8mb4_unicode_ci`, mirroring the PHP constructors byte
for byte.

## What is NOT in this batch

- `packages/db/migrations/{master,tenant}/*.sql` are placeholder directories
  (`.gitkeep` only). Populating them — introspecting the template 280-table
  schema + master into committed migration files, and writing the one-time
  drift-audit reconciliation migrations — is the "Schema governance"
  cross-cutting workstream (plan §4.1), tracked separately from this
  foundation batch.
- `kysely-codegen` has never been run against a real DB from this container
  (no live tenant/master DB is reachable here). `src/codegen.ts` +
  `scripts/codegen.sh` are ready to run the moment one is — see below.

## Pool registry

```ts
import { getMasterDb, getTenantDb, tenantPoolRegistry } from '@reya/db';
import { sql } from 'kysely';

const master = getMasterDb();                          // Kysely<any> over zrismpsz_reya_platform
const tenantDb = await getTenantDb(42);                 // Kysely<any> over master.tenants[id=42].db_name

const rows = await sql`select 1`.execute(master);        // raw-SQL escape hatch (plan §1.2 rationale)
tenantPoolRegistry.size();                              // current live tenant pool count
```

`getMasterDb()`/`getTenantDb()` return `Kysely<any>` (plan §1.2: registry is
`Map<dbName, Kysely<TenantDB>>`), backed by a plain (callback-style) `mysql2`
`Pool` wrapped in Kysely's `MysqlDialect` — not Prisma, and not a raw
`mysql2/promise` pool. `any` because no `kysely-codegen` output exists yet in
this batch (no live DB to introspect from this container); once
`scripts/codegen.sh` has been run for real, swap `Kysely<any>` for the
generated `Database` interface — every call site threads the type parameter
through, so it's a one-line change here, not a call-site migration.

Internally, each registry entry keeps the **raw mysql2 pool** alongside the
Kysely wrapper and evicts by calling `pool.end()` on the raw pool directly,
never `Kysely#destroy()` — Kysely's driver only opens the pool lazily on the
first query and its `destroy()` is a no-op if no query ever ran, which would
make LRU/idle eviction non-deterministic if we routed through it.

- `db_name` lookups are cached 60s per tenantId (`TenantPoolRegistry`
  constructor option `dbNameCacheTtlMs`).
- The pool map is an LRU with capacity 50 (`maxPools`) — once full, adding a
  new tenant pool evicts the least-recently-touched one.
- A pool untouched for 10 minutes (`idleEvictMs`) is evicted even if the
  registry isn't full.
- `connectionLimit` must be 3-5 inclusive (plan §1.2) — the constructor throws
  a `RangeError` outside that range.
- `TenantNotFoundError` is thrown when `master.tenants` has no row for the
  given id (mirrors `Database::forTenant()`'s `RuntimeException`).

All of the above is unit-tested against a mocked `mysql2` (the plain,
callback-style export Kysely's `MysqlDialect` requires — not
`mysql2/promise`) — see `tests/tenantPoolRegistry.test.ts` (LRU eviction,
idle eviction via fake timers, connectionLimit pass-through, 60s db_name
cache) and `tests/helpers/fakeMysqlPool.ts` (the shared fake pool: `pool.getConnection()`
→ `connection.query()` → `connection.release()`, faithful enough for
`MysqlDialect` to drive end-to-end without ever opening a socket).

## migrate-all runner

```bash
pnpm --filter @reya/db run migrate-all -- --dry-run
pnpm --filter @reya/db run migrate-all -- --tenant=42
pnpm --filter @reya/db run migrate-all -- --continue-on-error
```

Applies every `*.sql` file under `migrations/tenant/` (sorted by filename) to
each tenant DB (or one, via `--tenant`) that hasn't already recorded it as
`applied` in the master DB's **existing** `tenant_migrations` ledger table
(`database/migration_2026-05-25_platform_master.sql`). `--dry-run` computes
the plan and prints it without applying anything or writing to the ledger.
`--continue-on-error` keeps going after a failing tenant/migration instead of
aborting the whole run (a `failed` row is still recorded either way).

The runner core (`migrateAll()`) is dependency-injected and fully unit-tested
offline (`tests/migrateAll.test.ts`) — no real DB or filesystem touched.
`createDefaultMigrateAllDeps()` wires the real fs + `@reya/db` implementation
for CLI use; it has not been run against a live DB from this container.

## kysely-codegen

Two introspection targets, run separately (kysely-codegen introspects one DB
per invocation):

```bash
# Master DB (zrismpsz_reya_platform) — writes src/generated/master-db.d.ts
DB_HOST=<host> DB_USER=<user> DB_PASS=<pass> \
  pnpm --filter @reya/db run codegen -- master

# One tenant DB (or a scratch DB restored from
# database/migration_2026-05-25_tenant_template.sql as the representative
# 280-table schema) — writes src/generated/tenant-db.d.ts
DB_HOST=<host> DB_USER=<user> DB_PASS=<pass> \
  pnpm --filter @reya/db run codegen -- tenant --db=reya_tenant_0001
```

Equivalent via the shell wrapper (used by ops/CI, prefers `dist/` if built):

```bash
DB_HOST=<host> DB_USER=<user> DB_PASS=<pass> \
  ./packages/db/scripts/codegen.sh tenant --db=reya_tenant_0001
```

`--dry-run` (or omitting `--db` for the tenant target, which throws before
ever reaching `child_process`) prints the exact `kysely-codegen ...`
invocation that would run, with the password redacted, and exits without
touching `child_process.spawn` at all — this is what's exercised in this
container instead of the plan's "codegen 280 ตาราง ผ่าน" acceptance line,
which needs a live DB:

```bash
pnpm --filter @reya/db run codegen -- master --dry-run
pnpm --filter @reya/db run codegen -- tenant --db=reya_tenant_0001 --dry-run
```

## Build / test

```bash
pnpm --filter @reya/db build   # tsc -b (builds @reya/config first via project references)
pnpm --filter @reya/db test    # vitest — entirely offline, mysql2 is mocked
```
