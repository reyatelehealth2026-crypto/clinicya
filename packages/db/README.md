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

- `packages/db/migrations/tenant/` is still a placeholder directory
  (`.gitkeep` only) — no committed tenant migration files exist yet.
  `packages/db/migrations/master/migration_2026-07-12_node_sessions.sql` is
  committed but, per its own header comment, is applied by hand (as part of
  the "Regenerating the types" recipe below) rather than by any runner —
  `migrateAll()` only ever walks `migrations/tenant/`. Populating
  `migrations/tenant/` with the introspected-from-template committed
  migration files, wiring an equivalent runner for `migrations/master/`, and
  writing the one-time drift-audit reconciliation migrations are the
  remaining "Schema governance" cross-cutting workstream items (plan §4.1),
  tracked separately from this foundation batch.
- `kysely-codegen` **has** now been run for real against schema-complete
  scratch DBs and its output is committed (`src/generated/master-db.d.ts`,
  `src/generated/tenant-db.d.ts`) — see "Regenerating the types" below for
  the exact recipe. What's still open: those two files are a snapshot from
  one point in time; they are not regenerated automatically by CI or by
  `migrate-all`, so a schema change lands in the generated types only when
  someone re-runs the recipe below and commits the diff.

## Pool registry

```ts
import { getMasterDb, getTenantDb, tenantPoolRegistry, type MasterDB, type TenantDB } from '@reya/db';
import { sql } from 'kysely';

const master = getMasterDb();                          // Kysely<MasterDB> over zrismpsz_reya_platform
const tenantDb = await getTenantDb(42);                 // Kysely<TenantDB> over master.tenants[id=42].db_name

const rows = await sql`select 1`.execute(master);        // raw-SQL escape hatch (plan §1.2 rationale)
tenantPoolRegistry.size();                              // current live tenant pool count
```

`getMasterDb()`/`getTenantDb()` return `Kysely<MasterDB>` / `Kysely<TenantDB>`
(plan §1.2: registry is `Map<dbName, Kysely<TenantDB>>`), backed by a plain
(callback-style) `mysql2` `Pool` wrapped in Kysely's `MysqlDialect` — not
Prisma, and not a raw `mysql2/promise` pool. `MasterDB`/`TenantDB` are
`kysely-codegen`'s generated `DB` interface from `src/generated/{master,tenant}-db.d.ts`,
re-exported from `@reya/db`'s index under those aliases (kysely-codegen has
no `--interface-name` flag — the interface in both generated files is always
literally named `DB`, so every consumer imports it aliased: `import type { DB
as MasterDB } from './generated/master-db'` inside `@reya/db` itself, then
`import type { MasterDB } from '@reya/db'` everywhere else). See
"Regenerating the types" below for how these files are produced — **never
hand-edit `src/generated/*.d.ts`**, they are fully regenerable output.

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
touching `child_process.spawn` at all — this is the no-live-DB path, always
kept green regardless of whether a scratch DB is available:

```bash
pnpm --filter @reya/db run codegen -- master --dry-run
pnpm --filter @reya/db run codegen -- tenant --db=reya_tenant_0001 --dry-run
```

### Regenerating the types

`src/generated/master-db.d.ts` and `src/generated/tenant-db.d.ts` are
committed, generated output — **never hand-edit them**, treat them as fully
regenerable from this recipe. Both were produced against a throwaway local
MariaDB container, never against a shared/persistent DB, and the container
was torn down afterward — nothing about the scratch environment itself is
committed, only the two `.d.ts` files and this procedure.

**1. Bring up a scratch MariaDB (plain `docker run`, not a compose file):**

```bash
SCRATCH_PW=$(openssl rand -hex 16)   # throwaway, never written to a tracked file
docker run -d --name codegen-mariadb-scratch \
  -e MARIADB_ROOT_PASSWORD="$SCRATCH_PW" \
  -p 33061:3306 \
  mariadb:10.11
# wait for it to accept connections:
until docker exec codegen-mariadb-scratch mariadb -uroot -p"$SCRATCH_PW" -e "SELECT 1" >/dev/null 2>&1; do
  sleep 2
done
```

Use a `codegen-`-prefixed container name and a high host port — this is
unrelated to (and must never collide with) the mig-infra e2e MariaDB/Redis
stack, which uses its own container names/ports.

**2. Create the master DB — name matters:** `src/codegen.ts`'s
`resolveDbName()` hardcodes `zrismpsz_reya_platform` for `target=master`
regardless of any `--db` flag (which is ignored for that target), so the
scratch DB must be created with exactly that name:

```bash
docker exec codegen-mariadb-scratch mariadb -uroot -p"$SCRATCH_PW" -e "
  CREATE DATABASE IF NOT EXISTS zrismpsz_reya_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE DATABASE IF NOT EXISTS reya_tenant_scratch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"
```

**3. Apply the master DB migrations, IN ORDER** (the first four live in
`database/`, the last one is `packages/db/migrations/master/` and is
flagged in `packages/auth/README.md` as "not yet wired into any runner" — it
is applied by hand here, exactly as that flag says):

