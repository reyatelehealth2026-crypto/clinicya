# Phase 2 settings batch 1 — /settings?tab={welcome,email,consent,shop_tax} parity harness

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 2
(page-by-page port), §1.5 (strangler edge), §7.3 (canary ramp). Owner:
mig-infra (this harness extension) / settingsHubAndCore (shell + welcome/email
tabs) / settingsConsentTax (consent + shop-tax tabs) / mig-orchestrator
(route-flip authorization).

This is a **new file, cross-referenced with, not appended into,**
`docs/runbooks/phase2-batch1-users-dashboard-parity.md` — that file is
already huge (batches 1-3 of the `/users`/`/dashboard`/`/analytics`/
`/templates`/… surface) and this batch's brief explicitly asked for a
separate file. **Read that file first** for anything not repeated here:

- §1 "How to run it" — the exact `node infra/e2e/parity.mjs` invocation,
  compose lifecycle, seeding order, login flow. Unchanged by this batch —
  not repeated below.
- §2 "How to read the JSON output" — the `{result, pages, steps, failedAt}`
  envelope shape. Unchanged by this batch — not repeated below.
- `docs/runbooks/phase4-batch1-inbox-reads-parity.md` — the most recent prior
  extension (the `/inbox` surface + the two JSON cursor-pagination walks).

Only what's genuinely new in this batch is documented here.

---

## 0. Exact before/after `pages`-array count (verified empirically, not guessed)

The brief for this batch warned not to trust any pre-quoted number and to
verify empirically instead. Done as follows: **actually ran**
`node infra/e2e/parity.mjs` against this batch's own (finished) harness from
a fully torn-down state (`docker ps -a` empty beforehand) and partitioned the
real JSON output's `pages` array by name prefix, rather than statically
counting `pages.push(` call sites in the source (which undercounts —
several call sites are inside `for` loops over filter-combo arrays, and two
call sites `pages.push(...(await runXChecks(...)))` spread an array of
multiple named entries per call). Full ordered list of all 52 `page` names
from this real run is reproduced in §7 below.

```
non-settings (pre-existing, batches 1-4 + inbox) count: 47
settings:*  (this batch's new entries)             count:  5
                                                    total: 52
```

- **47** — matches `git log`'s own trail (batches 1-3 landed 42 entries per
  `phase2-batch1-users-dashboard-parity.md`'s own §17/§18 write-up; Phase 4
  batch 1 added 5 more: `inbox:php-empty-currentbotid-clobbered`,
  `inbox:next-baseline`, `inbox-thread:id=7001`,
  `inbox-conversations-cursor-walk`, `inbox-messages-cursor-walk` — 42 + 5 =
  47). Independently cross-checked against `git show HEAD:infra/e2e/parity.mjs`
  (the committed baseline immediately before this batch's own edits): that
  revision's source has no `settings:`-prefixed page name anywhere, and its
  own most recently landed batch (Phase 4 batch 1) is the same 47-entry
  harness this section's real run reproduces. Every one of these 47 stays
  `ok:true` in this batch's own real runs (see §7 below) — **no regression**.
- **5**, not "4" as an early draft of this batch's brief implied — see §3's
  "`settings:email` is two entries, not one" note for why `settings:email`
  became `settings:email-php-line-fallback` + `settings:email-next-real`.

## 1. What's new — the 5 new `pages` entries

`infra/e2e/parity.mjs` now fetches+extracts+diffs 5 additional page-pair
entries, appended to the SAME `pages` array batches 1-4 already populate.
Same independent-try/catch-per-entry pattern as every batch before it — one
broken/missing tab fails as its own entry, never the others (rehearsed in
§8 below).

| `pages` entry | Shape | PHP source | Next source |
|---|---|---|---|
| `settings:welcome` | two-sided `runPagePair()` diff | `includes/settings/welcome.php` (271 LOC) | `_components/WelcomeTab.tsx` + `_components/WelcomeMessageForm.tsx` |
| `settings:email-php-line-fallback` | one-sided `runSingleSideCheck()` (PHP) | root `settings.php`'s `$tabs` whitelist fallback → `includes/settings/line.php` | — |
| `settings:email-next-real` | one-sided `runSingleSideCheck()` (Next) | — | `_components/EmailTab.tsx` |
| `settings:consent` | two-sided `runPagePair()` diff | `includes/settings/consent.php` (242 LOC) | `_components/ConsentTab.tsx` + `_lib/consent-queries.ts` |
| `settings:shop-tax` | two-sided `runPagePair()` diff | `includes/settings/shop-tax.php` (227 LOC) | `_components/ShopTaxTab.tsx` + `_lib/shop-tax-queries.ts` |

All four PHP source files above are `include`d from root **`/settings.php`**
(941 LOC) — confirmed the LIVE hub (see §2 below for why this matters), never
the dead `includes/settings/settings.php` (562 LOC) duplicate.

### `settings:welcome` (PHP: `settings.php?tab=welcome`, Next: `/settings?tab=welcome`)

Extraction (`extractSettingsWelcomeTab()` in `infra/e2e/lib/extract.mjs`):
`isEnabled` (the header toggle's `checked` state), `messageType` (`'text'` or
`'flex'`, whichever radio is `checked`), `isDefaultGreeting` (a boolean
marker — does the `text_content` textarea contain the well-known hardcoded
default greeting's `"ยินดีต้อนรับ! 🎉"` substring).

**Expected — and confirmed (§7) — to always come back EQUAL, on the exact
same DEFAULT values, on both stacks.** See §4.1's `welcome_settings`
missing-table finding.

### `settings:email-php-line-fallback` + `settings:email-next-real`

**Not a diff — a deliberate one-sided-assertion pair**, same family as
`runCrmDashboardAdvancedChecks()`/`runInboxSidebarChecks()` in
`phase2-batch1-users-dashboard-parity.md`'s own precedent. See §4.2 for the
full "why PHP's `?tab=email` is unreachable" finding and §3 for why this is
two `pages` entries, not one.

- `settings:email-php-line-fallback` — `extractSettingsEmailPhpFallback()`
  fetches `settings.php?tab=email` and **throws** unless the response shows
  `includes/settings/line.php`'s own markup (`"บัญชี LINE Official
  Account"` heading + `openLineModal()` trigger) AND does **not** show
  `includes/settings/email.php`'s markup (`"ตั้งค่า Email/SMTP"` heading,
  `name="smtp_host"` field).
- `settings:email-next-real` — `extractSettingsEmailTab()` fetches
  `/settings?tab=email` and **throws** unless the response's SMTP form
  fields exactly match the seeded `email_settings` (id=1) fixture row
  (`SETTINGS_EMAIL_FIXTURE` constant in `extract.mjs`): `smtp_host`,
  `smtp_port`, `smtp_user`, `smtp_pass` (presence only, not value),
  `from_email`, `from_name`, and the `smtp_secure` `<select>`'s selected
  `<option>`.

### `settings:consent` (PHP: `settings.php?tab=consent`, Next: `/settings?tab=consent`)

Extraction (`extractSettingsConsentTab()`) **auto-detects** which of two
valid states rendered rather than assuming one — see §4.3 for the full "does
`consent.php`'s `admin_users` JOIN actually throw?" investigation, which is
the single most important finding in this batch:

- If the red `"❌ ... กรุณารัน migration ก่อน"` error banner is present →
  returns `{errorState: true}` only.
- Otherwise → returns `{errorState: false, totalConsented,
  privacyPolicyCount, termsOfServiceCount, healthDataCount,
  consentLogRowCount, accessLogRowCount}` — the 4 stat-card numbers
  (label-anchored `HtmlCursor.beforeLabel()` lookups, same "number div, then
  label div" shape `extractUserDetailPage()` already anchors on elsewhere in
  this file) plus a row count for each of the two log tables, via the
  fixture's own known-distinct `ip_address` literals (see §5's fixture
  write-up) — the SAME "known-value row-counting" technique this file's own
  module doc documents for avatar-fallback/tag-name counting, just keyed on
  IP strings.

