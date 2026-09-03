#!/usr/bin/env node
// infra/e2e/lib/api-extract.mjs
//
// Phase 3 batch 1 (mig-infra) — per-endpoint request builders + allowlist
// definitions consumed by infra/e2e/api-parity.mjs's JSON API parity mode.
//
// PHASE 3 BATCH 2 EXTENSION (mig-infra): appended below the batch-1 content,
// which is otherwise UNTOUCHED (no renames/reorders above this file's
// original content) — adds 19 new PHP-vs-Next diffable ENDPOINT_CASES
// entries (appointments x5, health-profile-writes x6, consent:save,
// data-rights x3, medication-reminders x4) plus a STRUCTURALLY SEPARATE
// `NEXT_ONLY_CASES` export (addresses x3 — see that export's own doc comment
// for why). See docs/runbooks/phase3-batch2-miniapp-api-parity.md for the
// full writeup of the load-bearing findings this extension encodes (the
// addresses no-PHP-source gap, the consent/data-rights Host-header-pin
// requirement, the data_deletion_requests migration dependency).
// Mirrors infra/e2e/lib/extract.mjs's role for the page-pair harness: this
// file is the CONFIG (what to request, what to ignore when diffing), never
// the fetch/diff mechanics themselves (those stay in api-parity.mjs, same
// separation extract.mjs/parity.mjs already established).
//
// CONFIG-DRIVEN PER THE BRIEF ("mig-api-reads/writes build concurrently —
// this harness must not assume their routes exist while it's being
// developed"): ENDPOINT_CASES is a flat array, each entry independent of
// whether the underlying apps/admin route exists yet. A missing/broken route
// surfaces as that ONE entry's {ok:false, mismatches:[...]} — see
// api-parity.mjs's runApiCase() for the try/catch that guarantees this.
//
// IDENTITY STRATEGY: read-only (GET, non-mutating) actions share ONE fixture
// identity ("rich member", id=3001 — see 50-phase3-batch1-miniapp-fixture.sql.tmpl)
// across both the PHP and Next call, since a GET never mutates state a later
// assertion depends on. WRITE actions (member:check/register/update_profile,
// rewards:redeem, wishlist:toggle/remove) each get TWO separately-seeded
// identities, suffixed `-php` / `-next` in the fixture, so exercising the
// action against PHP never consumes/mutates the row the Next-side call of
// the SAME case needs — see the fixture file's own header comment for the
// full rationale (this is the "two separately-seeded users" strategy the
// brief asked this script's header to document, not a reset-between-runs
// step, because it lets both calls happen in either order with no teardown
// in between and keeps the DB-row-shape comparison meaningful).
//
// FIELD-NAME ALLOWLIST (not full JSON-path allowlist): matches the brief's
// own example verbatim (`member:check -> ['created_at','registered_at','id']`)
// — every entry in a case's `allow` array is a bare property NAME, stripped
// from the diff WHEREVER it appears in the response tree (object key or
// array-item key), not a `a.b[0].c`-style path. This is coarser than a full
// path allowlist but matches the brief's documented convention and is
// sufficient here: every case's fixture is small and hand-built, so a
// same-named field appearing in two semantically-different places within
// ONE response (e.g. a top-level `id` and a nested `reward.id`) is either
// (a) both legitimately non-deterministic in this harness (safe to allow
// both) or (b) doesn't co-occur in the cases below at all — verified by
// inspecting each case's actual response shape while writing this file.

// ---------------------------------------------------------------------------
// Batch 2 imports — the addresses NEXT_ONLY_CASES validate the Next response
// against the REAL zod schemas from @reya/contracts (per the brief: "import
// it, don't hand-roll validation"). This is the ONE place this harness
// imports application-owned schema code rather than defining its own
// allowlist/regex config — deliberate, since there is no PHP response to
// diff against for these 3 cases (see NEXT_ONLY_CASES's own doc comment).
//
// Imported via a RELATIVE path to packages/contracts/dist/index.js, not the
// bare `@reya/contracts` specifier: infra/e2e/ is deliberately outside
// pnpm-workspace.yaml's `packages:` globs (`apps/*`, `packages/*` only —
// infra/ is the pre-existing PHP-migration tooling tree, not a workspace
// package), so pnpm never links `@reya/contracts` into any node_modules
// reachable from here (verified — `node -e "require.resolve('@reya/contracts')"`
// run from the repo root fails; it only resolves from inside
// apps/admin/node_modules's own symlink). The relative path sidesteps that
// entirely. `dist/index.js` is @reya/contracts's committed CommonJS build
// target (package.json `"type":"commonjs"`, `"main":"./dist/index.js"`) —
// Node's ESM-importing-CJS interop resolves its named exports via static
// analysis (cjs-module-lexer), same mechanism apps/admin's own Next build
// already relies on. api-parity.mjs's buildContracts() step (documented
// there) runs `pnpm --filter @reya/contracts run build` before this module
// is ever imported, so this never depends on a stale/missing dist/ left
// over from a previous session.
// ---------------------------------------------------------------------------

import {
  AddressesListResponseSchema,
  AddressesUpsertResponseSchema,
  AddressesDeleteResponseSchema,
} from '../../../packages/contracts/dist/index.js';

// ---------------------------------------------------------------------------
// Fixture identities/IDs — MUST stay in sync with
// infra/e2e/seed/50-phase3-batch1-miniapp-fixture.sql.tmpl. Not re-derived
// at runtime (no SELECT-and-discover step) — both files are hand-authored
// together and read side by side, same convention
// infra/e2e/seed/30-phase2-batch1-fixture.sql.tmpl / lib/extract.mjs already
// established (fixture comments cross-reference the consuming extractor).
// ---------------------------------------------------------------------------

