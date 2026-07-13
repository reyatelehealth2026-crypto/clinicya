# Phase 3 batch 2 — miniapp JSON API parity harness (extension)

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 3
(API/service port). Owner: mig-infra (this harness) / mig-api-reads +
mig-api-writes (the two endpoint-porting agents whose Next output this
harness verifies) / mig-verify (re-review gate) / mig-orchestrator
(canary-ramp authorization). Cross-reference:
[`docs/runbooks/phase3-batch1-miniapp-api-parity.md`](./phase3-batch1-miniapp-api-parity.md)
(the harness this document EXTENDS, not replaces — same JSON-line-output
convention, same "single seeded tenant, not a live-traffic shadow" limits
framing, same `infra/e2e/lib/api-extract.mjs`/`infra/e2e/api-parity.mjs`
split; read that document first, this one only covers what changed).

## 0. What changed, in one paragraph

`infra/e2e/api-parity.mjs` now covers **38** endpoint x action pairs, not 16:
the 16 batch-1 pairs (untouched) plus **19 new PHP-vs-Next diffable pairs**
(appointments x5, health-profile-writes x6, consent:save, data-rights x3,
medication-reminders x4) plus **3 structurally separate NEXT-ONLY
self-consistency pairs** (addresses:list/upsert/delete — there is no PHP
source for this endpoint at all). Same single command:

```bash
node infra/e2e/api-parity.mjs
```

Same "reuses the ONE seeded tenant, `e2e-api-parity-harness`, no second
tenant" constraint, same "cannot run concurrently with `run.mjs`/`parity.mjs`"
constraint (unmodified `infra/e2e/docker-compose.yml`), same
always-tears-down-in-`finally` / one-JSON-line-on-stdout / exit-0-only-on-PASS
contract.

## 1. The addresses no-PHP-source special-casing (why 3 of the 38 pairs are NOT parity checks)

`/api/miniapp/addresses` (`list`/`upsert`/`delete`) has **no PHP endpoint to
diff against**. Verified exhaustively (by mig-api's own brief, re-verified
here): `ls api/*address*.php` returns nothing, a repo-wide grep for
`user-addresses`/`user_addresses` under `*.php` returns nothing, and
`git log --all -- api/user-addresses.php` comes up empty. This is a
**genuine, pre-existing production gap** — `line-mini-app/src/lib/addresses-api.ts`
and the live `AddressesSheet.tsx` component both call `/api/user-addresses.php`
today, so this feature 404s in production right now. apps/admin's port
(`apps/admin/src/app/api/miniapp/addresses/**`) is therefore a **first-class
Next implementation**, built from (a) the client contract already shipped in
`addresses-api.ts` and (b) the `user_addresses` table, which was already
present in `database/migration_2026-05-25_tenant_template.sql` before this
batch (columns: `id, line_user_id, line_account_id DEFAULT 0, label, name,
phone, address, subdistrict, district, province, postcode, created_at,
updated_at`, `UNIQUE KEY unique_user_label (line_user_id, line_account_id,
label)` — notably **no `user_id` column**, so unlike every other endpoint in
this batch there is no `users` row to resolve or auto-create for this one).

Because there is no PHP response to diff against, this harness's normal
`callStack()`-diffs-PHP-vs-Next mechanism is **meaningless** for these 3
cases. `infra/e2e/lib/api-extract.mjs` exports them from a **separate**
`NEXT_ONLY_CASES` array (not mixed into `ENDPOINT_CASES`), each entry has no
`phpPath` field at all (by design, not merely omitted), and
`infra/e2e/api-parity.mjs` runs them through a **separate** function,
`runNextOnlyCase()` (not `runApiCase()`). Each case instead:

1. Calls the Next endpoint only.
2. Validates the JSON response against the **real zod schema** imported from
   `@reya/contracts` (`AddressesListResponseSchema` /
   `AddressesUpsertResponseSchema` / `AddressesDeleteResponseSchema` — the
   actual contract mig-api's brief landed, not a hand-rolled shape re-derived
   here).
