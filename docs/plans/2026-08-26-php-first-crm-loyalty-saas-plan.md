# REYA / Clinicya — PHP-First CRM, Membership, Loyalty & SaaS Improvement Plan

**Project:** `C:\Users\Administrator\clinicya`  
**Plan date:** 2026-08-26  
**Status:** Planning / review only  
**Implementation principle:** **PHP-first** — the existing PHP application remains the primary source of truth for business logic until each area is deliberately migrated and proven equivalent.  
**Purpose:** Document what was inspected, what was found, what should be fixed, why it matters, and the recommended implementation order before making production code changes.

---

## 1. Executive summary

The current REYA / Clinicya codebase already contains a substantial amount of CRM, loyalty, membership, LINE OA, tenant, landing-page, and SaaS infrastructure. The main problem is **not a lack of features**. The main problem is that several important domains—especially loyalty points, membership tiers, rewards, and CRM—have been implemented in multiple generations and now have overlapping sources of truth and duplicated paths.

The recommended direction is therefore **consolidation before expansion**:

1. Keep PHP as the canonical runtime for the current product.
2. Make the loyalty ledger authoritative and atomic.
3. Unify membership/tier semantics.
4. Consolidate CRM concepts around Customer 360 + Lifecycle + Segmentation + Actions.
5. Connect loyalty events to CRM automation.
6. Harden LINE identity, tenant/OA scoping, and public APIs.
7. Rework the SaaS story around outcomes rather than a long feature list.
8. Improve onboarding so a new tenant reaches a first visible success quickly.
9. Only after the PHP core is stable should any Next.js migration continue for these domains.

The product already has several unusually strong differentiators for Thai pharmacy SaaS:

- LINE-first customer identity and communication.
- Pharmacy-aware Customer 360.
- Health / allergy / medication context.
- Loyalty for both LINE and phone-only walk-in members.
- One-time QR points claims.
- Reward redemption and tiering.
- Dynamic segmentation and drip campaigns.
- Database-per-tenant SaaS foundation.
- Public tenant storefront/landing customization.
- SaaS lead capture, tenant provisioning, and onboarding infrastructure.

The opportunity is to turn these into one coherent product loop:

```text
LINE / Walk-in Customer
        ↓
Identity + Membership
        ↓
Customer 360
        ↓
Purchase / Consultation / Service
        ↓
Points + Tier + Reward eligibility
        ↓
Lifecycle + Segment
        ↓
Targeted follow-up / campaign
        ↓
Repeat purchase / retention
        ↓
Measured outcome
```

---

# 2. Scope of this review

This plan focuses on these areas:

- PHP CRM architecture.
- Customer 360.
- Tags and segmentation.
- Drip/automation campaigns.
- Membership registration.
- Membership card behavior.
- Loyalty points.
- Point history / ledger.
- Tier calculation.
- Rewards and redemption.
- Phone-only/offline members.
- QR point claims.
- LINE Mini App loyalty APIs.
- Tenant isolation as it relates to CRM/loyalty.
- SaaS onboarding.
- Public marketing landing page.
- Beta lead capture.
- Content strategy for selling the SaaS.

This plan does **not** propose a PHP-to-Next rewrite. The existing Node/Next applications may continue to exist, but CRM/loyalty changes described here should first be made correct and coherent in PHP.

---

# 3. Files and components inspected

The review included the following important PHP/runtime areas.

## Loyalty / membership

- `classes/LoyaltyPoints.php`
- `classes/TierService.php`
- `membership.php`
- `loyalty-members.php`
- `admin-points-settings.php` redirect behavior
- `admin-rewards.php` redirect behavior
- `api/points.php`
- `api/points-history.php`
- `api/rewards.php`
- `api/member.php`
- `api/points-claim.php`

## CRM

- `classes/CRMManager.php`
- `classes/AdvancedCRM.php`
- `classes/CRMDashboardService.php`
- `crm-dashboard.php`
- `crm-dashboard-advanced.php`
- `api/crm-dashboard-api.php`

## Pharmacy/customer health context

- `classes/CustomerHealthEngineService.php`

## SaaS / public marketing / tenant onboarding

- `index.php`
- `landing-reya-pharmacy.html`
- `beta.php`
- `admin/tenant-onboard.php`
- `admin/landing-settings.php`

## Repository state noted before planning

The working tree already contained local changes unrelated to this plan. Those changes must not be overwritten or mixed into the CRM/loyalty refactor without deliberate review.

---

# 4. Current system map

## 4.1 Customer and CRM domain

The system currently contains at least three overlapping CRM-level services:

### `CRMManager.php`

Responsibilities observed:

- Tag management.
- User-by-tag queries.
- Tag statistics.
- Drip campaign creation.
- Campaign steps.
- Campaign enrollment.
- Cron-driven drip processing.
- Conditions such as purchase/tag checks.
- Follow triggers.
- Purchase triggers.
- Inactivity detection.
- Auto-tagging such as New Customer, VIP, Inactive.

### `AdvancedCRM.php`

Responsibilities observed:

- Tag creation/assignment/removal.
- Dynamic customer segments.
- Segment member calculation.
- Recency-style conditions.
- Total spend filters.
- Order count filters.
- Tier filters.
- Tag filters.
- Province filters.
- Active/new user analytics.

### `CRMDashboardService.php`

Responsibilities observed:

- Executive overview.
- Revenue/pipeline metrics.
- CRM deals.
- Tickets.
- Campaigns.
- Segments.
- Customer lists.
- Customer 360.
- Customer timeline.
- Analytics/reporting.

### Assessment

The product has enough pieces for a strong CRM, but the boundaries are not clear. The same product concepts are spread across multiple services. This makes behavior harder to reason about and encourages future features to be added wherever convenient rather than to one canonical domain service.

Recommended long-term direction:

```text
CustomerCRMService
  ├─ customer profile / 360
  ├─ tags
  ├─ lifecycle
  ├─ segments
  └─ timeline/activity

CRMAutomationService
  ├─ event triggers
  ├─ campaign enrollment
  ├─ step evaluation
  └─ actions

CRMDashboardService
  └─ read model / reporting only
```

`CRMManager` and `AdvancedCRM` can initially become compatibility wrappers rather than being deleted immediately.

---

# 5. CRM findings

## 5.1 Good foundations already present

The CRM is not a blank slate. It already supports many capabilities that should be retained:

