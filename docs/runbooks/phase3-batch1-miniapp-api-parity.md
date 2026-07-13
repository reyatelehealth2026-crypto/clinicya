# Phase 3 batch 1 — miniapp JSON API parity harness

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 3
(API/service port), §1.3 (`routeByLineAccount`), §1.5 (strangler edge), §7.3
(canary ramp). Owner: mig-infra (this harness) / mig-api-reads + mig-api-writes
(the two endpoint-porting agents whose Next output this harness verifies) /
mig-orchestrator (canary-ramp authorization). Cross-reference:
`docs/runbooks/phase2-batch1-users-dashboard-parity.md` (the page-pair harness
this one sits alongside — same JSON-line-output convention, same "single
seeded tenant, not a live-traffic shadow" limits framing, different
harness/output shape because this batch ports JSON API endpoints, not
server-rendered pages).

## Scope note (read first — same documented-limits pattern as
`infra/e2e/parity.mjs`'s and `infra/e2e/run.mjs`'s own scope notes)

`infra/e2e/api-parity.mjs` proves **JSON-response-and-DB-row parity, on ONE
seeded tenant and ONE fixed fixture dataset**, between the six ported PHP
endpoints (`api/resolve-line-account.php`, `api/points-history.php`,
`api/checkout.php` + `api/shop-products.php`, `api/health-profile.php`,
`api/member.php`, `api/rewards.php`, `api/wishlist.php`) and their Next ports
at `apps/admin/src/app/api/miniapp/**` — on the REAL stack (a genuine MariaDB
10.11 + Redis 7 + `php:8.2-apache` container set, and a genuine `next build` +
standalone-server Next process), never mocks.

**What this is NOT:**

- **Not a live-traffic shadow test.** It never sees real tenant data, real
  LIFF sessions, or real production load.