**Confirmed by this batch's own real harness run: BOTH stacks land in the
POPULATED state** (`errorState: false`, `totalConsented: 3,
privacyPolicyCount: 2, termsOfServiceCount: 1, healthDataCount: 1` — matching
exactly on both sides, every real run) — **not** the permanent error-banner
state `apps/admin`'s own `consent-queries.ts` module doc predicted. See §4.3.

### `settings:shop-tax` (PHP: `settings.php?tab=shop_tax`, Next: `/settings?tab=shop_tax`)

Extraction (`extractSettingsShopTaxTab()`): `businessName`, `taxId`,
`isVatRegisteredChecked` (the checkbox's `checked` state), `defaultVatRate`
(the raw `value="..."` string of the `default_vat_rate` `<input>`).

**Expected — and confirmed (§7) — to land on shop-tax.php's DEFAULT values
on both stacks** (see §4.4), **and PASSES** on all four fields, including
`defaultVatRate` (`php="7" next="7"`). This was a real, confirmed FAIL
earlier in this batch's own development — see §4.5 for the full
before/after (a one-field `apps/admin` default-value bug, found by this
harness, fixed by settingsConsentTax, confirmed fixed by this batch's own
real, repeated harness runs in §7).

## 2. Scope correction inherited from this batch's own hand-off

Same correction `apps/admin/src/app/(tenant)/settings/page.tsx`'s own module
doc already makes, repeated here because it changes which PHP file is the
actual source of truth: `includes/settings/settings.php` (562 LOC) — the
file an early draft of this round's hand-off named — is **dead, orphaned
code**: zero `include`/`require` of it anywhere in the repo (grepped), not
linked from `includes/header.php`'s nav. The genuinely LIVE hub real tenants
hit is root **`/settings.php`** (941 LOC), modeled by every extractor in this
batch and by `apps/admin/src/app/(tenant)/settings/page.tsx`. Every `settings:*`
entry in this file fetches/diffs the LIVE file.

`settings.php`'s own `$tabs` whitelist (lines 33-46) — the array
`getActiveTab()` (`includes/components/tabs.php` lines 336-351) validates
`?tab=` against via `isset($tabs[$tab])`:

```php
$tabs = [
    'line' => [...], 'platform' => [...], 'general' => [...],
    'shop_tax' => [...], 'welcome' => [...],
    // 'liff' => [...],          <- commented out
    // 'vibe-selling' => [...],  <- commented out
    // 'telegram' => [...],      <- commented out
    // 'email' => [...],         <- commented out
    'notifications' => [...], 'consent' => [...],
    // 'quick-access' => [...],  <- commented out
];
```

7 tabs live (`line`, `platform`, `general`, `shop_tax`, `welcome`,
`notifications`, `consent`); 5 more code-present-but-commented-out
(`liff`, `vibe-selling`, `telegram`, `email`, `quick-access`). This batch
ports **4 of the 7 live tabs** (`shop_tax`/`welcome`/`consent` two-sided,
plus the not-nav-visible-but-routable `email`) — `line`/`platform`/
`general`/`notifications` remain PHP-only (Next's `page.tsx` renders an
explicit `NotYetMigratedTab` placeholder for those four).

`getActiveTab($tabs, 'line')` semantics (confirmed by reading
`includes/components/tabs.php` lines 336-351 in full):

```php
function getActiveTab($tabs, $default = null) {
    $tab = $_GET['tab'] ?? null;
    if ($tab && isset($tabs[$tab])) { return $tab; }
    if ($default && isset($tabs[$default])) { return $default; }
    return array_key_first($tabs);
}
```

`?tab=` must be a whitelisted key or the page falls back to the explicit
default (`'line'`, itself the first key) — **never** a different, unrelated
tab's content. This is exactly what makes §4.2's `email`-unreachable finding
true, and exactly what `settings:email-php-line-fallback` positively asserts.

## 3. `settings:email` is TWO entries, not one

This batch's brief's deliverables list named a single `settings:email`
extraction pair. Implemented instead as **two** independent
`runSingleSideCheck()`-based `pages` entries
(`settings:email-php-line-fallback` + `settings:email-next-real`), via a new
`runSettingsEmailChecks()` composer function in `parity.mjs` — mirroring
`runCrmDashboardAdvancedChecks()`'s (3 entries for 1 logical page) and
`runInboxSidebarChecks()`'s (2 entries for 1 logical page) own established
precedent in `phase2-batch1-users-dashboard-parity.md`.

**Why not force it into one entry**: PHP and Next are *expected* to render
two genuinely unrelated things at `?tab=email` (PHP: the LINE tab's
markup, because of the whitelist fallback in §2; Next: a real, working
`EmailTab`) — there is no meaningful "diff" between them, and
`runPagePair()`'s `diff()` is a plain structural `deepEqual`, not a tool for
asserting two *different* shapes are each individually correct.
`runSingleSideCheck()`'s own contract (`assertAndExtract(resp)` runs
SYNCHRONOUSLY against ONE already-fetched response — see its own signature
in `parity.mjs`) doesn't support fetching+asserting BOTH sides from inside a
single call without changing that shared helper's signature, which is
outside this batch's allowed paths (append-only, no helper signature
changes). Two independently-`ok`/`mismatches` entries was the correct,
established-precedent choice.