- Manual and automatic tags.
- Drip campaigns.
- Delay-based steps.
- Conditional message steps.
- Follow/purchase/inactivity triggers.
- Dynamic segmentation.
- Recency, frequency, monetary-like signals.
- Customer lifecycle analytics.
- Customer 360 concepts.
- Ticket/service-center concepts.
- LINE-based customer communication.

This is a good base for a pharmacy-specific retention CRM.

## 5.2 Current CRM is too generic in its top-level story

The advanced dashboard includes a traditional sales-pipeline model:

```text
lead
qualified
proposal
negotiation
closed_won
closed_lost
```

That is useful for B2B, wholesale, enterprise sales, or clinic packages, but it should not become the primary CRM mental model for ordinary pharmacy customers.

For a pharmacy SaaS, the primary customer lifecycle should instead be closer to:

```text
Follower
→ Member
→ First Purchase
→ Repeat Customer
→ Loyal
→ VIP
→ At Risk
→ Inactive
→ Reactivated
```

A second healthcare-service status dimension can exist separately:

```text
Needs Pharmacist
Consulted
Follow-up Due
Medication Refill Due
Appointment Due
Resolved
```

This gives staff a clear answer to the operational question:

> “Who needs attention today?”

instead of only showing abstract pipeline metrics.

## 5.3 CRM needs one canonical lifecycle engine

The lifecycle state should be calculated from explicit, auditable rules—not from ad-hoc tags alone.

Recommended fields/signals:

- First seen date.
- Member registration date.
- First purchase date.
- Last purchase date.
- Purchase count.
- Total spend.
- 30/60/90-day spend.
- Last message/interaction.
- Last consultation.
- Current tier.
- Available points.
- Expiring points.
- Number of redemptions.
- Branch affinity.
- Channel/LINE OA.

The lifecycle should be recalculated after meaningful events and periodically by cron.

---

# 6. Customer 360 findings

## 6.1 Customer 360 should become the primary CRM screen

The codebase already has enough data sources to make Customer 360 the strongest part of REYA.

Recommended customer summary:

```text
Customer
├─ Identity
│  ├─ LINE profile
│  ├─ phone
│  ├─ member ID
│  └─ registration status
├─ Loyalty
│  ├─ available points
│  ├─ lifetime/qualifying points
│  ├─ tier
│  ├─ progress to next tier
│  └─ expiring points
├─ Commerce
│  ├─ total spent
│  ├─ total orders
│  ├─ last order
│  └─ favorite categories/products
├─ CRM
│  ├─ lifecycle
│  ├─ tags
│  ├─ segments
│  ├─ campaigns
│  └─ last contact
├─ Pharmacy care
│  ├─ allergies
│  ├─ conditions
│  ├─ current medications
│  ├─ consultations
│  └─ follow-up due
└─ Timeline
   ├─ messages
   ├─ orders
   ├─ points
   ├─ rewards
   ├─ consultations
   └─ staff actions
```

## 6.2 Health data must be separated from marketing eligibility

`CustomerHealthEngineService.php` shows that the platform can hold clinically sensitive information such as:

- Drug allergies.
- Medical conditions.
- Current medications.
- Health-profile data.
- Communication style.

This is valuable for pharmacist care but should not automatically become a general marketing segmentation source.

Recommended separation:

```text
Care Context
    ≠
Marketing Profile
```

Marketing segmentation should use safe commercial/lifecycle signals by default. Health-derived campaigns should require an explicit approved use-case and appropriate consent rules.

---

# 7. Loyalty system findings

This is the highest-priority technical area.

## 7.1 Multiple point balances currently coexist

Observed point-related columns and tables include:

```text
users.points
users.total_points
users.available_points
users.used_points

points_history
points_transactions
```

There are also multiple API/business-logic paths using different combinations of those stores.

### Examples

`LoyaltyPoints.php` primarily derives balance from `points_transactions`, with fallback to user aggregate columns.

`api/points.php` still uses the older pattern involving:

- `users.points`
- `points_history`
- direct redemption logic

`api/points-history.php` uses the newer `LoyaltyPoints` / `points_transactions` path.

`api/member.php` awards the welcome bonus by directly writing `users.points` and `points_history` rather than going through the newer loyalty ledger.

### Impact

This creates a split-brain model where different screens can legitimately disagree about the same member’s points.

Possible failure mode:

```text
Admin balance        800
Mini App balance     750
Legacy API balance   850
```

This must be fixed before aggressively expanding loyalty features.

---

# 8. Critical loyalty bug pattern: zero-balance fallback

`LoyaltyPoints::getUserPoints()` falls back to user columns when the calculated `points_transactions` available balance is zero.

A zero ledger balance is not necessarily “no ledger data.” It may mean:

```text
+500 earn
-500 redeem
= 0 valid balance
```

If `users.available_points` or `users.points` is stale and non-zero, the fallback can effectively resurrect points that the ledger correctly says are gone.

### Required fix

The code must distinguish:

```text
A. no ledger rows exist
B. ledger rows exist and sum to zero
```

Fallback should only be considered for migration/legacy users in case A.

After migration is complete, fallback should be removed entirely.

---

# 9. Recommended loyalty source of truth

Use `points_transactions` as the canonical ledger.

## 9.1 Canonical ledger

Recommended invariant:

> Every point movement is represented by exactly one immutable ledger transaction.

Example:

```text
points_transactions
────────────────────────────────
id
line_account_id
user_id
type
points
balance_after
reference_type
reference_id
idempotency_key
description
expires_at
metadata_json
created_by
created_at
```

Suggested transaction types:

```text
earn
bonus
redeem
refund
adjust
expire
reverse
migration
```

## 9.2 User point columns become cached aggregates

Keep these if useful for performance:

```text
users.available_points
users.total_points
users.used_points
```

But define them explicitly as **derived/cache fields**, not authoritative history.

They should only be mutated by the loyalty service in the same database transaction as the ledger insert.

## 9.3 Legacy stores

Deprecate gradually:

```text
users.points
points_history
```

Do not delete them immediately.

Migration approach:

1. Read compatibility.
2. Backfill ledger.
3. Compare balances.
4. Switch all writes.
5. Switch all reads.
6. Monitor.
7. Remove old write paths.
8. Remove old tables/columns only in a later version.

---

# 10. Atomicity and concurrency

## 10.1 Current risk

`LoyaltyPoints::addPoints()` and `deductPoints()` perform multiple writes:

```text
read current balance
update users aggregate
insert ledger transaction
update tier
```