- **Not every tenant's real data.** One seeded tenant, one fixture. A tenant
  with schema drift (e.g. a `users` table missing `email`/`member_tier`) is
  exercised indirectly (both `api/member.php` and its Next port run the SAME
  `SHOW COLUMNS`-style dynamic-column detection PHP always did — see
  `apps/admin/src/app/api/miniapp/member/_lib/columns.ts`'s own module doc),
  but this harness's own fixture DB is NOT deliberately drifted to prove that
  path — it's the same committed tenant template every other E2E harness
  in this repo uses.
- **Not exhaustive.** Only the 16 endpoint × action pairs the phase-3-batch-1
  briefs actually port are covered (see §2 below) — every other action either
  PHP source file has is explicitly out of scope (zero line-mini-app callers,
  verified by grep in the porting briefs) and is untouched by this harness.
- **Not a checkout/cart/order test.** `api/checkout.php`'s write actions
  (`add_to_cart`, `create_order`, `upload_slip`, …) are the highest-risk,
  last-to-flip piece per the plan and stay on PHP — this harness only proves
  parity for the two READ actions it lends to `shop-products` (`products`,
  `product_detail`), never touches the write side.

If you need a broader/live check, that is a separate, larger verification
pass — this harness's JSON output says `"result":"PASS"` for THIS dataset,
never "Phase 3 batch 1: PASS" in some larger sense.

---

## 1. How to run it

```bash
node infra/e2e/api-parity.mjs
```

Single command, no flags required. It will (in order):

1. `docker compose -p reya-e2e-api-parity -f infra/e2e/docker-compose.yml up
   -d --build` (the SAME compose file `run.mjs`/`parity.mjs` use — mariadb,
   redis, php — UNMODIFIED). Because that file's container names/ports are
   fixed (not templated per-project), **this harness cannot run concurrently
   with `run.mjs` or `parity.mjs`** — same pre-existing constraint those two
   already have with each other. Sequential use only.
2. Seed the master DB (7 committed migrations — the same 6
   `infra/e2e/parity.mjs` applies, plus
   `database/migration_2026-06-02_route_liff_id.sql` for
   `resolve-line-account`'s fast-path `liff_id` column) + one fresh tenant DB
   (the ~280-table committed template) + this batch's own tenant/plan/routing
   row (`infra/e2e/seed/45-phase3-batch1-plan-and-tenant.sql.tmpl` — own
   tenant slug `e2e-api-parity-harness`, plus two
   `tenant_line_account_routes` rows so `line_account_id`-based tenant
   resolution works on BOTH stacks) + the miniapp fixture
   (`infra/e2e/seed/50-phase3-batch1-miniapp-fixture.sql.tmpl` — see that
   file's own extensive header/inline comments for every seeded row's "why").
3. `pnpm --filter admin run build` (ALWAYS — never trusts a possibly-stale
   `.next/standalone`), copies `.next/static` into the standalone bundle
   (Next's own documented `output: 'standalone'` requirement), starts
   `node server.js` as a plain host child process.
4. Waits for both the PHP container (`http://127.0.0.1:18092/`) and the Next
   server (`http://127.0.0.1:3220/api/health`) to answer HTTP.
5. Issues the SAME logical request to both stacks for each of the 16
   endpoint × action pairs in `infra/e2e/lib/api-extract.mjs`'s
   `ENDPOINT_CASES`, diffs the JSON response bodies (+ HTTP status + selected
   headers), runs any configured post-write DB-row assertions, and — unlike
   the page-pair harness — **no PHP/Next login step at all**: `/api/miniapp/**`
   is an unauthenticated, trust-on-input surface (contractNote's identity
   model; see §5 below), so there is no session to establish.
6. **Always** tears down (`docker compose down -v` + kill the Next child) in
   a `finally` block, even on a thrown error, and prints exactly one
   machine-readable JSON line to stdout:

   ```json
   {"result":"PASS","endpoints":[{"endpoint":"resolve-line-account","ok":true,"mismatches":[]}, ...],"steps":{...},"failedAt":null}
   ```

   Exit code `0` only on `"result":"PASS"`. A failing entry's `mismatches`
   array is the diagnosable evidence — every mismatch line is
   `<path>: php=<value> next=<value>` (or a `dbCheck[<label>] ...`-prefixed
   line for a failed post-write DB assertion), same convention
   `infra/e2e/parity.mjs`'s own diff engine already uses.

## 2. The 16 endpoint × action pairs

| `endpoint` value | PHP source | Next route |
|---|---|---|
| `resolve-line-account` | `api/resolve-line-account.php` | `apps/admin/src/app/api/miniapp/resolve-line-account/route.ts` |
| `points-history:history` | `api/points-history.php` (`action=history`) | `.../points-history/route.ts` |
| `shop-products:products` | `api/checkout.php` (`action=products`, **not** `api/shop-products.php`'s own `products` branch — see §4) | `.../shop-products/route.ts` |
| `shop-products:product_detail` | `api/checkout.php` (`action=product_detail`) | `.../shop-products/route.ts` |
| `shop-products:categories` | `api/shop-products.php` (`action=categories`) | `.../shop-products/route.ts` |
| `health-profile:get` | `api/health-profile.php` (`action=get`) | `.../health-profile/route.ts` |
| `member:check` | `api/member.php` (`action=check`) | `.../member/route.ts` |
| `member:get_card` | `api/member.php` (`action=get_card`) | `.../member/route.ts` |
| `member:register` | `api/member.php` (`action=register`) | `.../member/route.ts` |
| `member:update_profile` | `api/member.php` (`action=update_profile`) | `.../member/route.ts` |
| `rewards:list` | `api/rewards.php` (`action=list`) | `.../rewards/route.ts` |
| `rewards:redeem` | `api/rewards.php` (`action=redeem`) | `.../rewards/route.ts` |
| `rewards:my_redemptions` | `api/rewards.php` (`action=my_redemptions`) | `.../rewards/route.ts` |
| `wishlist:list` | `api/wishlist.php` (`action=list`) | `.../wishlist/route.ts` |
| `wishlist:toggle` | `api/wishlist.php` (`action=toggle`) | `.../wishlist/route.ts` |
| `wishlist:remove` | `api/wishlist.php` (`action=remove`) | `.../wishlist/route.ts` |

Every entry is CONFIG-DRIVEN (`infra/e2e/lib/api-extract.mjs`'s
`ENDPOINT_CASES` array) — mig-api-reads and mig-api-writes built their routes
concurrently with this harness, so nothing here assumes a route exists ahead
of time. A still-missing/broken route fails as its OWN `{ok:false,
mismatches:[...]}` entry — it never aborts the run or skips the other 15
entries (verified — see §6's acceptance-criteria checklist).

## 3. Identity model under test (why there's no Host header)

`infra/e2e/parity.mjs`'s page-pair harness sends every request with
`Host: <tenant-slug>.re-ya.com` to exercise SUBDOMAIN tenant routing. This
harness deliberately does the OPPOSITE: every `/api/miniapp/**` request below
carries **no** tenant-pinning `Host` header at all (Host defaults to the
harness's own `127.0.0.1:PORT` address). Both `bootstrap/resolve_subdomain.php`
(PHP) and `@reya/tenant`'s `resolveTenant()` via `apps/admin/src/proxy.ts`
(Next) resolve that host to "no tenant" (neither a `*.re-ya.com` subdomain
nor the bare root domain — see `packages/tenant/src/resolveTenant.ts`'s own
doc table), so every request falls through to **phase (b)**:
`line_account_id`-based resolution (`bootstrap/route_by_account.php` / the
Next `resolveMiniappTenantContext()`'s `routeByLineAccount()` call). This is
the SAME resolution path real line-mini-app traffic uses on the root domain
(line-mini-app is one static export shared by every tenant — see
contractNote point 2) — it is the more representative choice for this
surface, not a simplification for the harness's convenience.

`resolve-line-account` is the one deliberate exception: it never resolves a
tenant at all (mirrors `REYA_SKIP_SUBDOMAIN_RESOLUTION`) — it queries the
master DB directly by `liff_id`.

## 4. `shop-products:products` / `:product_detail` route to `api/checkout.php`, not `api/shop-products.php`

Verified by reading the real call graph (`shop-api.ts` → `ShopClient.tsx` /
`ShopProductDetailClient.tsx`): the mini-app's actual product/category reads
go through `api/checkout.php`'s `products`/`product_detail` actions
(`handleGetProducts()`/`handleGetProductDetail()`, reading `business_items` +
`business_categories`), NOT `api/shop-products.php`'s own `products`/
`product_detail` branches (which are dead from the mini-app's perspective —
`shop-products.php`'s `categories` action IS the real, live one, ported
separately). This harness's `shop-products:products` / `:product_detail`
entries therefore call `api/checkout.php` on the PHP side — see
`infra/e2e/lib/api-extract.mjs`'s own case comments for the full citation.
`api/checkout.php` itself is otherwise untouched this batch (its
cart/order/slip write actions stay on PHP, per the plan's "checkout ports
last" ordering).

## 5. The two-points-tables gotcha (preserve, do not "fix")

A real, existing PHP quirk, deliberately preserved by both Next ports and
asserted by this harness, not something to clean up:

- `LoyaltyPoints`/`points-history.php`'s `history` action, and `rewards.php`'s
  `redeem`, all read/write **`points_transactions`**.
- `member.php`'s welcome-bonus insert (both `handleRegister()` and
  `autoRegisterMember()`/`autoUpgradeMember()`) writes to a **different**
  table, **`points_history`** — best-effort, wrapped in its own try/catch.

The practical effect: a brand-new member's 50pt welcome bonus updates
`users.points`/`available_points`, but **never appears** in the
`points-history:history` view (which only ever reads `points_transactions`).

This harness's fixture keeps the two scenarios on two DIFFERENT identities so
they never interact:

- `points-history:history` reads a pre-seeded, already-registered identity
  ("richMember", `line_user_id=e2e-mp-rich-member`) whose balance is
  established via `points_transactions` rows directly in
  `50-phase3-batch1-miniapp-fixture.sql.tmpl` — `points_history` is never
  touched for this identity.
- `member:check`'s own dbChecks (see `infra/e2e/lib/api-extract.mjs`) instead
  assert the welcome-bonus row DOES land in `points_history` (`type='bonus'`)
  for the two freshly-auto-registered identities that case exercises — proving
  the PORT preserved the quirk, not that the quirk is "fixed."

If a future cleanup batch ever unifies these two tables, this harness's
`member:check` dbCheck (which asserts a `points_history` row, not a
`points_transactions` row) is the piece that will need updating alongside it.

## 6. Acceptance criteria (mig-verify executes these)

- [ ] `node infra/e2e/api-parity.mjs` exits `0` and prints
      `{"result":"PASS",...}` with all 16 endpoint × action entries (§2's
      table) reporting `ok:true`, once both mig-api briefs have landed.
- [ ] A deliberately-broken Next route (temporarily rename one `route.ts`'s
      action string) still causes a clean teardown and a diagnosable
      `{ok:false, mismatches:[...]}` entry for that ONE endpoint only — the
      harness must not hang, crash, or skip teardown.
- [ ] `node infra/nginx/generate-routes.mjs --validate-only` passes against
      the updated `routes.json`, and the regenerated `strangler-edge.conf` is
      checked in and matches a fresh generation (no drift beyond the
      `Generated at` timestamp line).
- [ ] This runbook exists and documents the two-points-tables gotcha (§5) and
      the `/api/miniapp` routing deviation (§7) explicitly enough that a
      future reader doesn't rediscover them from scratch.

## 7. The `/api/miniapp` routing deviation (`infra/nginx/routes.json`)

Every prior Phase 2 batch's `routes.json` entry follows the same pattern:
`upstream: "php_backend"` (already the strangler default, so a functional
no-op) with a note explaining that `mig-orchestrator` flips it to
`next_admin` only after a completed canary ramp. **`/api/miniapp` does NOT
follow that pattern** — it is registered with `upstream: "next_admin"`
directly, unconditionally, for every tenant, from the moment this batch
lands. This is intentional, not an oversight:

- `/api/miniapp/**` is a **brand-new path PHP has never served**. The ported
  endpoints reuse legacy PHP paths like `/api/member.php` only as the
  origin-map fallback line-mini-app's client-side `php-bridge.ts` override
  targets — they do not live at those literal paths inside `apps/admin`.
  A "default `php_backend`, not yet flipped" placeholder is therefore
  meaningless here: there is nothing at `/api/miniapp` on the `php_backend`
  upstream to serve it, unlike `/users`, `/dashboard`, etc., which mirror an
  existing PHP page 1:1 and can safely default to the PHP page that already
  exists at that literal path.
- **The real canary ramp for THIS surface does not run through
  `routes.json` at all.** line-mini-app is ONE static export shared by every
  tenant, so nginx's host×path canary mechanism (which can flip a whole ADMIN
  PAGE per tenant subdomain) cannot express "10% of mini-app traffic" the way
  the plan's ramp (demo tenant → 1 real tenant → 10% → 50% → 100%) implies for
  a bundle every tenant shares. Instead, the ramp is driven **client-side**:
  `line-mini-app/src/lib/php-bridge.ts` exposes a per-endpoint override map
  (`NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES`), and mig-orchestrator adjusts
  that env/config per environment/build to move traffic from the legacy PHP
  endpoint to `/api/miniapp/**` one endpoint (or percentage) at a time —
  `/api/miniapp` already points at `next_admin` unconditionally at the edge,
  so the edge is never the thing being flipped for this surface.
- This routing decision is **mig-orchestrator's explicit sign-off**, given in
  the phase-3-batch-1 brief itself — it is documented here for mig-verify's
  benefit, not presented as an open question to re-litigate.

## 8. What this harness does NOT prove about the canary mechanic

This harness only proves PHP/Next JSON parity on the seeded tenant — it does
NOT exercise `NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES` or `php-bridge.ts`'s
override-map logic at all (that lives in `line-mini-app/`, out of this
agent's allowed paths). Proving the client-side ramp mechanic itself is a
separate, `line-mini-app`-scoped verification concern — see §9, which closes
this gap.

## 9. Rollback drill — `infra/e2e/rollback-drill.mjs` (closes §8's gap)

mig-verify (Phase 3 batch 1 re-review) correctly flagged that §8's gap was
not just documented but actually unproven: the plan's §7 verification gate,
item 6, requires "every phase must actually drill flipping back on canary
before ramp" — the `php-bridge.test.ts`/`config.ts` unit tests prove
`resolveEndpointTarget()`/`buildPhpRequestUrl()` parse the override env var
correctly IN ISOLATION (no network, no real servers), which is necessary but
not sufficient.

`infra/e2e/rollback-drill.mjs` closes it: on the SAME real stack
`api-parity.mjs` uses (genuine MariaDB + Redis + `php:8.2-apache`, a genuine
`next build` + standalone server), it spawns
`infra/e2e/lib/rollback-drill-client.mjs` under
`node --experimental-strip-types`, which imports
`line-mini-app/src/lib/config.ts` DIRECTLY AND UNMODIFIED (no reimplemented
logic) and drives three REAL, live network calls for
`GET /api/health-profile.php` (the same case §2's table already proves
JSON-exact PHP/Next parity for):

1. **Baseline** — no override configured — resolves to and calls the real
   PHP container, returns the seeded richMember profile.
2. **Flip** — `NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES` set for
   `GET /api/health-profile.php` only — resolves to and calls the real
   `/api/miniapp/health-profile` Next route, returns a body identical to
   step 1 (functional parity across the flip, not just "some 200") and
   carries the Next route's own CORS header shape
   (`Access-Control-Allow-Methods: GET, POST, OPTIONS`, vs PHP's
   `GET, POST, PUT, DELETE, OPTIONS`) — independent confirmation the request
   really left the PHP origin.
3. **Revert** — the override removed (mig-orchestrator's actual one-line
   rollback) — resolves back to PHP, response byte-identical to step 1.

Run it with `node infra/e2e/rollback-drill.mjs` (same single-command,
always-tears-down-in-`finally`, one-JSON-line-on-stdout convention as
`api-parity.mjs`). Exit code `0` / `{"result":"PASS",...}` only when all 15
checks in its `checks` array pass (each named
`baseline_*`/`flip_*`/`revert_*`; see the script's own `verifyDrill()` for
the full list). Last recorded run: `PASS`, all 15 checks true, clean
teardown (`docker ps -a` empty afterward).

**Still out of scope for this drill** (documented limits, same pattern as
§"Scope note" above): only ONE of the 16 endpoint × action pairs is drilled
(the other 15 share the identical `resolveEndpointTarget()`/
`buildPhpRequestUrl()` code path, so this is a representative, not
exhaustive, sample); it proves the mechanic works on ONE seeded tenant in a
staging-shaped harness, not a real canary ramp in production.

## 10. Acceptance criteria addendum (mig-verify executes this alongside §6)

- [ ] `node infra/e2e/rollback-drill.mjs` exits `0` and prints
      `{"result":"PASS",...}` with all `checks` entries `ok:true`.
- [ ] Both `checks` and `steps` in that output show the flip step's `url`
      pointing at the Next origin/`/api/miniapp/health-profile` path and the
      baseline/revert steps' `url`s pointing at the PHP origin/legacy path —
      i.e. the drill is asserting on the REAL resolved URLs, not merely
      trusting a `success:true` body.
