# Loyalty & CRM — Phase 0 audit and source-of-truth matrix

**Date:** 2026-08-26
**Scope:** Phase 0 of `docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md`
**Status:** complete — this is the deliverable Phase 0 asks for
**Rule that governed it:** *"No production behavior changed yet."* Nothing in this
document changed anything; the Batch 1 changes it justifies are listed in §6.

---

## 0. What this is, and how it was produced

The plan's Phase 0 asks for one artefact before the loyalty core is touched:

> A source-of-truth matrix — every operation, its current writer, the tables it
> writes, and its target writer.

This document is that matrix, plus the evidence behind it. It was produced by
sweeping the PHP monolith along seven axes — point writers, point readers, reward
redemption implementations, tier entrypoints, the on-disk schema, identity and
tenant scoping on the public APIs, and the test harness — and then re-verifying
the highest-severity claims against the files a second time. Line numbers are as
of commit `95cead2`.

**Headline.** The plan says loyalty has "overlapping sources of truth". That is
an understatement. A single member's point balance can be read from **seven**
distinct stores and written by **26** distinct code paths in three mutually
incompatible styles. Only three of those 26 writers are idempotent, and before
Batch 1 not one of them kept the ledger and its cache in a single transaction.

---

## 1. The stores

| Store | Written by | Read by | Status |
|---|---|---|---|
| `points_transactions` | `LoyaltyPoints`, Odoo webhook, shop order approval | `getUserPoints()`, history screens, summary tiles | **canonical as of Batch 1** |
| `users.available_points` | `LoyaltyPoints`, Odoo webhook | ~12 screens directly | derived cache (Batch 1) |
| `users.total_points` | `LoyaltyPoints`, Odoo webhook | `TierService`, the inbox "use points" modal | derived cache (Batch 1) |
| `users.used_points` | `LoyaltyPoints` | history screens, PDPA export | derived cache (Batch 1) |
| `users.points` | `api/member.php`, `api/points.php`, `shop/order-detail.php`, Odoo webhook | `api/member.php?action=check`, `api/points.php`, `LiffMessageHandler` | **legacy — retire in Batch 2** |
| `points_history` | `api/member.php` ×3, `api/points.php`, `shop/order-detail.php`, `migration_auto_member.php` | `api/points.php`, `api/ai-admin.php` | **legacy — retire in Batch 2** |
| `loyalty_points` | **nothing, ever** | `api/user-profile.php`, `users.php` filters, `AutoTagManager`, `AdvancedCRM` segments, `DynamicRichMenu` | **dead — always reads 0/bronze** |

Two further tables, `user_points` and `loyalty_points_history`, are provisioned
into every tenant schema and referenced by no PHP at all.

> **`loyalty_points` is a silent product failure, not just dead code.** Five live
> features read it: the admin users-list points filter buckets every member into
> 0–100, `min_points` auto-tag rules never fire, `max_points` rules always fire,
> and every tier-based CRM segment — i.e. every tier-targeted broadcast — is
> empty. Nobody is getting an error; they are getting wrong answers.

---

## 2. The source-of-truth matrix (Phase 0 deliverable)

Every code path that writes points today. "Target writer" for all of them is
`LoyaltyLedgerService`, reached via the `LoyaltyPoints` facade until Batch 2
migrates each call site to the ledger's own API.