**Consequence for the "deliberately break Next" acceptance rehearsal (§8)**:
breaking Next's `page.tsx` makes 4 of these 5 `settings:*` entries fail via a
404-shaped fetch error (`settings:welcome`, `settings:email-next-real`,
`settings:consent`, `settings:shop-tax` — every entry that touches the Next
fetch) while `settings:email-php-line-fallback` correctly STAYS `ok:true`
(it never touches Next at all) — this is the intended, correct behavior, not
a gap: it proves breaking Next doesn't spuriously fail an unrelated PHP-only
check.

## 4. Findings — read before assuming any `settings:*` entry's shape is obvious

Three of these five were ALREADY documented by settingsHubAndCore/
settingsConsentTax's own module docs before this batch started (§4.1, §4.2,
part of §4.4) — reproduced here with this harness's own independent
empirical confirmation, per this agent's mandate ("reproduced against a real
running stack", not just read off a comment). **Two are NEW, discovered by
THIS batch's own harness run** (§4.3's refutation, §4.5's real bug) — neither
was visible from reading any single file in isolation.

### 4.1 `welcome_settings` — missing table, both stacks degrade identically (CONFIRMED, pre-existing)

`welcome_settings` does not exist anywhere in
`database/migration_2026-05-25_tenant_template.sql` (grepped: zero `CREATE
TABLE` matches) and is not auto-created by any PHP file (grepped
`welcome_settings` repo-wide — every hit is a SELECT/INSERT/UPDATE against
it, never a `CREATE TABLE`). On the committed schema:

- `includes/settings/welcome.php`'s own `SELECT * FROM welcome_settings ...`
  (lines 11-17) is try/catch-wrapped and degrades to the hardcoded default
  greeting object (lines 19-25) — `is_enabled=0, message_type='text',
  text_content='สวัสดีค่ะ ยินดีต้อนรับ! 🎉\n\n...'`.
- `settings.php`'s `save_welcome` action (lines 484-516) is wrapped in its
  own try/catch too, setting `$error` on failure.
- `apps/admin`'s `welcome-queries.ts::getWelcomeSettings()` ports the EXACT
  same try/catch-then-default contract (its own module doc independently
  confirms the same grep result).

