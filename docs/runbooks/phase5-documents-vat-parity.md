# Phase 5 — documents/VAT parity

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 5
("Dispense + Documents/VAT (flip พร้อมกันเป็น atomic ต่อ tenant)"). This
runbook covers **only the Documents/VAT half** of Phase 5 — `genDocNumber`
(atomic Buddhist-era numbering), `calcVAT`, `formatThaiDate`, the rest of
`includes/document-helpers.php`, and the `list`/`get`/`create` actions of
`api/documents.php`. The dispense chain half is a **separate builder's
territory (dispenseChain)** and is out of scope here — see
`docs/runbooks/phase5-dispense-parity.md`.

Owner: documentsVat (`packages/core/**`,
`apps/admin/src/app/api/documents/**`).

## 1. What landed

### 1.1 `packages/core` (`@reya/core`) — port of `includes/document-helpers.php` (227 LOC)

| PHP source | Port | Notes |
|---|---|---|
| `REYA_DOCUMENT_TYPES` (lines 27-41) | `src/genDocNumber.ts` — `REYA_DOCUMENT_TYPES` const + `DocType` union + `isDocType()` | All 11 types (QT/BL/INV/RE/TAX/DN/CN/PO/GR/DNP/CNP), same `label`/`label_en`→`labelEn`/`prefix`/`group` shape |
| `genDocNumber()` (lines 55-113) | `src/genDocNumber.ts` — `genDocNumber(db, lineAccountId, docType, when?)` | `INSERT IGNORE` + `SELECT...FOR UPDATE` + `UPDATE` against `document_sequences`, via Kysely's query builder (`.ignore()`, `.forUpdate()`). Transaction ownership mirrors PHP's `$db->inTransaction()` check via Kysely's `isTransaction` getter — see §2.1 |
| `calcVAT()` (lines 123-140) | `src/vat.ts` | Uses `phpRound()` (see §2.2) for the 3 `round($x, 2)` calls |
| `computeLineTotal()` (lines 219-227) | `src/vat.ts` | |
| `formatMoney()` (lines 209-212) | `src/vat.ts` | |
| `formatThaiDate()` (lines 148-173) | `src/thaiDate.ts` | See §2.3 for the scope decision on PHP's much broader `DateTimeImmutable` grammar |
| `docTypeLabel()`/`docStatusLabel()`/`docStatusBadge()` (lines 175-204) | `src/docLabels.ts` | Ported for fidelity even though this round's JSON API only calls `docTypeLabel`/`docStatusLabel` (not the HTML-rendering `docStatusBadge`) |
| (new, not in PHP) | `src/phpRound.ts` — `phpRound(value, places)` | A from-scratch, empirically-validated port of PHP's `round()` — see §2.2 |

Barrel export: `src/index.ts`. `packages/core/package.json` models `packages/tenant`'s shape (`@reya/core`, CJS, `tsc -b` build, `@reya/db` + `kysely` + `mysql2` dependencies, `tsx` devDependency for the `test:live` script, matching `packages/db`'s `migrate-all`/`codegen` precedent).

### 1.2 `apps/admin/src/app/api/documents/**` — port of `api/documents.php`'s `list`/`get`/`create` actions

| PHP source | Next port | Notes |
|---|---|---|
| `case 'list':` (lines 212-279) | `route.ts` `GET` | `doc_type`/`status`/`q`/`from`/`to`/`page`/`per_page` filters, `per_page` clamped to `[10,200]` default 50, decorates each row with `doc_type_label`/`status_label`/`issue_date_thai` |
| `case 'create':` (lines 297-377) | `route.ts` `POST` | Atomic `genDocNumber()` + `documents_insert()` inside one `db.transaction().execute()` — see §2.1 |
| `case 'get':` (lines 282-294) | `[id]/route.ts` `GET` | Dynamic route segment instead of `?action=get&id=N` — same precedent as `api/inbox/actions/notes/[noteId]/route.ts` |
| `documents_fetch()` (lines 84-98) | `_lib/documents.ts` — `documentsFetch()` | |
| `documents_norm_items()` (lines 113-157) | `_lib/documents.ts` — `documentsNormItems()` | |
| `documents_insert()` (lines 159-204) | `_lib/documents.ts` — `documentsInsert()` | Uses `sql` raw-template inserts, not Kysely's typed `.values()` builder — `business_documents`' DATE columns are `Date`-typed on the Insertable side, and PHP never constructs a `DateTime` for these either (it binds the raw `YYYY-MM-DD` string). See the file's own doc comment |
| `documents_resolve_input()` (lines 100-111) | *(not ported — simplified)* | `await request.json()` directly, same simplification precedent `api/inbox/actions/send-message/route.ts` already used |
| Auth + `$lineAccountId` resolution (lines 26-76) | `_lib/session.ts` | `session.currentBotId ?? 1`, the established precedent at `api/inbox/conversations/route.ts:51` — PHP's 3-tier fallback chain (`admin_users` lookup / first-active `line_accounts`) is **not** replicated; `TenantSession` already carries the equivalent field |