| # | Operation (trigger) | Writer | Stores written | Atomic | Idempotent | OA-scoped |
|---|---|---|---|---|---|---|
| 1 | MODERN core — credit points (all earn flows funnel here) | `classes/LoyaltyPoints.php:171 addPoints()` | UPDATE users.total_points, users.available_points; UPDATE users.member_tier; INSERT points_transactions(user_id, line_account_id, type='earn', points, balance_after, reference_type, reference_id, description, expires_at). Never writes users.points or users.used_points. | no — users UPDATE and ledger INSERT are two unwrapped statements; enlists in a caller's transaction only if the caller opened one | no — no dedupe key of any kind; every call appends a new ledger row | partial — points_transactions.line_account_id = $this->lineAccountId, which is null whenever the service is constructed without one; the users UPDATE is keyed on id only |
| 2 | MODERN core — debit points (all redeem/reversal flows funnel here) | `classes/LoyaltyPoints.php:202 deductPoints()` | UPDATE users.available_points (-), users.used_points (+); UPDATE users.member_tier; INSERT points_transactions(type='redeem', points=-N, balance_after, ...). Never writes users.points or users.total_points. | no — same two-statement pattern as addPoints | no — sufficiency guard reads the ledger while the UPDATE decrements the users cache, so users.available_points can go negative | partial — same as addPoints |
| 3 | Customer scans a purchase receipt in LINE chat; Gemini Vision OCR reads a high-confidence total | `webhook.php:5404 handleReceiptPointsClaim() → LoyaltyPoints::addPoints` | receipt_point_claims (INSERT, UNIQUE uk_claim), then addPoints → users.total_points/available_points/member_tier + points_transactions(reference_type='receipt') | no — the claim INSERT commits before the credit; no shared transaction | yes — real UNIQUE KEY uk_claim(line_account_id, claim_key) on receipt number or amount+date hash; but a failed credit burns the key permanently | yes — line_account_id on both the claim and the ledger row |
| 4 | Customer scans the pharmacist's one-time QR in the Mini App | `api/points-claim.php:1178 handleClaim() → LoyaltyPoints::addPoints` | UPDATE points_claims SET status='claimed' (guarded); addPoints → users.total_points/available_points/member_tier + points_transactions(reference_type='claim'); UPDATE points_claims.points_transaction_id | yes — beginTransaction at :1157, commit at :1211, rollBack on every failure branch | yes — guarded UPDATE ... WHERE status='pending' AND expires_at>NOW() with rowCount()===0 check at :1168 | yes — line_account_id taken from the claim row, plus a request/token mismatch rejection at :1134 |
| 5 | Pharmacist credits the customer whose chat is open in inbox-v2 (no QR) | `api/points-claim.php:447 handleGiveDirect() → LoyaltyPoints::addPoints` | INSERT points_claims(status='claimed'); addPoints → users.total_points/available_points/member_tier + points_transactions(reference_type='claim'); UPDATE points_claims.points_transaction_id | yes — beginTransaction :431 / commit :475 | no — fresh random token + voucher per call; a double-click credits twice | yes — requires $_SESSION['admin_user'] and verifies the customer belongs to this line_account_id at :399 |
| 6 | Pharmacist credits a walk-in by phone number (creates an 'offline:<digits>' ghost user if needed) | `api/points-claim.php:623 pcCreditCounterSale(), called from handleGiveByPhone:887` | optional INSERT users(source='counter'); INSERT points_claims(status='claimed'); addPoints → users.total_points/available_points/member_tier + points_transactions(reference_type='claim'); UPDATE points_claims.points_transaction_id | yes — beginTransaction :609 / commit :644 | no — same missing dedupe key as give_direct | yes — admin session required; all user lookups filtered by line_account_id |
| 7 | Pharmacist confirms merging a phone-only ghost's balance into a LINE account | `api/points-claim.php:1049-1050 handleConfirmMerge() → deductPoints + addPoints` | deduct from ghost (users.available_points/used_points + points_transactions type='redeem'), credit to LINE user (users.total_points/available_points + points_transactions type='earn'), both reference_type='merge'; UPDATE points_merge_candidates.status='merged' | NO — the only handler in this file with no beginTransaction; the two halves can apply independently | partial — the candidate must be status='pending', but a crash between the two halves leaves it pending with points already moved | yes — candidate and ghost both re-verified against line_account_id |
| 8 | POS cashier completes a sale for a member | `classes/POSService.php:191 completeTransaction() → LoyaltyPoints::addPoints` | addPoints → users.total_points/available_points/member_tier + points_transactions(reference_type='pos_sale'); UPDATE pos_transactions.points_earned | yes — inside POSService's beginTransaction :176 | no — the status!=='draft' guard is read at :166, before beginTransaction at :176 (TOCTOU) | yes — LoyaltyPoints built with the POS session's line_account_id (api/pos.php:77) |
| 9 | POS cashier voids a completed sale | `classes/POSService.php:258 voidTransaction() → LoyaltyPoints::deductPoints` | users.available_points/used_points + points_transactions(reference_type='pos_void') | yes — inside beginTransaction :250 | no — status!=='completed' guard read before the transaction opens | yes |
| 10 | POS customer pays with points (10 points = 1 baht, hardcoded) | `classes/POSPaymentService.php:353 processPointsPayment()` | deductPoints → users.available_points/used_points + points_transactions(reference_type='pos_payment'); INSERT pos_payments; UPDATE pos_transactions.points_redeemed/points_value | partial — atomic when reached via POSService::completeTransaction; processPayment() called directly opens no transaction of its own | no | yes |
| 11 | POS processes a customer return that claws back points | `classes/POSReturnService.php:251 processReturn() → LoyaltyPoints::deductPoints` | users.available_points/used_points + points_transactions(reference_type='pos_return') | yes — inside beginTransaction :243 | no — status!=='pending' guard read at :233 before beginTransaction | yes |
| 12 | Customer redeems a reward from the Mini App / LIFF (three entry points) | `classes/LoyaltyPoints.php:420 redeemReward() → deductPoints; entered from api/rewards.php:117, api/points-history.php:398, classes/BusinessBot.php:2603` | users.available_points/used_points/member_tier + points_transactions(reference_type='reward'); UPDATE rewards.stock; INSERT reward_redemptions | no — deduct, stock decrement and redemption INSERT are three unwrapped statements | no — the stock guard is read at :399 and applied at :426; concurrent redeems can both pass | partial — reward_redemptions.line_account_id is set, but api/rewards.php:107 and api/points-history.php:74 resolve the user by line_user_id with no account filter, and neither endpoint authenticates |
| 13 | Admin cancels a reward redemption (refund) — admin API | `api/admin/rewards.php:529 cancelRedemption() → LoyaltyPoints::addPoints` | users.total_points/available_points/member_tier + points_transactions(reference_type='refund'); UPDATE reward_redemptions.status; UPDATE rewards.stock | no | no — guard rejects only status='delivered', so an already-cancelled row refunds again | yes — admin session checked at :24; LoyaltyPoints built from $_SESSION['current_bot_id'] |
| 14 | Admin cancels a reward redemption (refund) — membership page AJAX | `membership.php:186 case 'cancel_redemption' → LoyaltyPoints::addPoints` | same as above | no | no — same status!=='delivered' guard defect | NO — the AJAX block at :42 runs before auth_check (header.php at :357); $adminId is null for anonymous callers |
| 15 | Admin manually adds/removes points on a customer record | `user-detail.php:59 / :61 → LoyaltyPoints::addPoints / deductPoints, then TierService::updateUserTier` | users.total_points/available_points/used_points + users.member_tier (written twice, from two different source columns) + points_transactions(reference_type='admin' / 'admin_deduct', reference_id=NULL) | no | no — a resubmitted POST applies the adjustment again | NO — POST handler at :23 precedes includes/header.php at :77; $currentBotId is undefined so every row lands with line_account_id=1 |
| 16 | Admin imports legacy balances from a CSV | `import-legacy-points.php:174 → LoyaltyPoints::addPoints` | users.total_points/available_points/member_tier + points_transactions(reference_type='import', reference_id=NULL) | no — no transaction around the batch loop | no — reference_id is NULL so no batch can be identified or reversed; re-submitting re-credits everyone | yes — line_account_id from the form POST |
| 17 | Dev/test script credits 1000 points to a hardcoded LINE user | `install/add_test_points.php:33 → LoyaltyPoints::addPoints` | users.total_points/available_points/member_tier + points_transactions(reference_type='manual') | no | no — every HTTP request adds another 1000 | yes — line_account_id read from the target user row |
| 18 | Debug endpoint grants or spends arbitrary points, auth explicitly disabled | `archive/debug-files/debug-rewards.php:225 addPoints / :188 redeemReward` | users.total_points/available_points/used_points/member_tier + points_transactions; INSERT users for a synthetic test account | no | no | partial — line_account_id supplied by the caller; no authentication at all |
| 19 | LEGACY — new member completes the registration form (welcome bonus) | `api/member.php:263 + :273 handleRegister()` | UPDATE users.points = 50 (ABSOLUTE, overwrites); on the existing-user branch also users.points = 0 at :163; INSERT points_history(line_account_id, user_id, points, type='bonus', description, balance_after). Ledger and available/total/used untouched. | no — two independent try/catch blocks that swallow their exceptions | no — guarded only by is_registered at :120; rows with is_registered=0 are wiped and re-bonused on every call | partial — line_account_id comes from the unauthenticated request body, defaulting to 1 |
| 20 | LEGACY — Mini App calls action=check for an unknown LINE user (auto-register) | `api/member.php:386-403 autoRegisterMember()` | INSERT users(..., points = 50); INSERT points_history(type='bonus'). No ledger, no available/total/used. | no | partial — only creates when the SELECT at :304/:314 found nothing; two concurrent 'check' calls can both insert | partial — $_GET['line_account_id'] ?? 1, unauthenticated |
| 21 | LEGACY — Mini App action=check finds an existing non-member (auto-upgrade) | `api/member.php:443 + :454 autoUpgradeMember()` | UPDATE users.points = COALESCE(points,0) + 50, member_tier='bronze'; INSERT points_history(type='bonus', balance_after via subquery). No ledger. | no | no — any user row left with is_registered=0 gets another +50 on the next 'check' call | partial — request-supplied line_account_id, unauthenticated |
| 22 | LEGACY — customer redeems a reward through the old points API | `api/points.php:281 + :302 handleRedeem()` | UPDATE users.points = <absolute new balance>; UPDATE rewards.stock; INSERT points_history(type='redeem', reference_type='reward'); optional INSERT point_redemptions. Never touches available_points/used_points/points_transactions/member_tier. | yes — beginTransaction :276 / commit :332 / rollBack :340 | no — a retry deducts again | NO — the only points writer missing bootstrap/route_by_account.php (writes can land in the legacy fallback DB); user lookup at :223 is unscoped; no authentication |
| 23 | MIXED — Odoo invoice.paid webhook (inline handler) | `api/odoo-webhook.php:297-314 awardInvoicePoints(), reached from api/odoo-webhook.php:934` | UPDATE users.points + available_points + total_points + total_spent + order_count; INSERT points_transactions(type='earn', reference_type='invoice', line_account_id). No points_history, no used_points, no tier. | no — four unwrapped statements | partial — SELECT-then-INSERT dedupe on (reference_type='invoice', reference_id); no UNIQUE index backs it, so concurrent deliveries both award | partial — line_account_id copied from the user row with a hardcoded fallback of 3; the user lookup itself is unscoped |
| 24 | MIXED — Odoo invoice.paid webhook (class handler, duplicate implementation) | `classes/OdooWebhookHandler.php:2595-2612 awardInvoicePoints(), reached from api/webhook/odoo.php:71 and cron/webhook_retry_processor.php:193` | identical to the inline copy: users.points + available_points + total_points + total_spent + order_count; INSERT points_transactions(reference_type='invoice') | no | partial — same unindexed check-then-act dedupe; shares the reference_id namespace with the inline copy, which is the only reason the two implementations do not double-award each other | partial — same hardcoded line_account_id fallback of 3 |
| 25 | MIXED — admin approves a payment slip on a shop order | `shop/order-detail.php:407-421 action=approve_payment` | UPDATE users.points (+= earned); INSERT points_history(type='earn', reference_type='order'); INSERT points_transactions(type='earn', reference_type='order'). balance_after on BOTH rows is computed from users.points, not the ledger. No available_points/total_points/used_points, no tier. | no — three statements, each in its own swallowing try/catch | no — the payment_status='paid' UPDATE at :348 is unconditional, so every resubmit re-awards | NO — POST block at :190 runs before includes/header.php/auth_check at :605; line_account_id from $_SESSION['current_bot_id'] ?? 1 |
| 26 | LEGACY — one-shot migration that bulk-upgrades unregistered users | `database/migration_auto_member.php:86 + :96` | UPDATE users.points = COALESCE(points,0) + 50, member_id, is_registered=1, member_tier; INSERT points_history(type='bonus'). No ledger. | partial — one beginTransaction at :70 / commit at :114 wraps the whole loop, but the per-row try/catch at :109 swallows failures without rolling back | partial — the same UPDATE sets is_registered=1, so a fully committed run cannot repeat; a partial run can leave rows upgraded-without-bonus | yes — line_account_id read from each user row |