**This batch's own real harness run confirms**: `settings:welcome` passes
every real run (§7/§8) — both stacks render `{isEnabled: false, messageType:
'text', isDefaultGreeting: true}`, byte-identical. A pre-existing PHP
defect, same class as `crm-dashboard-advanced`'s missing `crm_deals`/
`crm_tickets` finding — **out of scope to fix here** (`database/**` is
outside this agent's allowed paths). The fixture (§5) deliberately does
**not** create this table or seed a row — doing so would defeat the point of
this entry, which is to prove the degrade path is identical on both stacks.

### 4.2 PHP's `?tab=email` is unreachable via the nav — always falls back to the LINE tab (CONFIRMED, pre-existing)

See §2's `$tabs` whitelist trace. `settings.php?tab=email` **always**
renders `includes/settings/line.php`'s LINE-accounts-manager markup, never
`includes/settings/email.php`'s — confirmed both by static reading (the
whitelist array + `getActiveTab()`'s fallback logic) and by this batch's own
real harness run: `settings:email-php-line-fallback` passes every real run
(§7/§8), positively confirming PHP's response contains `"บัญชี LINE
Official Account"` + `openLineModal()` and NOT `"ตั้งค่า Email/SMTP"` or
`name="smtp_host"`.

`email.php`'s own code still works perfectly if reached some other way —
this is a routing/whitelist gap, not a broken partial. `apps/admin`'s port
deliberately does NOT reproduce the fallback for `email` (see
`page.tsx`'s own module doc: "`ROUTABLE_TAB_KEYS` includes 'email' ...
while `SETTINGS_TABS` ... stays at 7" — the nav pill list matches PHP
exactly; only direct `?tab=email` navigation differs, and only in the
"more capable, not less" direction). This is why `settings:email` is a
ONE-SIDED assertion pair (§3), never a diff.

### 4.3 Does `consent.php`'s `admin_users` JOIN actually throw? — REFUTED, by this batch's own empirical run

`apps/admin`'s `_lib/consent-queries.ts` module doc states, as a "CONFIRMED
FINDING": *"this tab's red error banner is the PERMANENT state on any
tenant DB built from the committed template ... `admin_users` is a
platform-level table [that] does NOT exist inside a tenant DB."* That
reasoning is a correct description of the **committed migration file alone**
(`database/migration_2026-05-25_tenant_template.sql`'s own header does say
platform-level tables like `admin_users` live in `reya_platform`, and grep
confirms zero `CREATE TABLE admin_users` there) — but it does **not**
account for `classes/AdminAuth.php`'s **runtime** behavior:

```php
// classes/AdminAuth.php, ensureTables(), lines 34-43
try {
    $result = $this->db->query("SELECT 1 FROM admin_users LIMIT 1");
    ...
} catch (Exception $e) {
    // Create admin_users table  (lines 44-71)
    $this->db->exec("CREATE TABLE IF NOT EXISTS admin_users (...)");
    ...
}
```

`includes/auth_check.php` line 17 instantiates `new AdminAuth($db)`
**unconditionally**, before its own `isset($_SESSION['admin_user'])` gate —
so it fires on every request through `auth_check.php`, authenticated or not,
against `Database::getInstance()->getConnection()` — **the exact same
physical tenant database** `admin_users`, `consent_logs`,
`data_access_logs`, etc. all live in (bootstrap/resolve_subdomain.php's
resolution — same DB either PHP's or Next's Kysely `db` connects to for this
harness's one seeded tenant). The FIRST such request in this whole harness's
run — `fireThrowawayProbeRequest()`, an unauthenticated GET to
`/system-status.php` — creates `admin_users` (with `id`, `username`,
`password`, `email`, `display_name`, `role`, `is_active`, `last_login`,
`login_count`, `created_at`, `updated_at` columns — **no** `line_account_id`
column, which matters for §4.4/§4.5, but is irrelevant here) well before
either stack's `/settings?tab=consent` is ever fetched.

`consent.php`'s (and `getConsentPageData()`'s) 4th query —
`LEFT JOIN admin_users au ON dal.admin_user_id = au.id` — only needs `au.id`
and `au.username`, both of which genuinely exist on the auto-created table.
**Verified by actually fetching the real page on both stacks** (see §7):
both land in the POPULATED view (`errorState: false`), with matching stat
numbers (`totalConsented: 3, privacyPolicyCount: 2, termsOfServiceCount: 1,
healthDataCount: 1`) — **not** the permanent error banner.

**This is flagged as a genuine correction to a sibling batch's documented
finding**, not a criticism of the reasoning method — reading the committed
migration file and concluding "the table doesn't exist" is exactly right for
a schema-only analysis; it's `AdminAuth.php`'s runtime side effect that
changes the real-world answer, and that side effect is only visible by
actually running the full stack end-to-end, which is precisely this agent's
job. `extractSettingsConsentTab()` was deliberately designed to
AUTO-DETECT which state renders (§1) rather than assume either — so if a
future change ever makes the error banner genuinely permanent again (e.g. a
schema change that removes `admin_users.username`), this entry would still
correctly compare `{errorState: true}` on both sides and pass, or catch a
real asymmetry if only one side breaks.

### 4.4 `settings:shop-tax`'s `$lineAccountId` always resolves to 0 in this harness (CONFIRMED, pre-existing, fixture-invariant limitation)

`includes/settings/shop-tax.php` resolves its own LOCAL `$lineAccountId`
independently of `settings.php`'s outer variable (PHP `include` shares
scope, so this local reassignment wins):

```php
$lineAccountId = (int)($_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 0);
if ($lineAccountId <= 0 && !empty($_SESSION['user_id'])) {
    try { /* SELECT line_account_id FROM admin_users WHERE id = ? */ } catch (\Throwable $e) {}
}
if ($lineAccountId <= 0) {
    try { /* SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1 */ } catch (\Throwable $e) {}
}
if ($lineAccountId > 0) { /* SELECT * FROM shop_tax_info WHERE line_account_id = ? */ }
```