export const FIXTURE = {
  lineAccountPrimary: 1, // is_default=1, liff_id set, owns rewards 801/802
  lineAccountSecondary: 2, // no rewards of its own -> rewards:list fallback target
  liffId: '1234567890-e2eminiapp',

  richMemberLineUserId: 'e2e-mp-rich-member', // id=3001, read-only shared identity
  richMemberProductFavorited: 601, // pre-existing wishlist row (is_on_sale=1, discount 20%)

  productParacetamol: 601, // price 25.00, sale_price 20.00
  productVitaminC: 602, // price 150.00, no sale_price

  rewardActive: 801, // points_required=200, stock=-1 (unlimited)
  rewardInactive: 802, // is_active=0 — must NOT appear in any `list` response

  // member:check — deliberately NO pre-seeded `users` row for either identity
  // (the auto-register branch under test IS the absence of a row).
  checkLineUserId: { php: 'e2e-mp-check-php', next: 'e2e-mp-check-next' },

  // member:register — same "deliberately absent row" reasoning as check.
  registerLineUserId: { php: 'e2e-mp-register-php', next: 'e2e-mp-register-next' },

  // member:update_profile — pre-seeded rows (ids 3101/3102) so the UPDATE has
  // something real to mutate and a resulting DB row to diff.
  updateProfileLineUserId: { php: 'e2e-mp-updateprofile-php', next: 'e2e-mp-updateprofile-next' },

  // rewards:redeem — pre-seeded rows (ids 3201/3202) with a 500-point balance.
  redeemLineUserId: { php: 'e2e-mp-redeem-php', next: 'e2e-mp-redeem-next' },

  // wishlist:toggle — pre-seeded rows (ids 3301/3302), no wishlist row yet.
  wishToggleLineUserId: { php: 'e2e-mp-wishtoggle-php', next: 'e2e-mp-wishtoggle-next' },

  // wishlist:remove — pre-seeded rows (ids 3401/3402) WITH an existing wishlist row.
  wishRemoveLineUserId: { php: 'e2e-mp-wishremove-php', next: 'e2e-mp-wishremove-next' },

  // -------------------------------------------------------------------------
  // PHASE 3 BATCH 2 additions — MUST stay in sync with
  // infra/e2e/seed/55-phase3-batch2-miniapp-fixture.sql.tmpl (same
  // hand-authored-together convention as the batch-1 identities above).
  // Reuses lineAccountPrimary/lineAccountSecondary above — no new
  // line_accounts rows.
  // -------------------------------------------------------------------------

  // appointments — 901 shared read-only pharmacist (list + available_slots +
  // my_appointments'/cancel's appointments); 911/912 DEDICATED to book:php/
  // book:next so the two concurrent book() calls can never race on the same
  // (pharmacist_id, date, time) "slot taken" check.
  apptPharmacistShared: 901,
  apptPharmacistBook: { php: 911, next: 912 },

  apptReadLineUserId: 'e2e-mp2-appt-read', // id=4001, my_appointments read-only identity
  apptUpcomingAppointmentId: 6001,
  apptPastAppointmentId: 6002,

  apptBookLineUserId: { php: 'e2e-mp2-appt-book-php', next: 'e2e-mp2-appt-book-next' }, // ids 4011/4012
  // Fixed, distinctive date/time — handleBook() never validates past/future
  // (only available_slots does), so no relative-date arithmetic is needed
  // here; only available_slots's own `date` query needs to move with time.
  apptBookDate: '2027-02-20',
  apptBookTime: '09:15',

  apptCancelLineUserId: { php: 'e2e-mp2-appt-cancel-php', next: 'e2e-mp2-appt-cancel-next' }, // ids 4021/4022
  apptCancelAppointmentId: { php: 6011, next: 6012 },

  // health-profile writes — NONE of the six ported handlers ever queries
  // `users` (see the fixture file's own header comment), so these need no
  // pre-seeded `users` row at all; only remove_allergy/remove_medication
  // need a pre-existing target row.
  hpUpdatePersonalLineUserId: { php: 'e2e-mp2-hp-personal-php', next: 'e2e-mp2-hp-personal-next' },
  hpUpdateMedicalHistoryLineUserId: { php: 'e2e-mp2-hp-medhist-php', next: 'e2e-mp2-hp-medhist-next' },
  hpAddAllergyLineUserId: { php: 'e2e-mp2-hp-addallergy-php', next: 'e2e-mp2-hp-addallergy-next' },
  hpRemoveAllergyLineUserId: { php: 'e2e-mp2-hp-rmallergy-php', next: 'e2e-mp2-hp-rmallergy-next' },
  hpRemoveAllergyId: { php: 6201, next: 6202 },
  hpAddMedicationLineUserId: { php: 'e2e-mp2-hp-addmed-php', next: 'e2e-mp2-hp-addmed-next' },
  hpRemoveMedicationLineUserId: { php: 'e2e-mp2-hp-rmmed-php', next: 'e2e-mp2-hp-rmmed-next' },
  hpRemoveMedicationId: { php: 6211, next: 6212 },

  // consent:save — deliberately NO pre-seeded `users` row (auto-create
  // branch under test, same reasoning as member:check).
  consentSaveLineUserId: { php: 'e2e-mp2-consent-save-php', next: 'e2e-mp2-consent-save-next' },

  // data-rights:* — resolveUser() never auto-creates, so every identity
  // below needs a pre-existing `users` row (explicit ids so dbChecks can
  // reference `user_id = <id>` directly instead of a subquery).
  drWithdrawLineUserId: { php: 'e2e-mp2-dr-withdraw-php', next: 'e2e-mp2-dr-withdraw-next' },
  drWithdrawUserId: { php: 4211, next: 4212 },
  drDeletionLineUserId: { php: 'e2e-mp2-dr-deletion-php', next: 'e2e-mp2-dr-deletion-next' },
  drDeletionUserId: { php: 4221, next: 4222 },
  drExportLineUserId: { php: 'e2e-mp2-dr-export-php', next: 'e2e-mp2-dr-export-next' },
  drExportUserId: { php: 4231, next: 4232 },

  // medication-reminders — api/medication-reminders.php DOES look up
  // `users` for every action (including `list`), unlike health-profile.
  mrListLineUserId: 'e2e-mp2-mr-read', // id=4331, read-only shared identity
  mrAddLineUserId: { php: 'e2e-mp2-mr-add-php', next: 'e2e-mp2-mr-add-next' },
  mrDeleteLineUserId: { php: 'e2e-mp2-mr-delete-php', next: 'e2e-mp2-mr-delete-next' },
  mrDeleteReminderId: { php: 6311, next: 6312 },
  mrMarkTakenLineUserId: { php: 'e2e-mp2-mr-marktaken-php', next: 'e2e-mp2-mr-marktaken-next' },
  mrMarkTakenReminderId: { php: 6321, next: 6322 },

  // addresses — NEXT-ONLY (no PHP source, see NEXT_ONLY_CASES below), so a
  // single identity per case, no php/next split (each case makes exactly
  // ONE HTTP call — no concurrent-race concern to design around).
  addrListLineUserId: 'e2e-mp2-addr-list', // 2 pre-seeded rows: primary, secondary_1
  addrUpsertLineUserId: 'e2e-mp2-addr-upsert', // no pre-existing row for the 'primary' label it targets
  addrDeleteLineUserId: 'e2e-mp2-addr-delete', // pre-seeded 'secondary_1' row to delete

  // -------------------------------------------------------------------------
  // PHASE 3 BATCH 3 additions — MUST stay in sync with
  // infra/e2e/seed/65-phase3-batch3-miniapp-fixture.sql.tmpl (same
  // hand-authored-together convention as batch 1/2's identities above).
  // Reuses lineAccountPrimary above (and, for `cart`, batch 1's
  // richMemberLineUserId/productParacetamol/productVitaminC) — no new
  // line_accounts rows.
  // -------------------------------------------------------------------------

  // checkout-cart:cart reuses richMemberLineUserId (id=3001) directly — no
  // new identity constant needed; 65-...'s own fixture gives it two
  // pre-seeded cart_items rows (products 601 qty2 / 602 qty1).

  // checkout-order:create_order's race-guard products — stock=1 each, TWO
  // DEDICATED rows (not one shared row) — see 65-...'s own comment on why.
  ccProductLowStock: { php: 1601, next: 1602 },

  ccAddToCartLineUserId: { php: 'e2e-mp3-cart-add-php', next: 'e2e-mp3-cart-add-next' }, // no pre-existing users/cart_items row (auto-create + INSERT branch).
  ccUpdateCartLineUserId: { php: 'e2e-mp3-cart-update-php', next: 'e2e-mp3-cart-update-next' }, // pre-existing cart_items row, product 601 qty2.
  ccRemoveFromCartLineUserId: { php: 'e2e-mp3-cart-remove-php', next: 'e2e-mp3-cart-remove-next' }, // pre-existing cart_items row, product 602 qty3.
  ccClearCartLineUserId: { php: 'e2e-mp3-cart-clear-php', next: 'e2e-mp3-cart-clear-next' }, // pre-existing cart_items rows, products 601+602.

  coCreateOrderLineUserId: { php: 'e2e-mp3-order-create-php', next: 'e2e-mp3-order-create-next' },
  coUploadSlipLineUserId: { php: 'e2e-mp3-slip-upload-php', next: 'e2e-mp3-slip-upload-next' },
  coUploadSlipOrderId: { php: 8101, next: 8102 }, // pre-existing `transactions` rows to attach a slip to.
};

// ---------------------------------------------------------------------------
// PHP_HOST — the Host header this harness pins on the PHP-side call ONLY for
// the 4 cases whose PHP source is missing `require_once
// bootstrap/route_by_account.php` (consent:save, data-rights:withdraw_consent/
// request_deletion/export_data — see those 4 cases' own `phpHost` field
// below and docs/runbooks/phase3-batch2-miniapp-api-parity.md §2 for the
// full "why"). Same `${TENANT_SLUG}.re-ya.com` shape infra/e2e/parity.mjs's
// page-pair harness already uses for its Host-based subdomain routing
// (TENANT_HOST there) — duplicated here as a literal (not imported) because
// api-parity.mjs's own TENANT_SLUG is a module-level const in a script with
// no exports (same "documented copy, not import" tradeoff that script's own
// module doc already accepts for buildAdmin() et al). MUST stay in sync with
// api-parity.mjs's TENANT_SLUG = 'e2e-api-parity-harness'.
// ---------------------------------------------------------------------------

export const PHP_HOST = 'e2e-api-parity-harness.re-ya.com';

// ---------------------------------------------------------------------------
// Format-check regexes for allowlisted-but-structurally-meaningful fields —
// per the brief: "member_id is deterministic-by-formula so assert its FORMAT
// not exact equality unless the harness pins the sequence." Applied by
// api-parity.mjs's runApiCase() to every occurrence of the named field in
// BOTH the php and next response bodies, independently.
// ---------------------------------------------------------------------------

export const FORMAT_CHECKS = {
  member_id: /^M\d{2}\d{5}$/, // api/member.php::generateMemberId() — 'M' + 2-digit year + 5-digit sequence.
  redemption_code: /^RW[A-Z0-9]{4,}$/, // LoyaltyPoints::generateUniqueRedemptionCode().
  // Batch 2 additions — regexes copied verbatim from mig-api's own contract doc comments (per the brief).
  appointment_id: /^APT\d{15}$/, // packages/contracts/src/appointments.ts's APPOINTMENT_ID_FORMAT_REGEX ('APT' + ymdHis(12 digits) + rand(100,999)).
  confirmation_code: /^REYA-DEL-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/, // packages/contracts/src/data-rights.ts's DATA_RIGHTS_CONFIRMATION_CODE_REGEX.
  // Batch 3 additions.
  order_number: /^TXN\d{8}\d{4}$/, // api/checkout.php's handleCreateOrder(): 'TXN' . date('Ymd') . str_pad(mt_rand(1,9999),4,'0',STR_PAD_LEFT).
  // handleUploadSlip() builds an ABSOLUTE, host-derived URL
  // (`$scheme://$_SERVER['HTTP_HOST']/uploads/slips/slip_{order_number}_{time()}.{ext}`)
  // — the php and next calls hit DIFFERENT hosts/ports in this harness
  // (127.0.0.1:18092 vs 127.0.0.1:3220 — see PHP_BASE_URL/NEXT_BASE_URL in
  // api-parity.mjs) AND different pre-seeded order_numbers (see
  // FIXTURE.coUploadSlipOrderId), so this can never be a literal byte-diff —
  // same "format not exact equality" treatment as member_id/redemption_code/
  // appointment_id/confirmation_code above. `[^/]+` deliberately matches
  // EITHER host so one regex covers both stacks' responses.
  image_url: /^https?:\/\/[^/]+\/uploads\/slips\/slip_[A-Za-z0-9_-]+_\d+\.(jpg|jpeg|png|gif|webp)$/,
};

// ---------------------------------------------------------------------------
// bangkokDatePlusDays() — 'YYYY-MM-DD' for "today + N days" in Asia/Bangkok,
// computed at REQUEST time (this file's `query()`/`body()` functions run in
// api-parity.mjs's own Node process, once per harness invocation — NOT baked
// into the SQL fixture, which is why this lives here and not in
// 55-phase3-batch2-miniapp-fixture.sql.tmpl). Mirrors
// apps/admin/src/app/api/miniapp/appointments/_lib/bangkokTime.ts's own
// `todayInBangkok()`/`addDaysToDateString()` pair (documented copy, not an
// import — this file's own module doc already establishes "config-only,
// duplicated-but-documented constants" as the pattern here). Used ONLY by
// appointments:available_slots below, whose `date` param must stay within
// PHP's/Next's own "not more than 30 days out" validation window no matter
// how long after this file is written the harness actually runs.
// ---------------------------------------------------------------------------