### Reading the matrix

- **MODERN** (rows 1–17) reach `points_transactions` through `LoyaltyPoints`.
  They never touch `users.points`.
- **LEGACY** (rows 18–21, 25) write `users.points` + `points_history` only. They
  never reach the ledger and never update the tier.
- **MIXED** (rows 22–24) write some combination of both, with a hand-rolled
  `balance_after` derived from `users.points` — a number no reader can reconcile.

Four writers execute **before** `includes/header.php` / `auth_check.php` loads,
or with no auth at all: `shop/order-detail.php` (approve_payment),
`user-detail.php` (add_points), `membership.php` (cancel_redemption), and
`api/points-history.php` (redeem). `api/points.php` is additionally the only
points writer that omits `bootstrap/route_by_account.php`, so on a request
without a tenant subdomain its writes land in the **legacy fallback DB rather
than the tenant DB**.

---

## 3. The read side: how many numbers can one member see?

At least six, concurrently, from these six different expressions:

| # | Expression | Where it surfaces |
|---|---|---|
| 1 | `SUM(points_transactions.points)` (net) | member card, POS sufficiency check, reward redemption |
| 2 | `SUM(points_transactions.points WHERE points > 0)` (lifetime) | LINE chat member card, inbox HUD tier ladder |
| 3 | `users.available_points` | mini-app history header, admin members list, PDPA export, phone-members page |
| 4 | `users.total_points` | `TierService`, and the inbox **"ใช้แต้มสะสม"** modal's spendable maximum |
| 5 | `users.points` | `api/member.php?action=check`, `api/points.php`, the "แลกแต้มสำเร็จ" Flex card |
| 6 | `loyalty_points.points` | LIFF profile bootstrap, CRM segments, auto-tags — **always 0** |

