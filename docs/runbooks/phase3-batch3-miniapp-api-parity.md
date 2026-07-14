# Phase 3 batch 3 — miniapp JSON API parity harness (checkout: cart + pricing + order)

Source of truth: `docs/plans/2026-07-12-nextjs-full-migration-plan.md` Phase 3
(API/service port), risk register item #9 (checkout race conditions). Owner:
mig-infra (this harness) / cartAndPricing + orderCreation (the two
concurrently-building agents whose Next output this harness verifies) /
mig-verify (re-review gate) / mig-orchestrator (traffic-flip authorization —
**not exercised this round**, see §7). Cross-reference:
[`docs/runbooks/phase3-batch1-miniapp-api-parity.md`](./phase3-batch1-miniapp-api-parity.md)
and
[`docs/runbooks/phase3-batch2-miniapp-api-parity.md`](./phase3-batch2-miniapp-api-parity.md)
(the harness this document EXTENDS, not replaces — same JSON-line-output
convention, same "single seeded tenant, not a live-traffic shadow" limits
framing, same `infra/e2e/lib/api-extract.mjs`/`infra/e2e/api-parity.mjs`
split; read those two first, this one only covers what changed).

## 0. What changed, in one paragraph

`infra/e2e/api-parity.mjs` now covers **46** endpoint x action pairs, not 38:
the 35 batch-1/2 PHP-vs-Next diffable pairs + 3 batch-2 addresses next-only
pairs (all untouched) plus **8 new PHP-vs-Next diffable pairs** covering
`api/checkout.php`'s cart/pricing/order write surface —
`checkout-cart:{cart,add_to_cart,update_cart,remove_from_cart,clear_cart}`,
`checkout-pricing:validate_promo`, `checkout-order:{create_order,upload_slip}`
— split across two concurrently-building agents (cartAndPricing owns
`apps/admin/src/app/api/miniapp/checkout/{cart,pricing}/**`; orderCreation
owns `apps/admin/src/app/api/miniapp/checkout/order/**`). Same single
command:

```bash
node infra/e2e/api-parity.mjs
```

Same "reuses the ONE seeded tenant, `e2e-api-parity-harness`, no second
tenant" constraint, same "cannot run concurrently with `run.mjs`/`parity.mjs`"
constraint (`infra/e2e/docker-compose.yml` is **unmodified** this round — an
explicit round constraint, not an oversight), same always-tears-down-in-
`finally` / one-JSON-line-on-stdout / exit-0-only-on-PASS contract.

**routes.json is unchanged this round** — see §7 for the full statement of
what this batch does and does not authorize.

## 1. The multipart/form-data wrinkle — why new harness plumbing was required

