#!/usr/bin/env node
// infra/e2e/lib/api-extract.mjs
//
// Phase 3 batch 1 (mig-infra) — per-endpoint request builders + allowlist
// definitions consumed by infra/e2e/api-parity.mjs's JSON API parity mode.
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
};

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
};

// ---------------------------------------------------------------------------
// Endpoint x action cases — one entry per row of the acceptance-criteria
// table (16 total). `path`/`nextPath` are relative to PHP_BASE_URL/NEXT_BASE_URL
// respectively. `query`/`body` are either a plain object (GET-only cases,
// identical for both stacks) or a `(variant) => object` function (write
// cases, where `variant` is 'php' | 'next' and selects the paired identity).
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
];