3. For `upsert`/`delete` (the two WRITE actions), runs the SAME `dbCheck`
   mechanism `ENDPOINT_CASES` uses — but comparing the resulting/deleted row
   against a literal `expect`ed value (there is no second, PHP-side row to
   diff against), proving the write actually happened, not just that the
   HTTP call returned 200.

Every printed result for these 3 cases carries an explicit
`"mode":"next-only-self-consistency"` field — on **both** a PASS and a FAIL,
not just on failure — specifically so a reader of the final summary JSON can
never mistake one of these 3 for a 20th/21st/22nd real PHP-vs-Next parity
PASS. **A green result here proves "the Next port is internally correct and
matches its own contract," not "the Next port matches PHP."** There is
nothing to fix about that distinction — it is the correct, honest
description of what these 3 cases can and cannot prove, given there is no
PHP original.

## 2. The consent/data-rights Host-header-pin mechanism, and why

`api/consent.php` and `api/data-rights.php` are **both** conspicuously
missing `require_once bootstrap/route_by_account.php` — verified by reading
both files in full. Every other file in this batch and batch 1
(`appointments.php`, `health-profile.php`, `medication-reminders.php`,
`member.php`, `checkout.php`) requires it; these two do not. This is a
**genuine, pre-existing bug in the PHP originals**, not something introduced
by this migration, and mig-api's own contract files
(`packages/contracts/src/consent.ts`, `packages/contracts/src/data-rights.ts`)
document it prominently as "DEVIATION #1."

### 2.1 What the missing require actually breaks

`bootstrap/route_by_account.php` is what lets a request on the **root
domain** (no tenant-identifying subdomain — the situation every real
line-mini-app request is in, since line-mini-app is one static bundle shared
by every tenant) resolve which tenant DB to use, by reading a
`line_account_id`/`la`/`account` signal out of the request and calling
`TenantContext::routeByLineAccount()`. Without it, `TenantContext` never gets
pinned by that mechanism at all for `consent.php`/`data-rights.php`, and
`Database::getInstance()` falls through to `Modules\Core\Database`'s
`legacyFallback()` — a DIFFERENT connection than the tenant DB a
correctly-routed request would use. In real production this means PDPA
consent/data-rights writes issued from the root domain likely land in the
wrong (legacy/default) database today.

### 2.2 Why a Host-header pin fixes it in THIS harness without touching PHP

`config/database.php` includes `bootstrap/resolve_subdomain.php`
**unconditionally** on every PHP request that loads it (unless
`REYA_SKIP_SUBDOMAIN_RESOLUTION` is defined) — this is a **completely
separate** tenant-resolution mechanism from `route_by_account.php`, keyed off
the `Host` header's subdomain, not off any request parameter. Both
`consent.php` and `data-rights.php` still go through this path (neither
opts out of it), so pinning `Host: e2e-api-parity-harness.re-ya.com` (this
harness's own seeded tenant's subdomain — `TENANT_SLUG` constant, same shape
`infra/e2e/parity.mjs`'s own `TENANT_HOST` already uses for its page-pair
harness) makes `resolve_subdomain.php` call
`TenantContext::setCurrentTenantId($tenantId)` with the REAL, seeded tenant's
numeric id — exercising the exact code path a genuinely-multi-tenant
subdomain deployment relies on, without editing a single PHP file.

`infra/e2e/api-parity.mjs`'s `callStack()` accepts an optional per-case
`phpHost` field, applied **only** to the `'php'` variant of the request
(never the `'next'` variant, on every case, by construction — a
`variant === 'php'` guard, not a conditional the case config could
accidentally flip). It is set on exactly 4 cases:

- `consent:save`
- `data-rights:withdraw_consent`
- `data-rights:request_deletion`
- `data-rights:export_data`

Every other case in this batch (and every batch-1 case) leaves `phpHost`
unset — the harness's normal "no tenant-pinning Host header at all" identity
model (batch-1 runbook §3) is unchanged for them, because their PHP source
DOES call `route_by_account.php` and resolves correctly via the request's own
`line_account_id`.

Node's `http.request()` (which `httpRequest()` in `harness-common.mjs`
already wraps) accepts an explicit `Host` header override with no special
handling required — `infra/e2e/parity.mjs`'s own `TENANT_HOST` usage already
proves this is a low-risk, already-relied-upon mechanism in this repo, not
new plumbing invented for this batch.