Those operations need one atomic transaction boundary.

Without it, a partial failure can produce:

```text
users.available_points changed
but ledger insert failed
```

or the reverse.

## 10.2 Required transaction pattern

For an earn operation:

```text
BEGIN

SELECT member row FOR UPDATE
validate request / idempotency
calculate new balance
INSERT points transaction
UPDATE cached user aggregates
recalculate qualifying tier if needed
record CRM event

COMMIT
```

For redemption:

```text
BEGIN

SELECT member FOR UPDATE
SELECT reward FOR UPDATE
validate active window
validate stock
validate per-user limit
validate available points
create redemption
insert negative point transaction
update user cached balance
update reward stock atomically

COMMIT
```

If any step fails:

```text
ROLLBACK
```

## 10.3 Idempotency

Order, payment, QR-claim, webhook, and retryable events need idempotency.

Recommended unique concept:

```text
(line_account_id, reference_type, reference_id, transaction_role)
```

or a generated `idempotency_key`.

Examples:

```text
order:123:earn
member-register:881:welcome-bonus
claim:abc123:earn
redemption:9001:redeem
redemption:9001:refund
```

This prevents accidental double-awards during retries.

---

# 11. Points expiry model

The existing system stores `expires_at` on earning transactions and can show “points expiring soon.”

However, correct expiry requires knowing which earned point lots have already been consumed.

If a user earns:

```text
Jan: +100 expires Dec
Feb: +100 expires next Jan
```

then redeems 150 points, the system must know whether redemption consumed:

- FIFO points,
- earliest-expiring points,
- or another policy.

Simply summing all positive transactions whose `expires_at` is near can overstate the points actually at risk of expiration.

## Recommended policy

Use **earliest expiry first** / FIFO consumption.

Implementation options:

### Option A — Point lots

Add an earning-lot table:

```text
point_lots
├─ source_transaction_id
├─ original_points
├─ remaining_points
├─ expires_at
└─ status
```

Redemption consumes lots in expiry order.

### Option B — Allocation table

Keep immutable transactions plus:

```text
point_allocations
├─ debit_transaction_id
├─ credit_transaction_id
└─ points_allocated
```

Option B is more auditable, but Option A is simpler for the current PHP system.

Expiry jobs should create explicit `expire` ledger transactions, not silently modify balance.

---

# 12. Tier findings

## 12.1 Tier semantics are currently inconsistent

`TierService::getUserTier()` prefers lifetime-ish `total_points`.

But `LoyaltyPoints::updateUserTier()` is called using the newly calculated **available balance** after add/deduct.

This can create behavior like:

```text
Gold member
5,500 accumulated points
redeems 5,000
available = 500
→ tier calculation may fall back toward Bronze
```

That is usually the wrong membership experience.

## 12.2 Separate spendable points from tier qualification

Define three concepts explicitly:

### Available points

Spendable currency.

```text
available_points
```

### Lifetime earned points

Historical value.

```text
total_points_earned
```

### Tier qualifying points

Points that determine status.

```text
qualifying_points
```

Initial implementation may map qualifying points to lifetime earned points.

Later, if the business wants annual qualification:

```text
qualifying_points_rolling_365d
```

can be introduced without changing the meaning of spendable points.

---

# 13. Tier configuration bug/semantic mismatch

The tier settings screen saves a field conceptually named `multiplier`.

`TierService.php` currently aliases this field as `discount_percent` when reading `tier_settings`.

These are different business concepts.

Example:

```text
Gold earn multiplier = 1.25x
Gold discount = 5%
```

They must not share one column.

## Required schema/domain split

Recommended tier fields:

```text
tier_code
tier_name
min_qualifying_points
earn_multiplier
discount_percent
badge_color
icon
benefits_json
is_active
sort_order
```

Example:

```text
Bronze   min=0      earn=1.00 discount=0
Silver   min=1000   earn=1.10 discount=2
Gold     min=5000   earn=1.25 discount=5
Platinum min=15000  earn=1.50 discount=10
```

---

# 14. Loyalty rule engine

The UI already exposes several rule concepts:

- Base points rate.
- Minimum purchase amount.
- Expiry days.
- Point campaigns.
- Campaign multiplier.
- Category bonus.
- Tier multiplier.

But the canonical calculation path needs to use all of them consistently.

Recommended calculation:

```text
base_points
× campaign_multiplier
× category_multiplier
× tier_multiplier
+ fixed_event_bonus
= awarded_points
```

## Recommended `LoyaltyRuleEngine`

Create a dedicated PHP service responsible only for calculation.

```php
LoyaltyRuleEngine::calculateForOrder($userId, $order)
```

Return a transparent breakdown:

```json
{
  "base_points": 100,
  "campaign_multiplier": 2.0,
  "category_multiplier": 1.0,
  "tier_multiplier": 1.25,
  "bonus_points": 0,
  "final_points": 250
}
```

Store the breakdown in transaction metadata so support staff can answer:

> “Why did this customer get 250 points?”

---

# 15. Reward system findings

The reward system is already functionally rich.

Observed concepts include:

- Reward name.
- Description.
- Image.
- Required points.
- Reward type.
- Reward value.
- Stock.
- Max per user.
- Active/inactive status.
- Start date.
- End date.
- Redemption status.
- Approval.
- Delivery.
- Cancellation/refund.
- LINE notifications.

This is a strong feature base.

## 15.1 Reward redemption must use one implementation

There are currently at least two redemption paths:

- Legacy/direct logic in `api/points.php`.
- `LoyaltyPoints::redeemReward()` via `api/rewards.php`.

They should converge on one canonical service.

Recommended:

```text
RewardRedemptionService
  ├─ quote()
  ├─ redeem()
  ├─ approve()
  ├─ deliver()
  ├─ cancel()
  └─ refund()
```

`api/points.php` should eventually delegate to the same service instead of implementing redemption itself.

## 15.2 Reward validation should be complete

Before redemption, validate:

- Correct tenant / LINE OA.
- Active flag.
- Start/end validity window.
- Current stock.
- `max_per_user`.
- Available points.
- Duplicate request/idempotency.
- Any tier restriction.
- Any branch restriction.

Stock decrement should be guarded atomically.

---

# 16. Phone-only membership is a strong differentiator

`loyalty-members.php` and `api/points-claim.php` already support a useful real-world retail path:

```text
walk-in customer
↓
phone number
↓
offline member record
↓
points awarded
↓
history retained
↓
customer later connects LINE
↓
possible merge detected
↓
staff confirms merge
```

