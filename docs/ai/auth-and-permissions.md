# Auth and Permissions

## Tenant Admin Authentication

Confirmed flow: `auth/login.php` accepts username/password. `classes/AdminAuth.php` queries `admin_users WHERE username = ? AND is_active = 1`, verifies with `password_verify`, updates `last_login` and `login_count`, and stores admin data in session. `includes/auth_check.php` redirects to login when `$_SESSION['admin_user']` is absent.

## Platform Authentication

`admin/platform-login.php` authenticates `platform_users` using email/password hash and calls `session_regenerate_id(true)` after success. Google OAuth flow in `auth/google-callback.php` verifies CSRF state, exchanges Google token server-side, verifies `id_token`, maps/creates `platform_users`, and hands tenant users to `auth/sso-consume.php` using short-lived SSO.

## Session and Tenant Context

`includes/auth_check.php` resolves tenant context after login by using `$_SESSION['current_bot_id']`, `$_SESSION['active_tenant_id']`, `platform_user_id`, and `admin_switched_to_tenant_id`. `TenantContext` is pinned for the request when available. Super admins only enter tenant context explicitly via switch path according to comments in `includes/auth_check.php`.

## Roles and Authorization Checks

Confirmed role helpers:

| Helper | Roles |
| --- | --- |
| `isSuperAdmin()` | `super_admin` |
| `isAdmin()` | `admin`, `super_admin` |
| `isStaff()` | `staff` |
| `isUser()` | `user` |

Confirmed enforcement helpers: `requireSuperAdmin()`, `requireAdmin()`, `requireUserWithAccount()`, `canAccessLineAccount()`, `canAccessBotPermission()`.

Fastify backend uses JWT auth. Evidence: `backend/src/config/config.ts` requires `JWT_SECRET` and `JWT_REFRESH_SECRET`; `backend/src/services/AuthService.ts` signs/validates tokens; route files import/use `authenticate`; `backend/src/websocket/server.ts` verifies JWT for socket authentication.

## Permission Matrix

| Capability | Evidence | Auth/permission |
| --- | --- | --- |
| PHP admin pages | `includes/auth_check.php` | session `admin_user` required |
| Super admin pages/actions | `requireSuperAdmin()` | role `super_admin` |
| Admin actions | `requireAdmin()` | role `admin` or `super_admin` |
| Bot/account access | `canAccessLineAccount()`, `AdminAuth::canAccessBot()` | super admin all; otherwise account permission |
| Platform login | `admin/platform-login.php` | `platform_users` email/password |
| Fastify API | `backend/src/routes/*.ts` | JWT `authenticate` except health/performance routes where configured |
| Mini app PHP APIs | `line-mini-app/src/lib/*.ts`, `api/*.php` | mostly `line_user_id` and `line_account_id`; server-side LIFF token verification not confirmed |

## Privilege Escalation Risks

- Mini app endpoints inspected rely heavily on client-supplied `line_user_id`/`line_account_id`; no universal server-side LIFF token verification was confirmed in `api/checkout.php` or the client contract.
- Tenant routing has legacy fallback behavior in `Modules\Core\Database::getInstance()`, which can route calls to legacy DB when tenant context is absent.
- Permission checks are helper-based and not uniformly evident across all `api/*.php` files.

## Last Verified From Code

Verified on 2026-07-03 from `auth/login.php`, `classes/AdminAuth.php`, `includes/auth_check.php`, `admin/platform-login.php`, `auth/google-callback.php`, `auth/sso-consume.php`, `classes/TenantContext.php`, `backend/src/config/config.ts`, `backend/src/services/AuthService.ts`, `backend/src/websocket/server.ts`, `backend/src/routes/*.ts`, `line-mini-app/src/lib/*.ts`, `api/checkout.php`.