Two of those six authorise spending. `api/member.php` returns **two different
balances for the same member from two actions in the same file** — `check` reads
`users.points`, `get_card` reads `getUserPoints()` — and the mini app calls both
during one session.

**No balance read anywhere applies a `line_account_id` filter**, even though the
column exists on `points_transactions` and is written. See §6.2 for why Batch 1
deliberately left it that way.

---

## 4. Confirmed P0 defects

Each was re-verified against the files by an independent pass. Severity is the
verifier's, not the finder's.

### 4.1 The zero-ledger fallback resurrected spent points — CRITICAL *(fixed in Batch 1)*

`classes/LoyaltyPoints.php:50-96`. The fallback to the `users` columns fired
whenever the ledger **netted to zero**, not when the ledger was empty. The code
comment said "If no data in points_transactions"; the condition said
`(int)$result['available_points'] === 0`. Those are different statements.

The exploitable shape is the Odoo path (`api/odoo-webhook.php:297-313`), which
increments `users.points` **and** `users.available_points` **and** writes a
matching ledger row:

```
Odoo pays out 300  →  ledger +300, users.available_points 300, users.points 300
member spends 300  →  ledger nets 0, users.available_points 0, users.points 300
next read          →  ledger nets 0 → fallback → users.points 300 → "300 available"
```