**The Next-side call is deliberately left unchanged** (no Host header,
ever) for all 4 cases — this is not an oversight, it mirrors
`apps/admin/src/lib/miniapp/tenant.ts`'s own documented policy: the Next
port for `consent:save`/`data-rights:*` uses the STANDARD two-phase
`resolveMiniappTenantContext()`/`withMiniappTenant()` helper regardless of
what the PHP original does, which means it resolves correctly from
`line_account_id` in the request body alone — a **deliberate, byte-level
improvement over the PHP original's tenant-resolution behavior** for these 2
endpoints specifically (mig-orchestrator sign-off, documented in both
contract files, not re-litigated here). `consent:save`'s real client
(`line-mini-app/src/lib/consent-api.ts::saveHealthDataConsent()`) already
sends `line_account_id` in its body even though PHP's own
`handleSaveConsent()` never reads it — this harness's fixture request bodies
match that real-client shape.

### 2.3 An honest caveat about THIS harness's own environment (read before over-trusting a dbCheck alone)

`infra/e2e/api-parity.mjs`'s `seedDatabase()` creates exactly **one**
non-master application database in this ephemeral container, named after
whatever `config/config.php`'s local (gitignored) `DB_NAME` literal says
(`zrismpsz_clinicya` in every checkout this harness has been run from) — and
`45-phase3-batch1-plan-and-tenant.sql.tmpl` seeds `tenants.db_name` to that
**exact same string**. Because `Modules\Core\Database::legacyFallback()`
also connects using that same `DB_NAME` constant (same host, same
credentials), **in this specific harness's environment**, PHP's
"properly-tenant-routed" connection and its "fell-through-to-legacy"
connection are, by construction, the physically identical MySQL schema. This
is a property of every E2E harness in this repo (an intentional
single-database-per-run design, not something batch 2 introduced), not a
bug — but it does mean a bare "the row landed in `__APP_DB_NAME__`" assertion
cannot, by itself, distinguish "PHP resolved the tenant correctly via
`resolve_subdomain.php`" from "PHP silently fell through to legacy and got
lucky because the two happen to be the same schema in this one harness."

This harness does not paper over that: `data-rights:request_deletion`'s own
`dbCheck` comment in `infra/e2e/lib/api-extract.mjs` calls this out
explicitly, and §5 below documents exactly which assertion is doing the real
work and what it can/cannot prove on its own. The Host-header pin is still
the right thing to implement regardless — it is what a genuinely
multi-tenant deployment (where legacy-fallback and the real tenant DB are
NOT the same schema) actually needs, and it is what mig-api's own contract
files document as the required mechanism — this harness aims to be
representative of that real mechanism, not merely to produce a green
checkmark that happens to be un-falsifiable in its own sandboxed database
topology.

## 3. The `data_deletion_requests` migration dependency

`data-rights:request_deletion` (`UPDATE users SET deletion_status=...`) and
`data-rights:export_data` (`profile.deletion_status`/`deletion_requested_at`)
both depend on:

- `users.deletion_status` / `users.deletion_requested_at` columns
- the `data_deletion_requests` table

**Confirmed absent** from `database/migration_2026-05-25_tenant_template.sql`
(no `CREATE TABLE`/`ALTER TABLE` for either anywhere in that file) — they
live only in the separately-committed
`database/migration_2026-07-04_pdpa_data_rights.sql` (idempotent: guarded
`ALTER TABLE` via an `INFORMATION_SCHEMA` check wrapped in a stored
procedure, `CREATE TABLE IF NOT EXISTS` for the ledger table — safe to
re-apply). `infra/e2e/api-parity.mjs`'s `seedDatabase()` applies it
**immediately after** `TENANT_TEMPLATE` and **before** any fixture file
(`PLAN_AND_TENANT_FILE`/`FIXTURE_FILE`/`FIXTURE_FILE_BATCH2`) — the exact
same "master migration applied before the fixture that needs it" pattern
`MASTER_MIGRATIONS` already established for
`migration_2026-06-02_route_liff_id.sql` (resolve-line-account's `liff_id`
fast path, batch 1).