This should be preserved and improved rather than treated as legacy behavior.

## Why this matters commercially

It removes two common adoption barriers:

1. Customer does not need to download a new app.
2. Customer does not need to connect LINE at the exact moment of purchase.

Suggested SaaS message:

> “ลูกค้าไม่มี LINE ตอนสมัครก็สะสมแต้มได้ พอเชื่อม LINE ภายหลัง ประวัติเดิมยังอยู่”

## Merge safety

The current direction of flagging a possible phone merge and requiring staff confirmation is preferable to automatic merging because:

- Family members may share a phone number.
- Old phone numbers may be reassigned.
- Duplicate/dirty CRM data is common.

Merge actions should be audited and reversible where practical.

---

# 17. QR points claim is another strong differentiator

`api/points-claim.php` implements a useful one-time QR workflow:

```text
staff enters amount / points
↓
one-time token generated
↓
QR opens LIFF claim URL
↓
customer scans
↓
customer identity resolved
↓
points credited
↓
token becomes unusable
```

Observed safeguards include:

- Token uniqueness.
- Expiration.
- Tenant/LINE account scoping intent.
- Voucher number.
- Audit-related fields.
- LIFF dependency check.

This feature should be treated as a **hero demo** for sales content because its value is obvious on video without explanation.

---

# 18. Member card direction

The member card should not be only a static visual badge.

Recommended information:

```text
Shop / brand
Customer name
Tier
Available points
Progress to next tier
Expiring points
Member number
Dynamic QR
Reward shortcut
Point-history shortcut
Tier-benefit shortcut
```

## Dynamic member QR

Do not expose a simple static user/member ID as the only proof of identity.

Prefer a short-lived signed token:

```text
member_id
tenant/line_account_id
issued_at
expires_at
nonce/signature
```

This reduces screenshot reuse and accidental customer mix-ups at the counter.

---

# 19. Security findings to treat as P0 until verified

Several public-facing PHP APIs accept `line_user_id` from request input.

Examples inspected include member/points/reward flows.

In the inspected entrypoints, robust LINE ID token/access-token verification was not visible before using the supplied `line_user_id`.

There may be upstream verification elsewhere, but until that is proven, the safe assumption is:

> **A client-supplied LINE user ID must not be treated as authenticated identity.**

## Required pattern

For Mini App requests:

1. Client sends LINE ID token or access token.
2. Server verifies with LINE / validates token claims.
3. Server derives the real LINE user ID.
4. Server ignores any conflicting client-provided `line_user_id`.
5. Server resolves the correct tenant / LINE OA.
6. Only then perform customer/points/reward operations.

## Other API-hardening items

Review and tighten:

- `Access-Control-Allow-Origin: *` where unnecessary.
- Error responses exposing internal file/path/line information.
- Admin APIs lacking explicit CSRF protection.
- Production `display_errors` settings.
- Request rate limits for public claim/reward/member endpoints.
- Replay prevention for claim/redeem operations.

---

# 20. Tenant and LINE-account isolation

The architecture uses database-per-tenant, which is a strong isolation boundary.

However, several features also support multiple LINE accounts/OAs inside a tenant database.

Therefore every relevant operation needs to be clear about two scopes:

```text
Tenant scope
LINE account / OA scope
```

## Review requirement

Every CRM/loyalty query should be classified as one of:

```text
A. tenant-wide intentionally
B. LINE-account scoped intentionally
C. branch scoped intentionally
D. customer scoped
```

Any query with no scope should be reviewed rather than assumed correct.

Particular attention should be paid to dashboard/reporting queries because some current service queries operate on CRM tables without an explicit `line_account_id` condition.

With database-per-tenant this may still be tenant-safe, but it may mix multiple OAs within one tenant.

---

# 21. CRM + loyalty integration

This is where the product can become substantially more valuable.

The loyalty system should publish domain events that CRM automation can consume.

Recommended events:

```text
member.registered
member.line_linked
member.phone_created
points.earned
points.redeemed
points.expiring_soon
points.expired
tier.upgraded
tier.downgraded
reward.redeemed
reward.approved
reward.delivered
order.completed
customer.inactive
customer.reactivated
consultation.completed
followup.due
```

## Example automations

### Near next tier

```text
Condition:
points_to_next_tier <= 100

Action:
LINE message / tag / task
```

### Expiring points

```text
Condition:
expiring_points > 0
AND expiry <= 30 days

Action:
reminder campaign
```

### Win-back

```text
Condition:
last_purchase > 45 days
AND available_points > 0

Action:
At Risk segment
→ targeted message
```

### Tier upgrade

```text
event: tier.upgraded
→ congratulation Flex
→ tier-benefit explanation
→ optional reward
```

### Reward redeemed

```text
event: reward.redeemed
→ confirmation
→ follow-up after delivery/use
```

This integration turns loyalty from a passive points database into a retention engine.

---

# 22. Recommended CRM lifecycle model

## Commercial lifecycle

```text
follower
member
first_purchase
repeat
loyal
vip
at_risk
inactive
reactivated
```

Suggested starting rules—configurable later:

### follower

LINE contact exists, not registered, no purchase.

### member

Registered but no completed purchase.

### first_purchase

Exactly one completed order.

### repeat

2+ orders.

### loyal

Configurable combination of frequency / monetary / recency.

### vip

Top tier or explicit VIP rule.

### at_risk

Previously active but outside normal repeat interval.

### inactive

Longer inactivity threshold.

### reactivated

New purchase after an inactive period.

## Pharmacy-care state

Keep separate from commercial lifecycle:

```text
none
needs_pharmacist
consultation_open
followup_due
refill_due
appointment_due
resolved
```

This separation keeps dashboards understandable and prevents health-care state from being confused with marketing state.

---

# 23. Dashboard redesign priorities

The default owner/operator dashboard should answer:

> “What happened, who needs attention, and what can I do now?”

Recommended primary KPIs:

| KPI | Purpose |
|---|---|
| Members | CRM base size |
| New members | Acquisition |
| Active 30d | Engagement |
| Repeat rate | Retention |
| At-risk customers | Recovery opportunity |
| Member revenue | Commercial value |
| Outstanding point liability | Loyalty cost exposure |
| Redemption rate | Loyalty engagement |

Recommended action panel:

```text
TODAY
────────────────────────────────
28 customers inactive >45 days
12 customers have points expiring soon
7 customers are within 100 pts of next tier
18 pharmacy follow-ups due

[Create campaign]
[Open customer list]
```