Those 300 points were not merely displayed. `redeemReward()` and
`POSPaymentService::processPointsRedemption()` both authorise spending against
this number, and `deductPoints()` then ran an unguarded
`UPDATE users SET available_points = available_points - 300`, leaving the column
at **-300 permanently** — a number every `users.*`-reading screen then displays.

### 4.2 Nothing was atomic — CRITICAL *(fixed in Batch 1)*

`LoyaltyPoints` contained no `beginTransaction`, no `commit`, no `rollBack` and
no `FOR UPDATE` anywhere. `addPoints()` issued its `users` UPDATE and its ledger
INSERT as two unwrapped statements; a failure between them left the two stores
permanently disagreeing.

### 4.3 Nothing was idempotent — CRITICAL *(fixed in Batch 1, adoption in Batch 2)*

`points_transactions` has **no UNIQUE constraint in any of its six on-disk
definitions**. A replayed LINE webhook, a re-delivered Odoo invoice or a
double-clicked "ให้แต้ม" awards twice with no defence. The only dedupe in the
whole loyalty system lives in two upstream tables —
`receipt_point_claims.uk_claim` and `points_claims.uniq_token`.

### 4.4 Reward redemption could oversell and double-spend — HIGH *(fixed in Batch 1)*

`redeemReward()` performed read-check-write on stock, on points and on the
redemption insert as five independent autocommit statements. Two concurrent
redemptions of the last item both passed the stock check.

Separately, `api/points.php?action=redeem` is a **second, fully parallel
redemption stack** settling against `users.points` + `points_history`. Neither
store sees the other, so the same reward can be redeemed once through each.
*(Not fixed — Phase 5.)*

### 4.5 Client-supplied `line_user_id` is trusted as identity — CRITICAL *(not fixed — Phase 6)*

