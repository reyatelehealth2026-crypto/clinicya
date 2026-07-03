# Security Review

Only findings supported by inspected code are listed.

## Trust Boundaries

- LINE to `webhook.php`: protected by `X-Line-Signature` validation using per-account `channel_secret`.
- Browser/mini app to PHP APIs: relies on HTTP parameters such as `line_user_id` and `line_account_id` in inspected contracts.
- Admin browser to PHP pages: session-based, `$_SESSION['admin_user']`.
- Fastify clients to backend: JWT Bearer authentication.
- Cron/worker to DB/LINE: local execution with DB credentials and LINE tokens.

## Supported Findings

### P0: Committed Secret-Like Values In Config

Evidence: `config/config.php` contains literal DB constants and Redis password fallback. This is sensitive even if production overrides exist.

Risk: credential leakage, unauthorized DB/Redis access, credential reuse.

Recommended remediation: move all secrets to environment or secret manager, rotate exposed values, keep examples sanitized.

### P0: Mini App Identity Is Client-Supplied In Inspected Contracts

Evidence: `line-mini-app/src/lib/shop-api.ts` sends `line_user_id` and `line_account_id`; `api/checkout.php` action handlers consume those values. No universal server-side LIFF access token verification was confirmed in inspected code.

Risk: user/order/cart spoofing across tenants or LINE users if endpoints are reachable directly.

Recommended remediation: verify LINE/LIFF identity server-side for sensitive actions, bind `line_user_id` to verified token/profile, and enforce tenant scope server-side.

### P1: Mixed Database Routing May Cause Cross-Tenant Or Legacy Fallback

Evidence: `classes/Database.php` warns about class collision with `config/database.php`; `Modules\Core\Database::getInstance()` falls back to legacy DB when no `TenantContext` is set.

Risk: wrong tenant data access or writes when entry points do not pin tenant context.

Recommended remediation: consolidate `config/database.php` to the tenant-aware shim, audit entry points for explicit tenant context.

### P1: CSRF Coverage Is Not Uniformly Evident

Evidence: `api/_products_lookup_crud.php` calls `reya_csrf_check()`, while many inspected file-based APIs use JSON/action switches without visible CSRF enforcement. `inbox-v2.php` AJAX actions are session-gated but no universal CSRF token was confirmed in the inspected same-page POST block.

Risk: admin session actions may be triggerable from another origin if browser protections and CORS do not block them.

Recommended remediation: require CSRF tokens for session-authenticated mutating PHP endpoints.

### P1: Upload Validation Is MIME/Size Or Extension Based In Some Paths

Evidence: `inbox-v2.php` handles image/PDF uploads using `$_FILES`, MIME/size checks, generated filenames, and `move_uploaded_file`; `api/checkout.php::handleUploadSlip()` accepts multipart slip upload.

Risk: content-type spoofing, unsafe public upload paths, malware storage, oversized processing.

Recommended remediation: validate file signatures, store outside executable paths or enforce webserver no-execute, scan where needed, and normalize extensions.

### P2: Some CORS Headers Are Broad

Evidence: `api/wishlist.php`, `api/webhook-monitoring.php`, and `api/ai_handler.php` include `Access-Control-Allow-Origin: *`.

Risk: broad browser access to endpoints, especially if combined with weak identity checks.

Recommended remediation: restrict origins for endpoints with user/tenant data and avoid wildcard CORS on authenticated or sensitive APIs.

## Positive Controls

- LINE webhook signature validation is implemented in `webhook.php`, `classes/LineAPI.php`, and `LineAccountManager`.
- Passwords use `password_hash` and `password_verify` in `classes/AdminAuth.php` and `admin/platform-login.php`.
- Google callback validates CSRF state and id token before platform login handoff.
- Fastify backend validates JWT tokens and WebSocket tokens.
- Many SQL paths use prepared statements; examples appear throughout `inbox-v2.php`, `api/checkout.php`, and services.

## Unknowns

Rate limiting for PHP endpoints was not confirmed globally. Web application firewall, server upload restrictions, production CORS behavior, and cookie flags were not verified.

## Last Verified From Code

Verified on 2026-07-03 from `config/config.php`, `webhook.php`, `classes/LineAPI.php`, `classes/LineAccountManager.php`, `classes/AdminAuth.php`, `admin/platform-login.php`, `auth/google-callback.php`, `includes/auth_check.php`, `classes/Database.php`, `modules/Core/Database.php`, `line-mini-app/src/lib/shop-api.ts`, `api/checkout.php`, `inbox-v2.php`, `api/_products_lookup_crud.php`, `api/wishlist.php`, `api/webhook-monitoring.php`, `backend/src/services/AuthService.ts`, `backend/src/websocket/server.ts`.