This is more actionable than a dashboard dominated by generic CRM metrics.

---

# 24. SaaS foundation findings

The codebase already includes meaningful SaaS infrastructure.

Observed:

- Platform/master DB usage.
- Tenant records.
- Tenant DB provisioning.
- Database-per-tenant model.
- Tenant onboarding progress.
- Shop setup.
- LINE OA setup.
- AI setup.
- Public marketing landing.
- Beta signup form.
- UTM capture.
- Lead scoring.
- GA4 hooks.
- Meta Pixel hooks.
- Tenant-specific public landing settings.

This means the business does not need to “invent SaaS architecture” before selling. It needs to improve product activation, consistency, and positioning.

---

# 25. Tenant onboarding redesign

Current guided onboarding emphasizes:

```text
Shop
→ LINE
→ AI
→ Done
```

That proves configuration, but not business value.

Recommended activation onboarding:

```text
1. Shop profile
2. Connect LINE OA
3. Create/import first member
4. Configure base points rule
5. Create membership tiers
6. Create first reward
7. Give test points
8. Open customer 360
9. Create first segment/campaign
10. Go live
```

AI should remain available but can be optional during initial activation.

## Activation milestone

A tenant should not be considered “activated” merely because settings are filled in.

Suggested activation definition:

```text
LINE connected
AND at least 1 member exists
AND loyalty configured
AND at least 1 points transaction exists
AND staff viewed/used customer CRM
```

That is a much more meaningful SaaS activation metric.

---

# 26. Marketing landing-page findings

The apex site is currently served from the static REYA marketing landing through the `index.php` apex override.

The page has good visual/product depth and already presents many real capabilities.

The main issue is positioning density.

Current message communicates many features at once:

- CRM Inbox.
- AI Pharmacist.
- Dispensing.
- Online shop.
- Loyalty.
- Broadcast.
- Inventory.
- Dashboard.

This is impressive but expensive cognitively for cold traffic.

## Recommended core message

Instead of leading with “everything the platform has,” lead with the customer outcome:

> **เปลี่ยน LINE ร้านยา ให้จำลูกค้าและพาลูกค้ากลับมาซื้อซ้ำ**

Supporting line:

> เก็บสมาชิก ดูประวัติลูกค้า สะสมแต้ม แบ่งกลุ่ม และติดตามลูกค้าผ่าน LINE OA ที่ร้านใช้อยู่แล้ว

Then explain the product in four steps:

```text
1. จำลูกค้าทุกคน
2. ให้แต้มได้ทั้งหน้าร้านและ LINE
3. รู้ว่าใครควรกลับมาซื้อ
4. ส่งข้อความเฉพาะกลุ่มที่เหมาะสม
```

After the visitor understands that loop, introduce:

- AI pharmacist.
- Telepharmacy.
- Inventory.
- Online storefront.
- Advanced analytics.

---

# 27. Beta signup findings

`beta.php` already does useful lead qualification:

- Business type.
- Branch count.
- Current system.
- Pain points.
- Goals.
- Trial urgency.
- LINE OA status.
- Decision-maker role.
- Preferred contact time.
- Package interest.
- Demo format.
- UTM attribution.
- Lead scoring.

That information is valuable to sales, but the first-touch form is long for cold traffic.

## Recommended two-stage conversion

### Stage 1 — low friction

Ask only:

```text
Name
Shop/clinic name
Phone or LINE
Number of branches
Main problem
```

CTA:

```text
ขอดู Demo
```

### Stage 2 — qualification

After lead capture, ask optional qualification questions or collect them during follow-up.

This preserves sales intelligence without forcing every visitor to complete a long questionnaire before becoming a lead.

---

# 28. SaaS package positioning

Do not package by internal technology counts.

Avoid:

```text
Basic: 5 APIs
Pro: 20 APIs
```

Recommended outcome-based packaging:

## Starter — Member & Loyalty

For stores that want to start remembering customers and increasing repeat visits.

- Member profiles.
- LINE/phone members.
- Member card.
- Points.
- Tier.
- Rewards.
- Point history.
- QR points claim.

## Growth — CRM & Retention

Adds:

- Customer 360.
- Segmentation.
- Lifecycle.
- Broadcast targeting.
- Drip automation.
- At-risk detection.
- Loyalty-triggered automation.
- Advanced analytics.

## Pharmacy Pro

Adds healthcare and larger-operation capability:

- Pharmacy health context.
- AI pharmacist workflows.
- Telepharmacy.
- Appointment/follow-up flows.
- Multi-branch capabilities.
- Advanced integrations.
- Enterprise reporting.

Pricing should be decided separately after calculating support, LINE messaging, AI usage, onboarding, and per-tenant infrastructure costs.

---

# 29. Content strategy for selling the SaaS

The strongest content should demonstrate a problem and its resolution—not introduce a feature list.

## Content pillars

### Pillar A — Customer memory

Hook:

> “ลูกค้าซื้อยาที่ร้านคุณวันนี้ อีก 3 เดือนคุณยังรู้ไหมว่าเขาเคยซื้ออะไร?”

Demo:

- Open customer 360.
- Show timeline.
- Show last purchase.
- Show tier/points.

### Pillar B — Loyalty without app installation

Hook:

> “สะสมแต้มร้านยา ไม่จำเป็นต้องโหลดแอป”

Demo:

- Phone-only member.
- QR claim.
- LINE member card.

### Pillar C — Stop broadcasting blindly

Hook:

> “LINE OA มีเพื่อน 5,000 คน แต่คุณรู้ไหมว่า 38 คนไหนกำลังจะหายไป?”

Demo:

- At-risk segment.
- Create campaign.
- Targeted send.

### Pillar D — Retention economics

Hook:

> “ระบบแต้มที่ดีไม่ได้มีไว้แจกแต้ม แต่มันต้องพาลูกค้ากลับมา”

Demo:

- Points earned.
- Near-tier trigger.
- Reward.
- Repeat purchase.

### Pillar E — Pharmacist workflow

Hook:

> “ก่อนตอบลูกค้า เภสัชกรเห็นประวัติที่จำเป็นในหน้าเดียว”

Demo:

- Customer 360.
- Allergy warning.
- Current medications.
- Previous consultation.

Care must be taken not to expose real patient data in public content. Demo data should always be synthetic.

---

# 30. First recommended sales video

## 20-second QR loyalty demo