Traced against this harness's own invariants:

- `$_SESSION['current_bot_id']` is never set anywhere in this harness's run
  — `includes/header.php` only auto-sets it when `getAccessibleBots()`
  returns a non-empty list, i.e. only when a `line_accounts` row exists.
  This harness deliberately seeds **zero** such rows (the invariant
  `60-phase2-batch3-fixture.sql.tmpl`'s own "WHY NO `line_accounts` ROWS"
  section establishes and every later batch, including this one, inherits
  unchanged — breaking it would silently re-scope every EARLIER batch's
  already-passing `line_account_id`-sensitive query on the shared `phpSid`
  session).
- `$_SESSION['line_account_id']` is never assigned anywhere in this codebase
  (grepped) — dead fallback, on both PHP and (per `shop-tax-queries.ts`'s
  own doc) the Next port.
- The `admin_users` lookup — **regardless of §4.3's finding that the table
  itself now exists at query time** — has NO `line_account_id` column (see
  §4.3's own `CREATE TABLE` excerpt), so `SELECT line_account_id FROM
  admin_users WHERE id = ?` throws "Unknown column" every time, caught,
  silently ignored.
- `line_accounts` is empty (same invariant) — that fallback also resolves to
  0.

So `$lineAccountId` is **always 0** in this harness; the final
`if ($lineAccountId > 0)` read-guard never fires; `shop-tax.php`'s
pre-initialized `$row` array (lines 31-44) is what always renders. Confirmed
by this batch's own real harness run (§7): both stacks show
`businessName: "", taxId: "", isVatRegisteredChecked: false` — matching. A
fixture-invariant limitation (production tenants DO have a real
`current_bot_id`), not a PHP defect — same class as §4.1's finding. The
fixture (§5) still seeds a fully-populated `shop_tax_info` row anyway (the
brief asked for it, and it future-proofs the fixture for whenever a later
batch's own fixture legitimately needs an active `line_accounts` row).

### 4.5 `settings:shop-tax`'s `default_vat_rate` — a real `apps/admin` default-value bug found by this harness, RESOLVED before this batch's final acceptance run

**This entry PASSES as of this batch's final, real harness runs (§7)** — but
it did not always: this section is kept as a full before/after because the
bug it documents was real, was found by this exact harness (not
theoretical), and the fix landed in a sibling agent's files this agent
cannot touch — worth a permanent record for whoever next edits
`shop-tax-queries.ts`.

PHP's hardcoded default `default_vat_rate` (the value that renders under
§4.4's always-unreached-query state) is the **float literal** `7.00`
(`includes/settings/shop-tax.php` line 43: `'default_vat_rate' => 7.00,`).
`shop-tax.php`'s own `$h()` helper casts it `(string)` before
`htmlspecialchars()`. PHP's float-to-string conversion of a whole-number
float **drops the trailing `.00`**:

```
$ php -r 'var_dump((string)7.00);'
string(1) "7"
```

— verified empirically in this exact environment, not assumed. So PHP's
DEFAULT-path HTML is `value="7"`.

**Before** — `apps/admin/src/app/(tenant)/settings/_lib/shop-tax-queries.ts`'s
`DEFAULT_SHOP_TAX_INFO.defaultVatRate` was the **string literal** `'7.00'`.
That file's doc comment justified this as "matching PHP's own un-cast
`$row['default_vat_rate']` (a PDO string)" — true of the **populated-row**
path (where PDO really does return a `DECIMAL(4,2)` column as a zero-padded
string like `"7.00"`), but that reasoning does **not** apply to the DEFAULT,
unreached-query path (§4.4) — on that path PHP's own value is a bare float
literal, not a PDO string, and its `(string)` cast produces `"7"`, not
`"7.00"`. An earlier run of this exact fixture + extractor pair (during this
batch's own development, before its final acceptance run) caught this as a
genuine `{"ok":false,"mismatches":["defaultVatRate: php=\"7\" next=\"7.00\""]}`
— a real, one-character, deterministic mismatch on the exact code path this
harness's own invariants make certain.

**Not fixed by this agent** — `apps/admin/src/app/(tenant)/settings/**` is
outside mig-infra's allowed paths (owned by settingsHubAndCore/
settingsConsentTax). Flagged in this agent's build report and routed to
settingsConsentTax.

**After** — `DEFAULT_SHOP_TAX_INFO.defaultVatRate` now reads `'7'`
(confirmed by reading the current source of `shop-tax-queries.ts`, not
assumed), with that file's own doc comment explaining the fix and crediting
this extractor for finding it. This batch's own real, repeated harness runs
(§7) confirm the fix: `defaultVatRate: php="7" next="7"` on every field of
every run, `settings:shop-tax` fully `ok:true`.

`extractSettingsShopTaxTab()` deliberately still does **not** normalize
`"7"`/`"7.00"` — it does a plain, unmodified string comparison. That was the
right design both before the fix (an honest FAIL, not a silently-hidden bug)
and after (an honest PASS, not a coincidence): if a future change
reintroduces a mismatch on either side, this extractor catches it exactly as
it did the first time, with zero code changes needed here.

## 5. The fixture — `infra/e2e/seed/75-phase2-settings-batch1-fixture.sql.tmpl`

Applied via `FIXTURE_FILES` in `parity.mjs` (append-only edit — added as the
5th, last entry, after `70-phase4-batch1-inbox-fixture.sql.tmpl`), same
additive-on-top-of-the-same-tenant-DB convention every earlier fixture file
already established. Full rationale for every design decision lives in the
file's own extensive header/inline comments — summarized:

- **`users`** — 3 new synthetic rows (ids `9201`-`9203`, `line_account_id IS
  NULL`, same zero-`line_accounts`-rows invariant every earlier batch
  maintains). `consent_logs cl JOIN users u ON cl.user_id = u.id` is an
  INNER JOIN — every `consent_logs` row needs a real `users.id` or the whole
  query silently drops that row.
- **`user_consents`** (4 `is_accepted=1` rows) — sized so the 4 KPI numbers
  are non-trivial and independently verifiable: `totalConsented=3`,
  `privacy_policy=2`, `terms_of_service=1`, `health_data=1`.
- **`consent_logs`** (4 rows, all 3 required `consent_type` values, one
  `withdraw` action) — each row's `ip_address` is a distinct literal
  (`203.0.113.101`-`104`), the row-counting marker (see §4's own "RSC
  hydration payload" finding, §6 below, for why this needed a fix).
- **`data_access_logs`** (2 rows) — one `admin_user_id=NULL` (exercises the
  `'System'` fallback label), one `admin_user_id=2` (the harness's own
  `e2e_parity_admin` row — see the fixture's own "SEQUENCING TRAP" section
  for the full, empirically-reasoned trace of exactly why `2` is
  deterministic, not guessed: `FIXTURE_FILES` runs strictly BEFORE
  `fireThrowawayProbeRequest()`/`seedAdminUser()` in `parity.mjs`'s own
  `main()`, so `admin_users` doesn't exist yet at THIS file's INSERT time —
  a plain literal integer is safe since the column has no FK constraint, and
  `AdminAuth::ensureTables()`'s own fixed insert order — one `'admin'` row
  first (id=1), then the harness's own seeded admin second (id=2) — makes
  `2` deterministic on a freshly-torn-down container every run).
- **`shop_tax_info`** (1 row, `line_account_id=1`, fully populated,
  `is_vat_registered=1`) — per §4.4, unreachable under this harness's own
  invariant; seeded anyway per the brief's ask and for future-proofing.
- **`email_settings`** (1 row, `id=1`, full SMTP config) — genuinely reached
  and asserted by `settings:email-next-real` (§1); `smtp_pass` is an
  obviously-fake placeholder string (GitGuardian secrets-scan discipline).
- **`welcome_settings`** — deliberately **NO** rows and **NO** `CREATE
  TABLE` (§4.1) — the whole point of `settings:welcome` is to prove the
  missing-table degrade path is identical on both stacks.

## 6. Extraction — `infra/e2e/lib/extract.mjs`'s new functions

Appended after `extractInboxThreadPage()` (the prior batch's last function),
same "label-anchored, not class-anchored" philosophy this file's own module
doc establishes for everything else — see that module doc before touching
any of these.

- `extractSettingsWelcomeTab(html)` — §1.
- `extractSettingsEmailPhpFallback(html)` / `extractSettingsEmailTab(html)`
  — §1, §3.
- `extractSettingsConsentTab(html)` — §1, §4.3.
- `extractSettingsShopTaxTab(html)` — §1, §4.4, §4.5.

New shared tag-attribute helpers (`findInputTag()`, `attrValue()`,
`hasCheckedAttr()`, `selectedOptionValue()`) — this batch's forms are plain
`<input>`/`<select>`/`<textarea>` elements, not the repeated-row/KPI-card
shapes every earlier extractor anchors on. They match a whole tag by
requiring a set of literal substrings ANYWHERE inside it (order-independent
— verified PHP and Next emit attributes in genuinely different orders; e.g.
`ShopTaxTab.tsx` writes `type`/`name`/`maxLength`/`required`/`defaultValue`/
`className`/`placeholder`, PHP writes `type`/`name`/`maxlength`/`value`/
`class`/`placeholder`), rather than assuming attribute position.

### RSC hydration payload double-counts row markers — a genuinely new extraction-methodology finding

`extractSettingsConsentTab()`'s row-counting technique (known-value
substring counting via the fixture's own distinct `ip_address` literals)
initially produced **exactly double** the real row count on the Next side
only (`consentLogRowCount: php=4 next=8`, `accessLogRowCount: php=2
next=4` — both real, repeated `PARITY_DUMP_HTML=1` runs, not a one-off).

**Root cause, confirmed by inspecting the real dumped HTML**: each fixture
IP literal (e.g. `203.0.113.101`) appears **twice** in Next's raw SSR
response — once in the real, visible `<td>` markup, and a second time
inside a `self.__next_f.push(...)` React Server Components hydration
payload `<script>` tag (a JSON-escaped re-serialization of the same row
data, used by React to hydrate without a second client fetch):

```
--- occurrence 1 (real markup) ---
...text-gray-500">v<!-- -->1.0</td><td class="px-4 py-3 text-xs text-gray-400">203.0.113.101</td></tr>...
--- occurrence 2 (RSC hydration payload) ---
...\"children\":\"203.0.113.101\"}]]}]],null]}]]}]}]}]\n"])</script>
```

This is the **same general quirk** `phase2-batch1-users-dashboard-parity.md`
already documents for `extractLineGroupDetailBody()` (that page's own
`self.__next_f.push(...)` finding) — but that page's fix ("cut the read
window off at the first trailing `<script` tag") does **not** generalize
here: this page's hydration `<script>` chunks are INTERLEAVED throughout the
document (Next's streaming SSR here emits several `<script>` tags starting
well BEFORE the visible table markup, confirmed via the same real dump), so
a single "cut before the first `<script`" bound would incorrectly discard
real content too.

**Fix applied**: a new `stripScriptBlocks()` helper removes EVERY
`<script>...</script>` block (tag + contents) from the extraction source
before counting — verified (via the same real dump) to bring every fixture
IP's count down to exactly 1 on both stacks. PHP is unaffected either way
(`consent.php`'s own `<script>` block, the CSS-tab-toggle JS, contains no
IP-address-shaped text). Applied uniformly to both stacks for symmetry.
Every other extractor in this batch (`findInputTag()`'s tag-syntax search,
`HtmlCursor`'s label-adjacent lookups) was checked against the same real
dumps and found NOT to need this treatment — HTML tag syntax and
immediately-adjacent label text don't coincidentally re-appear verbatim
inside a JSON-escaped RSC payload the way a bare data value does; only this
batch's own new known-value row-counting technique was exposed to it.

## 7. Acceptance evidence — clean run (rehearsed in this environment, real docker + real `next build`)

Multiple full, real runs were executed while building this harness. An
EARLIER run (while §4.5 was still an open, unfixed bug) produced a real,
honest `{"result":"FAIL"}` with `settings:shop-tax` the sole `ok:false`
entry (`mismatches: ["defaultVatRate: php=\"7\" next=\"7.00\""]`) — that FAIL
was the correct, working-as-designed result for the codebase's state AT THAT
TIME, and is why §4.5 exists. It is not reproduced verbatim here because it
is now stale (the bug was fixed, see §4.5's "After"); what follows is the
LAST run, against the current (fixed) source, which is the evidence that
counts for this batch's acceptance criteria.

**`node infra/e2e/parity.mjs` from a fully torn-down state** (`docker ps -a`
empty beforehand — verified, 0 containers):

```json
{"result":"PASS","pages":[...52 entries, every one ok:true...],"steps":{...},"failedAt":null}
```

Full ordered list of all 52 `page` names in this real run (§0's 47
pre-existing + this batch's 5 new, every single one `ok:true`,
`mismatches:[]`):

```
users:baseline, users:search, users:tag, users:tier, users:points,
users:activity, users:purchase-purchased, users:purchase-never,
users:status, user-detail:id=1, user-detail:id=2, user-detail:id=11,
dashboard:tab=executive, dashboard:tab=crm, analytics:tab=overview,
analytics:tab=advanced, analytics:tab=crm, analytics:tab=account,
activity-logs:baseline, activity-logs:type, activity-logs:action,
activity-logs:search, activity-logs:date-range, activity-logs:combined,
activity-logs:page2, loyalty-members:baseline, loyalty-members:search,
templates:baseline, groups:baseline, groups:view-empty,
groups:view-members, line-groups:baseline, line-group-detail:id=1,
line-group-detail:id=2, line-group-detail:php-header-defect id=1,
line-group-detail:next-header id=1, line-group-detail:php-header-defect id=2,
line-group-detail:next-header id=2, crm-dashboard-advanced:php-500-expected,
crm-dashboard-advanced:next-overview-200-defensive-empty,
crm-dashboard-advanced:next-pipeline-200-defensive-empty,
system-status:baseline, inbox:php-empty-currentbotid-clobbered,
inbox:next-baseline, inbox-thread:id=7001, inbox-conversations-cursor-walk,
inbox-messages-cursor-walk,
settings:welcome, settings:email-php-line-fallback, settings:email-next-real,
settings:consent, settings:shop-tax
```

- **52 of 52** `pages` entries `ok:true` — the **47** pre-existing entries
  (§0) unchanged/unregressed, PLUS all **5** new `settings:*` entries
  `ok:true`, INCLUDING `settings:shop-tax` (confirms §4.5's fix: real,
  repeated runs now show `defaultVatRate: php="7" next="7"` on both sides,
  not the earlier `php="7" next="7.00"` mismatch).
- `result: "PASS"`, `failedAt: null`.
- `docker ps -a` empty afterward (teardown ran cleanly in the `finally`
  block, same as every prior batch) — verified, 0 containers.

This is the real, current, passing result for this codebase's state — not a
theoretical projection. It satisfies this batch's acceptance criterion
verbatim: all pre-existing entries stay `ok:true` (no regression) and all 4
(in practice 5, per §3) new `settings:*` entries are `ok:true`.

## 8. Acceptance evidence — deliberate-break/restore rehearsal

Renamed `apps/admin/src/app/(tenant)/settings/page.tsx` →
`page.tsx.disabled-for-parity-break-test` (same technique every prior
batch's own deliberate-break rehearsal uses), ran from a clean teardown
(`docker ps -a` empty beforehand):

```json
{"result":"FAIL","failedAt":"page_parity","pages":[...52 entries, 4 ok:false...]}
```

Real mismatches observed, verbatim:

```
settings:welcome            -> ["extraction/fetch error: settings:welcome (next): expected 200, got 404 (location=n/a)"]
settings:email-next-real    -> ["assertion error: settings?tab=email (next): expected 200, got 404 (location=n/a)"]
settings:consent            -> ["extraction/fetch error: settings:consent (next): expected 200, got 404 (location=n/a)"]
settings:shop-tax           -> ["extraction/fetch error: settings:shop-tax (next): expected 200, got 404 (location=n/a)"]
```

- **Exactly 4** of the 5 `settings:*` entries newly fail via a 404-shaped
  fetch/assertion error — every entry that fetches the Next side.
  `settings:email-next-real`'s error string says `"assertion error"` rather
  than `"extraction/fetch error"` because it's a `runSingleSideCheck()` entry
  (its `assertAuthedOk`-equivalent status check happens inside
  `extractSettingsEmailTab`'s caller, not `runPagePair()`'s own
  `assertAuthedOk` call) — still the same underlying 404, just a different
  wrapping try/catch; not a bug.
- `settings:email-php-line-fallback` correctly STAYS `ok:true` — confirmed:
  it never touches the Next fetch at all (§3's own design rationale,
  empirically confirmed here, not just asserted).
- The other **47** pre-existing entries (batches 1-4 + inbox, §0) stay
  `ok:true`, unaffected — verified by diffing the full `pages` array against
  §7's list: zero unexpected failures outside the 4 `settings:*` entries
  above.
- `docker ps -a` empty afterward — no hang, no crash, no leftover
  containers, on the FAILING run — verified, 0 containers.

Restored `page.tsx`, re-ran clean from a fully torn-down state:

```json
{"result":"PASS","pages":[...52 entries, every one ok:true...],"failedAt":null}
```

— back to §7's exact all-`ok:true` state, `docker ps -a` empty afterward
(verified, 0 containers). Confirms the break/restore cycle is fully
reversible and leaves no residue, on both the failing run and the recovery
run.

## 9. `infra/nginx/routes.json` — 18 → 19, one new `/settings` entry, explicitly NOT flip-ready

Appended exactly one entry (`node infra/nginx/generate-routes.mjs
--validate-only` confirms 19 routes, schema-valid — same command every prior
batch's own §"routes.json" section runs):

```json
{
  "path": "/settings",
  "upstream": "php_backend",
  "tenants": "all",
  "note": "... (full text in infra/nginx/routes.json itself)"
}
```

Same "functional no-op today" shape as every prior batch's placeholder
entries (`php_backend` is already the strangler default) — exists only so a
future flip has a named line to edit.

**Explicitly marked NOT flip-ready**, mirroring `/inbox`'s own established
precedent language (`phase4-batch1-inbox-reads-parity.md`'s "THIS BATCH IS
READS ONLY" note): `/settings.php`'s live hub has **7** reachable tabs
(`line`/`platform`/`general`/`shop_tax`/`welcome`/`notifications`/
`consent`); this batch ports genuinely-diffed coverage for only **3** of
them (`shop_tax`/`welcome`/`consent`), plus the not-nav-visible `email`.
`line`/`platform`/`general`/`notifications` remain 100% PHP-only — Next
shows an explicit `NotYetMigratedTab` placeholder for those four, never a
silent fallback. **mig-orchestrator will not start a canary ramp for
`/settings` until enough later settings batches land to make the Next hub a
usable replacement for the PHP one** — same rule `/inbox`'s own entry
already established for an analogous partial-surface situation, and only
mig-orchestrator (never this batch's executor) decides when that ramp
starts.

`node infra/nginx/generate-routes.mjs` was run against the updated
`routes.json` and regenerated `infra/nginx/generated/strangler-edge.conf`
cleanly (`✓ ... validates against routes.schema.json (19 route(s))`, `✓
wrote ...strangler-edge.conf`) — no schema-validation error. Same
"Generated at" nondeterministic-timestamp caveat every prior batch's own
routes.json section already flags — not reintroduced or worsened here.

## 10. Gaps intentionally not fixed (per this batch's allowed-paths boundary)

- **NOT a gap as of this batch's final acceptance run**: §4.5's
  `default_vat_rate` "7" vs "7.00" mismatch was a real `apps/admin`-side bug,
  found by this harness. `apps/admin/src/app/(tenant)/settings/**` is
  outside this agent's allowed paths (owned by settingsHubAndCore/
  settingsConsentTax), so it was flagged in a build report rather than fixed
  unilaterally here — and settingsConsentTax fixed it before this batch's
  final run (§4.5's "After"). Listed here only so the history isn't lost;
  §7's real, current evidence shows `settings:shop-tax` fully `ok:true`.
- **§4.1's `welcome_settings` missing table** and **§4.4's
  `admin_users`-lacks-`line_account_id`-column** gaps — both pre-existing
  PHP/schema defects, `database/**` is outside this agent's allowed paths
  (same "do not add a migration to work around it" boundary every prior
  batch's own runbook already documents).
- **No audit was performed** of whether OTHER not-yet-ported `settings.php`
  tabs (`line`/`platform`/`general`/`notifications`) have their own
  `includes/header.php`-style variable-collision defects (the exact bug
  class `phase2-batch1-users-dashboard-parity.md` §13.1/§13.2 already found
  twice elsewhere) — out of this batch's scope; flagged as worth a
  dedicated look whenever those tabs' own porting batch starts.
- **Live production route-flip** for `/settings` — not performed, not
  authorized for this agent per its own "Do not" boundary; §9 documents the
  exact mechanic for whenever mig-orchestrator is ready.