`api/member.php`, `api/points.php`, `api/points-history.php`, `api/rewards.php`
and `api/user-profile.php` take the caller's identity from a request parameter.
No LINE ID-token or access-token verification exists anywhere in the repo.
Changing one field in a request reads or mutates another member's balance.

**This is the highest-severity finding in the audit and Batch 1 does not address
it.** It is Phase 6 in the plan. It should arguably be pulled forward.

### 4.6 Tier semantics are inverted — HIGH *(not fixed — Phase 3)*

`TierService::getTiers()` aliases `tier_settings.multiplier AS discount_percent`.
That column's own DB comment reads *"Points earning multiplier for this tier"*
and the admin UI labels it **"ตัวคูณแต้ม"** with the help text *"1.5x = ได้แต้ม
เพิ่ม 50%"*. A Gold tier configured as "earn 1.5×" is served to the mini app as
"1.5% discount". Nothing applies either number to a price, and
`calculatePoints()` has no tier term at all, so the field is inert as well as
mislabelled.

Worse: `deductPoints()` recomputed the tier from the **post-redemption available
balance**, so spending points demoted the member. Every redeem, POS points
payment, POS void, POS return and account merge was a downgrade path.
*(Preserved verbatim in Batch 1 — see §6.1.)*

Six independent tier ladders exist, reading three different point columns.
`users.member_tier`, the column they all write, exists in **no** `database/*.sql`
file — every write is wrapped in a `SHOW COLUMNS` guard or a swallowed
try/catch, so on a freshly provisioned tenant every tier write silently no-ops.

---

## 5. Schema reality

`points_transactions` is declared **six times** across `database/` and
`install/`, and the definitions disagree on column types, on the `type` ENUM
value set, and on which indexes exist.

What a **freshly provisioned tenant** actually gets (`TenantProvisioning`
applies only `database/migration_2026-05-25_tenant_template.sql`):

- `points_transactions` with `PRIMARY KEY(id)` **and no other index** — so the
  balance query `SUM(points) WHERE user_id = ?` full-scans on every award and
  every redeem.
- `type` as `enum('earn','redeem','expire','adjust','refund')` — **no `'bonus'`**,
  though `api/member.php` writes `'bonus'` to `points_history`.
- No `idempotency_key`, no `metadata`, no `created_by`.
- `points_settings` **without** `UNIQUE(line_account_id)`, so
  `LoyaltyPoints::updateSettings()`'s `ON DUPLICATE KEY UPDATE` degenerates to a
  plain INSERT: every save of the points-settings screen appends a row, and which
  one wins the subsequent `ORDER BY line_account_id DESC LIMIT 1` is undefined.
  **The tenant's earn rate can change between requests.**
- `users` **without** `UNIQUE unique_line_user`, which `api/points-claim.php:867`
  explicitly depends on to resolve the offline-ghost creation race.
- `points_claims`, `receipt_point_claims` and `points_merge_candidates` are
  absent entirely; they exist only because PHP auto-creates them at request time.

And a live inconsistency: the production dump's `points_history` has **no
`line_account_id` column**, while five callers name it explicitly in their
INSERT. Three of the five swallow the resulting error into `error_log`, so on a
prod-shaped database those are **silent point losses**, not visible failures.

Batch 1's migration fixes the `points_transactions` items. The
`points_settings` and `users` unique keys are deliberately left alone: adding a
UNIQUE to a table that may already contain duplicates needs a dedupe pass first,
which is its own migration with its own review.

---

## 6. What Batch 1 changed

| Change | File |
|---|---|
| Canonical ledger service — atomic, idempotent, honest zero | `classes/LoyaltyLedgerService.php` (new) |
| `LoyaltyPoints` becomes a facade over it; fallback bug fixed | `classes/LoyaltyPoints.php` |
| `redeemReward()` made transactional with a guarded stock claim | `classes/LoyaltyPoints.php` |
| `idempotency_key` + UNIQUE, `metadata`, `created_by`, widened ENUM, missing indexes | `database/migration_2026-08-26_loyalty_ledger_idempotency.sql` (new) |
| Read-only reconciliation report | `scripts/loyalty-reconcile.php` (new) |
| 263 regression tests, registered in `phpunit.xml` | `tests/Loyalty/` (new) |