Every OTHER table this batch's cases touch (`appointments`, `pharmacists`,
`pharmacist_schedules`, `user_health_profiles`, `user_drug_allergies`,
`user_current_medications`, `user_addresses`, `user_consents`,
`consent_logs`, `medication_reminders`, `medication_taken_history`,
`ai_conversation_history`, `transactions`, `transaction_items`) is CONFIRMED
present, unconditionally, in the base `TENANT_TEMPLATE` — verified directly
(not just by trusting mig-api's own contract doc comments, though those were
cross-checked too and agree) — `data_deletion_requests` is the ONE exception.

`modules/PDPA/Services/DataRightsService.php::ensureDeletionSchema()` also
has its own lazy self-healing `ALTER TABLE`/`CREATE TABLE IF NOT EXISTS`
fallback (a PHP resilience mechanism for tenants that haven't run the
migration yet) — meaning the PHP side of `request_deletion`/`export_data`
would technically still work even without this harness applying the
migration explicitly. The Next side would NOT: its `_lib/service.ts` uses
the `sql` tagged-template escape hatch for these specific columns/table
precisely because they are absent from `packages/db/src/generated/tenant-db.d.ts`'s
Kysely types, and there is no equivalent DDL-on-request fallback on the Next
side (deliberately not ported — see that file's own doc comment, same
"flagged simplification" precedent `health-profile/_lib/query.ts` already
uses for its own dropped `CREATE TABLE IF NOT EXISTS`). This harness applies
the migration explicitly so both stacks are exercised on the SAME schema
state, not "PHP self-heals, Next 500s."

## 4. `infra/nginx/routes.json` — verified no-op, left untouched

`infra/nginx/generate-routes.mjs`'s `nginxLocationPattern(path)` returns the
route's `path` **unmodified** — verified by reading the function directly:

```js
function nginxLocationPattern(path) {
  return path;
}
```

`renderLocation()` then emits a plain `location <path> { ... }` block, never
an exact-match (`location = <path>`) or regex (`location ~ <path>`) variant.
nginx resolves a request URI against the **longest matching prefix** among
every `location` block, so the SINGLE existing `/api/miniapp` entry
(`upstream: next_admin`, `tenants: "all"` — see batch 1's own runbook §7 for
why it's unconditional, not the usual "default php_backend, flip later"
placeholder) already covers every new sub-path this batch adds
(`/api/miniapp/appointments`, `/api/miniapp/consent`,
`/api/miniapp/data-rights`, `/api/miniapp/medication-reminders`,
`/api/miniapp/addresses`) with **zero additional `routes.json` entries
required** — nginx's prefix match does not need one entry per literal
sub-path the way an exact-match config would.

This was re-verified for this batch (not taken on the brief's word alone,
per this repo's own "correct a mistaken brief premise, document why, don't
silently comply" precedent — see batch 1's own
`50-phase3-batch1-miniapp-fixture.sql.tmpl`'s "SCHEMA-DDL NOTE" for the
prior instance of that same discipline):

```bash
node infra/nginx/generate-routes.mjs --validate-only   # PASS, 11 routes, unchanged
node infra/nginx/generate-routes.mjs                    # regenerate (no --validate-only)
git diff infra/nginx/generated/strangler-edge.conf      # ONLY the "Generated at" timestamp line differs
```

Both `infra/nginx/routes.json` and `infra/nginx/generated/strangler-edge.conf`
are therefore **untouched** by this batch — confirmed, not merely assumed.

## 5. New `FORMAT_CHECKS` entries