```text
0–3s
Customer pays at counter
Text: “สะสมแต้มร้านยา ไม่ต้องโหลด App”

3–7s
Staff enters ฿450
Presses “ให้แต้ม”

7–10s
One-time QR appears

10–14s
Customer scans with LINE

14–17s
Screen shows:
+45 points
Silver Member

17–20s
CRM dashboard shows:
Members
At Risk
VIP

Text:
“นี่ไม่ใช่แค่ระบบแต้ม
นี่คือ CRM ของร้านคุณ”

REYA
CRM ร้านยาบน LINE
```

This demo expresses a complete product loop much faster than a generic feature tour.

---

# 31. Proposed PHP-first service architecture

No framework rewrite is required.

Recommended service boundaries:

```text
classes/
├─ LoyaltyLedgerService.php
├─ LoyaltyRuleEngine.php
├─ RewardRedemptionService.php
├─ MembershipService.php
├─ MemberIdentityService.php
├─ TierService.php
├─ CustomerCRMService.php
├─ CustomerLifecycleService.php
├─ CRMAutomationService.php
├─ CRMEventService.php
└─ CRMDashboardService.php
```

## Responsibilities

### `LoyaltyLedgerService`

- Credit/debit points.
- Atomic balance updates.
- Ledger history.
- Idempotency.
- Reversal/refund.
- Expiry transactions.

### `LoyaltyRuleEngine`

- Base rate.
- Minimum spend.
- Campaign multipliers.
- Category multipliers.
- Tier multipliers.
- Event bonuses.
- Calculation explanation.

### `RewardRedemptionService`

- Reward validation.
- Redemption transaction.
- Stock handling.
- Per-member limits.
- Cancellation/refund.
- Status workflow.

### `MembershipService`

- Registration.
- Welcome benefit.
- Member ID.
- Member card data.
- Phone-only membership.

### `MemberIdentityService`

- LINE identity verification.
- Phone identity.
- Link/merge candidates.
- Manual merge workflow.

### `CustomerCRMService`

- Customer 360.
- Tags.
- Segments.
- Customer timeline.

### `CustomerLifecycleService`

- Commercial lifecycle.
- At-risk/inactive rules.
- Reactivation.

### `CRMAutomationService`

- Event-to-campaign rules.
- Drip enrollment.
- Step conditions.
- Actions.

### `CRMDashboardService`

- Reporting/read-model only.
- No canonical business-state mutation.

---

# 32. Backward compatibility strategy

A hard rewrite is not recommended.

Use a strangler approach.

Example:

```text
Old api/points.php
        ↓
Compatibility adapter
        ↓
New LoyaltyLedgerService
```

Likewise:

```text
CRMManager
        ↓
CustomerCRMService / CRMAutomationService
```

and:

```text
AdvancedCRM
        ↓
CustomerCRMService
```

This allows existing pages to keep working while logic is centralized underneath them.

---

# 33. Proposed implementation phases

## Phase 0 — Baseline and guardrails

### Goal

Know the exact current behavior before changing the loyalty core.

### Work

- Document current DB columns/tables for loyalty/member/rewards per tenant migration version.
- Identify every code path that writes points.
- Identify every code path that reads points.
- Identify every reward redemption implementation.
- Identify every tier calculation entrypoint.
- Identify LINE identity/authentication middleware.
- Identify tenant/OA routing for every public Mini App API.
- Add targeted regression tests around current critical flows.

### Deliverable

A source-of-truth matrix:

| Operation | Current writer | Current table(s) | Target writer |
|---|---|---|---|
| Welcome bonus | `api/member.php` | users.points + points_history | LoyaltyLedgerService |
| Order earn | existing order flow | mixed | LoyaltyLedgerService |
| QR claim | `api/points-claim.php` | LoyaltyPoints | LoyaltyLedgerService |
| Direct staff award | `api/points-claim.php` | LoyaltyPoints | LoyaltyLedgerService |
| Reward redeem | multiple paths | mixed | RewardRedemptionService |
| Refund | membership/reward flow | LoyaltyPoints | RewardRedemptionService |

### Acceptance

No production behavior changed yet.

---

## Phase 1 — Canonical loyalty ledger

### Goal

Make point movement deterministic and auditable.

### Work

- Introduce `LoyaltyLedgerService`.
- Add idempotency support.
- Wrap credit/debit in DB transactions.
- Update cached user aggregates atomically.
- Fix zero-ledger-balance fallback.
- Add reconciliation command/report.

### Files likely touched

- `classes/LoyaltyPoints.php`
- new `classes/LoyaltyLedgerService.php`
- migration SQL
- loyalty tests

### Acceptance

For every tested user:

```text
ledger sum == available_points cache
```

and duplicate idempotency keys cannot award twice.

---

## Phase 2 — Move every loyalty writer to the ledger

### Work

Refactor:

- `api/member.php` welcome bonus.
- `api/points.php` earn/redeem compatibility path.
- `api/points-claim.php` direct/QR award.
- Order point awards.
- Manual admin adjustments.
- Reward refunds.
- Any cron bonus/expiry jobs.

### Acceptance

There is only one production write path for point balances.

Legacy tables may still be readable during migration, but no normal runtime path creates new legacy-only point movements.

---

## Phase 3 — Tier model cleanup

### Work

- Define qualifying-points policy.
- Stop tier downgrade based purely on spendable balance.
- Split `earn_multiplier` from `discount_percent`.
- Normalize tier schema.
- Ensure UI/settings use same schema as TierService.
- Backfill current tiers.

### Acceptance

Redeeming points does not incorrectly destroy earned membership status.

Tier shown in:

- Admin.
- Member card.
- Mini App.
- CRM.

must match.

---

## Phase 4 — Loyalty rule engine

### Work

- Build `LoyaltyRuleEngine`.
- Apply base rate.
- Campaign multiplier.
- Category multiplier.
- Tier multiplier.
- Bonus points.
- Persist explanation metadata.
- Add admin preview calculator.

### Acceptance

Given the same order + user + timestamp, all call sites calculate the same point award.

---

## Phase 5 — Rewards consolidation

### Work

- Introduce `RewardRedemptionService`.
- Route all reward APIs through it.
- Validate validity window.
- Enforce max per user.
- Atomic stock decrement.
- Atomic points debit.
- Idempotent redemption.
- Explicit refund/reversal.

### Acceptance

Concurrent double-click/double-request cannot:

- make stock negative,
- spend points twice incorrectly,
- or generate duplicate valid redemptions.

---

## Phase 6 — Identity and API hardening

### Work

- Verify LINE token server-side.
- Derive `line_user_id` from verified identity.
- Review CORS.
- Remove production error-detail leakage.
- Add CSRF to authenticated admin actions where needed.
- Review rate limits.
- Confirm tenant/OA scope on every endpoint.

### Acceptance

A user cannot obtain another member’s point/reward/member data merely by changing a request `line_user_id`.

---

## Phase 7 — Customer CRM consolidation

### Work

- Introduce `CustomerCRMService`.
- Centralize tags.
- Centralize segments.
- Centralize customer summary/360.
- Convert old CRM classes to wrappers where practical.
- Create one timeline model.

### Acceptance

Customer 360 has one canonical backend response and is consistent across admin surfaces.

---

## Phase 8 — Lifecycle + loyalty automation

### Work

- Add lifecycle calculation.
- Add CRM event table/bus abstraction in PHP.
- Emit loyalty/member/order events.
- Add At Risk / Inactive / Reactivated.
- Add near-tier and expiring-point segments.
- Connect events to drip/campaign enrollment.

### Acceptance

The system can automatically produce lists/actions such as:

```text
customers inactive >45 days
customers near next tier
customers with expiring points
new Gold members
recent reward redeemers
```

---

## Phase 9 — Member experience

### Work

- Improve member card.
- Dynamic QR member proof.
- Tier progress.
- Expiry display based on correct point-lot accounting.
- Rewards shortcut.
- History shortcut.
- Clear benefit explanations.

### Acceptance

A customer can understand in one screen:

- who they are,
- how many points they can spend,
- their tier,
- what comes next,
- and what rewards they can claim.

---

## Phase 10 — SaaS activation and marketing

### Work

- Redesign onboarding around first value.
- Simplify first beta/lead form.
- Reposition public landing.
- Add focused demo videos.
- Add CRM/loyalty proof sections.
- Define activation analytics.
- Define funnel metrics.

### Acceptance

A new lead can understand the core value in under one minute and a new tenant can complete a meaningful loyalty/CRM action during onboarding.

---

# 34. Migration strategy for existing points

A migration must not simply copy one arbitrary balance column into the new ledger.

Recommended reconciliation process per tenant:

## Step 1 — calculate candidate balances

For every user calculate:

```text
ledger_balance        = SUM(points_transactions.points)
legacy_history_balance = SUM(points_history.points)
users_available       = users.available_points
users_points          = users.points
```

## Step 2 — classify

```text
MATCHED
LEDGER_ONLY
LEGACY_ONLY
CACHE_ONLY
CONFLICT
ZERO_LEDGER_WITH_STALE_CACHE
```

## Step 3 — define migration authority

Rules must be explicit.

Example:

- If modern ledger has rows, prefer ledger unless known migration exception.
- If no modern ledger rows but legacy history is complete, backfill from legacy history.
- If only user cache exists, create one `migration` opening-balance transaction with audit metadata.
- Conflicts above a threshold go to a reconciliation report.

## Step 4 — do not silently rewrite history

Any adjustment made to align a member should be recorded as a migration/reconciliation transaction.

---

# 35. Test plan

## 35.1 Loyalty unit tests

Test:

- Base earn.
- Minimum purchase threshold.
- Campaign multiplier.
- Category multiplier.
- Tier multiplier.
- Combined multipliers.
- Rounding rules.
- Duplicate idempotency.
- Credit transaction rollback.
- Debit insufficient balance.
- Concurrent debit.
- Refund.
- Reversal.
- Expiry.

## 35.2 Tier tests

Test:

- Bronze/Silver/Gold/Platinum boundaries.
- Progress percent.
- Exact threshold.
- Max tier.
- Redemption does not reduce qualification under lifetime policy.
- Settings changes invalidate cache.
- Earn multiplier separate from discount.

## 35.3 Rewards tests

Test:

- Active reward.
- Inactive reward.
- Before start date.
- After end date.
- Unlimited stock.
- Zero stock.
- Concurrent last-item redemption.
- Max per user.
- Insufficient points.
- Cancellation refund.
- Delivered cannot be cancelled when policy forbids it.
- Duplicate redeem request.

## 35.4 Identity/security tests

Test:

- Valid LINE token.
- Invalid token.
- Expired token.
- Token subject mismatch.
- Wrong tenant/OA.
- Client-forged `line_user_id` ignored/rejected.

## 35.5 Membership tests

Test:

- New LINE registration.
- Existing follower registration.
- Welcome bonus exactly once.
- Phone-only member creation.
- Phone lookup.
- Merge candidate.
- Confirm merge.
- Dismiss merge.
- No duplicate welcome bonus after retries.

## 35.6 CRM tests

Test:

- Tag assignment.
- Dynamic segment membership.
- Lifecycle transitions.
- Inactivity threshold.
- Reactivation.
- Near-tier segment.
- Expiring-point segment.
- Campaign duplicate enrollment prevention.

---

# 36. Observability and support tooling

A loyalty SaaS needs support visibility.

Recommended admin/support tools:

## Member points audit

For a user:

```text
cached available points
ledger calculated balance
lifetime earned
used
expired
last 20 transactions
reconciliation status
```

## Transaction detail

Show:

- Reason.
- Source.
- Reference.
- Rule calculation breakdown.
- Staff/system actor.
- Timestamp.
- Tenant/OA.
- Idempotency key.

## Tenant loyalty health

Show:

```text
members with balance mismatch
failed point operations
failed reward operations
expired claims
duplicate/replayed requests blocked
outstanding points liability
```

This reduces support/debugging cost significantly.

---

# 37. Metrics to measure after launch

## Product activation

- LINE connected tenants.
- Tenants with first member.
- Tenants with first points award.
- Tenants with first reward.
- Tenants with first campaign.
- Time to first value.

## Loyalty

- Active members.
- Earn rate.
- Redemption rate.
- Point liability.
- Expiry rate.
- Average available balance.
- Reward utilization.
- Tier distribution.

## CRM

- Repeat purchase rate.
- Reactivation rate.
- At-risk recovery rate.
- Campaign conversion.
- Member revenue share.
- Customer lifetime value proxy.

## SaaS

- Visitor → lead conversion.
- Lead → demo conversion.
- Demo → trial conversion.
- Trial → activated tenant.
- Activated → paid.
- 30/60/90-day retention.

---

# 38. Priorities