The public API of `LoyaltyPoints` is unchanged: all ~15 existing call sites keep
working untouched. An optional trailing `$options` argument on
`addPoints()` / `deductPoints()` is the seam Batch 2 uses to pass idempotency
keys, and `LoyaltyPoints::ledger()` exposes the service directly.

The cache is now **recomputed from the ledger** on every write rather than
incremented, which makes it self-healing: the invariant
`SUM(points_transactions.points) == users.available_points` holds after every
movement, even for a member whose history predates the service.

### 6.1 Deliberately NOT changed

These are all real defects. They are left for the phase the plan assigns them,
because Batch 1's whole purpose is to make point accounting stable *before*
anything is built on top of it.

| Left as-is | Why | Phase |
|---|---|---|
| Tier computed from the post-redemption available balance (spending demotes) | Changing the qualification metric is a tier-semantics change, not a ledger change | 3 / Batch 3 |
| `multiplier` aliased as `discount_percent` | Same | 3 |
| `api/points.php` second redemption stack | Full `RewardRedemptionService` | 5 |
| `max_per_user`, start/end validity window, tier restrictions on redemption | Same | 5 |
| Client-trusted `line_user_id` on the public APIs | Needs LINE token verification | **6 — highest severity open item** |
| Welcome bonus still writing `users.points` + `points_history` | Writer migration | 2 |
| `loyalty_points` dead-table readers | CRM consolidation | 7 |
| `points_settings` / `users` missing UNIQUE keys | Needs a dedupe pass first | separate migration |

### 6.2 Why balance reads are still not OA-scoped

Under database-per-tenant a `users` row belongs to exactly one OA, so `user_id`
already implies the OA — the `line_account_id` on a ledger row is denormalised.
Adding the predicate would only **drop** rows that earlier writers stamped with a
wrong or NULL account (`user-detail.php` hard-codes 1; the Odoo handler defaults
to 3), silently destroying real balances. Scoping is Phase 6 work and needs those
mis-stamped rows repaired first. The reconciliation report surfaces them.

---

## 7. How to verify

```bash
# 1. Regression suite (263 tests). Must be green.
php vendor/bin/phpunit --testsuite "Loyalty Tests"

# 2. Apply the migration to one tenant, then confirm the shape.
mysql -u USER -p reya_tenant_XXXX \
  < database/migration_2026-08-26_loyalty_ledger_idempotency.sql
mysql -u USER -p reya_tenant_XXXX -e 'SHOW CREATE TABLE points_transactions\G'

# 3. Size the migration backlog. Read-only; safe on production.
php scripts/loyalty-reconcile.php --all-tenants
php scripts/loyalty-reconcile.php --tenant=7 --show=STALE_CACHE --limit=100
```

The report's `STALE_CACHE` bucket is the direct measure of §4.1: it counts the
members who would have been handed phantom, spendable points before this batch.

### Test-harness caveats

- `composer test` exits 127 in a clean checkout — `vendor/bin/phpunit` ships
  without the exec bit. Use `php vendor/bin/phpunit`.
- The suite baseline is **red before this change and red after it**: 500 errors
  (all `Tests\InboxChat\TemplateRoundTripPropertyTest`, a sqlite fixture that
  fell behind an `ALTER TABLE`) and 133 failures (100 of them
  `Tests\AIChat\SessionContinuityPropertyTest`). None touch loyalty. The
  acceptance signal is "the new suite passes and those counts do not increase".
- Eight test directories and three root-level test files have no `<testsuite>`
  entry in `phpunit.xml` and therefore never run — including
  `tests/LiffTelepharmacy/`, which holds all six pre-existing loyalty tests.
  `tests/Loyalty/` was registered to avoid joining them.
- `composer analyse` cannot complete on this repo: phpstan aborts on a
  pre-existing parse error in `classes/inbox-v2.php` (`unexpected T_CASE`), which
  suppresses analysis of every other file. The Batch 1 files were analysed
  individually instead.

---

## 8. Recommended next step

Per the plan, **Batch 2 — writer migration**: move the welcome bonus, the QR and
direct awards, order awards, manual admin adjustments and reward refunds onto
`LoyaltyLedgerService`, each with an idempotency key, so that only one production
path creates point movements.

One deviation worth raising: §4.5 (client-trusted LINE identity) is the most
severe finding in this audit and the plan schedules it at Phase 6. It is
independent of the ledger work and could be pulled forward without disturbing the
Batch 2 → 3 sequence.
