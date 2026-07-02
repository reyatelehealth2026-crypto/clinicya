# Testing Map

## Existing Test Surfaces

PHP tests use PHPUnit per `composer.json` script `composer test`. Static analysis and style scripts are `composer analyse` and `composer lint`.

Confirmed PHP test groups under `tests/`:

- `AIChat`: triage session, protocol sequence, red flags, drug interaction/allergy, conversation history, emergency alert, pharmacist notification, symptom mapper.
- `LiffTelepharmacy`: cart, product card, orders, points, rewards, QR, promo code, transaction history, touch targets.
- `LandingPage`: structured data, sitemap, SEO meta, responsive layout, LIFF URL correctness, shop data display.
- `InboxChat`: template roundtrip, placeholder replacement, search relevance, pagination limit.
- `AdminMenu`: role menu visibility, structure, auto-expand.
- `VibeSelling`: urgent symptoms, drug widgets, allergy checks, margins, health profile, stock exclusion.
- `Payment`: slip verifier.
- `Onboarding`: setup URLs, provisioning link, LIFF endpoint, install wizard guard.
- Additional: `AuditLoggingTest.php`, `DashboardCacheServiceTest.php`, `PerformanceOptimizationTest.php`.

Mini app tests: `line-mini-app/src/lib/__tests__/*.test.ts`, with package script `test:unit`.

Backend tests: `backend/src/test/**` covers auth services, JWT security, route tests, payments, customers, infrastructure, system/property tests.

## Modules With Partial or No Confirmed Coverage

Partial/unknown coverage:

- `webhook.php` full event matrix and reply/push fallback paths.
- `inbox-v2.php` same-page AJAX actions, uploads, and dispense flow.
- `api/checkout.php` end-to-end create order and slip upload with DB side effects.
- Cron jobs and worker behavior under concurrency/lock conditions.
- Tenant routing load-order behavior between `config/database.php` and `classes/Database.php`.
- Fastify WebSocket auth and Redis failure modes.

## Critical Paths Without Enough Evidence

- Server-side validation that mini app `line_user_id` belongs to the authenticated LIFF user.
- CSRF coverage for PHP admin same-page AJAX beyond specific helper-based endpoints.
- File upload MIME/content validation for admin and payment slip uploads.
- Runtime migration/schema drift caused by scripts that create/alter tables on execution.

## Proposed Tests By Priority

P0:

- Webhook signature/account resolution tests for `?account`, signature fallback, invalid signature, and missing default.
- Mini app checkout integration tests for product/cart/order/slip upload with tenant scoping.
- Auth/tenant context tests for admin, platform user, switched tenant, and missing tenant fallback.

P1:

- Inbox dispense tests for cash/credit stock decrement and transfer/later cart/order seeding.
- AI chat SSE parser and PHP endpoint contract tests for structured events and error frames.
- Cron idempotency tests for broadcast queue and webhook retry processor.

P2:

- API contract snapshots for member/rewards/appointments/reminders.
- Performance regression tests for conversation list pagination and product catalog paging.

## Last Verified From Code

Verified on 2026-07-03 from `composer.json`, `tests/**`, `line-mini-app/package.json`, `line-mini-app/src/lib/__tests__`, `backend/src/test/**`, `webhook.php`, `inbox-v2.php`, `api/checkout.php`, `cron/*.php`.