## P0 — correctness/security before growth

1. Canonical point ledger.
2. Fix zero-balance fallback behavior.
3. Route welcome bonus through canonical loyalty service.
4. Remove duplicate redemption logic.
5. Atomic point/reward transactions.
6. Idempotency.
7. Correct tier qualification semantics.
8. Split tier earn multiplier from discount percent.
9. Verify LINE identity server-side.
10. Verify tenant/OA scope on public APIs.
11. Remove sensitive production error details.

## P1 — product value

1. Loyalty rule engine.
2. Customer CRM service consolidation.
3. Lifecycle engine.
4. At-risk/inactive/reactivated states.
5. Loyalty → CRM event automation.
6. Better Customer 360.
7. Correct point expiry accounting.
8. Member card improvements.

## P2 — SaaS conversion

1. Onboarding around first value.
2. Shorter lead form.
3. Outcome-first landing page.
4. Demo-first content.
5. Outcome-based package positioning.
6. Funnel instrumentation.

## P3 — later optimization

1. Remove old compatibility APIs/tables after proven migration.
2. Optional UI/runtime migration to Next.js where beneficial.
3. Advanced scoring/prediction.
4. Cross-branch/enterprise optimizations.

---

# 39. Risk matrix

| Risk | Severity | Why |
|---|---:|---|
| Multiple point sources of truth | Critical | Can show/spend incorrect balances |
| Non-atomic point updates | Critical | Partial writes / inconsistent ledger |
| Duplicate/retry awards | Critical | Financial/loyalty liability |
| Client-trusted LINE identity | Critical until verified | Possible account impersonation |
| Tier based on spendable balance | High | Bad member experience |
| Multiplier/discount semantic mix | High | Incorrect rewards/benefits |
| Multiple reward implementations | High | Different redemption behavior |
| Expiry without allocation model | High if expiry enabled | Incorrect expiry notices/debits |
| Multi-OA query scoping gaps | High | Data mixing inside tenant |
| CRM service overlap | Medium | Maintenance and feature drift |
| Long SaaS signup form | Medium | Lower acquisition conversion |
| Feature-heavy landing message | Medium | Weak product comprehension |
| Premature PHP → Next migration | Medium/High | Adds risk before domain is stable |

---

# 40. Implementation guardrails

These rules should be followed during the refactor.

## PHP-first

- Do not move CRM/loyalty source of truth into Next.js as part of these fixes.
- Existing Next apps may consume PHP APIs after they are stabilized.

## No big-bang rewrite

- Introduce new services behind existing pages/APIs.
- Keep compatibility until traffic/tests prove the new path.

## Preserve tenant isolation

- Never trade database-per-tenant isolation for implementation convenience.

## Preserve existing working user flows

Particularly:

- LINE member registration.
- Phone-only member.
- QR claim.
- Reward redemption.
- Admin point adjustment.
- Existing Mini App screens.

## Every balance change is auditable

No silent direct point mutations after Phase 2.

## Every external retryable event is idempotent

Orders, webhooks, claims, rewards, and welcome bonuses must be safe under retry.

## No marketing use of health data by accident

Pharmacy-care data and commercial CRM data must remain logically separated.

---

# 41. Proposed first code batch after approval

Do **not** start by redesigning screens.

The first implementation batch should be small and foundational:

## Batch 1 — loyalty invariant

1. Add tests reproducing current ledger/cache mismatch scenarios.
2. Introduce `LoyaltyLedgerService`.
3. Fix `getUserPoints()` so a valid zero ledger balance does not fall back to stale cache.
4. Make add/deduct atomic.
5. Add idempotency capability.
6. Keep `LoyaltyPoints` as compatibility facade.
7. Add a read-only reconciliation report/script.

No broad UI redesign in this batch.

## Batch 2 — writer migration

Move:

- Welcome bonus.
- QR/direct awards.
- Order awards.
- Manual adjustment.
- Reward debit/refund.

onto the same ledger service.

## Batch 3 — tiers

Then fix tier semantics and settings.

This order prevents building new CRM/marketing functionality on top of unstable point accounting.

---

# 42. What should explicitly NOT be done yet

Before P0 is complete, avoid:

- Adding more point-balance columns.
- Adding a third point history table.
- Creating another loyalty class for one new page.
- Rewriting loyalty into Node/Next.
- Adding complicated AI loyalty decisions.
- Selling point expiry heavily before expiry accounting is correct.
- Adding more CRM dashboard pages without consolidating the backend model.
- Automatically merging phone and LINE identities without staff-safe rules.

---

# 43. Product direction in one sentence

The strongest direction for REYA is:

> **A LINE-first pharmacy CRM that remembers customers, rewards loyalty, tells the pharmacy who needs attention, and turns those signals into measurable repeat business.**

The engineering plan should support that sentence directly.

---

# 44. Definition of “core ready”

Before calling the CRM + loyalty core ready for broad SaaS rollout, all of the following should be true:

- [ ] PHP is the documented canonical runtime for current CRM/loyalty business logic.
- [ ] One canonical point ledger exists.
- [ ] All point writes go through one service.
- [ ] Point operations are transactional.
- [ ] Retry/idempotency is implemented.
- [ ] Balance reconciliation reports zero unexplained mismatches.
- [ ] Tier uses a clearly defined qualification metric.
- [ ] Earn multiplier and discount are separate concepts.
- [ ] Reward redemption uses one service.
- [ ] Reward stock is concurrency-safe.
- [ ] Expiry policy is mathematically correct.
- [ ] LINE identity is verified server-side.
- [ ] Tenant/OA scoping is tested.
- [ ] Customer 360 has one canonical backend representation.
- [ ] Lifecycle rules exist.
- [ ] Loyalty events can trigger CRM actions.
- [ ] Phone-only → LINE merge is auditable.
- [ ] Member card reflects the same balances/tier as admin.
- [ ] New tenant onboarding reaches a real first-value event.
- [ ] Marketing page explains the core outcome before secondary features.

---

# 45. Recommended next step

After this plan is approved, the next engineering action should be **Phase 0 + Batch 1 only**:

```text
Audit all point read/write paths
→ write regression tests
→ create canonical ledger service
→ fix balance fallback
→ add transactions/idempotency
→ build reconciliation report
```

Only after that passes should the project move into tier, rewards, CRM lifecycle, member-card, and marketing changes.

This keeps the highest-risk financial/loyalty state stable while preserving the existing PHP application and user flows.