Two fields are non-deterministic BY DESIGN on the endpoint that generates
them (same treatment `member_id`/`redemption_code` already got in batch 1 —
format-checked via regex, not exact-equality, and allowlisted in the
relevant case's `allow` array):

| Field | Regex | Source |
|---|---|---|
| `appointment_id` | `/^APT\d{15}$/` | `api/appointments.php`'s `'APT' . date('ymdHis') . rand(100, 999)` — literal `APT` + 12-digit timestamp + 3-digit rand. Copied verbatim from `packages/contracts/src/appointments.ts`'s exported `APPOINTMENT_ID_FORMAT_REGEX`. |
| `confirmation_code` | `/^REYA-DEL-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/` | `modules/PDPA/Services/DataRightsService::generateConfirmationCode()` — `REYA-DEL-` + 8 chars from a no-ambiguous-glyph alphabet (no `0`/`O`/`1`/`I`). Copied verbatim from `packages/contracts/src/data-rights.ts`'s exported `DATA_RIGHTS_CONFIRMATION_CODE_REGEX`. |

Only used on the ONE case each was introduced for (`appointments:book`,
`data-rights:request_deletion` respectively).

## 6. The `appointments:book` concurrent-race avoidance (a fixture design note, not a finding about PHP)

`appointments:book`'s php/next calls run concurrently (`Promise.all` in
`runApiCase()`, same as every other write case). `handleBook()`'s "slot
taken" check (`SELECT id FROM appointments WHERE pharmacist_id = ? AND
appointment_date = ? AND appointment_time = ? AND status NOT IN
('cancelled','no_show')`) is keyed on `(pharmacist_id, date, time)` only —
NOT user-scoped, and the `appointments` table has no UNIQUE constraint
enforcing it at the DB level either. Two concurrent `book()` calls targeting
the exact SAME `(pharmacist_id, date, time)` tuple would race unpredictably
(both might succeed, or one might legitimately see the other's
just-committed row and fail with "ช่วงเวลานี้ถูกจองแล้ว"). Rather than accept
that flakiness, the fixture seeds **two dedicated pharmacists** (`911`
php-target, `912` next-target — see
`55-phase3-batch2-miniapp-fixture.sql.tmpl`'s own comment) so the two
concurrent calls can never collide, while `date`/`time` stay IDENTICAL
across both variants (matching this harness's "only the identity signal
varies" convention every other write case already follows —
`pharmacist_id` varies instead, invisible in the response since
`AppointmentsBookOk` never echoes it back).

## 7. Acceptance criteria (mig-verify executes these)

- [ ] `node infra/e2e/api-parity.mjs` exits `0` and prints
      `{"result":"PASS",...}` with all 16 batch-1 entries, all 19 new
      PHP-vs-Next diffable entries, and all 3 addresses next-only
      self-consistency entries reporting `ok:true` — **38 total covered
      pairs**.
- [ ] A deliberately-broken new route (temporarily rename one new action
      string in any ONE of the new `route.ts` files) still produces a clean
      teardown and an isolated `{ok:false, mismatches:[...]}` entry for that
      ONE endpoint only — every other of the 37 remaining entries still
      reports its real result (same isolation guarantee batch 1's own §6
      already established, unchanged mechanism — `runApiCase()`/
      `runNextOnlyCase()` both never throw past their own try/catch).
- [ ] `node infra/nginx/generate-routes.mjs --validate-only` passes, and the
      regenerated `strangler-edge.conf` matches a fresh generation with no
      drift beyond the `Generated at` timestamp line (holds whether or not
      new `routes.json` entries were added — see §4: none were).
- [ ] This runbook exists and, read alone (no prior context), explains: why
      `addresses` has no PHP diff (§1), why `consent`/`data-rights` get a
      Host-header pin and why (§2), and where the PDPA migration gets
      applied (§3).
- [ ] The Host-header-pin mechanism is proven to actually change PHP's
      tenant resolution, at the standard §2.3-caveated level of confidence:
      `data-rights:request_deletion`'s `dbCheck` queries the resulting
      `users.deletion_status` row AND a `data_deletion_requests` row count
      straight out of `__APP_DB_NAME__` (this harness's ONE seeded tenant
      DB) after the PHP-side (Host-pinned) call — a query that only finds
      rows there at all if PHP's request actually reached and wrote to THIS
      schema. §2.3 documents, explicitly and by name, the one respect in
      which this harness's own single-database topology cannot fully
      distinguish "correctly tenant-routed" from "legacy-fell-through, but
      the fallback happens to be the same schema here" — a future reader
      chasing a stronger proof should start there, not rediscover the
      nuance from scratch.