**Out of scope this round** (per the brief, not stubbed either):
`update`/`approve`/`cancel`/`convert`/`pdf`/`export_csv` (`api/documents.php`
lines 380-688), `documents.php`'s own nav-tab UI,
`includes/documents/pdf-renderer.php`, `sales-tax-register.php`. The PHP
originals are untouched, read-only reference material.

Wiring: exactly one added line each in `apps/admin/package.json`
(`dependencies["@reya/core"] = "workspace:*"`) and `apps/admin/jest.config.js`
(`moduleNameMapper["^@reya/core$"]`), matching the existing
`@reya/db`/`@reya/tenant`/`@reya/auth`/`@reya/contracts` entries.

## 2. Fidelity notes

### 2.1 Transaction ownership — the atomicity property

PHP's `genDocNumber()` checks `$db->inTransaction()` to decide whether it
owns its own `beginTransaction()`/`commit()`/`rollBack()`, or whether it's
already running inside a caller's transaction (the `create` action's case:
one `$db->beginTransaction()` shared across `genDocNumber()` **and**
`documents_insert()`). The port uses Kysely's `isTransaction` getter
(`false` on a plain `Kysely<TenantDB>`, `true` on an open
`Transaction<TenantDB>` handle) for the identical check. When already inside
a transaction, `genDocNumber()` never issues its own `begin`/`commit` — it
just runs its 3 queries against the caller's handle and lets any later error
(e.g. the `business_documents` insert throwing) roll back **everything**,
including the `document_sequences.last_seq` bump, via the caller's own
`db.transaction().execute()` wrapper.

Proven at three layers:
- `packages/core/tests/genDocNumber.test.ts` — mocked Kysely, asserts the
  exact `begin`/`commit`/`rollback` sequence in both the own-transaction and
  shared-transaction cases, plus the "later query in the same shared
  transaction throws → rollback only, never commit" scenario.
- `apps/admin/src/app/api/documents/_lib/documents.test.ts` — same property
  at the `documentsInsert()` layer.