`checkout-order:upload_slip` is the **first file-upload endpoint ported
anywhere in this migration effort** (batch 1/2 + the other 7 pairs in this
batch are all GET-query-string or JSON-body POSTs). `api/checkout.php`'s
`handleUploadSlip()` (L1733-1863) reads `$_POST['order_id']` and
`$_FILES['slip']` — a real multipart request, not JSON — and its Next port
(`apps/admin/src/app/api/miniapp/checkout/order/route.ts`) branches on the
incoming `Content-Type` header specifically to call `request.formData()` for
this one action. Every existing harness helper assumed a JSON body:
`infra/e2e/lib/api-extract.mjs`'s `ENDPOINT_CASES` shape only had
`query(variant)` (GET) or `body(variant)` (POST-JSON), and
`infra/e2e/api-parity.mjs`'s `callStack()` only knew how to
`JSON.stringify()` a body and set `Content-Type: application/json`. Neither
can express "POST a binary file part with a `multipart/form-data;
boundary=...` Content-Type."

This batch adds, deliberately as **new plumbing, not a copy of the existing
JSON-body helper**:

- `infra/e2e/lib/harness-common.mjs`: `buildMultipartBody(fields, file)` (a
  ~20-line hand-rolled RFC 7578 encoder — CRLF-separated parts, each preceded
  by `--boundary`, closed by `--boundary--`) and `httpRequestMultipart(...)`
  (wraps it + `httpRequest()`, sets `Content-Type`/`Content-Length`
  explicitly to avoid chunked transfer-encoding). No new npm dependency
  (`form-data`/`undici`'s `FormData`) was pulled into `infra/e2e/`, which has
  its own `package.json` outside `pnpm-workspace.yaml`'s `packages:` globs
  (same reason `infra/e2e/lib/api-extract.mjs` already imports
  `@reya/contracts` via a relative `dist/` path instead of the bare
  specifier — see that file's own module doc) — the wire format is simple
  enough that a small auditable encoder beat adding a dependency for one call
  site.
- `infra/e2e/lib/harness-common.mjs`: `TINY_PNG_FIXTURE` — the smallest valid
  PNG that exists (a 1x1 transparent pixel, 68 bytes, the well-known
  public-domain "tiny PNG" test image, embedded as a base64 literal). Neither
  PHP nor the Next port validates image *content* (only MIME type and byte
  size — see `handleUploadSlip()`'s `$allowedTypes`/`5 * 1024 * 1024` checks
  and `uploadSlip.ts`'s identical `ALLOWED_MIME_TYPES`/`MAX_SLIP_BYTES`), so a
  minimal fixture exercises the real code path with nothing extra committed
  to the repo.
- `infra/e2e/api-parity.mjs`: `callStack()` gained a `caseDef.multipart`
  branch — when set, it builds the request via `caseDef.fields(variant)` (a
  plain form-fields object, the multipart analogue of every other case's
  `body(variant)`) plus a fixed file part (`caseDef.file` supplies
  `{name, filename, contentType}`; the actual bytes always come from
  `TINY_PNG_FIXTURE`, since this harness has exactly one multipart case, so
  there is nothing to gain from per-case-configurable bytes).
- `infra/e2e/lib/api-extract.mjs`: the `checkout-order:upload_slip` case sets
  `multipart: true` and `fields`/`file` instead of `body`.

Everything downstream of the request (response-body diffing, `dbChecks`,
`FORMAT_CHECKS`) is **unchanged, reused as-is** — the multipart wrinkle is
entirely in how the *request* gets built, not in how the *response* gets
verified.

### 1.1 A second, related "first" this batch surfaced: the shared `uploads/slips/` directory must be WRITABLE by the PHP container

`upload_slip` is also the first case in this whole harness (batch 1/2/3)
whose PHP side needs to **write a file to the host filesystem**, not just to
MariaDB. `infra/e2e/docker-compose.yml`'s `php` service bind-mounts the
whole repo root (`../..:/var/www/html`) — the SAME mechanism every other
batch already relies on for serving PHP source, but batch 1/2 never
exercised a code path that writes back through that mount. `handleUploadSlip()`
writes to `<repo-root>/uploads/slips/` (via `move_uploaded_file()`), and the
Next port writes to the SAME shared directory (`uploadSlip.ts`'s own
`resolveSlipsUploadDir()` — deliberate, so both stacks keep serving/reading
the same on-disk slips during strangler coexistence). On this checkout's
host, `uploads/`/`uploads/slips/` are checked out `root:root`, mode `0755`
— the container's apache2 worker processes run as `www-data` (no `USER`
override in `infra/php/Dockerfile`), which is neither the owning user nor
in the owning group, so it gets read+execute only, no write.
`move_uploaded_file()` then fails with PHP's own `'Failed to save file'`
message — confirmed empirically: the FIRST real run of this batch's harness
failed `checkout-order:upload_slip` with exactly that message on the PHP
side before this was diagnosed and fixed.

**Fix applied (filesystem permission only, not a tracked-file change —
verified with `git status --porcelain uploads/` before/after, empty both
times):**

```bash
chmod 0777 uploads uploads/slips
```

This is **not** a `packages/contracts`/`infra/e2e` code change — it is an
environment precondition for running this harness at all, same category as
"`config/config.php` must exist locally" (`harness-common.mjs`'s own
`parseLocalConfigPhp()` already documents that one). Recorded here,
explicitly, so a future CI environment that checks out this repo with
different host-side ownership (a very plausible difference between this
sandbox and a real CI runner) knows exactly what to check first if
`checkout-order:upload_slip` fails with `'Failed to save file'` on the PHP
side: `ls -ld uploads/slips` and confirm the PHP container's runtime user
(`www-data`, absent a `USER` override in `infra/php/Dockerfile`) can write
there. A permanent fix (e.g. an explicit `chown`/`chmod` step in
`infra/php/Dockerfile`, or a docker-compose `user:` override matching the
host's checkout owner) is out of this round's allowed paths (`infra/php/Dockerfile`
is not in this round's file list) — flagged here as a candidate for whichever
future round owns that file, not silently worked around without a trace.

## 2. The `promotions`-table-absent finding (must NOT be simplified away)

`checkout-pricing:validate_promo` exercises the SAME kind of runtime-probe
preservation §3 of batch 1's fixture and cartAndPricing's own contract file
already establish elsewhere: `handleValidatePromo()`
(`api/checkout.php` L2202-2312) runs `SHOW TABLES LIKE 'promotions'` on
**every call** and branches on the result — if the table exists, it queries
real `promotions`/`promotion_usage` rows (`findPromotion()`,
date/usage/per-user-limit checks, `validateFromPromotionsTable()`); if not,
it falls through to `validateHardcodedPromo()`'s 4 fixed codes
(`WELCOME10`/`SAVE50`/`FREESHIP`/`NEWUSER`).

**Confirmed absent** from `database/migration_2026-05-25_tenant_template.sql`
— `grep -n "promotions\`" database/migration_2026-05-25_tenant_template.sql`
finds only unrelated boolean *columns* named `promotions` on notification
preference tables, never a `CREATE TABLE`. This is exactly the situation
`apps/admin/src/app/api/miniapp/checkout/pricing/_lib/handlers.ts`'s own
module doc calls out: unlike `cartProductSource.ts`'s
`ensureCartProductSourceSupport()`/`tableExists('shop_products')`
simplifications (which really are dead code on the committed schema, since
`shop_products` unconditionally exists per `packages/db`'s generated
`tenant-db.d.ts`), the `promotions` table genuinely does not exist — the
`SHOW TABLES LIKE 'promotions'` probe (`promotionsTableExists()` in the Next
port) is a **real, live branch on every tenant that hasn't manually added
this table**, not dead weight to drop. Both `handlers.ts` and this harness's
own `checkout-pricing:validate_promo` case therefore preserve the probe as a
genuine runtime check — a future edit that "simplifies" it to a hardcoded
`false` would silently break any tenant that ever does add a real
`promotions` table (the Next port already implements that branch in full,
`validateFromPromotionsTable()`, exercised by `handlers.test.ts`, just not by
this harness's fixture, which only covers what's reachable on the committed
template — same "harness proves the reachable branch, unit tests prove the
rest" split every prior batch already uses).

`checkout-pricing:validate_promo`'s own case (see
`infra/e2e/lib/api-extract.mjs`) sends `code: 'welcome10'` (lowercase, on
purpose — exercises `strtoupper(trim())`/`.trim().toUpperCase()`
normalization identically on both stacks) with `subtotal: 200`, expecting
`discount: 20` and — **a real, faithfully-preserved PHP quirk, verified by
reading `handleValidatePromo()` directly (L2221-2226)** —
`discount_type: 'fixed'`, HARDCODED in this response branch regardless of
WELCOME10's own internal type (`percentage`). Asserted byte-equal, not
allowlisted, precisely because both stacks must reproduce this quirk
identically, not "fix" it.

## 3. The race-guard case (`checkout-order:create_order`) — plan risk register #9, the highest-risk single assertion this round

### 3.1 The quirk itself

`handleCreateOrder()` (`api/checkout.php` L1288-1656) decrements stock with:

```php
$stmt = $db->prepare('UPDATE business_items SET stock = stock - ? WHERE id = ? AND stock >= ?');
$stmt->execute([(int) $item['quantity'], (int) $item['product_id'], (int) $item['quantity']]);
```

The `AND stock >= ?` guard means the UPDATE silently affects **zero rows**
when the requested quantity exceeds available stock — but PHP **never checks
`$stmt->rowCount()`** afterward. Execution falls straight through to
`transaction_items` insertion, `cart_items` deletion, `$db->commit()`, and a
`{success:true, message:'Order created', ...}` response. The practical
effect: a customer can successfully "order" more units than are in stock,
the order and its line items are real and committed, and `business_items`'s
own stock counter is simply **left unchanged** (not decremented, not
clamped, not erroring) — a genuine, pre-existing PHP production bug, not
something this migration introduces.

The Next port (`apps/admin/src/app/api/miniapp/checkout/order/_lib/createOrder.ts`)
preserves this **on purpose, explicitly flagged NON-NEGOTIABLE** in its own
module doc:

> "NON-NEGOTIABLE, byte-for-byte (this batch's acceptance criteria): the
> guarded UPDATE stays exactly `UPDATE business_items SET stock = stock - ?
> WHERE id = ? AND stock >= ?` and PHP NEVER checks either guarded UPDATE's
> ... affected-row-count afterward — the order is created/committed even
> when a guard silently no-ops on insufficient stock. Do NOT add a
> rowCount/affected-rows check here as an 'improvement'."

Same treatment applies to the parallel `shop_products.saleable_qty` guard a
few lines later in both files.

### 3.2 How the fixture proves it, not just documents it

`65-phase3-batch3-miniapp-fixture.sql.tmpl` seeds **two dedicated low-stock
products** — `business_items` id `1601` (php-target) and `1602`
(next-target), both `stock=1`, no `sale_price` — TWO rows, not one shared
row, for the exact same reason `55-phase3-batch2-miniapp-fixture.sql.tmpl`'s
pharmacists `911`/`912` exist for `appointments:book`'s own concurrent-race
avoidance: this case's php/next calls run **concurrently**
(`Promise.all` in `runApiCase()`), and this harness's `dbCheck` mechanism
diffs "the php-run's query result" against "the next-run's query result" —
querying **one** shared row from both sides would make that diff a
tautology (comparing a row to itself, incapable of distinguishing a real
per-stack regression from "both sides happen to read the same value"). Two
independent rows means the `stock` dbCheck genuinely proves the Next port's
guard-preserving behavior matches PHP's own, already-verified
guard-preserving behavior — not merely that two queries against the same row
agree with each other.

Each dedicated identity (`e2e-mp3-order-create-php`/`-next`, users `5041`/
`5042`) gets ONE pre-existing `cart_items` row at `quantity=5` against its
own low-stock product (stock=1). The case's request body carries **no**
`cart_items` field — deliberately matching the REAL client shape
(`line-mini-app/src/lib/shop-api.ts`'s `createShopOrder()` never sends
`cart_items`, only `{action,line_user_id,line_account_id,address,
payment_method,subtotal?}`), which forces both `handleCreateOrder()` and its
Next port through `loadCheckoutCartLinesFromDb()` — the DB-cart branch real
traffic actually takes, not the request-body-cart_items branch (which is
also ported, on both stacks, but is not what this case exercises).

Three dbChecks run after the (concurrent) calls:

1. `transactions` row exists per identity, with `total_amount=495.00`
   (99 x 5), `shipping_fee=50.00` (subtotal 495 < `free_shipping_min` 500),
   `grand_total=545.00`, `status='pending'`, `payment_status='pending'` —
   proves **the order was created despite insufficient stock**.
2. `transaction_items` row exists with `quantity=5`, `product_price=99.00`,
   `subtotal=495.00` — proves **the full requested quantity was recorded**,
   not silently clamped to available stock.
3. `business_items.stock` is still `1` on both dedicated rows — proves **no
   decrement happened**, on either stack, despite the order succeeding.

All three together are what "no rowCount short-circuit on either side" (this
round's acceptance criteria) actually means in practice: the guard exists at
the SQL level, is correctly written on both stacks, and is correctly
*ignored* by both stacks' calling code afterward — a faithful port of a real
bug, not a fixed one.

`payment_method: 'transfer'` is deliberate: it is NOT one of
`['credit','cod','term','invoice']`, so
`in_array(strtolower($paymentMethod), $creditPaymentMethods)` is false and
`AccountReceivableService` is never even instantiated for this case — see
§4 for why that matters.

### 3.3 A SECOND, empirically-discovered DDL-inside-transaction bug (found by actually running this harness)

Building the case above surfaced a genuine, previously-undocumented,
pre-existing PHP bug — found not by static reading alone, but by running
`node infra/e2e/api-parity.mjs` for real against the fixture in §3.2 and
reading the failing entry's `mismatches`:

```
"success: php=false next=true"
"message: php=\"There is no active transaction\" next=\"Order created\""
```

...paired with the `dbCheck`s (unaffected by the response mismatch) showing
the `transactions`/`transaction_items` rows **did** land correctly on the
PHP side despite the reported failure. This is the SAME class of bug batch
2's runbook already documented for `api/consent.php`'s
`ActivityLogger::getInstance()` (§2 there, "DEVIATION #1" in
`packages/contracts/src/consent.ts`) — a `CREATE`/`ALTER` DDL statement
executed **inside** an open `$db->beginTransaction()` block causes MySQL/
InnoDB's implicit-commit rule to fire, silently ending the transaction
early; the subsequent `$db->commit()` then throws
`PDOException("There is no active transaction")`, which
`handleCreateOrder()`'s own `catch (Exception $e) { ...; throw $e; }`
re-surfaces verbatim to the client as a false failure — but every statement
executed before that point already autocommitted individually and
persisted.

**This is a DIFFERENT statement than consent.php's bug**, independently
discovered here: `handleCreateOrder()` (L1439-1444) runs, unconditionally,
on every single call, INSIDE its own transaction:

```php
// Auto-add payment_status column if missing (defensive migration)
try {
    $db->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT 'pending'");
} catch (Exception $e) {
    // Ignore: column already exists or DB doesn't support IF NOT EXISTS
}
```

`payment_status` **already exists** on every real tenant DB (confirmed
unconditionally present in `database/migration_2026-05-25_tenant_template.sql`
— this is precisely why `createOrder.ts`'s own module doc drops this ALTER
TABLE entirely as a "SIMPLIFICATION," independently of this bug being
found: `packages/db/src/generated/tenant-db.d.ts` confirms
`Transactions.payment_status` is unconditionally present, so the defensive
migration is unreachable dead weight on the committed schema). But
MySQL/InnoDB's implicit-commit rule fires on the **DDL statement itself**,
regardless of whether it ends up being a no-op — `ADD COLUMN IF NOT EXISTS`
on an already-existing column is still parsed and executed as DDL. This
means **every real production `create_order` call likely hits this exact
bug today** — the order is genuinely created, but the customer-facing
response falsely reports failure.

**The Next port does not reproduce this** — not as a targeted fix for this
specific bug (it predates this discovery), but as a direct consequence of
`createOrder.ts`'s own, already-documented "SIMPLIFICATION" decision to drop
the unreachable `ALTER TABLE` entirely. This makes the Next port's
`{success:true}` response for this case a **real, deliberate correctness
improvement over the PHP original** — not a port defect — the same category
of legitimate PHP-vs-Next deviation batch 2's runbook §2 already established
a precedent for with `consent:save`/`data-rights:*`'s tenant-resolution
improvement.

**Consequently**, `checkout-order:create_order`'s case sets
`skipResponseBodyDiff: true`, same mechanism and same reasoning as
`consent:save` (batch 2's runbook §2): `success`/`message`/`order_id`/
`order_number`/`total`/`payment_method`/`ar_id` are all
LEGITIMATELY, DETERMINISTICALLY different between the two stacks for every
single call (PHP never reaches its own `jsonResponse(true, ...)` line), not
"sometimes" — an `allow` entry cannot express "this key is never present on
one side, always present on the other." `http_status` (both 200) and the
three dbChecks (§3.2, which STILL run, unconditionally) remain the real
assertions for this case: they prove the underlying WRITE is equivalent on
both stacks despite PHP's misleading response — exactly the same "the
dbChecks are doing the real work" framing consent:save's own case comment
already established.

This finding was NOT anticipated by either builder's brief (it surfaced only
once the two lanes were stitched together and actually run end-to-end
against a real MariaDB) — recorded here specifically so a future reader
investigating "why does create_order intermittently fail in production"
doesn't have to rediscover it from scratch.

## 4. Dead fields and the deferred-scope decisions (so a future reader doesn't rediscover any of it)

### 4.1 Dead/unread fields, verified by both builders independently

| Field | Where sent | Where read by PHP | Status |
|---|---|---|---|
| `unit_id` | `add_to_cart`/`update_cart`/`remove_from_cart` request bodies (`line-mini-app/src/lib/shop-api.ts`) | Nowhere — zero occurrences in `api/checkout.php`'s cart handlers (grep-verified by cartAndPricing) | Multi-unit cart selection is silently dropped server-side today, in production, right now. `cart_items.unit_id` still exists as a column and is still echoed back raw by `handleGetCart()`/`GET action=cart` (the column is written by nothing, read by nobody, but not removed from the row shape) — reproduced faithfully by the Next port and this harness's own `CartItemSchema` (`packages/contracts/src/checkout-cart.ts`). |
| `payment_info` (action) | `line-mini-app/src/lib/shop-api.ts`'s `fetchPaymentInfo()` | Not a valid `api/checkout.php` action at all — falls through to the generic `{success:false, message:'Invalid action'}` response | Confirmed dead client code; not ported, not tested by this harness (there is nothing to call). |
| `qr_data` | `uploadPaymentSlip()`'s multipart body (client-side QR pre-decode, best-effort) | Nowhere — zero occurrences anywhere in `api/checkout.php` (grep-verified by orderCreation); no slip auto-verification exists to port | Deliberately **omitted** from this harness's `checkout-order:upload_slip` case's form fields — sending it would add a field neither stack reads, adding noise without proving anything. |
| `user_id` | `uploadPaymentSlip()`'s multipart body | Read only for a debug `error_log()` line in PHP, never for any logic | Accepted-and-ignored by the Next port too (`uploadSlip.ts`'s own module doc); omitted from this harness's case for the same reason as `qr_data`. |

### 4.2 The AccountReceivableService / ActivityLogger / NotificationService deferred-scope decision

`handleCreateOrder()` (PHP), after `$db->commit()`, calls three more
integrations before returning: `AccountReceivableService::createFromTransaction()`
(L1594-1608, AR ledger row for `credit`/`cod`/`term`/`invoice` payment
methods), `NotificationService::notifyNewOrder()` (L1613-1625, LINE/email
fanout beyond the Telegram push), and `ActivityLogger::logOrder()`
(L1627-1640, audit trail). `handleUploadSlip()` similarly calls
`NotificationService::notifyPayment()`.

**orderCreation's own brief scoped all three OUT of this round** — this is
an explicit orchestrator scoping decision, documented directly in
`createOrder.ts`'s module doc:

> "DEFERRED, NOT silently dropped (orchestrator scoping decision, this
> batch's brief): AR ledger creation ..., NotificationService::notifyNewOrder()
> ..., and ActivityLogger::logOrder() ... are OUT OF SCOPE this round — see
> the TODO comment at this function's end. `ar_id` is always `null` in this
> port's response."

What **is** in scope and IS ported on both stacks: `notifyTelegramNewOrder()`
(create_order) and `notifyTelegramPayment()`/`sendReceiptMessage()`
(upload_slip) — the Telegram push and the LINE Flex receipt. Both are
best-effort, independently try/catch-swallowed on both stacks, matching
PHP's own `catch (Exception $e) { error_log(...); return false; }` shape
exactly — a notify failure must never fail the already-committed order/slip
response.

**Why this harness's own fixture design makes the AR/NotificationService/
ActivityLogger question moot regardless of which way that scoping decision
had gone:** `payment_method: 'transfer'` in the `create_order` case (§3.2)
means the AR branch's own `in_array(...)` guard is false on the PHP side
too — this case would never have exercised AR parity even if the Next port
had implemented it. And separately (see §5), this batch's fixture disables
both Telegram and LINE-push credentials, so `notifyTelegramNewOrder()`,
`notifyTelegramPayment()`, and `sendReceiptMessage()` all take their own
early-return no-op path with zero network I/O on both stacks, regardless of
whether a given integration is ported at all — a passing harness run here
never depended on guessing right about scope.

One contrast worth recording precisely, since it is easy to conflate with
§3.3's finding: `checkout.php` calls `ActivityLogger::getInstance($db)`
**once, at file load time (L26), before any transaction is ever opened**,
and `handleCreateOrder()`'s own `$activityLogger->logOrder(...)` call
(L1629) reuses that already-constructed instance **after** `$db->commit()`
— so the `ActivityLogger` constructor's own `CREATE TABLE IF NOT EXISTS
activity_logs` (the exact mechanism behind `consent.php`'s bug, batch 2's
runbook §2) is genuinely NOT the cause of anything here; by the time
`handleCreateOrder()`'s transaction opens, that DDL already ran and
committed long before, harmlessly. **`checkout-order:create_order` still
needs `skipResponseBodyDiff: true`** — but for a DIFFERENT, independently
discovered DDL statement (the `payment_status` `ALTER TABLE`, §3.3), not
this one. Two distinct bugs, same underlying MySQL/InnoDB mechanism,
found in two different PHP files by two different batches of this harness —
worth being precise about which statement causes which, since "ActivityLogger
strikes again" would be an easy, wrong shorthand for a future reader to
reach for.

## 5. Notification-guard determinism — why telegram_settings + channel_access_token are deliberately emptied

`create_order`/`upload_slip` are the first two actions in this whole harness
that reach code paths capable of a REAL outbound HTTP call
(`api.telegram.org`, `api.line.me`) if left unguarded. Verified directly in
both the PHP originals and their Next ports (`notify.ts`'s own module doc
quotes this precisely): `sendReceiptMessage()`/`sendOrderConfirmation()`
early-return `false` when the owning `line_accounts` row's
`channel_access_token` is empty; `notifyTelegramNewOrder()`/
`notifyTelegramPayment()` early-return `false` when `telegram_settings`
(`WHERE id=1`) is missing, `is_enabled=0`, or missing `bot_token`/`chat_id`.

Batch 1's own fixture seeded `line_accounts` id 1/2 with **non-empty**
dummy tokens (`e2e-dummy-token-1`/`-2`) — harmless for every batch-1/2 case
(none of them ever reach a LINE-push call site), but this batch's
create_order/upload_slip DO reach the guard, and a non-empty (if fake) token
would make PHP attempt a real `curl_exec()` against `api.line.me` with a
bogus bearer token: slow, network-dependent, and a bad look in a CI fixture
even though the token itself carries no real access. `65-phase3-batch3-miniapp-fixture.sql.tmpl`
therefore:

- `UPDATE`s both `line_accounts` rows' `channel_access_token` to `''`.
- Seeds an explicit `telegram_settings` row (`id=1`, hardcoded by both
  `notifyTelegramNewOrder()`/`notifyTelegramPayment()` — `is_enabled=0`,
  `bot_token=''`, `chat_id=''`.

**Do not wire real credentials into a CI fixture** (this round's own
constraint) — emptying the token/disabling the settings row is the correct
way to get a *deterministic* no-op, not a workaround for a missing feature.
This also makes the harness robust to **either** scoping outcome for
`NotificationService` (§4.2): whether or not a given integration is ported,
its guard (present on both stacks, verified by reading both) makes it a
no-op here regardless.

## 6. `infra/nginx/routes.json` — re-verified, still a no-op for this batch

Same finding as batch 2's own runbook §4, re-verified for this batch
specifically (not assumed): `infra/nginx/generate-routes.mjs`'s
`nginxLocationPattern(path)` returns the route's `path` unmodified, and
nginx resolves a request URI against the **longest matching prefix** among
every `location` block. The single existing `/api/miniapp` entry
(`upstream: next_admin`, `tenants: "all"`) already covers
`/api/miniapp/checkout/cart`, `/api/miniapp/checkout/pricing`, and
`/api/miniapp/checkout/order` — there is no more specific `/api/miniapp/checkout*`
entry in `routes.json` (confirmed: `grep -n '"path"' infra/nginx/routes.json`
returns only `/miniapp` and `/api/miniapp`, 17 routes total, same count as
batch 2 left it), so zero new `routes.json` entries were needed for this
batch's 8 new sub-paths, exactly like appointments/consent/data-rights/
medication-reminders/addresses needed none in batch 2.

```bash
node infra/nginx/generate-routes.mjs --validate-only   # PASS, 17 routes, unchanged
node infra/nginx/generate-routes.mjs                    # regenerate (no --validate-only)
git diff infra/nginx/generated/strangler-edge.conf      # ONLY the "Generated at" timestamp line differs
```

Both `infra/nginx/routes.json` and `infra/nginx/generated/strangler-edge.conf`
are **untouched** by this batch — verified, and the regenerated conf's own
diff (checked, then reverted so the working tree carries no incidental
timestamp-only change) confirms it.

## 7. Traffic-flip status — read this before treating a green harness run as a go-ahead

**This round is code + tests merge-ready. It is NOT a traffic flip, and does
not authorize one.**

- `infra/nginx/routes.json` has **zero diff** from its state before this
  round (§6) — the strangler edge still defaults every tenant's
  `/api/miniapp/**` traffic exactly as batch 1 first wired it (`next_admin`,
  unconditional — see batch 1's own runbook §7 for why `/api/miniapp` was
  never a "default php, flip later" placeholder to begin with; it has no PHP
  equivalent path to default to).
- **No canary ramp was performed this round.** This batch does not touch
  `line-mini-app/**` at all (out of allowed paths, per the round brief) —
  the client-side `NEXT_PUBLIC_MINIAPP_ENDPOINT_OVERRIDES` override map
  (`line-mini-app/src/lib/php-bridge.ts`) that actually governs which
  checkout endpoint a real mini-app build calls is untouched, meaning real
  production traffic for `add_to_cart`/`create_order`/`upload_slip`/etc.
  still goes to the legacy PHP endpoints exactly as it did before this
  round, regardless of this harness's own result.
- **No rollback drill is owed for this round.** Batch 1's own §9
  (`infra/e2e/rollback-drill.mjs`) exists specifically to prove the
  override-map flip-and-revert mechanic works — that drill is only required
  when a round actually flips a canary tier. This round flips nothing, so
  none is run or expected here; a **future** round that does flip a canary
  tier for any of these 8 checkout actions owes its own rollback-drill
  evidence at that time, separately co-signed by mig-orchestrator.
- Moving any of these 8 actions from PHP to Next in production is an
  **explicit, separate, later mig-orchestrator-authorized round**, with its
  own risk sign-off (checkout is explicitly called out as "the highest-risk,
  last-to-flip piece" in batch 1's own runbook §Scope-note) and its own
  rollback-drill evidence gathered at that time — not implied, assumed, or
  partially started by this round's green harness result.

## 8. Acceptance criteria (mig-verify executes these)

- [x] `node infra/e2e/api-parity.mjs` exits `0` and prints
      `{"result":"PASS",...}` with all 35 batch-1/2 PHP-vs-Next entries, all
      3 batch-2 addresses next-only entries, AND all 8 new batch-3 entries
      reporting `ok:true` — **46 total covered pairs**. **Verified**: run
      live against this batch's own worktree (genuine `docker compose`
      MariaDB+Redis+`php:8.2-apache` stack, genuine `next build` standalone
      server) — first run surfaced 2 real findings (§3.3's DDL-in-transaction
      bug and §1.1's uploads-directory permission precondition), both fixed
      (case config + host chmod respectively, no application code touched),
      second run: `"result":"PASS"`, all 46 entries `ok:true`, clean teardown
      (`docker ps -a` empty afterward).
- [x] `checkout-order:upload_slip` specifically: the Next response's
      `image_url` matches `FORMAT_CHECKS.image_url`'s host-derived-URL
      pattern (same regex/allowlist treatment as `member_id`/
      `redemption_code`/`appointment_id`/`confirmation_code`/`order_number`
      — never a literal byte-diff, since the filename embeds a
      request-time Unix timestamp and the two stacks run on different
      origins in this harness), AND its `payment_slips` `dbCheck` confirms a
      row landed with `status='pending'` after the call. **Verified** in the
      passing run above.
- [x] `checkout-order:create_order`'s race-guard dbChecks (§3.2) all pass:
      `transactions`/`transaction_items` rows created on BOTH stacks despite
      `quantity=5 > stock=1`, AND `business_items.stock` unchanged (`=1`) on
      BOTH dedicated rows — no rowCount short-circuit on either side.
      **Verified** in the passing run above (via `skipResponseBodyDiff` +
      the dbChecks, per §3.3's finding).
- [x] A deliberately-broken new route (temporarily rename one new action
      string in any ONE of the 3 new `route.ts` files —
      `checkout/cart/route.ts`, `checkout/pricing/route.ts`, or
      `checkout/order/route.ts`) still produces a clean teardown and an
      isolated `{ok:false, mismatches:[...]}` entry for that ONE endpoint
      only — every other of the 45 remaining entries still reports its real
      result (same isolation guarantee batch 1/2 established;
      `runApiCase()`/`runNextOnlyCase()` both never throw past their own
      try/catch, unmodified this round). **Verified**: `case 'clear_cart':`
      in `checkout/cart/route.ts` was temporarily renamed to
      `'clear_cart_BROKEN_FOR_ISOLATION_TEST'`, the harness re-run, and the
      result was `{"result":"FAIL", ...}` with **exactly one** failing
      entry (`checkout-cart:clear_cart` — `next` returned `Invalid action`
      while `php` still succeeded, and its `dbCheck` correctly caught the
      resulting `cart_items` row-count divergence) and all other **45**
      entries still `ok:true`; teardown was clean
      (`docker ps -a` empty afterward). The route string was reverted
      immediately after (confirmed via `grep -c BROKEN` returning `0`
      afterward) and a subsequent clean run returned to
      `{"result":"PASS",...}`, 46/46.
- [x] `node infra/nginx/generate-routes.mjs --validate-only` passes and the
      regenerated `strangler-edge.conf` shows no diff beyond the "Generated
      at" timestamp line — confirms zero routes.json changes were actually
      needed (§6). **Verified**: 17 routes validated; regenerated conf
      diffed (`Generated at` line only) then reverted so the working tree
      carries no incidental change.
- [x] This runbook exists and, read alone, explains why `upload_slip` needed
      new harness plumbing (§1), why the promotions-table probe must NOT be
      simplified away (§2), and explicitly states this round's traffic-flip
      status (§7): code+tests merge-ready, `routes.json` unchanged,
      production flip pending a future mig-orchestrator co-sign.

## 9. mig-orchestrator co-sign checklist (Phase-3/checkout HIGH-RISK gate per `docs/agents/nextjs-migration-team.md`)

- [ ] mig-verify's parity report shows `"result":"PASS"` on all 46 pairs
      (§8, first bullet) — a fresh run, not a cached/assumed prior result.
- [ ] The race-guard dbChecks (§3.2) and the multipart/`payment_slips`
      dbCheck (§8, second bullet) are confirmed to have **actually run** —
      i.e. present in the printed `endpoints` JSON with real `dbChecks`
      evaluation, not merely present in this file's source as unexecuted
      config.
- [ ] `infra/nginx/routes.json` has **zero diff** from its state before this
      round (§6/§7) — `git diff infra/nginx/routes.json` is empty.
- [ ] No rollback drill is owed yet for this round specifically — confirmed
      by §7: no traffic was flipped, no canary ramp was performed, no
      `line-mini-app/**` change shipped. (A rollback drill IS owed by
      whichever future round actually performs a canary flip for any of
      these 8 checkout actions — that is a forward-looking obligation on a
      later round, not a gap in this one.)