```bash
for f in \
  database/migration_2026-05-25_platform_master.sql \
  database/migration_2026-05-27_master_products.sql \
  database/migration_2026-05-27_tenant_line_account_routes.sql \
  database/migration_2026-06-04_platform_billing.sql \
  database/migration_2026-06-04_platform_billing_details.sql \
  packages/db/migrations/master/migration_2026-07-12_node_sessions.sql \
; do
  docker exec -i codegen-mariadb-scratch mariadb -uroot -p"$SCRATCH_PW" zrismpsz_reya_platform < "$f"
done
```

Despite the tenant-sounding filename, `migration_2026-05-27_tenant_line_account_routes.sql`
is a **platform**-DB table (`tenant_line_account_routes` lives in
`zrismpsz_reya_platform`, routing LINE OAs to tenants) — confirmed by its own
header comment, not by the filename. The first, fourth, fifth, and sixth
files each carry their own `USE \`zrismpsz_reya_platform\`;`; the second and
third don't, which is why the default DB is passed explicitly on the
`mariadb` command line above for all of them.

**4. Apply the tenant template** to the second scratch DB — this is the
representative ~280-table tenant schema `src/codegen.ts`'s own doc comment
already names as the intended source for this step:

```bash
docker exec -i codegen-mariadb-scratch mariadb -uroot -p"$SCRATCH_PW" reya_tenant_scratch \
  < database/migration_2026-05-25_tenant_template.sql
```

**5. Run codegen for both targets.** `CodegenEnvLike` has no `DB_PORT`
field, but `buildDatabaseUrl()` builds a real `mysql://host/db` URL string —
so a non-3306 scratch port folds into `DB_HOST` itself
(`DB_HOST=127.0.0.1:33061`). This URL-based trick is specific to this
codegen path; it does not apply to raw `mysql2` `Pool` configs elsewhere in
this monorepo (those take a separate `port` field).

```bash
DB_HOST=127.0.0.1:33061 DB_USER=root DB_PASS="$SCRATCH_PW" \
  pnpm --filter @reya/db run codegen -- master
# ✓ Introspected 13 tables and generated ./src/generated/master-db.d.ts

DB_HOST=127.0.0.1:33061 DB_USER=root DB_PASS="$SCRATCH_PW" \
  pnpm --filter @reya/db run codegen -- tenant --db=reya_tenant_scratch
# ✓ Introspected 279 tables and generated ./src/generated/tenant-db.d.ts
```

This writes `src/generated/master-db.d.ts` and `src/generated/tenant-db.d.ts`
(kysely-codegen's default `${outDir}/${target}-db.d.ts` naming — `--out-dir`
overrides `outDir`, the `master`/`tenant` half of the filename always tracks
`target`). kysely-codegen has no `--interface-name` flag, so the generated
interface in **both** files is always literally named `DB`. Import it
aliased, never rename it inside the generated file itself:

```ts
import type { DB as MasterDB } from './generated/master-db'; // masterPool.ts
import type { DB as TenantDB } from './generated/tenant-db';  // tenantPoolRegistry.ts
```

`@reya/db`'s `index.ts` re-exports both under those same aliases
(`export type { DB as MasterDB } from './generated/master-db'` /
`... TenantDB ... tenant-db`), so downstream packages (`@reya/auth`, etc.)
import `MasterDB`/`TenantDB` straight from `@reya/db` without reaching into
`./generated/*` themselves.

**6. `.gitignore` trap — generated files are silently untracked by default.**
The root `.gitignore` has a blanket `generated/` rule ("Build/scratch
artifacts — not source") that matches `packages/db/src/generated/` too.
`packages/db/src/generated/*.d.ts` IS source here (committed, regenerable
via this exact recipe) — the repo's own `.gitignore` carries a negation
whitelist for it, the same pattern CLAUDE.md documents for
`database/migration_*.sql`:

```gitignore
generated/
!packages/db/src/generated/
!packages/db/src/generated/*.d.ts
```

After regenerating, always confirm the files are actually stageable before
assuming the diff will land:

```bash
git status packages/db/src/generated/
git add -n packages/db/src/generated/master-db.d.ts packages/db/src/generated/tenant-db.d.ts
```

Codegen can succeed perfectly and still produce an empty `git diff` if this
whitelist entry is ever removed or the files move outside it.

**7. Tear down the scratch container** — nothing about it is committed:

```bash
docker rm -f codegen-mariadb-scratch
```

**Sanity checks worth re-running after a regenerate:**

```bash
grep -c '^export interface' packages/db/src/generated/tenant-db.d.ts   # ~280 (279 tables + the DB interface)
grep -n 'realm' packages/db/src/generated/master-db.d.ts | head        # NodeSessions.realm should be present
pnpm -r run build && pnpm -r run test && pnpm -r run lint              # whole workspace, not just @reya/db
```

## Build / test

```bash
pnpm --filter @reya/db build   # tsc -b (builds @reya/config first via project references)
pnpm --filter @reya/db test    # vitest — entirely offline, mysql2 is mocked
```