- `apps/admin/src/app/api/documents/route.test.ts` — same property at the
  full `POST` route-handler layer (the actual acceptance-criteria test: "when
  the `business_documents` insert throws after `genDocNumber()` already ran
  inside the same transaction, the whole transaction rolls back").
- **THE LIVE GATE** (real InnoDB row-locking, not a mock) — see §3.

### 2.2 `phpRound()` — satang-exact rounding, empirically validated against real PHP

PHP's `round($value, $places)` is not `Math.round(x*100)/100` — its C
implementation (`ext/standard/math.c`) applies a pre-rounding correction for
binary floating-point representation error (`round(1.005, 2) === 1.01` in
PHP, even though `1.005` is actually stored as `1.00499999999999989...` in
IEEE-754 binary64). `packages/core/src/phpRound.ts` reproduces this by
snapping the value to 15 significant decimal digits
(`Number.prototype.toPrecision(15)`, matching PHP's `DBL_DIG=15`) before and
after scaling by `10**places`, then rounding half-away-from-zero on the
now-clean value.

**Validation methodology** (not shipped as a runtime test dependency — see
below): a real `php` CLI (PHP 8.4, present in the build sandbox) executed
the actual, unmodified `includes/document-helpers.php` source against
thousands of generated inputs, dumping `(input, real-PHP-output)` pairs to
JSON. Those pairs became `packages/core/tests/fixtures/*-golden.json` —
`vat-golden.json` (1,511 cases, both `calcVAT` inclusive/exclusive paths),
`line-total-golden.json` (1,200 cases), `money-golden.json` (1,000 cases),
`thai-date-golden.json` (822 cases) — which the fast Vitest suite asserts
byte-for-byte against, with **zero mismatches** across every generated
corpus, the classic `x.xx5` edge cases (1.005, 2.675, 5.055, 100.005,
negative amounts), and explicit leap-day/year-boundary dates. The
generator scripts themselves were throwaway (`/tmp/.../roundcheck/*.php`,
not committed) — reproduce with:

```bash
php -r '
declare(strict_types=1);
require_once "includes/document-helpers.php";
mt_srand(13371337);
$out = [];
for ($i = 0; $i < 1500; $i++) {
    $subtotal = mt_rand(0, 100000000) / 10000.0;
    $rate = mt_rand(0, 3000) / 100.0;
    $inclusive = ($i % 2) === 0;
    $r = calcVAT($subtotal, $rate, $inclusive);
    $out[] = compact("subtotal", "rate", "inclusive") + $r;
}
echo json_encode($out);
' > /tmp/vat_cases.json
```

This is **strictly stronger evidence than a live `php` cross-check at test
time** would be for CI portability: the fast suite (`vitest run`, and thus
`pnpm turbo run test`) never spawns a `php` process and stays green on any
sandbox, while the numbers it asserts against were still genuinely produced
by executing the real PHP source, not re-derived from the same formula in
TypeScript (which would only prove internal self-consistency, not fidelity
to PHP).

### 2.3 `formatThaiDate()` — scope decision on PHP's date grammar

PHP's `DateTimeImmutable` constructor accepts a far broader grammar than
plain ISO `Y-m-d` (slash-separated dates, month names, trailing
time-of-day suffixes — e.g. `"2024-05-24T10:00:00"` parses fine in PHP,
ignoring the time part). Every real call site in this codebase only ever
feeds `formatThaiDate()` a `business_documents.issue_date` value — a SQL
`DATE` column, which MySQL always renders as a plain `YYYY-MM-DD` string —
so the port only supports that shape. What **is** replicated faithfully,
because PHP's own date tokenizer does it too: a day token in `00`-`31` and
a month token in `00`-`12` both parse even when not a real calendar date,
then roll over via ordinary calendar arithmetic (`2024-02-30` → 1 Mar 2567,
`2024-01-00` → 31 Dec 2566) — JS's `Date.UTC(year, monthIndex, day)`
performs the identical rollover, confirmed against real PHP output for
these exact inputs (see `thaiDate.ts`'s module doc for the full survey,
including `2024-01-32`/`2024-13-01` correctly failing to parse in PHP —
`DateMalformedStringException` — and being replicated as "return input
untouched").

### 2.4 Wire-format date parity (`Date` objects vs. PDO's raw strings)

`packages/db`'s mysql2 pool has no `dateStrings: true`, so
`business_documents`' DATE/DATETIME/TIMESTAMP columns hydrate as JS `Date`
objects, not PHP PDO's raw `YYYY-MM-DD[ HH:MM:SS]` strings. `_lib/documents.ts`
converts them back to PHP's raw string shape before they hit
`NextResponse.json()`, using the same "read with local getters" convention
this codebase already established (`api/inbox/messages/_lib/query.ts`'s
`toMysqlDateTimeString()`, `(tenant)/articles/_lib/format.ts`) — production/CI
pin `TZ=Asia/Bangkok` and the DB session is `SET time_zone='+07:00'`, so a
column's stored Bangkok-wall-clock digits round-trip onto the `Date`
object's local fields unshifted.

## 3. THE LIVE GATE — genDocNumber 50-concurrent-calls, real MariaDB 10.11

**Docker was unavailable in this build sandbox.** `docker ps` and
`service docker start` both fail with:

```
Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?
/etc/init.d/docker: 62: ulimit: error setting limit (Operation not permitted)
```

This is a sandbox-level restriction on nested Docker (the daemon itself
cannot start), not a missing `docker` CLI or a bug in the harness. The
harness was still fully built and exercised as far as this sandbox allows:
`pnpm --filter @reya/core test:live` was run and correctly attempted
`docker compose up -d`, correctly detected the daemon failure, and correctly
tore down (no-op, since nothing started) in its `finally` block, exiting 1:

```
[genDocNumber:live] docker compose up -d (project=docvat-core-live, port=33073) ...
[genDocNumber:live] docker compose down -v (teardown, always runs) ...
[genDocNumber:live] FAILED with an error: Error: docker compose up failed (exit 1): unable to get image 'mariadb:10.11': Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?
```

`docker ps -a --filter name=docvat-core-live` confirms nothing was left
running/orphaned.

**This is outcome (b)** per the acceptance criteria: a legitimate, honestly
reported result, but the live-numbering guarantee (real InnoDB row-locking
serializing 50 concurrent `INSERT IGNORE` + `SELECT...FOR UPDATE` +
`UPDATE` callers with zero collisions/gaps) is **unverified** and blocks
orchestrator co-sign until `pnpm --filter @reya/core test:live` is run
somewhere with real Docker access.

The `tests-live/genDocNumber.concurrency.ts` script itself was independently
typechecked in isolation (`tsc --noEmit` against a standalone tsconfig
covering `tests-live/**` + `src/**`, since `tests-live/` is deliberately
excluded from the package's own `tsconfig.json`/`lint` script) with zero
errors, and its `__dirname`-based path resolution matches
`packages/db/src/bin/migrate-all.ts`'s established CJS-script convention
(not `import.meta.url`, which would break under this package's
`"type": "commonjs"`).

### How to run it for real

```bash
cd packages/core
pnpm test:live
```

This will: generate a throwaway root password, `docker compose up -d`
(`tests-live/docker-compose.yml`, MariaDB 10.11, host port 33073 by
default — override with `DOCVAT_LIVE_DB_PORT`), wait for the healthcheck,
`CREATE TABLE document_sequences` from the exact DDL in
`tests-live/document_sequences.ddl.sql` (copied verbatim from
`database/migration_2026-05-25_tenant_template.sql` lines 2085-2095), fire
50 concurrent `genDocNumber(db, 999, 'QT')` calls against a real
mysql2+Kysely pool, assert 50 distinct doc numbers whose sequence tails
form exactly `{1..50}` and `document_sequences.last_seq === 50`, print a
`PASS`/`FAIL` summary, then **always** `docker compose down -v` in a
`finally` block regardless of outcome. Exit code 0 on pass, 1 on any
failure (bad result or thrown error) — paste the script's stdout here once
it has actually run against Docker.

Container/network naming (`docvat-core-live-*`) is unique to this stream so
it never collides with `infra/e2e`'s `e2e-*` harness or a sibling
migration stream's own scratch DB running concurrently in a different
worktree.

## 4. Commands

```bash
# Fast suite (Docker-independent, part of pnpm turbo run test):
pnpm install
pnpm turbo run test --filter=@reya/core --filter=admin

# Lint (tsc --noEmit) for both:
pnpm --filter @reya/core lint
pnpm --filter admin lint

# admin build (Next.js route-handler signature validation, extra assurance
# beyond plain tsc --noEmit):
pnpm --filter admin build

# THE LIVE GATE (separate, Docker-required, NOT part of the default `test` script):
pnpm --filter @reya/core test:live
```

## 5. Results (this build)

- `pnpm turbo run test --filter=@reya/core --filter=admin` — **green**.
  `@reya/core`: 4 test files, 47 tests. `admin`: 193 test files, 1,774
  tests (including this batch's 3 new suites:
  `api/documents/route.test.ts`, `api/documents/[id]/route.test.ts`,
  `api/documents/_lib/documents.test.ts`).
- `pnpm --filter @reya/core lint` — clean.
- `pnpm --filter admin lint` — clean.
- `pnpm --filter admin build` — succeeds; `/api/documents` and
  `/api/documents/[id]` both registered as dynamic (`ƒ`) routes.
- `pnpm --filter @reya/core test:live` — **could not complete**; see §3.

## 6. Deferred / out of scope (not silently dropped)

- `update`/`approve`/`cancel`/`convert`/`pdf`/`export_csv` actions
  (`api/documents.php` lines 380-688) — a future batch.
- `documents.php`'s own nav-tab admin UI, `includes/documents/pdf-renderer.php`,
  `sales-tax-register.php` — untouched.
- A minimal `apps/admin/src/app/(tenant)/documents/**` view was **not**
  added this round (the brief allowed it "only if" a minimal view was
  needed; the API-only deliverable was sufficient to satisfy every
  acceptance criterion).
- THE LIVE GATE (§3) — built, typechecked, exercised as far as this sandbox
  allows, but not run against a real database. Blocks orchestrator co-sign
  per the brief's explicit high-risk-phase rule until run with Docker
  access.