function bangkokDatePlusDays(days) {
  const BANGKOK_OFFSET_MS = 7 * 60 * 60 * 1000;
  const bangkokNow = new Date(Date.now() + BANGKOK_OFFSET_MS);
  const y = bangkokNow.getUTCFullYear();
  const m = bangkokNow.getUTCMonth();
  const d = bangkokNow.getUTCDate();
  const target = new Date(Date.UTC(y, m, d + days));
  return target.toISOString().slice(0, 10);
}

// ---------------------------------------------------------------------------
// Endpoint x action cases — one entry per row of the acceptance-criteria
// table (16 batch-1 + 19 batch-2 = 35; the 3 addresses cases are a SEPARATE
// NEXT_ONLY_CASES export below, per that export's own doc comment).
// `path`/`nextPath` are relative to PHP_BASE_URL/NEXT_BASE_URL respectively.
// `query`/`body` are either a plain object (GET-only cases, identical for
// both stacks) or a `(variant) => object` function (write cases, where
// `variant` is 'php' | 'next' and selects the paired identity). An optional
// `phpHost` field (batch 2) pins a `Host` header on the PHP-side call ONLY —
// see PHP_HOST's own doc comment above for which 4 cases set it and why.
// ---------------------------------------------------------------------------

export const ENDPOINT_CASES = [
  // -------------------------------------------------------------------------
  // resolve-line-account — platform-level, tenant-agnostic (no line_account_id
  // signal at all; resolves purely from liff_id via the master DB).
  // -------------------------------------------------------------------------
  {
    name: 'resolve-line-account',
    method: 'GET',
    phpPath: '/api/resolve-line-account.php',
    nextPath: '/api/miniapp/resolve-line-account',
    query: () => ({ liff_id: FIXTURE.liffId }),
    // tenant_id is a real, dynamically-allocated AUTO_INCREMENT value (the ONE
    // tenant this harness ever creates) — not hardcoded, but NOT allowlisted
    // either: both stacks must resolve the SAME tenant_id from the SAME master
    // DB row, so an exact match here is a meaningful assertion, not noise.
    allow: [],
    compareHeaders: ['cache-control'],
  },

  // -------------------------------------------------------------------------
  // points-history:history
  // -------------------------------------------------------------------------
  {
    name: 'points-history:history',
    method: 'GET',
    phpPath: '/api/points-history.php',
    nextPath: '/api/miniapp/points-history',
    query: () => ({
      action: 'history',
      line_user_id: FIXTURE.richMemberLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
      limit: 20,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // shop-products:products — checkout.php's handleGetProducts(), NOT
  // shop-products.php's own `products` branch (contractNote point 5 / scope
  // correction — see route.ts's own doc comment).
  // -------------------------------------------------------------------------
  {
    name: 'shop-products:products',
    method: 'GET',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/shop-products',
    query: () => ({
      action: 'products',
      line_account_id: FIXTURE.lineAccountPrimary,
      line_user_id: FIXTURE.richMemberLineUserId,
      limit: 10,
      offset: 0,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // shop-products:product_detail — checkout.php's handleGetProductDetail().
  // -------------------------------------------------------------------------
  {
    name: 'shop-products:product_detail',
    method: 'GET',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/shop-products',
    query: () => ({
      action: 'product_detail',
      product_id: FIXTURE.productParacetamol,
      line_account_id: FIXTURE.lineAccountPrimary,
      line_user_id: FIXTURE.richMemberLineUserId,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // shop-products:categories — shop-products.php's OWN standalone branch
  // (item_categories fallback, since shop_settings.order_data_source='shop').
  // -------------------------------------------------------------------------
  {
    name: 'shop-products:categories',
    method: 'GET',
    phpPath: '/api/shop-products.php',
    nextPath: '/api/miniapp/shop-products',
    query: () => ({ action: 'categories', account: FIXTURE.lineAccountPrimary }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // health-profile:get — pre-seeded profile, fully deterministic (no
  // auto-INSERT race — see the fixture file's own comment).
  // -------------------------------------------------------------------------
  {
    name: 'health-profile:get',
    method: 'GET',
    phpPath: '/api/health-profile.php',
    nextPath: '/api/miniapp/health-profile',
    query: () => ({
      action: 'get',
      line_user_id: FIXTURE.richMemberLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // member:check — GET verb, REAL write side effects (auto-register). Two
  // fresh identities, no pre-seeded `users` row for either.
  // -------------------------------------------------------------------------
  {
    name: 'member:check',
    method: 'GET',
    phpPath: '/api/member.php',
    nextPath: '/api/miniapp/member',
    query: (variant) => ({
      action: 'check',
      line_user_id: FIXTURE.checkLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      display_name: 'E2E Fresh User',
      picture_url: 'https://example.invalid/pic.jpg',
    }),
    // member_id IS present on member:check's response (unlike the doc below
    // this comment originally assumed) and is genuinely non-deterministic
    // here: this case's `php` and `next` calls run CONCURRENTLY
    // (Promise.all in runApiCase()) against TWO FRESH identities sharing the
    // SAME line_account_id, and generateMemberId()'s `SELECT ... ORDER BY
    // member_id DESC LIMIT 1` scan can race between the two auto-register
    // INSERTs — the two calls may legitimately land on the same or
    // different next-sequence value depending on timing. Format-checked via
    // FORMAT_CHECKS instead of exact-equality (same reasoning as
    // member:register below). `points`/`tier`/`tier_name` stay deterministic
    // (both fresh identities land on the identical 50pt welcome bonus ->
    // bronze tier regardless of member_id timing).
    allow: ['member_id'],
    dbChecks: [
      {
        label: 'users row after auto-register',
        table: 'users',
        where: (variant) => `line_user_id = '${FIXTURE.checkLineUserId[variant]}'`,
        columns: ['is_registered', 'points', 'first_name', 'last_name', 'line_account_id'],
        allow: ['member_id'], // present in the row but not asserted for exact equality here (format-checked separately if needed)
      },
      {
        label: 'points_history welcome-bonus row (two-tables quirk)',
        table: 'points_history',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.checkLineUserId[variant]}') AND type = 'bonus'`,
        columns: ['points', 'type', 'balance_after'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // member:get_card — pure read, shared richMember identity.
  // -------------------------------------------------------------------------
  {
    name: 'member:get_card',
    method: 'GET',
    phpPath: '/api/member.php',
    nextPath: '/api/miniapp/member',
    query: () => ({
      action: 'get_card',
      line_user_id: FIXTURE.richMemberLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // member:register
  // -------------------------------------------------------------------------
  {
    name: 'member:register',
    method: 'POST',
    phpPath: '/api/member.php',
    nextPath: '/api/miniapp/member',
    body: (variant) => ({
      action: 'register',
      line_user_id: FIXTURE.registerLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      first_name: 'ทดสอบ',
      last_name: 'ระบบ',
      birthday: '1995-05-05',
      gender: 'female',
    }),
    // member_id is sequential across the two calls BY DESIGN (PHP's call runs
    // first and consumes the next sequence number in the shared `users`
    // table; Next's call then consumes the one after) — allowlisted here,
    // format-checked via FORMAT_CHECKS instead of exact-equality.
    allow: ['member_id'],
    dbChecks: [
      {
        label: 'users row after register',
        table: 'users',
        where: (variant) => `line_user_id = '${FIXTURE.registerLineUserId[variant]}'`,
        columns: ['first_name', 'last_name', 'real_name', 'birthday', 'gender', 'is_registered', 'points', 'line_account_id'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // member:update_profile
  // -------------------------------------------------------------------------
  {
    name: 'member:update_profile',
    method: 'POST',
    phpPath: '/api/member.php',
    nextPath: '/api/miniapp/member',
    body: (variant) => ({
      action: 'update_profile',
      line_user_id: FIXTURE.updateProfileLineUserId[variant],
      // line_account_id is REQUIRED here even though handleUpdateProfile()
      // itself never reads it — bootstrap/route_by_account.php (PHP) /
      // resolveMiniappTenantContext() (Next) both need SOME routing signal
      // in the request to resolve the tenant DB at all (contractNote point
      // 2c: with none, PHP silently legacy-DB-falls-back while Next 400s
      // tenant_unresolved — a real, documented, accepted deviation, but only
      // for requests that genuinely omit the signal; real mini-app traffic
      // always includes it, so this fixture must too).
      line_account_id: FIXTURE.lineAccountPrimary,
      first_name: 'ใหม่',
      last_name: 'ปรับปรุง',
      phone: '0899999999',
      weight: 60,
      height: 165,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'users row after update_profile',
        table: 'users',
        where: (variant) => `line_user_id = '${FIXTURE.updateProfileLineUserId[variant]}'`,
        columns: ['first_name', 'last_name', 'real_name', 'phone', 'weight', 'height'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // rewards:list — deliberately queried against the SECONDARY account (no
  // rewards of its own) to exercise the default-account-fallback branch.
  // -------------------------------------------------------------------------
  {
    name: 'rewards:list',
    method: 'GET',
    phpPath: '/api/rewards.php',
    nextPath: '/api/miniapp/rewards',
    query: () => ({ action: 'list', line_account_id: FIXTURE.lineAccountSecondary }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // rewards:redeem
  // -------------------------------------------------------------------------
  {
    name: 'rewards:redeem',
    method: 'POST',
    phpPath: '/api/rewards.php',
    nextPath: '/api/miniapp/rewards',
    body: (variant) => ({
      action: 'redeem',
      line_user_id: FIXTURE.redeemLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      reward_id: FIXTURE.rewardActive,
    }),
    // redemption_code is random (LoyaltyPoints::generateUniqueRedemptionCode());
    // redemption_id is an AUTO_INCREMENT id, genuinely different per call.
    // `member.id` / `member.display_name` / `member.line_user_id` differ BY
    // DESIGN too — the redeem call is exercised against two DIFFERENT
    // fixture identities (php vs next, per this file's own "IDENTITY
    // STRATEGY" doc), and `member` is that identity's own row echoed back.
    // `id` also (harmlessly) strips `reward.id`'s equality check — safe here
    // since `reward.id` is driven by the SAME `reward_id=801` request param
    // on both calls, so it is trivially equal regardless.
    allow: ['redemption_code', 'redemption_id', 'id', 'display_name', 'line_user_id'],
    dbChecks: [
      {
        label: 'users row after redeem (points deducted)',
        table: 'users',
        where: (variant) => `line_user_id = '${FIXTURE.redeemLineUserId[variant]}'`,
        columns: ['available_points', 'used_points', 'total_points'],
        allow: [],
      },
      {
        label: 'reward_redemptions row created',
        table: 'reward_redemptions',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.redeemLineUserId[variant]}') AND reward_id = ${FIXTURE.rewardActive}`,
        columns: ['points_used', 'status'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // rewards:my_redemptions — pure read, shared richMember identity.
  // -------------------------------------------------------------------------
  {
    name: 'rewards:my_redemptions',
    method: 'GET',
    phpPath: '/api/rewards.php',
    nextPath: '/api/miniapp/rewards',
    query: () => ({
      action: 'my_redemptions',
      line_user_id: FIXTURE.richMemberLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
      limit: 20,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // wishlist:list — pure read, shared richMember identity.
  // -------------------------------------------------------------------------
  {
    name: 'wishlist:list',
    method: 'GET',
    phpPath: '/api/wishlist.php',
    nextPath: '/api/miniapp/wishlist',
    query: () => ({
      action: 'list',
      line_user_id: FIXTURE.richMemberLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // wishlist:toggle — no pre-existing row -> deterministic ADD.
  // -------------------------------------------------------------------------
  {
    name: 'wishlist:toggle',
    method: 'POST',
    phpPath: '/api/wishlist.php',
    nextPath: '/api/miniapp/wishlist',
    body: (variant) => ({
      action: 'toggle',
      line_user_id: FIXTURE.wishToggleLineUserId[variant],
      product_id: FIXTURE.productVitaminC,
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'user_wishlist row created by toggle',
        table: 'user_wishlist',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.wishToggleLineUserId[variant]}') AND product_id = ${FIXTURE.productVitaminC}`,
        columns: ['price_when_added', 'notify_on_sale'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // wishlist:remove — pre-existing row -> deterministic delete.
  // -------------------------------------------------------------------------
  {
    name: 'wishlist:remove',
    method: 'POST',
    phpPath: '/api/wishlist.php',
    nextPath: '/api/miniapp/wishlist',
    body: (variant) => ({
      action: 'remove',
      line_user_id: FIXTURE.wishRemoveLineUserId[variant],
      product_id: FIXTURE.productVitaminC,
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'user_wishlist row count after remove',
        table: 'user_wishlist',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.wishRemoveLineUserId[variant]}') AND product_id = ${FIXTURE.productVitaminC}`,
        columns: ['__row_count__'], // synthetic — see api-parity.mjs's dbCheck runner.
        allow: [],
      },
    ],
  },

  // ===========================================================================
  // PHASE 3 BATCH 2 (mig-infra) — 19 new PHP-vs-Next diffable cases appended
  // below. Every batch-1 case above is untouched. See
  // docs/runbooks/phase3-batch2-miniapp-api-parity.md for the full writeup;
  // NEXT_ONLY_CASES (addresses x3, no PHP source) is a SEPARATE export below
  // this array's closing bracket, not mixed in here.
  // ===========================================================================

  // -------------------------------------------------------------------------
  // appointments:pharmacists — read-only, no identity (pharmacists list
  // is not scoped by line_user_id at all).
  // -------------------------------------------------------------------------
  {
    name: 'appointments:pharmacists',
    method: 'GET',
    phpPath: '/api/appointments.php',
    nextPath: '/api/miniapp/appointments',
    query: () => ({ action: 'pharmacists', line_account_id: FIXTURE.lineAccountPrimary }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // appointments:available_slots — read-only. `date` is computed relative to
  // "now" (Asia/Bangkok, +5 days) at REQUEST time (bangkokDatePlusDays()
  // below), not a fixed fixture value, so this case stays valid no matter
  // how long after this file is written the harness actually runs. No
  // pharmacist_schedules/pharmacist_holidays rows are seeded for pharmacist
  // 901 — deliberately exercises the DEFAULT_SCHEDULE_BY_DAY fallback both
  // stacks replicate identically (see appointments.ts's DYNAMIC-COLUMN
  // VERIFICATION doc comment), so this is deterministic regardless of which
  // weekday +5 days happens to land on.
  // -------------------------------------------------------------------------
  {
    name: 'appointments:available_slots',
    method: 'GET',
    phpPath: '/api/appointments.php',
    nextPath: '/api/miniapp/appointments',
    query: () => ({
      action: 'available_slots',
      pharmacist_id: FIXTURE.apptPharmacistShared,
      date: bangkokDatePlusDays(5),
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // appointments:book — two DEDICATED pharmacists (911 php / 912 next, see
  // FIXTURE.apptPharmacistBook's own comment) so the two concurrent book()
  // calls (Promise.all in api-parity.mjs's runApiCase()) can never race on
  // the same (pharmacist_id, date, time) "slot taken" check — `date`/`time`
  // stay IDENTICAL across both variants (same convention every other write
  // case in this file uses: only the identity signal varies), it is
  // `pharmacist_id` that varies instead, invisible in the response
  // (AppointmentsBookOk never echoes pharmacist_id back).
  // -------------------------------------------------------------------------
  {
    name: 'appointments:book',
    method: 'POST',
    phpPath: '/api/appointments.php',
    nextPath: '/api/miniapp/appointments',
    body: (variant) => ({
      action: 'book',
      line_user_id: FIXTURE.apptBookLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      pharmacist_id: FIXTURE.apptPharmacistBook[variant],
      date: FIXTURE.apptBookDate,
      time: FIXTURE.apptBookTime,
      symptoms: 'ปวดหัว (E2E)',
      type: 'consultation',
    }),
    // id (AUTO_INCREMENT PK) and appointment_id (APT + timestamp + rand)
    // both genuinely differ per call — format-checked via FORMAT_CHECKS
    // instead of exact equality (same reasoning as member:register).
    allow: ['id', 'appointment_id'],
    dbChecks: [
      {
        label: 'appointments row after book',
        table: 'appointments',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.apptBookLineUserId[variant]}') AND pharmacist_id = ${FIXTURE.apptPharmacistBook[variant]}`,
        // Only the columns PHP's dynamic INSERT actually populates on this
        // template (see appointments.ts's DYNAMIC-COLUMN VERIFICATION note —
        // appointment_id/end_time/duration/type/symptoms/consultation_fee
        // are ALL silently dropped, the columns don't exist on this table).
        columns: ['appointment_date', 'appointment_time', 'status', 'line_account_id'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // appointments:my_appointments — shared, read-only identity (id=4001, two
  // pre-seeded appointments: one upcoming, one past+completed).
  // -------------------------------------------------------------------------
  {
    name: 'appointments:my_appointments',
    method: 'GET',
    phpPath: '/api/appointments.php',
    nextPath: '/api/miniapp/appointments',
    query: () => ({
      action: 'my_appointments',
      line_user_id: FIXTURE.apptReadLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // appointments:cancel — needs a pre-existing, cancellable appointment per
  // identity (ids 6011 php / 6012 next, both future-dated relative to NOW()
  // in the fixture SQL).
  // -------------------------------------------------------------------------
  {
    name: 'appointments:cancel',
    method: 'POST',
    phpPath: '/api/appointments.php',
    nextPath: '/api/miniapp/appointments',
    body: (variant) => ({
      action: 'cancel',
      appointment_id: FIXTURE.apptCancelAppointmentId[variant],
      line_user_id: FIXTURE.apptCancelLineUserId[variant],
      // handleCancel() itself never reads line_account_id (neither does the
      // real client, appointments-api.ts::cancelAppointment()) — added
      // anyway purely as a tenant-routing signal, same "required for
      // routing even though unread" precedent batch 1's member:update_profile
      // fixture comment established.
      line_account_id: FIXTURE.lineAccountPrimary,
      reason: 'เปลี่ยนใจ (E2E)',
    }),
    allow: [],
    dbChecks: [
      {
        label: 'appointments row after cancel',
        table: 'appointments',
        where: (variant) => `id = ${FIXTURE.apptCancelAppointmentId[variant]}`,
        columns: ['status', 'cancelled_reason'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // health-profile:update_personal — no `users` row needed (see the fixture
  // file's own header comment: none of the six write handlers ever queries
  // `users`). Fresh identity -> exercises the INSERT branch of the upsert.
  // -------------------------------------------------------------------------
  {
    name: 'health-profile:update_personal',
    method: 'POST',
    phpPath: '/api/health-profile.php',
    nextPath: '/api/miniapp/health-profile',
    body: (variant) => ({
      action: 'update_personal',
      line_user_id: FIXTURE.hpUpdatePersonalLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      name: 'ทดสอบ อัพเดท',
      age: 40,
      gender: 'male',
      weight: 65.5,
      height: 170.2,
      blood_type: 'A',
    }),
    allow: [],
    dbChecks: [
      {
        label: 'user_health_profiles row after update_personal',
        table: 'user_health_profiles',
        where: (variant) =>
          `line_user_id = '${FIXTURE.hpUpdatePersonalLineUserId[variant]}' AND line_account_id = ${FIXTURE.lineAccountPrimary}`,
        columns: ['name', 'age', 'gender', 'weight', 'height', 'blood_type'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // health-profile:update_medical_history — 'not_a_real_condition' exercises
  // PHP's array_filter() (and the Next port's equivalent) silently dropping
  // unrecognized values; the stored JSON must contain only the two valid
  // ones on BOTH stacks.
  // -------------------------------------------------------------------------
  {
    name: 'health-profile:update_medical_history',
    method: 'POST',
    phpPath: '/api/health-profile.php',
    nextPath: '/api/miniapp/health-profile',
    body: (variant) => ({
      action: 'update_medical_history',
      line_user_id: FIXTURE.hpUpdateMedicalHistoryLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      conditions: ['diabetes', 'asthma', 'not_a_real_condition'],
    }),
    allow: [],
    dbChecks: [
      {
        label: 'user_health_profiles.medical_conditions after update_medical_history',
        table: 'user_health_profiles',
        where: (variant) =>
          `line_user_id = '${FIXTURE.hpUpdateMedicalHistoryLineUserId[variant]}' AND line_account_id = ${FIXTURE.lineAccountPrimary}`,
        columns: ['medical_conditions'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // health-profile:add_allergy — no pre-existing row (fresh insert).
  // -------------------------------------------------------------------------
  {
    name: 'health-profile:add_allergy',
    method: 'POST',
    phpPath: '/api/health-profile.php',
    nextPath: '/api/miniapp/health-profile',
    body: (variant) => ({
      action: 'add_allergy',
      line_user_id: FIXTURE.hpAddAllergyLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      drug_name: 'Ibuprofen (E2E)',
      reaction_type: 'swelling',
      reaction_notes: 'บวมที่ใบหน้า',
      severity: 'severe',
    }),
    allow: ['id'], // allergy.id — AUTO_INCREMENT PK, genuinely different per call.
    dbChecks: [
      {
        label: 'user_drug_allergies row after add_allergy',
        table: 'user_drug_allergies',
        where: (variant) =>
          `line_user_id = '${FIXTURE.hpAddAllergyLineUserId[variant]}' AND drug_name = 'Ibuprofen (E2E)'`,
        columns: ['reaction_type', 'reaction_notes', 'severity', 'line_account_id'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // health-profile:remove_allergy — pre-existing row per identity (ids
  // 6201 php / 6202 next).
  // -------------------------------------------------------------------------
  {
    name: 'health-profile:remove_allergy',
    method: 'POST',
    phpPath: '/api/health-profile.php',
    nextPath: '/api/miniapp/health-profile',
    body: (variant) => ({
      action: 'remove_allergy',
      line_user_id: FIXTURE.hpRemoveAllergyLineUserId[variant],
      allergy_id: FIXTURE.hpRemoveAllergyId[variant],
      // removeAllergy() has NO line_account_id param at all (see
      // health-profile.ts's "SUBTLE TRAP" doc comment) and the real client
      // (health-api.ts::removeAllergy()) never sends it either — added
      // purely as a tenant-routing signal, same reasoning as
      // appointments:cancel above.
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'user_drug_allergies row count after remove_allergy',
        table: 'user_drug_allergies',
        where: (variant) => `id = ${FIXTURE.hpRemoveAllergyId[variant]}`,
        columns: ['__row_count__'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // health-profile:add_medication — no pre-existing medications for either
  // identity -> checkMedicationInteractions() always short-circuits to []
  // (see mutations.ts's own doc comment: realistically always the case
  // against the committed template, drug_interactions lacks drug1_id/
  // drug2_id) -> `interactions`/`has_interactions` never appear in the
  // response, so no extra allowlisting is needed for those keys.
  // -------------------------------------------------------------------------
  {
    name: 'health-profile:add_medication',
    method: 'POST',
    phpPath: '/api/health-profile.php',
    nextPath: '/api/miniapp/health-profile',
    body: (variant) => ({
      action: 'add_medication',
      line_user_id: FIXTURE.hpAddMedicationLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      medication_name: 'Amoxicillin (E2E)',
      dosage: '500mg',
      frequency: 'วันละ 3 ครั้ง',
      start_date: '2026-08-01',
      notes: 'หลังอาหาร',
    }),
    allow: ['id'], // medication.id — AUTO_INCREMENT PK.
    dbChecks: [
      {
        label: 'user_current_medications row after add_medication',
        table: 'user_current_medications',
        where: (variant) =>
          `line_user_id = '${FIXTURE.hpAddMedicationLineUserId[variant]}' AND medication_name = 'Amoxicillin (E2E)'`,
        columns: ['dosage', 'frequency', 'start_date', 'notes', 'is_active'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // health-profile:remove_medication — pre-existing row per identity (ids
  // 6211 php / 6212 next).
  // -------------------------------------------------------------------------
  {
    name: 'health-profile:remove_medication',
    method: 'POST',
    phpPath: '/api/health-profile.php',
    nextPath: '/api/miniapp/health-profile',
    body: (variant) => ({
      action: 'remove_medication',
      line_user_id: FIXTURE.hpRemoveMedicationLineUserId[variant],
      medication_id: FIXTURE.hpRemoveMedicationId[variant],
      line_account_id: FIXTURE.lineAccountPrimary, // unread, routing-only — see remove_allergy's own comment.
    }),
    allow: [],
    dbChecks: [
      {
        label: 'user_current_medications.is_active after remove_medication',
        table: 'user_current_medications',
        where: (variant) => `id = ${FIXTURE.hpRemoveMedicationId[variant]}`,
        columns: ['is_active'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // consent:save — THE PHP-HOST-PIN EXCEPTION (1 of 4). api/consent.php is
  // CONSPICUOUSLY MISSING bootstrap/route_by_account.php (verified — see
  // this file's own PHP_HOST doc comment and docs/runbooks/
  // phase3-batch2-miniapp-api-parity.md §2 for the full "why"). `phpHost`
  // pins the PHP-side call ONLY to this harness's own seeded tenant
  // subdomain, exercising PHP's real subdomain-resolution code path
  // (bootstrap/resolve_subdomain.php, which DOES still run — it's included
  // unconditionally via config/database.php — the thing that's missing is
  // ONLY route_by_account.php's line_account_id-based fallback) instead of
  // the legacy-DB-fallback path it would otherwise silently take on the
  // (Host-header-less) root domain. The Next-side call is UNCHANGED — no
  // Host header — it resolves via `line_account_id` in the body exactly
  // like every other case (real client, consent-api.ts, already sends it).
  //
  // VERIFIED, DETERMINISTIC, PRE-EXISTING PHP PRODUCTION BUG (found while
  // building this case — reported here per the "FAIL -> one bounce with
  // diagnosis" policy, NOT fixed, since api/consent.php cannot be modified
  // and this is the PHP ORIGINAL's own bug, not a Next-port defect):
  // `handleSaveConsent()` calls `$db->beginTransaction()`, INSERTs into
  // `user_consents`, THEN calls `ActivityLogger::getInstance($db)` — whose
  // constructor unconditionally runs `CREATE TABLE IF NOT EXISTS
  // activity_logs (...)`, every single request, even though that table
  // already exists on every real tenant DB (verified present in
  // TENANT_TEMPLATE). A `CREATE TABLE IF NOT EXISTS` on an ALREADY-EXISTING
  // table is STILL a DDL statement as far as MySQL/InnoDB's implicit-commit
  // rule is concerned — reproduced directly against this batch's own PHP
  // image (`docker run reya-e2e-api-parity-php php -r '...'`): after
  // `beginTransaction()` + one INSERT + `CREATE TABLE IF NOT EXISTS` (table
  // already existing), `PDO::inTransaction()` flips to `false` and a
  // subsequent `$db->commit()` throws `PDOException("There is no active
  // transaction")` — but the earlier INSERT is confirmed to have PERSISTED
  // (the implicit commit already committed it). `handleSaveConsent()`'s own
  // `catch (Exception $e) { $db->rollBack(); throw $e; }` then re-throws
  // that exact message, and it surfaces verbatim as
  // `{success:false, message:"There is no active transaction"}` to the
  // client — meaning EVERY real `action=save` call with a non-empty
  // `consents` map (i.e. every real call — `ActivityLogger::getInstance()`
  // runs unconditionally before the loop, so an empty map doesn't avoid it
  // either) currently returns a FALSE FAILURE to the customer while SILENTLY
  // still writing the consent row. The Next port does not reproduce this
  // (see this batch's `ActivityLogger::logConsent() SIDE EFFECT —
  // DELIBERATELY NOT PORTED` decision, already documented in
  // packages/contracts/src/consent.ts) — its transaction never contains a
  // DDL statement, so it commits cleanly and returns `success:true`. This
  // makes `success`/`message`/`user_id` LEGITIMATELY, DETERMINISTICALLY
  // different between the two stacks for every single call, not just
  // "sometimes" — `skipResponseBodyDiff` below turns off the body-level diff
  // for exactly this reason (an `allow` entry cannot express "this key is
  // NEVER present on one side, always present on the other" — the diff
  // engine's own presence check would still flag that, correctly, as a
  // mismatch even for an allowlisted field name). `http_status` (both 200 —
  // `jsonResponse()`/`ok()` never set a differing status either way) and the
  // dbChecks below (which STILL run, unconditionally) remain the real
  // assertions for this case: they prove the underlying WRITE is equivalent
  // on both stacks despite PHP's misleading response.
  // -------------------------------------------------------------------------
  {
    name: 'consent:save',
    method: 'POST',
    phpPath: '/api/consent.php',
    nextPath: '/api/miniapp/consent',
    phpHost: PHP_HOST,
    body: (variant) => ({
      action: 'save',
      line_user_id: FIXTURE.consentSaveLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      consents: { health_data: true },
    }),
    skipResponseBodyDiff: true, // see this case's own doc comment above — the ActivityLogger/implicit-commit PHP bug.
    allow: [], // unused (skipResponseBodyDiff above bypasses body diffing entirely) — kept empty, not deleted, for shape consistency with every other case.
    dbChecks: [
      {
        label: 'users row after consent:save auto-create',
        table: 'users',
        where: (variant) => `line_user_id = '${FIXTURE.consentSaveLineUserId[variant]}'`,
        columns: ['line_account_id', 'display_name'],
        allow: [],
      },
      {
        label: 'user_consents row after consent:save',
        table: 'user_consents',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.consentSaveLineUserId[variant]}') AND consent_type = 'health_data'`,
        columns: ['is_accepted', 'consent_version'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // data-rights:withdraw_consent — PHP-HOST-PIN EXCEPTION (2 of 4). Same
  // missing-route_by_account.php finding as consent:save (api/data-rights.php
  // itself, confirmed by reading it — see PHP_HOST's doc comment). Needs a
  // pre-existing users row + an accepted health_data user_consents row per
  // identity (resolveUser() never auto-creates, unlike consent.php).
  // -------------------------------------------------------------------------
  {
    name: 'data-rights:withdraw_consent',
    method: 'POST',
    phpPath: '/api/data-rights.php',
    nextPath: '/api/miniapp/data-rights',
    phpHost: PHP_HOST,
    body: (variant) => ({
      action: 'withdraw_consent',
      line_user_id: FIXTURE.drWithdrawLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      consent_type: 'health_data',
    }),
    allow: [],
    dbChecks: [
      {
        label: 'user_consents row after withdraw_consent',
        table: 'user_consents',
        where: (variant) => `user_id = ${FIXTURE.drWithdrawUserId[variant]} AND consent_type = 'health_data'`,
        columns: ['is_accepted'],
        allow: [],
      },
      {
        label: 'consent_logs row count (action=withdraw) after withdraw_consent',
        table: 'consent_logs',
        where: (variant) => `user_id = ${FIXTURE.drWithdrawUserId[variant]} AND action = 'withdraw'`,
        columns: ['__row_count__'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // data-rights:request_deletion — PHP-HOST-PIN EXCEPTION (3 of 4). Needs
  // database/migration_2026-07-04_pdpa_data_rights.sql applied (data_deletion_requests
  // table + users.deletion_status/deletion_requested_at columns — see
  // api-parity.mjs's seedDatabase()). THIS is the case whose dbCheck proves
  // the phpHost mechanism actually works — see docs/runbooks/
  // phase3-batch2-miniapp-api-parity.md §2.3 + §7's acceptance-criterion writeup.
  // -------------------------------------------------------------------------
  {
    name: 'data-rights:request_deletion',
    method: 'POST',
    phpPath: '/api/data-rights.php',
    nextPath: '/api/miniapp/data-rights',
    phpHost: PHP_HOST,
    body: (variant) => ({
      action: 'request_deletion',
      line_user_id: FIXTURE.drDeletionLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      reason: 'ทดสอบ E2E',
    }),
    allow: ['confirmation_code'], // format-checked via FORMAT_CHECKS instead of exact equality — genuinely random per call.
    dbChecks: [
      {
        // Proves the row landed in the SEEDED TENANT DB (__APP_DB_NAME__),
        // not merely that the HTTP call returned 200 — this query only
        // finds a row here at all if PHP really resolved and wrote to THIS
        // tenant's DB. deletion_requested_at is deliberately excluded (a
        // NOW()-captured timestamp, differs per call by construction).
        label: 'users.deletion_status after request_deletion',
        table: 'users',
        where: (variant) => `id = ${FIXTURE.drDeletionUserId[variant]}`,
        columns: ['deletion_status'],
        allow: [],
      },
      {
        label: 'data_deletion_requests row count after request_deletion',
        table: 'data_deletion_requests',
        where: (variant) => `user_id = ${FIXTURE.drDeletionUserId[variant]}`,
        columns: ['__row_count__'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // data-rights:export_data — PHP-HOST-PIN EXCEPTION (4 of 4). Twin
  // identities' consents/consent_logs/chat_history/orders are seeded
  // IDENTICALLY (only line_user_id itself, and whatever AUTO_INCREMENT PKs
  // MySQL assigns, differ) — see 55-phase3-batch2-miniapp-fixture.sql.tmpl's
  // own "export_data" comment — so this proves the export SHAPE is
  // non-trivially correct for real content, not just an empty-arrays pass,
  // while still asserting near-full byte-equality between the two stacks.
  // -------------------------------------------------------------------------
  {
    name: 'data-rights:export_data',
    method: 'POST',
    phpPath: '/api/data-rights.php',
    nextPath: '/api/miniapp/data-rights',
    phpHost: PHP_HOST,
    body: (variant) => ({
      action: 'export_data',
      line_user_id: FIXTURE.drExportLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    // generated_at: date('c') captured at request time, differs by
    // construction. user_id/id/line_user_id: forced to differ because the
    // two variants are two DIFFERENT pre-seeded `users` rows — every OTHER
    // field (profile content, consents, consent_history, chat_history,
    // orders' amounts/status/products) is seeded IDENTICALLY across the
    // pair and therefore asserted byte-equal, NOT allowlisted.
    allow: ['generated_at', 'user_id', 'id', 'line_user_id'],
  },

  // -------------------------------------------------------------------------
  // medication-reminders:list — shared, read-only identity (id=4331) with a
  // mixed taken/missed history so adherence_percent is a non-trivial 50%.
  // -------------------------------------------------------------------------
  {
    name: 'medication-reminders:list',
    method: 'GET',
    phpPath: '/api/medication-reminders.php',
    nextPath: '/api/miniapp/medication-reminders',
    query: () => ({
      action: 'list',
      line_user_id: FIXTURE.mrListLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // medication-reminders:add — fresh identities, no pre-existing reminder.
  // -------------------------------------------------------------------------
  {
    name: 'medication-reminders:add',
    method: 'POST',
    phpPath: '/api/medication-reminders.php',
    nextPath: '/api/miniapp/medication-reminders',
    body: (variant) => ({
      action: 'add',
      line_user_id: FIXTURE.mrAddLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      medication_name: 'Loratadine (E2E)',
      dosage: '10mg',
      frequency: 'daily',
      reminder_times: ['08:00', '20:00'],
      start_date: '2026-08-01',
      notes: 'ก่อนนอน',
    }),
    allow: ['reminder_id'], // AUTO_INCREMENT PK.
    dbChecks: [
      {
        label: 'medication_reminders row after add',
        table: 'medication_reminders',
        where: (variant) =>
          `line_user_id = '${FIXTURE.mrAddLineUserId[variant]}' AND medication_name = 'Loratadine (E2E)'`,
        columns: ['dosage', 'frequency', 'reminder_times', 'start_date', 'notes', 'is_active'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // medication-reminders:delete — NO ownership/existence pre-check in PHP
  // (see medication-reminders.ts's own "subtle traps" doc comment) — pre-
  // existing reminder per identity so the deactivation is real, not a no-op.
  // -------------------------------------------------------------------------
  {
    name: 'medication-reminders:delete',
    method: 'POST',
    phpPath: '/api/medication-reminders.php',
    nextPath: '/api/miniapp/medication-reminders',
    body: (variant) => ({
      action: 'delete',
      line_user_id: FIXTURE.mrDeleteLineUserId[variant],
      reminder_id: FIXTURE.mrDeleteReminderId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'medication_reminders.is_active after delete',
        table: 'medication_reminders',
        where: (variant) => `id = ${FIXTURE.mrDeleteReminderId[variant]}`,
        columns: ['is_active'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // medication-reminders:mark_taken — DOES verify ownership first (asymmetric
  // with delete, see medication-reminders.ts's own doc comment) — pre-existing
  // reminder per identity.
  // -------------------------------------------------------------------------
  {
    name: 'medication-reminders:mark_taken',
    method: 'POST',
    phpPath: '/api/medication-reminders.php',
    nextPath: '/api/miniapp/medication-reminders',
    body: (variant) => ({
      action: 'mark_taken',
      line_user_id: FIXTURE.mrMarkTakenLineUserId[variant],
      reminder_id: FIXTURE.mrMarkTakenReminderId[variant],
      scheduled_time: '08:00',
      status: 'taken',
      notes: 'ทานแล้ว',
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'medication_taken_history row count after mark_taken',
        table: 'medication_taken_history',
        where: (variant) => `reminder_id = ${FIXTURE.mrMarkTakenReminderId[variant]}`,
        columns: ['__row_count__'],
        allow: [],
      },
    ],
  },

  // ===========================================================================
  // PHASE 3 BATCH 3 (mig-infra) — 8 new PHP-vs-Next diffable cases appended
  // below: checkout-cart:{cart,add_to_cart,update_cart,remove_from_cart,
  // clear_cart}, checkout-pricing:validate_promo,
  // checkout-order:{create_order,upload_slip}. Every batch-1/2 case above is
  // untouched. See docs/runbooks/phase3-batch3-miniapp-api-parity.md for the
  // full writeup. `checkout-order:upload_slip` is the ONE case in this whole
  // harness (batch 1/2/3) that sets `multipart: true` — see that case's own
  // comment and infra/e2e/lib/harness-common.mjs's `buildMultipartBody()`/
  // `httpRequestMultipart()` doc comments for the plumbing this required.
  //
  // CONFIG-DRIVEN, SAME AS EVERY PRIOR BATCH: these 8 entries are added
  // regardless of whether apps/admin/src/app/api/miniapp/checkout/order/**
  // exists yet in this checkout — a still-missing/broken route fails as its
  // OWN {ok:false, mismatches:[...]} entry, exactly like every other case in
  // this file (see this file's own top-of-file module doc); it never aborts
  // the run or skips any other entry.
  // ===========================================================================

  // -------------------------------------------------------------------------
  // checkout-cart:cart — read-only, reuses batch 1's shared richMember
  // identity (id=3001). 65-phase3-batch3-miniapp-fixture.sql.tmpl gives it
  // two pre-seeded cart_items rows (business_items 601 qty2 sale_price=20 ->
  // lineUnit 40; 602 qty1 no sale_price -> lineUnit 150) -> subtotal=190
  // (below free_shipping_min=500) -> shipping_fee=50, total=240,
  // item_count=2 — fully deterministic, asserted byte-equal (no allowlist).
  // -------------------------------------------------------------------------
  {
    name: 'checkout-cart:cart',
    method: 'GET',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/checkout/cart',
    query: () => ({
      action: 'cart',
      line_user_id: FIXTURE.richMemberLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // checkout-cart:add_to_cart — fresh identities, no pre-existing
  // users/cart_items row (INSERT branch of `ON DUPLICATE KEY UPDATE`).
  // Targets product 601 (batch 1's own row, stock=100 — add_to_cart never
  // decrements business_items.stock, only create_order does, so reusing this
  // read-mostly catalog row across batches is safe, same precedent
  // shop-products:products/:product_detail already established).
  // -------------------------------------------------------------------------
  {
    name: 'checkout-cart:add_to_cart',
    method: 'POST',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/checkout/cart',
    body: (variant) => ({
      action: 'add_to_cart',
      line_user_id: FIXTURE.ccAddToCartLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      product_id: FIXTURE.productParacetamol,
      quantity: 2,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'cart_items row after add_to_cart',
        table: 'cart_items',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.ccAddToCartLineUserId[variant]}') AND product_id = ${FIXTURE.productParacetamol}`,
        columns: ['quantity', 'product_source'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // checkout-cart:update_cart — pre-existing cart_items row per identity
  // (product 601, quantity=2) so `quantity=5` in the request body is a REAL
  // update (handleUpdateCart() issues a plain UPDATE, no upsert).
  // -------------------------------------------------------------------------
  {
    name: 'checkout-cart:update_cart',
    method: 'POST',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/checkout/cart',
    body: (variant) => ({
      action: 'update_cart',
      line_user_id: FIXTURE.ccUpdateCartLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      product_id: FIXTURE.productParacetamol,
      quantity: 5,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'cart_items.quantity after update_cart',
        table: 'cart_items',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.ccUpdateCartLineUserId[variant]}') AND product_id = ${FIXTURE.productParacetamol}`,
        columns: ['quantity'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // checkout-cart:remove_from_cart — pre-existing cart_items row per
  // identity (product 602) so the delete is real, not a no-op.
  // -------------------------------------------------------------------------
  {
    name: 'checkout-cart:remove_from_cart',
    method: 'POST',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/checkout/cart',
    body: (variant) => ({
      action: 'remove_from_cart',
      line_user_id: FIXTURE.ccRemoveFromCartLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      product_id: FIXTURE.productVitaminC,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'cart_items row count after remove_from_cart',
        table: 'cart_items',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.ccRemoveFromCartLineUserId[variant]}') AND product_id = ${FIXTURE.productVitaminC}`,
        columns: ['__row_count__'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // checkout-cart:clear_cart — TWO pre-existing cart_items rows per identity
  // (products 601+602) so the case proves the WHOLE cart is cleared.
  // -------------------------------------------------------------------------
  {
    name: 'checkout-cart:clear_cart',
    method: 'POST',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/checkout/cart',
    body: (variant) => ({
      action: 'clear_cart',
      line_user_id: FIXTURE.ccClearCartLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    allow: [],
    dbChecks: [
      {
        label: 'cart_items row count after clear_cart',
        table: 'cart_items',
        where: (variant) => `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.ccClearCartLineUserId[variant]}')`,
        columns: ['__row_count__'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // checkout-pricing:validate_promo — THE PROMOTIONS-TABLE-ABSENT CASE (see
  // the runbook's §2). No fixture rows at all: `promotions` does not exist
  // anywhere in database/migration_2026-05-25_tenant_template.sql (verified
  // directly — grep for `CREATE TABLE.*promotions` comes up empty), so
  // handleValidatePromo()'s `SHOW TABLES LIKE 'promotions'` runtime probe
  // (and its Next port's `promotionsTableExists()`, see
  // apps/admin/src/app/api/miniapp/checkout/pricing/_lib/handlers.ts's own
  // module doc — a REAL, LIVE runtime branch on this harness, not simplified
  // away) always comes back empty on both stacks, falling through to
  // validateHardcodedPromo()'s 4 fixed codes. `code` sent LOWERCASE on
  // purpose (`welcome10`) — exercises `strtoupper(trim())`/
  // `.trim().toUpperCase()` normalization identically on both stacks.
  // subtotal=200 -> WELCOME10 (10% off, min 100) -> discount=20. NOTE:
  // `discount_type` is HARDCODED `'fixed'` in this response branch
  // (verified in api/checkout.php's handleValidatePromo(), L2221-2226) even
  // though WELCOME10 is internally a `percentage` promo — a real, faithfully
  // preserved PHP quirk, not a Next-port bug, asserted byte-equal below (not
  // allowlisted) precisely because both stacks must reproduce it identically.
  // -------------------------------------------------------------------------
  {
    name: 'checkout-pricing:validate_promo',
    method: 'POST',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/checkout/pricing',
    body: () => ({
      action: 'validate_promo',
      code: 'welcome10',
      line_account_id: FIXTURE.lineAccountPrimary,
      subtotal: 200,
    }),
    allow: [],
  },

  // -------------------------------------------------------------------------
  // checkout-order:create_order — THE RACE-GUARD CASE (plan risk register
  // #9 — see the runbook's §3 for the full writeup; the single highest-risk
  // assertion in this batch). Pre-existing `users` row + ONE pre-existing
  // cart_items row per identity, each referencing its OWN DEDICATED
  // low-stock product (1601 php / 1602 next, both stock=1 — see
  // 65-phase3-batch3-miniapp-fixture.sql.tmpl's own comment on why TWO
  // separate rows, not one shared row) at quantity=5 (> stock). Request body
  // carries NO `cart_items` field — matches the REAL client shape
  // (line-mini-app/src/lib/shop-api.ts's createShopOrder() never sends
  // cart_items), forcing both stacks through the
  // loadCheckoutCartLinesFromDb()/DB-cart branch, not the
  // request-body-cart_items branch. payment_method='transfer' deliberately
  // avoids the AccountReceivableService branch entirely (only
  // 'credit'/'cod'/'term'/'invoice' trigger it) — see the runbook's §4.
  //
  // `skipResponseBodyDiff: true` — A SECOND, INDEPENDENTLY-DISCOVERED
  // instance of the consent.php-class "DDL-inside-an-open-transaction causes
  // an implicit commit" bug (see docs/runbooks/phase3-batch2-miniapp-api-parity.md
  // §2's consent:save writeup for the first instance), found EMPIRICALLY by
  // actually running this harness (not by static reading alone — see the
  // runbook's §3.3 for the full writeup): `handleCreateOrder()` runs `$db->exec("ALTER
  // TABLE transactions ADD COLUMN IF NOT EXISTS payment_status ...")` INSIDE
  // its own `$db->beginTransaction()`/`$db->commit()` block, on EVERY call,
  // unconditionally — even though `payment_status` already exists on the
  // committed schema (confirmed absent-of-effect, but MySQL/InnoDB's
  // implicit-commit rule fires on the DDL STATEMENT ITSELF, not on whether
  // it changed anything). This flips `PDO::inTransaction()` to false
  // mid-transaction; the later `$db->commit()` then throws
  // `PDOException("There is no active transaction")`, which
  // `handleCreateOrder()`'s own `catch (Exception $e) { ...; throw $e; }`
  // re-surfaces verbatim as `{success:false, message:"There is no active
  // transaction"}` — but the transactions/transaction_items/business_items
  // writes BEFORE that point already executed as individually-autocommitted
  // statements (MySQL's own implicit-commit semantics) and PERSISTED. The
  // dbChecks below (unaffected by `skipResponseBodyDiff`, run unconditionally
  // exactly like consent:save's own) are what actually verify this — and DO
  // confirm the PHP-side row landed correctly despite the reported failure.
  // The Next port does NOT reproduce this: `createOrder.ts`'s own module doc
  // already (independently, before this bug was found) drops the ALTER
  // TABLE entirely as a "SIMPLIFICATION" (`payment_status` is unconditionally
  // present per generated Kysely types) — meaning the Next port's
  // `{success:true}` response is not just port-fidelity, it is a **real,
  // deliberate correctness improvement over the PHP original**, same
  // category of deviation batch 2's consent:save/data-rights already
  // established a precedent for. `order_id`/`order_number`/`total`/
  // `payment_method`/`ar_id` are all consequently PRESENT on next / ABSENT
  // on php (PHP never reaches its own `jsonResponse(true, ...)` call) —
  // exactly why a body diff is meaningless here, not merely noisy.
  // -------------------------------------------------------------------------
  {
    name: 'checkout-order:create_order',
    method: 'POST',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/checkout/order',
    body: (variant) => ({
      action: 'create_order',
      line_user_id: FIXTURE.coCreateOrderLineUserId[variant],
      line_account_id: FIXTURE.lineAccountPrimary,
      address: {
        name: 'ทดสอบ ลูกค้า (E2E)',
        phone: '0855555555',
        address: '55 ถนนทดสอบ',
        subdistrict: 'คลองตัน',
        district: 'คลองเตย',
        province: 'กรุงเทพมหานคร',
        postcode: '10110',
      },
      payment_method: 'transfer',
    }),
    skipResponseBodyDiff: true, // see this case's own doc comment above — the ALTER-TABLE-inside-transaction PHP bug.
    allow: [], // unused (skipResponseBodyDiff bypasses body diffing) — kept empty, not deleted, for shape consistency with consent:save.
    dbChecks: [
      {
        label: 'transactions row after create_order (race-guard: stock=1, quantity=5) — proves the write happened despite PHP reporting failure',
        table: 'transactions',
        where: (variant) =>
          `user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.coCreateOrderLineUserId[variant]}') AND transaction_type = 'purchase'`,
        columns: ['total_amount', 'shipping_fee', 'grand_total', 'payment_method', 'status', 'payment_status'],
        allow: [],
      },
      {
        label: 'transaction_items row after create_order (race-guard)',
        table: 'transaction_items',
        where: (variant) =>
          `transaction_id = (SELECT id FROM transactions WHERE user_id = (SELECT id FROM users WHERE line_user_id = '${FIXTURE.coCreateOrderLineUserId[variant]}') AND transaction_type = 'purchase') ` +
          `AND product_id = ${FIXTURE.ccProductLowStock[variant]}`,
        columns: ['product_name', 'product_price', 'quantity', 'subtotal'],
        // product_name is allowlisted ONLY because 65-...'s own fixture deliberately gives the two
        // dedicated race-guard products distinct, readable names ("... (E2E, PHP)" / "... (E2E, Next)") for
        // debuggability — price/quantity/subtotal (the financially load-bearing fields) are still asserted
        // byte-equal, not allowlisted.
        allow: ['product_name'],
      },
      {
        // THE race-guard assertion itself: `UPDATE business_items SET
        // stock=stock-? WHERE id=? AND stock>=?` fails its own WHERE guard
        // (stock=1 < quantity=5) on BOTH stacks, but NEITHER
        // handleCreateOrder() nor its Next port checks the UPDATE's
        // rowCount()/affected-rows before proceeding — the order is created
        // regardless (see the two dbChecks above) and stock is left
        // UNTOUCHED. Two dedicated rows (1601/1602) means this genuinely
        // diffs "PHP's real, verified guard-preserving behavior" against
        // "Next's guard-preserving behavior," not a row compared to itself.
        label: 'business_items.stock UNCHANGED after create_order (no rowCount short-circuit on either stack)',
        table: 'business_items',
        where: (variant) => `id = ${FIXTURE.ccProductLowStock[variant]}`,
        columns: ['stock'],
        allow: [],
      },
    ],
  },

  // -------------------------------------------------------------------------
  // checkout-order:upload_slip — THE FIRST multipart/form-data CASE in this
  // whole migration effort (see infra/e2e/lib/harness-common.mjs's
  // `buildMultipartBody()`/`httpRequestMultipart()` doc comments and the
  // runbook's §5). `multipart: true` + `fields`/`file` (not `body`) — see
  // api-parity.mjs's `callStack()` for how this flag is dispatched. Two
  // pre-existing `transactions` rows (8101 php / 8102 next — see
  // 65-...'s own comment) so the upload has a real `order_id` to attach to;
  // deliberately a SEPARATE order from create_order's own case above (kept
  // independent, no cross-case ordering dependency). `image_url` is
  // host-derived (differs by construction between the php/next origins AND
  // by pre-seeded order_number AND by request-time `time()`) — allowlisted +
  // format-checked via FORMAT_CHECKS.image_url, same "format not exact
  // equality" treatment as order_number above. `line_account_id` is sent in
  // the form body purely as a tenant-routing signal (real client,
  // `uploadPaymentSlip()` in shop-api.ts, sends it too, even though
  // `handleUploadSlip()` itself never reads it) — same "unread but required
  // for routing" precedent member:update_profile/appointments:cancel/etc.
  // already established. `qr_data` (client-side QR pre-decode,
  // best-effort) is deliberately OMITTED — see the runbook's §5 for why.
  // -------------------------------------------------------------------------
  {
    name: 'checkout-order:upload_slip',
    method: 'POST',
    phpPath: '/api/checkout.php',
    nextPath: '/api/miniapp/checkout/order',
    multipart: true,
    fields: (variant) => ({
      action: 'upload_slip',
      order_id: String(FIXTURE.coUploadSlipOrderId[variant]),
      line_account_id: String(FIXTURE.lineAccountPrimary),
    }),
    file: { name: 'slip', filename: 'slip.png', contentType: 'image/png' },
    allow: ['image_url'],
    dbChecks: [
      {
        label: 'payment_slips row after upload_slip (status=pending)',
        table: 'payment_slips',
        where: (variant) => `order_id = ${FIXTURE.coUploadSlipOrderId[variant]}`,
        columns: ['status'],
        allow: [],
      },
    ],
  },
];

// ---------------------------------------------------------------------------
// NEXT_ONLY_CASES — addresses:{list,upsert,delete}. STRUCTURALLY SEPARATE
// from ENDPOINT_CASES above, on purpose (per the brief: "must be visually
// and structurally distinct... so a future reader doesn't mistake a 'PASS'
// here for PHP parity"). There is NO PHP source for `/api/miniapp/addresses`
// at all (`api/user-addresses.php` does not exist anywhere in the repo or
// its git history — `ls api/*address*.php`, a repo-wide grep for
// `user-addresses`/`user_addresses` under `*.php`, and `git log --all --
// api/user-addresses.php` all come up empty; see
// packages/contracts/src/addresses.ts's own "PROMINENT FINDING" doc comment
// for the full verification writeup). The normal callStack()-diffs-PHP-vs-
// Next mechanism ENDPOINT_CASES relies on is MEANINGLESS here — there is no
// `phpPath` to call, so there is nothing to diff against.
//
// Each entry instead (see api-parity.mjs's runNextOnlyCase()):
//   1. Calls the Next endpoint ONLY (`nextPath` — no `phpPath` field at all,
//      by design, not merely omitted).
//   2. Validates the response against the REAL zod schema imported from
//      @reya/contracts above (`schema` — not a hand-rolled shape).
//   3. For upsert/delete (the two WRITE actions), runs the SAME dbCheck
//      mechanism ENDPOINT_CASES uses, but comparing the resulting/deleted
//      row against a literal `expect`ed value (there is no php row to diff
//      against) — proving the write actually happened, not just that the
//      HTTP call returned 200.
//
// These are SELF-CONSISTENCY checks (Next-is-internally-correct-and-matches-
// its-own-contract), NOT parity checks (Next-matches-PHP). Every printed
// result carries `mode: 'next-only-self-consistency'` specifically so the
// final summary JSON is never misread as a 20th/21st/22nd PHP-vs-Next PASS.
// ---------------------------------------------------------------------------

export const NEXT_ONLY_CASES = [
  {
    name: 'addresses:list',
    mode: 'next-only',
    method: 'GET',
    nextPath: '/api/miniapp/addresses',
    query: () => ({
      action: 'list',
      line_user_id: FIXTURE.addrListLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
    }),
    schema: AddressesListResponseSchema,
    // Schema validation alone only proves SHAPE — this proves the two
    // pre-seeded rows (labels 'primary'/'secondary_1') actually came back,
    // not an empty list that also happens to satisfy the schema.
    extraCheck: (json) => {
      const out = [];
      const addresses = Array.isArray(json?.addresses) ? json.addresses : [];
      if (addresses.length !== 2) {
        out.push(`expected 2 pre-seeded addresses, got ${addresses.length}`);
      }
      const labels = addresses.map((a) => a?.label).sort();
      const expected = ['primary', 'secondary_1'];
      if (JSON.stringify(labels) !== JSON.stringify(expected)) {
        out.push(`expected labels ${JSON.stringify(expected)}, got ${JSON.stringify(labels)}`);
      }
      return out;
    },
  },
  {
    name: 'addresses:upsert',
    mode: 'next-only',
    method: 'POST',
    nextPath: '/api/miniapp/addresses',
    body: () => ({
      action: 'upsert',
      line_user_id: FIXTURE.addrUpsertLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
      label: 'primary',
      name: 'ที่อยู่ใหม่ (E2E)',
      phone: '0844444444',
      address: '99 ถนนทดสอบ',
      subdistrict: 'คลองตัน',
      district: 'คลองเตย',
      province: 'กรุงเทพมหานคร',
      postcode: '10110',
    }),
    schema: AddressesUpsertResponseSchema,
    // Insert path — FIXTURE.addrUpsertLineUserId deliberately has NO
    // pre-existing 'primary' row (see the fixture file's own comment).
    dbCheck: {
      label: 'user_addresses row after upsert (insert path)',
      table: 'user_addresses',
      where: `line_user_id = '${FIXTURE.addrUpsertLineUserId}' AND line_account_id = ${FIXTURE.lineAccountPrimary} AND label = 'primary'`,
      columns: ['name', 'phone', 'address', 'subdistrict', 'district', 'province', 'postcode'],
      expect: {
        name: 'ที่อยู่ใหม่ (E2E)',
        phone: '0844444444',
        address: '99 ถนนทดสอบ',
        subdistrict: 'คลองตัน',
        district: 'คลองเตย',
        province: 'กรุงเทพมหานคร',
        postcode: '10110',
      },
    },
  },
  {
    name: 'addresses:delete',
    mode: 'next-only',
    method: 'POST',
    nextPath: '/api/miniapp/addresses',
    body: () => ({
      action: 'delete',
      line_user_id: FIXTURE.addrDeleteLineUserId,
      line_account_id: FIXTURE.lineAccountPrimary,
      label: 'secondary_1',
    }),
    schema: AddressesDeleteResponseSchema,
    // Delete path — FIXTURE.addrDeleteLineUserId HAS a pre-existing
    // 'secondary_1' row (see the fixture file's own comment), so this is a
    // real delete, not a no-op on an already-empty slot.
    dbCheck: {
      label: 'user_addresses row count after delete (pre-existing row really removed)',
      table: 'user_addresses',
      where: `line_user_id = '${FIXTURE.addrDeleteLineUserId}' AND line_account_id = ${FIXTURE.lineAccountPrimary} AND label = 'secondary_1'`,
      columns: ['__row_count__'],
      expect: { row_count: 0 },
    },
  },
];
